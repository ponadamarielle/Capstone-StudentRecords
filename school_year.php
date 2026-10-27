<?php

const SCHOOL_YEAR_DEFAULT_END = '08-19';

function schoolYearDb()
{
    static $conn = false;
    if ($conn !== false) {
        return $conn;
    }
    $conn = null;
    try {
        $link = @mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
        if ($link) {
            mysqli_query($link, "CREATE TABLE IF NOT EXISTS school_year_settings (
                end_year         SMALLINT     NOT NULL PRIMARY KEY,
                commencement_end DATE         NOT NULL,
                updated_by       VARCHAR(150) NULL,
                updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            $conn = $link;
        }
    } catch (Throwable $e) {
        error_log('School year settings unavailable: ' . $e->getMessage());
        $conn = null;
    }
    return $conn;
}

function schoolYearCutoffs($refresh = false)
{
    static $cutoffs = null;
    if ($cutoffs !== null && !$refresh) {
        return $cutoffs;
    }
    $cutoffs = [];
    $conn = schoolYearDb();
    if ($conn) {
        try {
            $result = mysqli_query($conn, "SELECT end_year, commencement_end FROM school_year_settings ORDER BY end_year");
            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $cutoffs[(int) $row['end_year']] = $row['commencement_end'];
            }
        } catch (Throwable $e) {
            error_log('Could not read school year settings: ' . $e->getMessage());
        }
    }
    return $cutoffs;
}

function schoolYearEndIsSet($endYear)
{
    return isset(schoolYearCutoffs()[(int) $endYear]);
}

function schoolYearEndDate($endYear)
{
    $endYear = (int) $endYear;
    return schoolYearCutoffs()[$endYear] ?? sprintf('%04d-%s', $endYear, SCHOOL_YEAR_DEFAULT_END);
}

function schoolYearSaveEnd($endYear, $date, $updatedBy)
{
    $conn = schoolYearDb();
    if (!$conn) {
        return 'Could not connect to the database.';
    }
    $endYear = (int) $endYear;
    $stmt = mysqli_prepare($conn, "INSERT INTO school_year_settings (end_year, commencement_end, updated_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE commencement_end = VALUES(commencement_end), updated_by = VALUES(updated_by)");
    mysqli_stmt_bind_param($stmt, 'iss', $endYear, $date, $updatedBy);
    if (!mysqli_stmt_execute($stmt)) {
        return 'Could not save the date.';
    }
    schoolYearCutoffs(true);
    return null;
}

function schoolYearClearEnd($endYear)
{
    $conn = schoolYearDb();
    if (!$conn) {
        return 'Could not connect to the database.';
    }
    $endYear = (int) $endYear;
    $stmt = mysqli_prepare($conn, "DELETE FROM school_year_settings WHERE end_year = ?");
    mysqli_stmt_bind_param($stmt, 'i', $endYear);
    mysqli_stmt_execute($stmt);
    schoolYearCutoffs(true);
    return null;
}

const SCHOOL_YEAR_REMINDER_STAGES = [60, 14];

function schoolYearReminderTable($conn)
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS school_year_reminders (
        end_year     SMALLINT     NOT NULL,
        stage        SMALLINT     NOT NULL,
        status       VARCHAR(10)  NOT NULL,
        recipients   VARCHAR(500) NULL,
        error        VARCHAR(500) NULL,
        attempted_at DATETIME     NOT NULL,
        PRIMARY KEY (end_year, stage)
    )");
}

function schoolYearReminderStage($endYear)
{
    $daysLeft = (int) floor((strtotime(schoolYearEndDate($endYear)) - strtotime(date('Y-m-d'))) / 86400);
    if ($daysLeft < 0) {
        return null;
    }
    $stage = null;
    foreach (SCHOOL_YEAR_REMINDER_STAGES as $days) {
        if ($daysLeft <= $days) {
            $stage = $days;
        }
    }
    return $stage === null ? null : ['stage' => $stage, 'days_left' => $daysLeft];
}

