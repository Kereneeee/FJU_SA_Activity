<?php

function student_get_active_membership(mysqli $conn, int $user_id, ?string $preferred_club_id = null): ?array
{
    $sql = "SELECT cm.club_id, c.club_name, cm.is_officer, cm.officer_title, cm.officer_confirmation_date, cm.join_date
            FROM club_members cm
            JOIN clubs c ON cm.club_id = c.club_id
            WHERE cm.user_id = ?
            ORDER BY cm.is_officer DESC, cm.officer_confirmation_date DESC, cm.join_date DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rows)) {
        return null;
    }

    $preferred_club_id = $preferred_club_id !== null && $preferred_club_id !== '' ? (string)$preferred_club_id : null;
    if ($preferred_club_id !== null) {
        foreach ($rows as $row) {
            if ((string)$row['club_id'] === $preferred_club_id) {
                return $row;
            }
        }
    }

    return $rows[0];
}

function student_membership_reference_date(?array $membership): ?string
{
    if (!$membership) {
        return null;
    }

    return !empty($membership['officer_confirmation_date'])
        ? (string)$membership['officer_confirmation_date']
        : (!empty($membership['join_date']) ? (string)$membership['join_date'] : null);
}

function student_academic_year_start_year(string $date): int
{
    $ts = strtotime($date);
    if (!$ts) {
        return 0;
    }

    $ymd = date('Y-m-d', $ts);
    if ($ymd >= '2025-05-01' && $ymd <= '2026-07-31') {
        return 2025;
    }

    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    // 學年度以 8/1 起算；5-7 月交接期建立的既有幹部仍屬前一學年度。
    return $month >= 8 ? $year : $year - 1;
}

function student_academic_year_start_date(int $roc_academic_year): ?string
{
    if ($roc_academic_year <= 0) {
        return null;
    }

    return ($roc_academic_year + 1911) . '-08-01';
}

function student_membership_valid_until(?array $membership): ?string
{
    $reference_date = student_membership_reference_date($membership);
    if (!$reference_date) {
        return null;
    }

    $start_year = student_academic_year_start_year($reference_date);
    if ($start_year <= 0) {
        return null;
    }

    return ($start_year + 1) . '-07-31';
}

function student_membership_academic_year(?array $membership): ?int
{
    $reference_date = student_membership_reference_date($membership);
    if (!$reference_date) {
        return null;
    }

    $start_year = student_academic_year_start_year($reference_date);
    return $start_year > 0 ? $start_year - 1911 : null;
}

function student_current_academic_year(?string $date = null): int
{
    $date = $date ?: date('Y-m-d');
    $start_year = student_academic_year_start_year($date);
    return $start_year > 0 ? $start_year - 1911 : 0;
}

function student_membership_is_current(?array $membership): bool
{
    $valid_until = student_membership_valid_until($membership);
    if (!$valid_until) {
        return false;
    }

    return strtotime(date('Y-m-d')) <= strtotime($valid_until);
}

function student_can_nominate_officers(?array $membership): bool
{
    if (!$membership) {
        return false;
    }

    return ((int)($membership['is_officer'] ?? 0) === 1)
        && trim((string)($membership['officer_title'] ?? '')) === '社長'
        && student_membership_is_current($membership);
}

function student_current_membership_sql_condition(string $alias = 'cm'): string
{
    $ref = "COALESCE($alias.officer_confirmation_date, $alias.join_date)";
    $start_year = "IF($ref BETWEEN '2025-05-01' AND '2026-07-31', 2025, IF(MONTH($ref) >= 8, YEAR($ref), YEAR($ref) - 1))";
    $valid_until = "STR_TO_DATE(CONCAT($start_year + 1, '-07-31'), '%Y-%m-%d')";
    return "(($alias.is_officer = 1 OR $alias.officer_confirmation_date IS NOT NULL) AND $ref IS NOT NULL AND $valid_until >= CURDATE())";
}

function student_can_apply_with_membership(?array $membership): bool
{
    if (!$membership) {
        return false;
    }

    return (((int)($membership['is_officer'] ?? 0) === 1)
        || !empty($membership['officer_confirmation_date']))
        && student_membership_is_current($membership);
}

function student_membership_label(?array $membership): string
{
    if (!$membership) {
        return '一般社員';
    }
    if (!student_membership_is_current($membership)) {
        return '一般社員';
    }
    if ((int)($membership['is_officer'] ?? 0) === 1) {
        return trim((string)($membership['officer_title'] ?? '')) ?: '幹部';
    }
    if (!empty($membership['officer_confirmation_date'])) {
        return '一般成員';
    }
    return '一般社員';
}

function student_sync_active_membership_session(mysqli $conn, int $user_id, ?string $preferred_club_id = null): ?array
{
    $membership = student_get_active_membership($conn, $user_id, $preferred_club_id);
    if (!$membership) {
        unset($_SESSION['active_club_id'], $_SESSION['active_club_name'], $_SESSION['active_position'], $_SESSION['active_can_apply']);
        return null;
    }

    $_SESSION['active_club_id'] = $membership['club_id'];
    $_SESSION['active_club_name'] = $membership['club_name'];
    $_SESSION['active_position'] = student_membership_label($membership);
    $_SESSION['active_can_apply'] = student_can_apply_with_membership($membership) ? 1 : 0;

    return $membership;
}

function student_require_application_access(mysqli $conn, int $user_id, ?string $preferred_club_id = null): array
{
    $membership = student_sync_active_membership_session($conn, $user_id, $preferred_club_id);
    if (!student_can_apply_with_membership($membership)) {
        $_SESSION['flash_message'] = '非本學年有效社團幹部不允許使用；若為新學年幹部，需由新社長重新提名。';
        $_SESSION['flash_message_type'] = 'warning';
        header('Location: dashboard.php');
        exit();
    }
    return $membership;
}
