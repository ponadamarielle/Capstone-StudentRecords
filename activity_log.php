<?php

const ACTIVITY_LOG_TIMEZONE = 'Asia/Manila';

const ACTIVITY_ACTIONS = [
    'login'                  => ['Login', 'authentication'],
    'logout'                 => ['Logout', 'authentication'],
    'login_failed'           => ['Failed Login', 'authentication'],
    'account_locked'         => ['Sign-in Temporarily Locked', 'authentication'],
    'account_status_changed' => ['Admin Account Status Changed', 'authentication'],
    'account_created'        => ['Admin Account Created', 'authentication'],
    'password_changed'       => ['Password Changed', 'authentication'],
    'password_reset'         => ['Admin Password Reset', 'authentication'],
    'school_year_end_set'    => ['School Year End Updated', 'audit'],
    'student_created'        => ['Student Record Created', 'data_modification'],
    'student_updated'        => ['Student Record Updated', 'data_modification'],
    'student_status_changed' => ['Student Status Changed', 'data_modification'],
    'document_uploaded'      => ['Document Uploaded', 'data_modification'],
    'document_replaced'      => ['Document Replaced', 'data_modification'],
    'document_type_changed'  => ['Document Type Changed', 'data_modification'],
    'document_deleted'       => ['Document Deleted', 'data_modification'],
    'student_auto_archived'  => ['Student Auto-Archived', 'data_modification'],
    'student_restored'       => ['Student Restored', 'data_modification'],
    'email_sent'             => ['Email Notification Sent', 'data_modification'],
    'email_failed'           => ['Email Notification Failed', 'audit'],
    'logs_exported'          => ['Activity Logs Exported', 'audit'],
    'backup_created'         => ['Database Backup Created', 'backup'],
    'backup_failed'          => ['Database Backup Failed', 'backup'],
    'backup_downloaded'      => ['Database Backup Downloaded', 'backup'],
    'backup_schedule_changed'=> ['Backup Schedule Changed', 'backup'],
];

const ACTIVITY_CATEGORIES = [
    'authentication'    => 'Authentication',
    'data_modification' => 'Data Modification',
    'audit'             => 'Audit',
    'backup'            => 'Backup',
];

function activityActionLabel($action)
{
    return ACTIVITY_ACTIONS[$action][0] ?? ucwords(str_replace('_', ' ', (string) $action));
}

function activityCategoryLabel($category)
{
    return ACTIVITY_CATEGORIES[$category] ?? ucwords(str_replace('_', ' ', (string) $category));
}

function activityLogConnection()
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }
    try {
        $conn = mysqli_connect(
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASS') ?: '',
            getenv('DB_NAME') ?: 'db_pup_eRecords'
        );
        if ($conn) {
            mysqli_set_charset($conn, 'utf8mb4');
            ensureActivityLogTable($conn);
        }
    } catch (Throwable $e) {
        error_log('Activity log: cannot connect: ' . $e->getMessage());
        $conn = null;
    }
    return $conn ?: null;
}