function schoolYearLastReminder($endYear)
{
    $conn = schoolYearDb();
    if (!$conn) {
        return null;
    }
    try {
        schoolYearReminderTable($conn);
        $endYear = (int) $endYear;
        $stmt = mysqli_prepare($conn, "SELECT * FROM school_year_reminders WHERE end_year = ? AND status = 'sent' ORDER BY attempted_at DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $endYear);
        mysqli_stmt_execute($stmt);
        return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function schoolYearReminderIfDue()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $conn = schoolYearDb();
    if (!$conn || !function_exists('currentSchoolYearEndYear')) {
        return;
    }
    try {
        $endYear = currentSchoolYearEndYear();
        if (schoolYearEndIsSet($endYear)) {
            return;
        }
        $due = schoolYearReminderStage($endYear);
        if ($due === null) {
            return;
        }
        $stage = (int) $due['stage'];

        schoolYearReminderTable($conn);
        $stmt = mysqli_prepare($conn, "SELECT status, attempted_at FROM school_year_reminders WHERE end_year = ? AND stage = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $endYear, $stage);
        mysqli_stmt_execute($stmt);
        $previous = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($previous && ($previous['status'] === 'sent' || strtotime($previous['attempted_at']) > time() - 6 * 3600)) {
            return;
        }

        $recipients = [];
        $result = mysqli_query($conn, "SELECT name, email FROM accounts WHERE role = 'Super Admin' AND status = 'Active'");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $recipients[$row['email']] = $row['name'];
            }
        }
        if (!$recipients) {
            return;
        }

        $error = schoolYearSendReminder($endYear, $due['days_left'], $recipients);
        $status = $error === null ? 'sent' : 'failed';
        $list = implode(', ', array_keys($recipients));
        $stmt = mysqli_prepare($conn, "REPLACE INTO school_year_reminders (end_year, stage, status, recipients, error, attempted_at) VALUES (?, ?, ?, ?, ?, NOW())");
        mysqli_stmt_bind_param($stmt, 'iisss', $endYear, $stage, $status, $list, $error);
        mysqli_stmt_execute($stmt);

        if (function_exists('logActivity')) {
            $label = 'SY ' . ($endYear - 1) . '-' . $endYear;
            logActivity($error === null ? 'email_sent' : 'email_failed',
                ($error === null ? 'Sent' : 'Could not send') . ' the school year end reminder for ' . $label . ' to the Super Admin (' . $list . ')',
                [
                    'system'   => true,
                    'category' => 'audit',
                    'details'  => array_filter([
                        'notification' => 'School year end reminder',
                        'recipient'    => $list,
                        'school_year'  => $label,
                        'days_left'    => $due['days_left'],
                        'error'        => $error,
                    ], fn($v) => $v !== null),
                ]);
        }
    } catch (Throwable $e) {
        error_log('School year reminder failed: ' . $e->getMessage());
    }
}

function schoolYearDashboardUrl()
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $base . '/superadmin/dashboard.php#school-year';
}

