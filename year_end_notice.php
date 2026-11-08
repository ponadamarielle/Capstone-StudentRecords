<?php
require_once __DIR__ . '/account_email.php';

const YEAR_END_REMINDER_DAYS = 3;

function yearEndNoticeEnv($key)
{
    if (!empty($_ENV[$key])) {
        return $_ENV[$key];
    }
    if (getenv($key)) {
        return getenv($key);
    }
    $envFile = __DIR__ . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            if (trim($name) === $key) {
                return trim($value);
            }
        }
    }
    return '';
}

function yearEndNoticeClaim($conn, $endYear)
{
    if (!mysqli_query($conn, "CREATE TABLE IF NOT EXISTS year_end_notices (
            end_year INT NOT NULL PRIMARY KEY,
            sent_at DATETIME NOT NULL,
            times_sent INT NOT NULL DEFAULT 1
        )")) {
        return false;
    }
    $endYear = (int) $endYear;
    $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO year_end_notices (end_year, sent_at) VALUES (?, NOW())");
    mysqli_stmt_bind_param($stmt, "i", $endYear);
    mysqli_stmt_execute($stmt);
    if (mysqli_stmt_affected_rows($stmt) === 1) {
        return 1;
    }
    $stmt = mysqli_prepare($conn, "UPDATE year_end_notices SET sent_at = NOW(), times_sent = times_sent + 1
        WHERE end_year = ? AND sent_at <= DATE_SUB(NOW(), INTERVAL " . (int) YEAR_END_REMINDER_DAYS . " DAY)");
    mysqli_stmt_bind_param($stmt, "i", $endYear);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_affected_rows($stmt) === 1 ? 2 : false;
}

function buildYearEndEmail($name, $endYear, array $preview, $isToday)
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $label = schoolYearLabel($endYear);
    $endDate = date('F j, Y', strtotime(schoolYearEndDate($endYear)));
    $first = trim(explode(' ', trim((string) $name))[0] ?? '') ?: 'there';
    $url = accountSignInUrl();
    $sentOn = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('F j, Y \a\t g:i A');
    $heading = $isToday ? $label . ' ends today' : $label . ' has ended';
    $intro = $isToday
        ? 'Today, ' . $endDate . ', is the last day of ' . $label . '.'
        : $label . ' ended on ' . $endDate . ' and the year-end update is still waiting for you.';
    $stat = function ($value, $text) use ($e) {
        return '<td width="48%" style="padding:16px 18px;background:#fbf7f4;border:1px solid #eadfd9;border-radius:10px;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="font-size:12px;color:#8a7b74;">' . $e($text) . '</div>'
            . '<div style="font-size:26px;line-height:32px;color:#3a0b0b;font-weight:bold;margin-top:4px;">' . (int) $value . '</div></td>';
    };

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eceff3;">'
        . '<div style="display:none;max-height:0;overflow:hidden;">Confirm the year-end update of ' . $e($label) . ' in PUP eRecords.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eceff3;"><tr><td align="center" style="padding:32px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6dcd7;">'
        . '<tr><td style="background:#6e0000;background-image:linear-gradient(135deg,#7d0000 0%,#560000 100%);padding:26px 32px 22px 32px;">'
        . '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;line-height:24px;color:#ffffff;font-weight:bold;">PUP eRecords</div>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;letter-spacing:2px;color:#e8c9c9;text-transform:uppercase;margin-top:4px;">Polytechnic University of the Philippines &middot; Bi&ntilde;an Campus</div>'
        . '</td></tr>'
        . '<tr><td style="height:4px;background:#f5c542;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:30px 32px 6px 32px;">'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:#6e0000;">Year-End Update</div>'
        . '<h1 style="margin:8px 0 0 0;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;line-height:31px;color:#3a0b0b;font-weight:normal;">' . $e($heading) . '</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 32px 0 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3b3b3b;">'
        . '<p style="margin:0 0 12px 0;">Hi ' . $e($first) . ',</p>'
        . '<p style="margin:0;">' . $e($intro) . ' Sign in to PUP eRecords and confirm the year-end update. Student statuses and year levels will not change until you confirm.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:22px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . $stat($preview['graduate'], 'Will graduate') . '<td width="4%" style="font-size:0;">&nbsp;</td>' . $stat($preview['move_up'], 'Move up a year')
        . '</tr></table></td></tr>'
        . '<tr><td align="center" style="padding:24px 32px 0 32px;">'
        . '<a href="' . $e($url) . '" style="display:inline-block;padding:12px 30px;background:#6e0000;border-radius:999px;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;letter-spacing:.5px;">Sign in to confirm</a>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 32px 30px 32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;color:#3b3b3b;">'
        . '<p style="margin:0;">Regards,<br><span style="font-family:Georgia,serif;font-size:16px;color:#6e0000;font-weight:bold;">PUP eRecords</span><br>'
        . '<span style="font-size:13px;color:#8a7b74;">PUP Bi&ntilde;an Campus</span></p>'
        . '</td></tr>'
        . '<tr><td style="background:#f6f7f9;border-top:1px solid #e3e6eb;padding:18px 32px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:17px;color:#7a808a;">'
        . 'This is an automated message from PUP eRecords sent on ' . $e($sentOn) . '. Please do not reply to this email.<br>'
        . 'PUP Bi&ntilde;an Campus, Brgy. Zapote, Bi&ntilde;an City, Laguna &middot; &copy; ' . date('Y') . ' Polytechnic University of the Philippines'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    $text = "Hi {$first},\n\n{$intro}\n\n"
        . "Will graduate: " . (int) $preview['graduate'] . "\n"
        . "Move up a year: " . (int) $preview['move_up'] . "\n\n"
        . "Sign in to PUP eRecords and confirm the year-end update. Student statuses and year levels will not change until you confirm.\n"
        . "Sign in: {$url}\n\nPUP eRecords\nPUP Biñan Campus";

    return [$heading, $html, $text];
}

