<?php
/**
 * One-time migration: separate add-on equipment requests from events table.
 * Idempotent — DDL steps are skipped if already done.
 * Run: php DB/migrate_equipment_requests.php
 */
require __DIR__ . '/../DB/db_config.php';

// ─── DDL (auto-committed by MySQL; skip if already done) ──────────────────

// ① spaces.status
$col = $conn->query("SHOW COLUMNS FROM spaces LIKE 'status'");
if ($col->num_rows > 0) {
    $conn->query("ALTER TABLE spaces DROP COLUMN status");
    echo "① Dropped spaces.status\n";
} else {
    echo "① spaces.status already removed, skipped\n";
}

// ② equipment_requests table
$tbl = $conn->query("SHOW TABLES LIKE 'equipment_requests'");
if ($tbl->num_rows === 0) {
    $conn->query("CREATE TABLE equipment_requests (
        request_id      INT AUTO_INCREMENT PRIMARY KEY,
        parent_event_id INT NOT NULL,
        user_id         INT NOT NULL,
        club_name       VARCHAR(100) NOT NULL,
        borrow_start    DATETIME NOT NULL,
        borrow_end      DATETIME NOT NULL,
        status          ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
        review_note     TEXT,
        reviewed_at     DATETIME,
        reviewed_by     INT,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (parent_event_id) REFERENCES events(event_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($conn->errno) { echo "FAILED: CREATE equipment_requests: " . $conn->error . "\n"; exit(1); }
    echo "② Created equipment_requests table\n";
} else {
    echo "② equipment_requests already exists, skipped\n";
}

// ③ equipment_borrow.request_id
$col2 = $conn->query("SHOW COLUMNS FROM equipment_borrow LIKE 'request_id'");
if ($col2->num_rows === 0) {
    $conn->query("ALTER TABLE equipment_borrow ADD COLUMN request_id INT NULL AFTER borrow_end");
    if ($conn->errno) { echo "FAILED: ADD request_id: " . $conn->error . "\n"; exit(1); }
    $conn->query("ALTER TABLE equipment_borrow ADD CONSTRAINT fk_eb_req FOREIGN KEY (request_id) REFERENCES equipment_requests(request_id) ON DELETE SET NULL");
    if ($conn->errno) { echo "FAILED: ADD FK: " . $conn->error . "\n"; exit(1); }
    echo "③ Added equipment_borrow.request_id FK\n";
} else {
    echo "③ equipment_borrow.request_id already exists, skipped\n";
}

// ─── Data migration (transactional) ──────────────────────────────────────

// Guard: if original_event_id column no longer exists, data is already migrated
$col_guard = $conn->query("SHOW COLUMNS FROM events LIKE 'original_event_id'");
if ($col_guard->num_rows === 0) {
    echo "④ original_event_id column already removed — data migration skipped (already done)\n";
    echo "\nMigration completed successfully.\n";
    exit(0);
}

$conn->begin_transaction();

try {
    $children = $conn->query(
        "SELECT ev.event_id, ev.original_event_id, ev.user_id, ev.club_name,
                ev.start_time, ev.end_time, ev.status, ev.review_note,
                ev.reviewed_at, ev.reviewed_by, ev.created_at
         FROM events ev
         WHERE ev.original_event_id IS NOT NULL AND ev.original_event_id != 0"
    );
    if (!$children) throw new Exception("Query child events: " . $conn->error);

    $migrated = $skipped = 0;

    $esc = function($v) use ($conn) {
        return $v !== null ? "'" . $conn->real_escape_string($v) . "'" : "NULL";
    };

    while ($child = $children->fetch_assoc()) {
        $parent_id = intval($child['original_event_id']);
        $old_eid   = intval($child['event_id']);
        $bs        = $child['start_time'];
        $be        = $child['end_time'];

        // Check if parent event exists
        $chk = $conn->query("SELECT 1 FROM events WHERE event_id = {$parent_id} LIMIT 1");
        if (!$chk || $chk->num_rows === 0) {
            // Orphaned child — clean up without migrating
            $conn->query("DELETE FROM equipment_borrow WHERE event_id = {$old_eid}");
            $conn->query("DELETE FROM events WHERE event_id = {$old_eid}");
            echo "   Orphan child event {$old_eid} (parent={$parent_id} missing): deleted\n";
            $skipped++;
            continue;
        }

        // Check if already migrated (equipment_borrow rows already pointing to parent)
        $already = $conn->query("SELECT 1 FROM equipment_borrow WHERE event_id = {$old_eid} LIMIT 1");
        $still_has_borrow = $already && $already->num_rows > 0;

        // Insert into equipment_requests
        $sql_ins = "INSERT INTO equipment_requests
            (parent_event_id, user_id, club_name, borrow_start, borrow_end,
             status, review_note, reviewed_at, reviewed_by, created_at)
            VALUES (
                {$parent_id},
                " . intval($child['user_id']) . ",
                " . $esc($child['club_name']) . ",
                " . $esc($bs) . ",
                " . $esc($be) . ",
                " . $esc($child['status']) . ",
                " . $esc($child['review_note']) . ",
                " . $esc($child['reviewed_at']) . ",
                " . ($child['reviewed_by'] !== null ? intval($child['reviewed_by']) : 'NULL') . ",
                " . $esc($child['created_at']) . "
            )";
        if (!$conn->query($sql_ins)) throw new Exception("INSERT equipment_requests: " . $conn->error);
        $new_rid = $conn->insert_id;

        // Update equipment_borrow: redirect to parent, set request_id, backfill times
        if ($still_has_borrow) {
            $sql_upd = "UPDATE equipment_borrow
                        SET event_id     = {$parent_id},
                            request_id   = {$new_rid},
                            borrow_start = COALESCE(borrow_start, " . $esc($bs) . "),
                            borrow_end   = COALESCE(borrow_end,   " . $esc($be) . ")
                        WHERE event_id = {$old_eid}";
            if (!$conn->query($sql_upd)) throw new Exception("UPDATE equipment_borrow: " . $conn->error);
        }

        // Delete child event
        if (!$conn->query("DELETE FROM events WHERE event_id = {$old_eid}")) {
            throw new Exception("DELETE child event {$old_eid}: " . $conn->error);
        }

        echo "   Migrated child event {$old_eid} → request_id={$new_rid} (parent={$parent_id})\n";
        $migrated++;
    }

    $conn->commit();
    echo "④ Data migration done: {$migrated} migrated, {$skipped} orphans cleaned\n";
    echo "\nMigration completed successfully.\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "\nMigration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
