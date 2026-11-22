<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../two_factor.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

const EC_CODE_MINUTES   = 10;
const EC_RESEND_SECONDS = 60;
const EC_MAX_FAILS      = 5;

function emailReply($success, $message, $field = '', array $extra = [])
{
    echo json_encode(['success' => $success, 'message' => $message, 'field' => $field] + $extra);
    exit();
}

if (($_SESSION['role'] ?? '') !== 'Super Admin' || empty($_SESSION['name'])) {
    http_response_code(401);
    emailReply(false, 'Your session has ended. Please sign in again.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    emailReply(false, 'Invalid request.');
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    emailReply(false, 'The page has expired. Please reload and try again.');
}

try {
    $conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
} catch (Throwable $e) {
    $conn = false;
}
if (!$conn) {
    emailReply(false, 'Could not connect to the database.');
}
mysqli_set_charset($conn, 'utf8mb4');

$sessionEmail = $_SESSION['email'] ?? '';
if ($sessionEmail !== '') {
    $stmt = $conn->prepare("SELECT id, name, email, pass, role FROM accounts WHERE email = ? AND role = 'Super Admin' LIMIT 1");
    $stmt->bind_param('s', $sessionEmail);
} else {
    $sessionName = $_SESSION['name'];
    $stmt = $conn->prepare("SELECT id, name, email, pass, role FROM accounts WHERE name = ? AND role = 'Super Admin' LIMIT 1");
    $stmt->bind_param('s', $sessionName);
}
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account) {
    emailReply(false, 'Your account was not found.');
}

$action = (string) ($_POST['action'] ?? '');

