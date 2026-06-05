<?php
/**
 * 場地協調系統輔助類
 * 管理場協登記時間、衝突檢測等業務邏輯
 */
class FieldCoordinationManager {
    private $conn;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }

    /**
     * 取得目前活躍的場協設定
     */
    public function getActiveSettings() {
        $sql = "SELECT * FROM field_coordination_settings 
                WHERE status = 'active' 
                AND NOW() BETWEEN registration_start_date AND registration_end_date
                ORDER BY created_at DESC 
                LIMIT 1";
        
        $result = $this->conn->query($sql);
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    }

    /**
     * 檢查是否在場協登記期間
     */
    public function isInRegistrationPeriod($setting_id = null) {
        if ($setting_id) {
            $sql = "SELECT * FROM field_coordination_settings 
                    WHERE setting_id = ? 
                    AND NOW() BETWEEN registration_start_date AND registration_end_date";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("i", $setting_id);
        } else {
            $sql = "SELECT * FROM field_coordination_settings 
                    WHERE status = 'active' 
                    AND NOW() BETWEEN registration_start_date AND registration_end_date 
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $is_in = $result ? $result->num_rows > 0 : false;
        $stmt->close();
        return $is_in;
    }

    /**
     * 檢查是否已過協調大會
     */
    public function hasCoordinationMeetingPassed($setting_id = null) {
        if ($setting_id) {
            $sql = "SELECT * FROM field_coordination_settings 
                    WHERE setting_id = ? 
                    AND NOW() > coordination_meeting_date";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("i", $setting_id);
        } else {
            $sql = "SELECT * FROM field_coordination_settings 
                    WHERE status = 'active' 
                    AND NOW() > coordination_meeting_date 
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $has_passed = $result ? $result->num_rows > 0 : false;
        $stmt->close();
        return $has_passed;
    }

    /**
     * 編輯場次後核准（場協大會換時間/場地用）
     * $sessions = [['reservation_id'=>, 'space_id'=>, 'start_time'=>'YYYY-MM-DD HH:MM:SS', 'end_time'=>'YYYY-MM-DD HH:MM:SS'], ...]
     */
    public function editAndApproveFieldCoordinationRegistration($registration_id, $sessions, $approval_note = null) {
        $stmt = $this->conn->prepare("SELECT event_id FROM field_coordination_registrations WHERE registration_id = ?");
        $stmt->bind_param("i", $registration_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return false;
        $event_id = $row['event_id'];

        foreach ($sessions as $s) {
            $stmt = $this->conn->prepare(
                "UPDATE reservations SET space_id=?, start_time=?, end_time=?, is_field_coordination_preliminary=0
                 WHERE reservation_id=? AND event_id=?");
            $stmt->bind_param("issii", $s['space_id'], $s['start_time'], $s['end_time'], $s['reservation_id'], $event_id);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $this->conn->prepare(
            "UPDATE field_coordination_registrations SET is_approved=1, approval_note=?, updated_at=NOW() WHERE registration_id=?");
        $stmt->bind_param("si", $approval_note, $registration_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->conn->prepare(
            "UPDATE events SET status='approved',
             start_time=(SELECT MIN(start_time) FROM reservations WHERE event_id=?),
             end_time=(SELECT MAX(end_time) FROM reservations WHERE event_id=?)
             WHERE event_id=?");
        $stmt->bind_param("iii", $event_id, $event_id, $event_id);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * 是否還在場協大會前（含登記期間 + 登記結束到大會之間）
     */
    public function isBeforeCoordinationMeeting() {
        $sql = "SELECT setting_id FROM field_coordination_settings
                WHERE status = 'active' AND coordination_meeting_date > NOW() LIMIT 1";
        $result = $this->conn->query($sql);
        return $result && $result->num_rows > 0;
    }

    /**
     * 取得社團的所有場協登記記錄
     */
    public function getClubFieldCoordinationRecords($club_id, $setting_id = null) {
        if ($setting_id) {
            $sql = "SELECT fcr.*, e.event_name, e.club_name, e.start_time, e.end_time, e.description, e.status
                    FROM field_coordination_registrations fcr
                    JOIN events e ON fcr.event_id = e.event_id
                    WHERE fcr.club_id = ? AND fcr.setting_id = ?
                    ORDER BY e.start_time DESC";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("FieldCoordinationManager::getClubFieldCoordinationRecords prepare failed: " . $this->conn->error);
                return [];
            }
            $stmt->bind_param("si", $club_id, $setting_id);
        } else {
            $sql = "SELECT fcr.*, e.event_name, e.club_name, e.start_time, e.end_time, e.description, e.status,
                           fcs.academic_year, fcs.semester, fcs.coordination_meeting_date
                    FROM field_coordination_registrations fcr
                    JOIN events e ON fcr.event_id = e.event_id
                    JOIN field_coordination_settings fcs ON fcr.setting_id = fcs.setting_id
                    WHERE fcr.club_id = ?
                    ORDER BY fcs.academic_year DESC, fcs.semester DESC, e.start_time DESC";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("FieldCoordinationManager::getClubFieldCoordinationRecords prepare failed: " . $this->conn->error);
                return [];
            }
            $stmt->bind_param("s", $club_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();
        return $records;
    }

    /**
     * 取得社團所有已核准、協調大會已過的場協結果，用於申請表單選擇與自動帶入
     */
    public function getAllApprovedFieldCoordinationForClub($club_id) {
        $sql = "SELECT fcr.registration_id, e.event_id, e.event_name, e.club_name, e.start_time, e.end_time, r.space_id, fcs.coordination_meeting_date, fcs.academic_year, fcs.semester
                FROM field_coordination_registrations fcr
                JOIN events e ON fcr.event_id = e.event_id
                JOIN reservations r ON e.event_id = r.event_id
                JOIN field_coordination_settings fcs ON fcr.setting_id = fcs.setting_id
                WHERE fcr.club_id = ?
                  AND fcr.is_approved = 1
                  AND NOW() > fcs.coordination_meeting_date
                  AND e.start_time >= NOW()
                ORDER BY e.start_time ASC";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("FieldCoordinationManager::getAllApprovedFieldCoordinationForClub prepare failed: " . $this->conn->error);
            return [];
        }
        $stmt->bind_param("s", $club_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();
        return $records;
    }

    /**
     * 檢測場協登記中的衝突
     */
    public function detectFieldCoordinationConflicts($setting_id, $space_ids, $start_time, $end_time, $exclude_registration_id = null) {
        $conflicts = [];
        
        // 查找同一設定下、相同場地、時間重疊的其他場協登記
        foreach ($space_ids as $space_id) {
            $sql = "SELECT DISTINCT fr.registration_id, fr.club_name, e.event_name, 
                           e.start_time, e.end_time, e.user_id, r.space_id
                    FROM field_coordination_registrations fr
                    JOIN events e ON fr.event_id = e.event_id
                    JOIN reservations r ON e.event_id = r.event_id
                    WHERE fr.setting_id = ?
                    AND r.space_id = ?
                    AND (
                        (e.start_time < ? AND e.end_time > ?)
                        OR (e.start_time >= ? AND e.start_time < ?)
                        OR (e.end_time > ? AND e.end_time <= ?)
                    )";
            
            if ($exclude_registration_id) {
                $sql .= " AND fr.registration_id != ?";
            }
            
            $stmt = $this->conn->prepare($sql);
            
            if ($exclude_registration_id) {
                $stmt->bind_param("iissssssi", $setting_id, $space_id, $end_time, $start_time, 
                                 $start_time, $end_time, $start_time, $end_time, $exclude_registration_id);
            } else {
                $stmt->bind_param("iissssss", $setting_id, $space_id, $end_time, $start_time,
                                 $start_time, $end_time, $start_time, $end_time);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $conflicts[] = [
                    'space_id' => $space_id,
                    'conflicting_club' => $row['club_name'],
                    'conflicting_event' => $row['event_name'],
                    'conflicting_time' => $row['start_time'] . ' ~ ' . $row['end_time'],
                    'registration_id' => $row['registration_id']
                ];
            }
            $stmt->close();
        }
        
        return $conflicts;
    }

    /**
     * 記錄衝突到資料庫
     */
    public function logConflict($setting_id, $reg_id_1, $reg_id_2, $space_id, $conflict_start, $conflict_end) {
        $sql = "INSERT INTO coordination_conflicts 
                (setting_id, registration_id_1, registration_id_2, space_id, conflict_start_time, conflict_end_time)
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiisss", $setting_id, $reg_id_1, $reg_id_2, $space_id, $conflict_start, $conflict_end);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }

    /**
     * 取得某設定下的所有衝突
     */
    public function getConflictsBySettingId($setting_id) {
        $sql = "SELECT cc.*, 
                   fcr1.club_name as club_name_1, e1.event_name as event_name_1,
                   fcr2.club_name as club_name_2, e2.event_name as event_name_2,
                   s.space_name
                FROM coordination_conflicts cc
                JOIN field_coordination_registrations fcr1 ON cc.registration_id_1 = fcr1.registration_id
                JOIN events e1 ON fcr1.event_id = e1.event_id
                JOIN field_coordination_registrations fcr2 ON cc.registration_id_2 = fcr2.registration_id
                JOIN events e2 ON fcr2.event_id = e2.event_id
                JOIN spaces s ON cc.space_id = s.space_id
                WHERE cc.setting_id = ?
                ORDER BY cc.conflict_start_time DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $setting_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $conflicts = [];
        while ($row = $result->fetch_assoc()) {
            $conflicts[] = $row;
        }
        $stmt->close();
        
        return $conflicts;
    }

    /**
     * 標記衝突為已解決
     */
    public function markConflictResolved($conflict_id, $resolution_note = null) {
        $sql = "UPDATE coordination_conflicts 
                SET status = 'resolved', resolution_note = ?
                WHERE conflict_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $resolution_note, $conflict_id);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }

    /**
     * 建立場協登記
     */
    public function createFieldCoordinationRegistration($setting_id, $event_id, $student_id, $club_id, $club_name) {
        $sql = "INSERT INTO field_coordination_registrations 
                (setting_id, event_id, student_id, club_id, club_name)
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiss", $setting_id, $event_id, $student_id, $club_id, $club_name); // club_id is VARCHAR
        $result = $stmt->execute();
        
        $registration_id = $stmt->insert_id;
        $stmt->close();
        
        // 標記event為場協登記
        $update_sql = "UPDATE events 
                      SET is_field_coordination = 1, field_coordination_setting_id = ?
                      WHERE event_id = ?";
        $update_stmt = $this->conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $setting_id, $event_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        return $registration_id;
    }

    /**
     * 批准場協登記（協調大會後）
     */
    public function approveFieldCoordinationRegistration($registration_id, $approval_note = null) {
        // 取得對應 event_id
        $stmt = $this->conn->prepare("SELECT event_id FROM field_coordination_registrations WHERE registration_id = ?");
        $stmt->bind_param("i", $registration_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return false;
        $event_id = $row['event_id'];

        // 更新登記狀態
        $stmt = $this->conn->prepare(
            "UPDATE field_coordination_registrations SET is_approved = 1, approval_note = ?, updated_at = NOW() WHERE registration_id = ?");
        $stmt->bind_param("si", $approval_note, $registration_id);
        $stmt->execute();
        $stmt->close();

        // 場協預約轉為正式預約（is_field_coordination_preliminary 0 = 正式）
        $stmt = $this->conn->prepare(
            "UPDATE reservations SET is_field_coordination_preliminary = 0 WHERE event_id = ? AND is_field_coordination_preliminary = 1");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $stmt->close();

        // 活動狀態更新為已核准
        $stmt = $this->conn->prepare("UPDATE events SET status = 'approved' WHERE event_id = ?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * 拒絕場協登記（協調大會後）
     */
    public function rejectFieldCoordinationRegistration($registration_id, $rejection_note) {
        // 取得對應 event_id
        $stmt = $this->conn->prepare("SELECT event_id FROM field_coordination_registrations WHERE registration_id = ?");
        $stmt->bind_param("i", $registration_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return false;
        $event_id = $row['event_id'];

        // 更新登記狀態
        $stmt = $this->conn->prepare(
            "UPDATE field_coordination_registrations SET is_approved = 0, approval_note = ?, updated_at = NOW() WHERE registration_id = ?");
        $stmt->bind_param("si", $rejection_note, $registration_id);
        $stmt->execute();
        $stmt->close();

        // 刪除暫定預約（場協未通過，釋放時段）
        $stmt = $this->conn->prepare(
            "DELETE FROM reservations WHERE event_id = ? AND is_field_coordination_preliminary = 1");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $stmt->close();

        // 活動狀態更新為已拒絕
        $stmt = $this->conn->prepare("UPDATE events SET status = 'rejected' WHERE event_id = ?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * 取得所有場協設定（管理員用）
     */
    public function getAllFieldCoordinationSettings($limit = null) {
        $sql = "SELECT * FROM field_coordination_settings 
                ORDER BY academic_year DESC, semester DESC, created_at DESC";
        
        if ($limit) {
            $sql .= " LIMIT " . intval($limit);
        }
        
        $result = $this->conn->query($sql);
        $settings = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[] = $row;
            }
        }
        
        return $settings;
    }

    public function getStudentIdByUserId($user_id) {
        $sql = "SELECT student_id FROM users WHERE user_id = ? LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['student_id'] ?? null;
    }

    /**
     * 建立場協設定（管理員用）
     */
    public function createFieldCoordinationSetting($academic_year, $semester, $start_date, $end_date, $meeting_date, $borrow_start_date, $borrow_end_date, $borrow_start_time, $borrow_end_time, $description, $created_by_student_id) {
        $status = 'active';

        // 不插入 allow_conflict_during_coordination，讓資料庫使用 DEFAULT 1
        $sql = "INSERT INTO field_coordination_settings
                (academic_year, semester, registration_start_date, registration_end_date,
                 coordination_meeting_date, borrow_start_date, borrow_end_date,
                 borrow_start_time, borrow_end_time, status, description, created_by_student_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("createFieldCoordinationSetting prepare failed: " . $this->conn->error);
            return false;
        }

        // 12 個參數：i i s s s s s s s s s i
        $stmt->bind_param("iisssssssssi",
            $academic_year, $semester,
            $start_date, $end_date, $meeting_date,
            $borrow_start_date, $borrow_end_date,
            $borrow_start_time, $borrow_end_time,
            $status, $description, $created_by_student_id
        );

        $result     = $stmt->execute();
        $setting_id = $stmt->insert_id;
        $stmt->close();

        return $result ? $setting_id : false;
    }

    /**
     * 更新場協設定（管理員用）
     */
    public function updateFieldCoordinationSetting($setting_id, $start_date, $end_date, $meeting_date, $borrow_start_date, $borrow_end_date, $borrow_start_time, $borrow_end_time, $status, $description) {
        $sql = "UPDATE field_coordination_settings 
                SET registration_start_date = ?, registration_end_date = ?, 
                    coordination_meeting_date = ?, borrow_start_date = ?, borrow_end_date = ?, 
                    borrow_start_time = ?, borrow_end_time = ?, status = ?, description = ?, updated_at = NOW()
                WHERE setting_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssssssssi", $start_date, $end_date, $meeting_date,
                         $borrow_start_date, $borrow_end_date, $borrow_start_time, $borrow_end_time,
                         $status, $description, $setting_id);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
}
?>