function yearEndNotifyIfDue($conn)
{
    if (!($conn instanceof mysqli) || !function_exists('pendingYearEnd')) {
        return 0;
    }
    try {
        $endYear = pendingYearEnd($conn);
        if ($endYear === null || yearEndNoticeClaim($conn, $endYear) === false) {
            return 0;
        }
        $result = mysqli_query($conn, "SELECT name, email FROM accounts WHERE role = 'Super Admin' AND status = 'Active' AND email <> ''");
        $recipients = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $recipients[] = $row;
        }
        if (!$recipients) {
            return 0;
        }

        $base = __DIR__ . '/PHPMailer-7.0.1/src/';
        if (!class_exists('PHPMailer\\PHPMailer\\Exception', false)) {
            require_once $base . 'Exception.php';
        }
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
            require_once $base . 'PHPMailer.php';
        }
        if (!class_exists('PHPMailer\\PHPMailer\\SMTP', false)) {
            require_once $base . 'SMTP.php';
        }

        $preview = yearEndPreview($conn, $endYear);
        $isToday = date('Y-m-d') === schoolYearEndDate($endYear);
        $user = yearEndNoticeEnv('GMAIL_USER');
        $sent = 0;
        foreach ($recipients as $recipient) {
            [$subject, $html, $text] = buildYearEndEmail($recipient['name'], $endYear, $preview, $isToday);
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = yearEndNoticeEnv('GMAIL_APP_PASSWORD');
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;
                $mail->Timeout = 15;
                $mail->setFrom($user ?: 'pupbc.superadmin@gmail.com', 'PUP eRecords');
                $mail->addAddress($recipient['email'], $recipient['name']);
                $mail->CharSet = 'UTF-8';
                $mail->isHTML(true);
                $mail->Subject = 'PUP eRecords: ' . $subject . ' - confirm the year-end update';
                $mail->Body = $html;
                $mail->AltBody = $text;
                $mail->send();
                $sent++;
            } catch (Throwable $e) {
                error_log('Year-end email to ' . $recipient['email'] . ' failed: ' . $mail->ErrorInfo);
            }
        }
        if ($sent === 0) {
            $retry = mysqli_prepare($conn, "UPDATE year_end_notices SET sent_at = DATE_SUB(NOW(), INTERVAL " . (int) (YEAR_END_REMINDER_DAYS * 24 - 1) . " HOUR) WHERE end_year = ?");
            $year = (int) $endYear;
            mysqli_stmt_bind_param($retry, "i", $year);
            mysqli_stmt_execute($retry);
        }
        if ($sent > 0 && function_exists('logActivity')) {
            logActivity('year_end_reminder_sent', 'Sent the year-end update reminder of ' . schoolYearLabel($endYear) . ' to the Super Admin', [
                'system'      => true,
                'target_type' => 'school_year',
                'details'     => ['school_year' => schoolYearLabel($endYear), 'recipients' => $sent],
            ]);
        }
        return $sent;
    } catch (Throwable $e) {
        error_log('Year-end reminder failed: ' . $e->getMessage());
        return 0;
    }
}