function emailInUse($conn, $email, $selfId)
{
    $stmt = $conn->prepare('SELECT id FROM accounts WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $email, $selfId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_row();
    $stmt->close();
    return (bool) $found;
}

function sendEmailChangeCode(array $account, $newEmail)
{
    if (!tfInternetReachable()) {
        return 'offline';
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    require_once __DIR__ . '/../PHPMailer-7.0.1/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer-7.0.1/src/SMTP.php';
    require_once __DIR__ . '/../PHPMailer-7.0.1/src/Exception.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = tfEnv('GMAIL_USER');
        $mail->Password = tfEnv('GMAIL_APP_PASSWORD');
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->Timeout = 15;
        $mail->setFrom('pupbc.superadmin@gmail.com', 'PUP eRecords');
        $mail->addAddress($newEmail, $account['name']);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = 'Your PUP eRecords verification code: ' . $code;
        [$mail->Body, $mail->AltBody] = buildEmailChangeCodeEmail($account['name'], $code, EC_CODE_MINUTES);
        $mail->send();
    } catch (Throwable $e) {
        error_log('Email change code failed: ' . ($mail->ErrorInfo ?: $e->getMessage()));
        logActivity('email_failed', 'Could not send the email change code to ' . $newEmail, [
            'category'    => 'audit',
            'target_type' => 'account',
            'target_id'   => $account['id'],
            'details'     => ['recipient' => $newEmail, 'error' => $mail->ErrorInfo ?: $e->getMessage()],
        ]);
        return tfInternetReachable() ? 'failed' : 'offline';
    }

    $_SESSION['email_change'] = [
        'account_id' => (int) $account['id'],
        'email'      => $newEmail,
        'hash'       => password_hash($code, PASSWORD_DEFAULT),
        'sent_at'    => time(),
        'expires_at' => time() + EC_CODE_MINUTES * 60,
        'attempts'   => 0,
    ];
    logActivity('email_change_code_sent', 'Sent an email change code to ' . $newEmail, [
        'category'    => 'audit',
        'target_type' => 'account',
        'target_id'   => $account['id'],
        'details'     => ['current_email' => $account['email'], 'new_email' => $newEmail],
    ]);
    return 'sent';
}

function sendReply($result, $newEmail)
{
    if ($result === 'sent') {
        emailReply(true, 'We sent a 6-digit code to ' . $newEmail . '.', '', ['email' => $newEmail, 'wait' => EC_RESEND_SECONDS]);
    }
    if ($result === 'offline') {
        emailReply(false, 'There is no internet connection, so the code could not be sent. Connect to the internet and try again.');
    }
    emailReply(false, 'The code could not be sent. Please try again.');
}

if ($action === 'send_code') {
    $current  = (string) ($_POST['current_password'] ?? '');
    $newEmail = strtolower(trim((string) ($_POST['new_email'] ?? '')));

    $lockedFor = (int) ($_SESSION['pw_change_lock_until'] ?? 0) - time();
    if ($lockedFor > 0) {
        emailReply(false, 'Too many wrong attempts. Try again in ' . max(1, (int) ceil($lockedFor / 60)) . ' minute(s).', 'current_password');
    }
    if ($current === '') {
        emailReply(false, 'Please enter your current password.', 'current_password');
    }
    if ($newEmail === '') {
        emailReply(false, 'Please enter the new email address.', 'new_email');
    }
    if (strlen($newEmail) > 50 || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        emailReply(false, 'Please enter a valid email address.', 'new_email');
    }
    if ($newEmail === strtolower($account['email'])) {
        emailReply(false, 'The new email address must be different from your current email address.', 'new_email');
    }

    if (!password_verify($current, $account['pass'])) {
        $_SESSION['pw_change_failures'] = ($_SESSION['pw_change_failures'] ?? 0) + 1;
        if ($_SESSION['pw_change_failures'] >= 5) {
            $_SESSION['pw_change_lock_until'] = time() + 300;
            logActivity('email_change_failed', 'Entered a wrong current password 5 times while changing the email address', [
                'category'    => 'audit',
                'target_type' => 'account',
                'target_id'   => $account['id'],
                'details'     => ['email' => $account['email']],
            ]);
            $_SESSION['pw_change_failures'] = 0;
        }
        emailReply(false, 'The current password is incorrect.', 'current_password');
    }
    $_SESSION['pw_change_failures'] = 0;

    if (emailInUse($conn, $newEmail, (int) $account['id'])) {
        emailReply(false, 'This email address is already used by another account.', 'new_email');
    }

    $pending = $_SESSION['email_change'] ?? null;
    if ($pending && $pending['email'] === $newEmail && (int) $pending['account_id'] === (int) $account['id']) {
        $wait = EC_RESEND_SECONDS - (time() - (int) $pending['sent_at']);
        if ($wait > 0) {
            emailReply(true, 'We already sent a code to ' . $newEmail . '.', '', ['email' => $newEmail, 'wait' => $wait]);
        }
    }

    sendReply(sendEmailChangeCode($account, $newEmail), $newEmail);
}

$pending = $_SESSION['email_change'] ?? null;
if (!$pending || (int) $pending['account_id'] !== (int) $account['id']) {
    emailReply(false, 'Please start again and request a new code.', '', ['restart' => true]);
}

if ($action === 'resend_code') {
    $wait = EC_RESEND_SECONDS - (time() - (int) $pending['sent_at']);
    if ($wait > 0) {
        emailReply(false, 'Please wait ' . $wait . ' seconds before requesting a new code.', 'code', ['wait' => $wait]);
    }
    if (emailInUse($conn, $pending['email'], (int) $account['id'])) {
        unset($_SESSION['email_change']);
        emailReply(false, 'This email address is already used by another account.', '', ['restart' => true]);
    }
    sendReply(sendEmailChangeCode($account, $pending['email']), $pending['email']);
}

if ($action === 'verify_code') {
    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
    if (strlen($code) !== 6) {
        emailReply(false, 'Enter the 6-digit code.', 'code');
    }
    if (time() > (int) $pending['expires_at'] || (int) $pending['attempts'] >= EC_MAX_FAILS) {
        emailReply(false, 'This code has expired. Send a new code.', 'code');
    }
    if (!password_verify($code, $pending['hash'])) {
        $_SESSION['email_change']['attempts'] = (int) $pending['attempts'] + 1;
        $left = EC_MAX_FAILS - $_SESSION['email_change']['attempts'];
        emailReply(false, $left > 0 ? 'The code is incorrect.' : 'This code has expired. Send a new code.', 'code');
    }

    $newEmail = $pending['email'];
    if (emailInUse($conn, $newEmail, (int) $account['id'])) {
        unset($_SESSION['email_change']);
        emailReply(false, 'This email address is already used by another account.', '', ['restart' => true]);
    }

    $update = $conn->prepare('UPDATE accounts SET email = ? WHERE id = ?');
    $update->bind_param('si', $newEmail, $account['id']);
    try {
        $ok = $update->execute();
    } catch (Throwable $e) {
        $ok = false;
    }
    $update->close();
    if (!$ok) {
        emailReply(false, 'The email address could not be changed. Please try again.');
    }

    unset($_SESSION['email_change']);
    $_SESSION['email'] = $newEmail;
    session_regenerate_id(true);

    logActivity('email_changed', 'Changed own email address', [
        'target_type' => 'account',
        'target_id'   => $account['id'],
        'details'     => [
            'changes' => ['Email address' => ['from' => $account['email'], 'to' => $newEmail]],
            'effect'  => 'Sign-in codes are now sent to the new email address.',
        ],
    ]);

    emailReply(true, 'Your email address was changed to ' . $newEmail . '.', '', ['email' => $newEmail]);
}

emailReply(false, 'Invalid request.');