function schoolYearSendReminder($endYear, $daysLeft, array $recipients)
{
    if (!function_exists('notifyEnv')) {
        require_once __DIR__ . '/student_notifications.php';
    }
    $mailerDir = __DIR__ . '/PHPMailer-7.0.1/src/';
    $user = notifyEnv('GMAIL_USER');
    $pass = notifyEnv('GMAIL_APP_PASSWORD');
    if ($user === '' || $pass === '') {
        return 'Email is not configured (GMAIL_USER / GMAIL_APP_PASSWORD missing in .env).';
    }
    if (!is_file($mailerDir . 'PHPMailer.php')) {
        return 'PHPMailer was not found.';
    }
    try {
        require_once $mailerDir . 'PHPMailer.php';
        require_once $mailerDir . 'SMTP.php';
        require_once $mailerDir . 'Exception.php';

        [$subject, $html, $text] = buildSchoolYearReminderEmail($endYear, $daysLeft);
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($user, notifyEnv('NOTIFY_FROM_NAME', 'PUP eRecords'));
        foreach ($recipients as $email => $name) {
            $mail->addAddress($email, (string) $name);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        return null;
    } catch (Throwable $e) {
        error_log('School year reminder email failed: ' . $e->getMessage());
        $message = isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
        return mb_substr($message, 0, 480);
    }
}

function buildSchoolYearReminderEmail($endYear, $daysLeft)
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $label   = 'SY ' . ($endYear - 1) . '-' . $endYear;
    $default = date('F j, Y', strtotime(schoolYearEndDate($endYear)));
    $url     = schoolYearDashboardUrl();
    $sent    = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('F j, Y \a\t g:i A');
    $when    = $daysLeft === 0 ? 'today' : ($daysLeft === 1 ? 'in 1 day' : 'in ' . $daysLeft . ' days');
    $subject = 'Reminder: set the Year-End Commencement date of ' . $label;

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eceff3;">'
        . '<div style="display:none;max-height:0;overflow:hidden;">The Year-End Commencement date of ' . $e($label) . ' is not set yet.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eceff3;"><tr><td align="center" style="padding:32px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6dcd7;">'
        . '<tr><td style="background:#6e0000;background-image:linear-gradient(135deg,#7d0000 0%,#560000 100%);padding:26px 32px 22px 32px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle;">'
        . '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;line-height:24px;color:#ffffff;font-weight:bold;">PUP eRecords</div>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;letter-spacing:2px;color:#e8c9c9;text-transform:uppercase;margin-top:4px;">Polytechnic University of the Philippines &middot; Bi&ntilde;an Campus</div>'
        . '</td>'
        . '<td align="right" style="vertical-align:middle;"><span style="display:inline-block;width:38px;height:38px;line-height:38px;border-radius:50%;background:#f5c542;color:#6e0000;text-align:center;font-family:Georgia,serif;font-size:18px;font-weight:bold;">&#9733;</span></td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="height:4px;background:#f5c542;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:30px 32px 6px 32px;">'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:#a35a00;">School Year Reminder</div>'
        . '<h1 style="margin:8px 0 0 0;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;line-height:31px;color:#3a0b0b;font-weight:normal;">Set the Year-End Commencement date</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 32px 0 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3b3b3b;">'
        . '<p style="margin:0 0 12px 0;">Hi Super Admin,</p>'
        . '<p style="margin:0;">' . $e($label) . ' is ending soon, but the last day of its Year-End Commencement has not been set in PUP eRecords. If it is not set, graduating students will be moved to Archive as GRADUATE after the default date.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:22px 32px 0 32px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff8e6;border-radius:10px;"><tr><td style="padding:16px 18px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b5f5a;">School year<br><span style="font-family:Georgia,serif;font-size:20px;line-height:28px;font-weight:bold;color:#2b2b2b;">' . $e($label) . '</span></td>'
        . '<td align="right" style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b5f5a;">Default date (' . $e($when) . ')<br><span style="font-family:Georgia,serif;font-size:20px;line-height:28px;font-weight:bold;color:#a35a00;">' . $e($default) . '</span></td>'
        . '</tr></table>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:22px 32px 0 32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;color:#3b3b3b;">'
        . 'Check the PUP University Calendar for the last day of the Year-End Commencement, then set it on the Dashboard under <strong>School year end</strong>.'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:22px 32px 0 32px;">'
        . '<a href="' . $e($url) . '" style="display:inline-block;padding:12px 30px;background:#6e0000;border-radius:999px;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;letter-spacing:.5px;">Open the Dashboard</a>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 32px 30px 32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;color:#3b3b3b;">'
        . '<p style="margin:0;">Regards,<br><span style="font-family:Georgia,serif;font-size:16px;color:#6e0000;font-weight:bold;">PUP eRecords</span><br>'
        . '<span style="font-size:13px;color:#8a7b74;">PUP Bi&ntilde;an Campus</span></p>'
        . '</td></tr>'
        . '<tr><td style="background:#f6f7f9;border-top:1px solid #e3e6eb;padding:18px 32px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:17px;color:#7a808a;">'
        . 'This is an automated message from PUP eRecords sent on ' . $e($sent) . '. Please do not reply to this email.<br>'
        . 'PUP Bi&ntilde;an Campus, Brgy. Zapote, Bi&ntilde;an City, Laguna &middot; &copy; ' . date('Y') . ' Polytechnic University of the Philippines'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    $text = "Hi Super Admin,\n\n"
        . $label . " is ending soon, but the last day of its Year-End Commencement has not been set in PUP eRecords.\n"
        . 'If it is not set, graduating students will be moved to Archive as GRADUATE after the default date: ' . $default . ' (' . $when . ").\n\n"
        . "Check the PUP University Calendar, then set the date on the Dashboard under School year end:\n" . $url . "\n\n"
        . "PUP eRecords\nPUP Biñan Campus\n\nThis is an automated message. Please do not reply.";

    return [$subject, $html, $text];
}