function ensureActivityLogTable($conn)
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS activity_logs (
            log_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at     DATETIME        NOT NULL,
            category       VARCHAR(30)     NOT NULL,
            action         VARCHAR(50)     NOT NULL,
            description    VARCHAR(500)    NOT NULL,
            user_id        INT             NULL,
            user_name      VARCHAR(150)    NOT NULL,
            user_role      VARCHAR(50)     NOT NULL,
            student_id     INT             NULL,
            student_number VARCHAR(30)     NULL,
            student_name   VARCHAR(255)    NULL,
            target_type    VARCHAR(30)     NULL,
            target_id      VARCHAR(50)     NULL,
            details        TEXT            NULL,
            ip_address     VARCHAR(45)     NULL,
            user_agent     VARCHAR(255)    NULL,
            KEY idx_created_at (created_at),
            KEY idx_user (user_name),
            KEY idx_category_action (category, action),
            KEY idx_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $existing = [];
        $res = mysqli_query($conn, "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                                    WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'activity_logs'");
        while ($res && ($row = mysqli_fetch_row($res))) {
            $existing[$row[0]] = true;
        }
        if (!isset($existing['activity_logs_no_update'])) {
            mysqli_query($conn, "CREATE TRIGGER activity_logs_no_update BEFORE UPDATE ON activity_logs
                                 FOR EACH ROW SIGNAL SQLSTATE '45000'
                                 SET MESSAGE_TEXT = 'Activity logs are read-only and cannot be modified.'");
        }
        if (!isset($existing['activity_logs_no_delete'])) {
            mysqli_query($conn, "CREATE TRIGGER activity_logs_no_delete BEFORE DELETE ON activity_logs
                                 FOR EACH ROW SIGNAL SQLSTATE '45000'
                                 SET MESSAGE_TEXT = 'Activity logs are read-only and cannot be deleted.'");
        }
    } catch (Throwable $e) {
        error_log('Activity log: setup failed: ' . $e->getMessage());
    }
}

function logActivity($action, $description, array $opts = [])
{
    try {
        $conn = activityLogConnection();
        if (!$conn) {
            return false;
        }

        $isSystem = !empty($opts['system']);
        $category = $opts['category'] ?? (ACTIVITY_ACTIONS[$action][1] ?? 'data_modification');

        $userName = $opts['user_name'] ?? ($isSystem ? 'System' : ($_SESSION['name'] ?? 'Unknown user'));
        $userRole = $opts['user_role'] ?? ($isSystem ? 'System' : ($_SESSION['role'] ?? ''));
        $userId   = $isSystem ? null : ($opts['user_id'] ?? ($_SESSION['user_id'] ?? ($_SESSION['id'] ?? null)));
        $userId   = is_numeric($userId) ? (int) $userId : null;

        $studentId     = isset($opts['student_id']) && is_numeric($opts['student_id']) ? (int) $opts['student_id'] : null;
        $studentNumber = $opts['student_number'] ?? null;
        $studentName   = $opts['student_name'] ?? null;
        $targetType    = $opts['target_type'] ?? null;
        $targetId      = isset($opts['target_id']) ? (string) $opts['target_id'] : null;
        $details       = isset($opts['details'])
            ? json_encode($opts['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        if ($isSystem && PHP_SAPI === 'cli') {
            $ip = $ua = null;
        }

        $now = (new DateTime('now', new DateTimeZone(ACTIVITY_LOG_TIMEZONE)))->format('Y-m-d H:i:s');
        $description = function_exists('mb_substr') ? mb_substr($description, 0, 500) : substr($description, 0, 500);

        $stmt = mysqli_prepare($conn, 'INSERT INTO activity_logs
            (created_at, category, action, description, user_id, user_name, user_role,
             student_id, student_number, student_name, target_type, target_id, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param(
            $stmt, 'ssssississsssss',
            $now, $category, $action, $description, $userId, $userName, $userRole,
            $studentId, $studentNumber, $studentName, $targetType, $targetId, $details, $ip, $ua
        );
        return mysqli_stmt_execute($stmt);
    } catch (Throwable $e) {
        error_log('Activity log: could not write "' . $action . '": ' . $e->getMessage());
        return false;
    }
}

function activityFetchStudent($conn, $studentId)
{
    try {
        $id   = (int) $studentId;
        $stmt = mysqli_prepare($conn, 'SELECT * FROM students WHERE student_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function activityStudentOpts($conn, $studentId, ?array $row = null)
{
    $row = $row ?? activityFetchStudent($conn, $studentId);
    return [
        'student_id'     => (int) $studentId,
        'student_number' => $row['student_number'] ?? null,
        'student_name'   => $row['name'] ?? null,
    ];
}

function activityStudentLabel(array $opts)
{
    $name = trim((string) ($opts['student_name'] ?? ''));
    $num  = trim((string) ($opts['student_number'] ?? ''));
    if ($name === '' && $num === '') {
        return 'student #' . ($opts['student_id'] ?? '?');
    }
    return $num === '' ? $name : ($name === '' ? $num : $name . ' (' . $num . ')');
}

function logStudentUpdate($conn, $studentId, ?array $before, $photoChanged = false)
{
    $after = activityFetchStudent($conn, $studentId);
    if (!$after) {
        return;
    }

    $fields = [
        'last_name'       => 'Last Name',
        'first_name'      => 'First Name',
        'middle_name'     => 'Middle Name',
        'student_number'  => 'Student Number',
        'course'          => 'Course',
        'year_section'    => 'Year & Section',
        'sex'             => 'Gender',
        'admission_track' => 'Admission Type',
        'date_of_birth'   => 'Date of Birth',
        'place_of_birth'  => 'Place of Birth',
        'mobile_number'   => 'Mobile Number',
        'email'           => 'Email Address',
        'address'         => 'Address',
        'graduation_year' => 'Graduation SY',
    ];

    $changes = [];
    foreach ($fields as $column => $label) {
        if (!array_key_exists($column, $after)) {
            continue;
        }
        $old = (string) ($before[$column] ?? '');
        $new = (string) ($after[$column] ?? '');
        if ($old !== $new) {
            $changes[$label] = ['from' => $old, 'to' => $new];
        }
    }
    if ($photoChanged) {
        $changes['Photo'] = ['from' => 'Previous photo', 'to' => 'New photo'];
    }

    $opts  = activityStudentOpts($conn, $studentId, $after);
    $label = activityStudentLabel($opts);
    $description = $changes
        ? 'Updated ' . $label . ': ' . implode(', ', array_keys($changes))
        : 'Saved ' . $label . ' (no field changes)';

    logActivity('student_updated', $description, $opts + [
        'target_type' => 'student',
        'target_id'   => $studentId,
        'details'     => ['changes' => $changes],
    ]);
}