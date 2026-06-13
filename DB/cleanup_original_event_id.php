<?php
/**
 * Cleanup migration:
 *   1. Backfill equipment_borrow.borrow_start/end from reservations (for rows that still have NULL)
 *   2. DROP events.original_event_id (the column is unused after the equipment_requests refactor)
 *
 * Idempotent — safe to re-run.
 * Run: php DB/cleanup_original_event_id.php
 */
require __DIR__ . '/../DB/db_config.php';

// ─── Step 1a: Backfill from reservations (rows that have reservation_id) ──────
$backfill1 = $conn->query(
    "UPDATE equipment_borrow eb
     JOIN reservations r ON eb.reservation_id = r.reservation_id
     SET eb.borrow_start = COALESCE(eb.borrow_start, r.start_time),
         eb.borrow_end   = COALESCE(eb.borrow_end,   r.end_time)
     WHERE eb.borrow_start IS NULL AND eb.reservation_id IS NOT NULL"
);
if ($conn->errno) {
    echo "FAILED: backfill from reservations: " . $conn->error . "\n";
    exit(1);
}
echo "①a Backfilled from reservations: {$conn->affected_rows} rows updated\n";

// ─── Step 1b: Backfill from events (rows without reservation_id) ───────────────
$backfill2 = $conn->query(
    "UPDATE equipment_borrow eb
     JOIN events ev ON eb.event_id = ev.event_id
     SET eb.borrow_start = COALESCE(eb.borrow_start, ev.start_time),
         eb.borrow_end   = COALESCE(eb.borrow_end,   ev.end_time)
     WHERE eb.borrow_start IS NULL AND eb.request_id IS NULL"
);
if ($conn->errno) {
    echo "FAILED: backfill from events: " . $conn->error . "\n";
    exit(1);
}
echo "①b Backfilled from events: {$conn->affected_rows} rows updated\n";

// Final check
$null_check = $conn->query("SELECT COUNT(*) AS cnt FROM equipment_borrow WHERE borrow_start IS NULL AND request_id IS NULL");
$null_cnt = $null_check->fetch_assoc()['cnt'];
if ($null_cnt > 0) {
    echo "   WARNING: {$null_cnt} equipment_borrow row(s) still have borrow_start=NULL after both backfills.\n";
}

// ─── Step 2: DROP events.original_event_id ─────────────────────────────────────
$col = $conn->query("SHOW COLUMNS FROM events LIKE 'original_event_id'");
if ($col->num_rows > 0) {
    if (!$conn->query("ALTER TABLE events DROP COLUMN original_event_id")) {
        echo "FAILED: DROP original_event_id: " . $conn->error . "\n";
        exit(1);
    }
    echo "② Dropped events.original_event_id\n";
} else {
    echo "② events.original_event_id already removed, skipped\n";
}

echo "\nCleanup completed successfully.\n";
