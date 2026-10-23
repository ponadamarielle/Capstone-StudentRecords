<?php
require_once __DIR__ . '/activity_log.php';

const STUDENT_NOTIFY_TIMEZONE = 'Asia/Manila';

function notifyEnv($key, $default = '')
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $file = __DIR__ . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    if (isset($env[$key]) && $env[$key] !== '') {
        return $env[$key];
    }
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

function ensureStudentNotificationTable($conn)
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_notifications (
            notification_id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id        INT NOT NULL,
            notification_type VARCHAR(20) NOT NULL,
            document_status   VARCHAR(20) NOT NULL,
            missing_list      TEXT NULL,
            recipient         VARCHAR(255) NULL,
            sent_at           DATETIME NOT NULL,
            success           TINYINT(1) NOT NULL DEFAULT 0,
            error_message     VARCHAR(500) NULL,
            KEY idx_student (student_id, sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('Student notifications: table setup failed: ' . $e->getMessage());
    }
}

function latestStudentNotification($conn, $studentId, $successOnly = false)
{
    ensureStudentNotificationTable($conn);
    try {
        $id = (int) $studentId;
        $stmt = mysqli_prepare($conn, 'SELECT * FROM student_notifications WHERE student_id = ?'
            . ($successOnly ? ' AND success = 1' : '')
            . ' ORDER BY sent_at DESC, notification_id DESC LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function studentRequirementSnapshot($conn, $studentId, array $requirements)
{
    $id = (int) $studentId;
    $stmt = mysqli_prepare($conn, 'SELECT * FROM students WHERE student_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$student) {
        return null;
    }

    $docStmt = mysqli_prepare($conn, 'SELECT requirement_code, document_type, file_name, uploaded_at FROM documents WHERE student_id = ? ORDER BY uploaded_at ASC');
    mysqli_stmt_bind_param($docStmt, 'i', $id);
    mysqli_stmt_execute($docStmt);
    $documents = mysqli_fetch_all(mysqli_stmt_get_result($docStmt), MYSQLI_ASSOC);

    $track = function_exists('normalizeAdmissionTrack')
        ? normalizeAdmissionTrack($student['admission_track'] ?? '')
        : ($student['admission_track'] ?? '');
    $onFile = [];
    foreach ($documents as $doc) {
        if (($doc['requirement_code'] ?? '') !== '') {
            $onFile[$doc['requirement_code']] = $doc;
        }
    }

    $items = [];
    foreach ($requirements as $r) {
        $requiredFor = $r['required_for'] ?? [];
        if (!is_array($requiredFor) || !in_array($track, $requiredFor, true)) {
            continue;
        }
        $items[] = [
            'label'    => $r['label'] ?? $r['code'],
            'received' => isset($onFile[$r['code']]),
            'date'     => isset($onFile[$r['code']]) ? ($onFile[$r['code']]['uploaded_at'] ?? '') : '',
        ];
    }
    $missing = array_values(array_map(fn($i) => $i['label'], array_filter($items, fn($i) => !$i['received'])));
    $tracks = function_exists('getAdmissionTracks') ? getAdmissionTracks() : [];

    return [
        'student'  => $student,
        'items'    => $items,
        'missing'  => $missing,
        'complete' => count($missing) === 0,
        'total'    => count($items),
        'received' => count($items) - count($missing),
        'track'    => $tracks[$track] ?? '',
    ];
}

function decideStudentNotification(array $snapshot, ?array $last)
{
    if (!$snapshot['total']) {
        return null;
    }
    $missingKey = implode('|', $snapshot['missing']);
    if (!$last) {
        return $snapshot['complete'] ? 'complete' : 'incomplete';
    }
    if ($last['document_status'] === 'Incomplete' && $snapshot['complete']) {
        return 'completed';
    }
    if ($last['document_status'] === 'Complete' && !$snapshot['complete']) {
        return 'incomplete';
    }
    if ($last['document_status'] === 'Incomplete' && !$snapshot['complete'] && (string) $last['missing_list'] !== $missingKey) {
        return 'updated';
    }
    return null;
}

function notifyStudentRequirements($conn, $studentId, array $requirements, $force = false)
{
    try {
        ensureStudentNotificationTable($conn);
        $snapshot = studentRequirementSnapshot($conn, $studentId, $requirements);
        if (!$snapshot) {
            return ['sent' => false, 'skipped' => true, 'reason' => 'Student not found.'];
        }
        $student = $snapshot['student'];
        $statusValue = strtolower((string) ($student['status'] ?? ''));
        if (in_array($statusValue, ['graduated', 'inactive', 'onleave'], true)) {
            return ['sent' => false, 'skipped' => true, 'reason' => 'Archived student.'];
        }
        if (function_exists('expectedGraduationYear') && function_exists('schoolYearHasEnded')) {
            $end = expectedGraduationYear($student);
            if ($end !== null && schoolYearHasEnded($end)) {
                return ['sent' => false, 'skipped' => true, 'reason' => 'Graduated student.'];
            }
        }

        $type = decideStudentNotification($snapshot, latestStudentNotification($conn, $studentId, true));
        if ($type === null && $force && $snapshot['total']) {
            $type = $snapshot['complete'] ? 'complete' : 'incomplete';
        }
        if ($type === null) {
            return ['sent' => false, 'skipped' => true, 'reason' => 'No change in document status.', 'status' => $snapshot['complete'] ? 'Complete' : 'Incomplete'];
        }
        $lastAttempt = latestStudentNotification($conn, $studentId);
        if (!$force && $lastAttempt && !$lastAttempt['success']
            && !filter_var(trim((string) ($student['email'] ?? '')), FILTER_VALIDATE_EMAIL)
            && $lastAttempt['document_status'] === ($snapshot['complete'] ? 'Complete' : 'Incomplete')
            && (string) $lastAttempt['missing_list'] === implode('|', $snapshot['missing'])) {
            return ['sent' => false, 'skipped' => true, 'reason' => 'The student has no valid email address on record.', 'status' => $lastAttempt['document_status']];
        }
        return sendStudentNotification($conn, $snapshot, $type);
    } catch (Throwable $e) {
        error_log('Student notification failed: ' . $e->getMessage());
        return ['sent' => false, 'error' => 'The email notification could not be prepared.'];
    }
}

function sendStudentNotification($conn, array $snapshot, $type)
{
    $student   = $snapshot['student'];
    $studentId = (int) $student['student_id'];
    $status    = $snapshot['complete'] ? 'Complete' : 'Incomplete';
    $recipient = trim((string) ($student['email'] ?? ''));
    $now       = (new DateTime('now', new DateTimeZone(STUDENT_NOTIFY_TIMEZONE)))->format('Y-m-d H:i:s');
    $error     = null;

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $error = 'The student has no valid email address on record.';
    } else {
        $error = deliverStudentNotification($snapshot, $type, $recipient);
    }

    $missingKey = implode('|', $snapshot['missing']);
    $success = $error === null ? 1 : 0;
    try {
        $stmt = mysqli_prepare($conn, 'INSERT INTO student_notifications
            (student_id, notification_type, document_status, missing_list, recipient, sent_at, success, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $recipientValue = $recipient !== '' ? $recipient : null;
        mysqli_stmt_bind_param($stmt, 'isssssis', $studentId, $type, $status, $missingKey, $recipientValue, $now, $success, $error);
        mysqli_stmt_execute($stmt);
    } catch (Throwable $e) {
        error_log('Student notification could not be recorded: ' . $e->getMessage());
    }

    $opts = activityStudentOpts($conn, $studentId, $student);
    $labels = [
        'complete'   => 'requirements complete',
        'incomplete' => 'missing requirements',
        'updated'    => 'updated missing requirements',
        'completed'  => 'requirements updated and completed',
    ];
    logActivity($success ? 'email_sent' : 'email_failed',
        ($success ? 'Sent' : 'Could not send') . ' the "' . $labels[$type] . '" email to ' . activityStudentLabel($opts)
            . ($recipient !== '' ? ' (' . $recipient . ')' : ''),
        $opts + [
            'category'    => 'audit',
            'target_type' => 'student',
            'target_id'   => $studentId,
            'details'     => array_filter([
                'notification'    => ucfirst($labels[$type]),
                'document_status' => $status,
                'recipient'       => $recipient,
                'missing'         => $snapshot['missing'] ? implode(', ', $snapshot['missing']) : 'None',
                'error'           => $error,
            ], fn($v) => $v !== null && $v !== ''),
        ]);

    return [
        'sent'      => (bool) $success,
        'type'      => $type,
        'status'    => $status,
        'recipient' => $recipient,
        'error'     => $error,
    ];
}

function deliverStudentNotification(array $snapshot, $type, $recipient)
{
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

        [$subject, $html, $text] = buildStudentNotificationEmail($snapshot, $type);
        $student = $snapshot['student'];

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
        $mail->addAddress($recipient, (string) ($student['name'] ?? ''));
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        return null;
    } catch (Throwable $e) {
        error_log('Student notification email failed: ' . $e->getMessage());
        $message = isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
        return function_exists('mb_substr') ? mb_substr($message, 0, 480) : substr($message, 0, 480);
    }
}

function studentNotificationGreetingName(array $student)
{
    $first = trim((string) ($student['first_name'] ?? ''));
    if ($first !== '') {
        return $first;
    }
    $name = trim((string) ($student['name'] ?? ''));
    if (strpos($name, ',') !== false) {
        $parts = explode(',', $name, 2);
        return trim($parts[1]) !== '' ? trim($parts[1]) : trim($parts[0]);
    }
    return $name !== '' ? $name : 'Student';
}

function buildStudentNotificationEmail(array $snapshot, $type)
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $student  = $snapshot['student'];
    $greeting = studentNotificationGreetingName($student);
    $complete = $snapshot['complete'];
    $office   = notifyEnv('NOTIFY_OFFICE_NAME', 'Admission and Registration Office');
    $campus   = 'PUP Biñan Campus';
    $date     = (new DateTime('now', new DateTimeZone(STUDENT_NOTIFY_TIMEZONE)))->format('F j, Y \a\t g:i A');

    $copy = [
        'complete' => [
            'subject' => 'Your admission requirements are complete',
            'eyebrow' => 'Requirements Update',
            'title'   => 'All set, your requirements are complete',
            'lead'    => 'We have received and checked every document required for your admission. Your student record is now marked <strong>Complete</strong>. No further action is needed from you.',
        ],
        'incomplete' => [
            'subject' => 'Action needed: missing admission requirements',
            'eyebrow' => 'Action Needed',
            'title'   => 'A few requirements are still missing',
            'lead'    => 'We have reviewed the documents in your student record, and some required documents have not been submitted yet. Your record is currently marked <strong>Incomplete</strong>.',
        ],
        'updated' => [
            'subject' => 'Update on your missing admission requirements',
            'eyebrow' => 'Requirements Update',
            'title'   => 'Thank you, we received your documents',
            'lead'    => 'Your newly submitted documents have been added to your student record. A few requirements are still missing, so your record remains <strong>Incomplete</strong> for now.',
        ],
        'completed' => [
            'subject' => 'Your requirements have been updated and completed',
            'eyebrow' => 'Requirements Completed',
            'title'   => 'Your requirements are now complete',
            'lead'    => 'The missing documents you submitted have been received and checked. We rechecked your record and it is now marked <strong>Complete</strong>. Thank you for completing your requirements.',
        ],
    ][$type];

    $accent    = $complete ? '#1f7a4d' : '#b3261e';
    $accentBg  = $complete ? '#e9f6ef' : '#fdecea';
    $statusTxt = $complete ? 'COMPLETE' : 'INCOMPLETE';
    $percent   = $snapshot['total'] ? (int) round($snapshot['received'] / $snapshot['total'] * 100) : 0;
    $barFill   = max(1, $percent);
    $barRest   = 100 - $barFill;

    $rows = '';
    foreach ($snapshot['items'] as $item) {
        $mark  = $item['received'] ? '&#10003;' : '&#10007;';
        $color = $item['received'] ? '#1f7a4d' : '#b3261e';
        $badge = $item['received'] ? 'Received' : 'Missing';
        $badgeBg = $item['received'] ? '#e9f6ef' : '#fdecea';
        $rows .= '<tr>'
            . '<td style="padding:11px 0 11px 0;border-bottom:1px solid #efe7e3;width:28px;vertical-align:top;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:' . $color . ';">' . $mark . '</td>'
            . '<td style="padding:11px 10px 11px 0;border-bottom:1px solid #efe7e3;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;color:#2b2b2b;">' . $e($item['label']) . '</td>'
            . '<td align="right" style="padding:11px 0;border-bottom:1px solid #efe7e3;white-space:nowrap;">'
            . '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:' . $badgeBg . ';color:' . $color . ';font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:.4px;">' . $badge . '</span></td>'
            . '</tr>';
    }

    $missingBlock = '';
    if (!$complete) {
        $count = count($snapshot['missing']);
        $missingBlock = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0 4px 0;">'
            . '<tr><td style="background:#fff8e6;border-left:4px solid #e0a100;border-radius:6px;padding:14px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#5c5240;">'
            . '<strong style="color:#8a6100;">' . $count . ' requirement' . ($count === 1 ? '' : 's') . ' still to submit.</strong> '
            . 'Please submit the original or certified true copies to the ' . $e($office) . ', ' . $e($campus) . ', as soon as possible.'
            . '</td></tr></table>';
    }

    $info = [
        ['Student Number', $student['student_number'] ?? ''],
        ['Course', $student['course'] ?? ''],
        ['Year & Section', $student['year_section'] ?? ''],
        ['Admission Type', $snapshot['track']],
    ];
    $infoRows = '';
    foreach ($info as [$label, $value]) {
        if ((string) $value === '') {
            continue;
        }
        $infoRows .= '<tr>'
            . '<td style="padding:4px 14px 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#8a7b74;white-space:nowrap;vertical-align:top;">' . $e($label) . '</td>'
            . '<td style="padding:4px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#2b2b2b;font-weight:bold;">' . $e($value) . '</td>'
            . '</tr>';
    }

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($copy['subject']) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eceff3;">'
        . '<div style="display:none;max-height:0;overflow:hidden;">' . $e($complete ? 'Your document status is Complete.' : count($snapshot['missing']) . ' requirement(s) still missing.') . '</div>'
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
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:' . $accent . ';">' . $e($copy['eyebrow']) . '</div>'
        . '<h1 style="margin:8px 0 0 0;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;line-height:31px;color:#3a0b0b;font-weight:normal;">' . $e($copy['title']) . '</h1>'
        . '</td></tr>'

        . '<tr><td style="padding:16px 32px 0 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3b3b3b;">'
        . '<p style="margin:0 0 12px 0;">Hi ' . $e($greeting) . ',</p>'
        . '<p style="margin:0;">' . $copy['lead'] . '</p>'
        . '</td></tr>'

        . '<tr><td style="padding:22px 32px 0 32px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $accentBg . ';border-radius:10px;">'
        . '<tr><td style="padding:16px 18px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b5f5a;">Document status<br>'
        . '<span style="font-family:Georgia,serif;font-size:22px;line-height:30px;font-weight:bold;color:' . $accent . ';letter-spacing:1px;">' . $statusTxt . '</span></td>'
        . '<td align="right" style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b5f5a;">Submitted<br>'
        . '<span style="font-family:Georgia,serif;font-size:22px;line-height:30px;font-weight:bold;color:#2b2b2b;">' . (int) $snapshot['received'] . '<span style="font-size:15px;color:#8a7b74;"> / ' . (int) $snapshot['total'] . '</span></span></td>'
        . '</tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;border-radius:999px;overflow:hidden;"><tr>'
        . '<td width="' . $barFill . '%" style="height:8px;background:' . $accent . ';font-size:0;line-height:0;">&nbsp;</td>'
        . ($barRest > 0 ? '<td width="' . $barRest . '%" style="height:8px;background:#ffffff;font-size:0;line-height:0;">&nbsp;</td>' : '')
        . '</tr></table>'
        . '</td></tr></table>'
        . '</td></tr>'

        . '<tr><td style="padding:22px 32px 0 32px;">'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#8a7b74;margin-bottom:4px;">Your requirements</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
        . $missingBlock
        . '</td></tr>'

        . '<tr><td style="padding:22px 32px 0 32px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px dashed #e0d4ce;border-radius:10px;"><tr><td style="padding:14px 18px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0">' . $infoRows . '</table>'
        . '</td></tr></table>'
        . '</td></tr>'

        . '<tr><td style="padding:24px 32px 30px 32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;color:#3b3b3b;">'
        . '<p style="margin:0 0 14px 0;">If you have questions about your requirements, please visit or contact the ' . $e($office) . '.</p>'
        . '<p style="margin:0;">Sincerely,<br><span style="font-family:Georgia,serif;font-size:16px;color:#6e0000;font-weight:bold;">' . $e($office) . '</span><br>'
        . '<span style="font-size:13px;color:#8a7b74;">' . $e($campus) . '</span></p>'
        . '</td></tr>'

        . '<tr><td style="background:#f6f7f9;border-top:1px solid #e3e6eb;padding:18px 32px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:17px;color:#7a808a;">'
        . 'This is an automated message from PUP eRecords sent on ' . $e($date) . '. Please do not reply to this email.<br>'
        . 'PUP Bi&ntilde;an Campus, Brgy. Zapote, Bi&ntilde;an City, Laguna &middot; &copy; ' . date('Y') . ' Polytechnic University of the Philippines'
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';

    $text = 'Hi ' . $greeting . ",\n\n"
        . strip_tags($copy['lead']) . "\n\n"
        . 'Document status: ' . ($complete ? 'Complete' : 'Incomplete') . ' (' . $snapshot['received'] . ' of ' . $snapshot['total'] . " submitted)\n";
    if (!$complete) {
        $text .= "\nStill to submit:\n- " . implode("\n- ", $snapshot['missing']) . "\n";
    }
    $text .= "\n" . $office . "\n" . $campus . "\n\nThis is an automated message. Please do not reply.";

    return [$copy['subject'], $html, $text];
}

function studentNotificationSummary($conn, $studentId)
{
    $last = latestStudentNotification($conn, $studentId);
    if (!$last) {
        return null;
    }
    $when = date('M j, Y g:i A', strtotime($last['sent_at']));
    $text = $last['success']
        ? 'Last email notification: ' . $last['document_status'] . ' · sent to ' . $last['recipient'] . ' on ' . $when
        : 'Last email notification failed (' . $last['document_status'] . ') on ' . $when . ': ' . ($last['error_message'] ?: 'unknown error');
    return ['text' => $text, 'success' => (bool) $last['success'], 'status' => $last['document_status']];
}

function studentNotificationPending($conn, $studentId, array $requirements)
{
    try {
        $snapshot = studentRequirementSnapshot($conn, $studentId, $requirements);
        if (!$snapshot || !$snapshot['total']) {
            return null;
        }
        $student = $snapshot['student'];
        if (in_array(strtolower((string) ($student['status'] ?? '')), ['graduated', 'inactive', 'onleave'], true)) {
            return null;
        }
        if (function_exists('expectedGraduationYear') && function_exists('schoolYearHasEnded')) {
            $end = expectedGraduationYear($student);
            if ($end !== null && schoolYearHasEnded($end)) {
                return null;
            }
        }
        if (!filter_var(trim((string) ($student['email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $lastSuccess = latestStudentNotification($conn, $studentId, true);
        if (!$lastSuccess || decideStudentNotification($snapshot, $lastSuccess) === null) {
            return null;
        }
        $status = $snapshot['complete'] ? 'Complete' : 'Incomplete';
        return [
            'status' => $status,
            'text'   => 'Requirement status changed to ' . $status
                . ($snapshot['missing'] ? ' (missing: ' . implode(', ', $snapshot['missing']) . ')' : '')
                . '. The student has not been emailed about this change.',
        ];
    } catch (Throwable $e) {
        return null;
    }
}