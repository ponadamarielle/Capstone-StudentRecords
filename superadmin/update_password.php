<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../activity_log.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function passwordReply($success, $message, $field = '')
{
    echo json_encode(['success' => $success, 'message' => $message, 'field' => $field]);
    exit();
}

if (($_SESSION['role'] ?? '') !== 'Super Admin' || empty($_SESSION['name'])) {
    http_response_code(401);
    passwordReply(false, 'Your session has ended. Please sign in again.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    passwordReply(false, 'Invalid request.');
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    passwordReply(false, 'The page has expired. Please reload and try again.');
}

$current   = (string) ($_POST['current_password'] ?? '');
$new       = (string) ($_POST['new_password'] ?? '');
$confirm   = (string) ($_POST['confirm_password'] ?? '');
$checkOnly = ($_POST['check_only'] ?? '') === '1';

$lockedFor = (int) ($_SESSION['pw_change_lock_until'] ?? 0) - time();
if ($lockedFor > 0) {
    passwordReply(false, 'Too many wrong attempts. Try again in ' . max(1, (int) ceil($lockedFor / 60)) . ' minute(s).', 'current_password');
}

if ($current === '') {
    passwordReply(false, 'Please enter your current password.', 'current_password');
}
if (!$checkOnly) {
    if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        passwordReply(false, 'Use at least 8 characters with at least one letter and one number.', 'new_password');
    }
    if (strlen($new) > 72) {
        passwordReply(false, 'The password must be 72 characters or fewer.', 'new_password');
    }
    if ($new !== $confirm) {
        passwordReply(false, 'The passwords do not match.', 'confirm_password');
    }
    if ($new === $current) {
        passwordReply(false, 'The new password must be different from your current password.', 'new_password');
    }
}

try {
    $conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
} catch (Throwable $e) {
    $conn = false;
}
if (!$conn) {
    passwordReply(false, 'Could not connect to the database.');
}

$email = $_SESSION['email'] ?? '';
if ($email !== '') {
    $stmt = $conn->prepare("SELECT id, name, email, pass FROM accounts WHERE email = ? AND role = 'Super Admin' LIMIT 1");
    $stmt->bind_param('s', $email);
} else {
    $name = $_SESSION['name'];
    $stmt = $conn->prepare("SELECT id, name, email, pass FROM accounts WHERE name = ? AND role = 'Super Admin' LIMIT 1");
    $stmt->bind_param('s', $name);
}
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account) {
    passwordReply(false, 'Your account was not found.');
}

if (!password_verify($current, $account['pass'])) {
    $_SESSION['pw_change_failures'] = ($_SESSION['pw_change_failures'] ?? 0) + 1;
    if ($_SESSION['pw_change_failures'] >= 5) {
        $_SESSION['pw_change_lock_until'] = time() + 300;
        logActivity('password_change_failed', 'Entered a wrong current password 5 times while changing the password', [
            'category'    => 'audit',
            'target_type' => 'account',
            'target_id'   => $account['id'],
            'details'     => ['email' => $account['email']],
        ]);
        $_SESSION['pw_change_failures'] = 0;
    }
    passwordReply(false, 'The current password is incorrect.', 'current_password');
}

$_SESSION['pw_change_failures'] = 0;
if ($checkOnly) {
    passwordReply(true, 'Current password is correct.', 'current_password');
}

$hash = password_hash($new, PASSWORD_DEFAULT);
$update = $conn->prepare('UPDATE accounts SET pass = ? WHERE id = ?');
$update->bind_param('si', $hash, $account['id']);
if (!$update->execute()) {
    passwordReply(false, 'The password could not be changed. Please try again.');
}
$update->close();

unset($_SESSION['pw_change_failures']);
session_regenerate_id(true);

logActivity('password_changed', 'Changed own password', [
    'target_type' => 'account',
    'target_id'   => $account['id'],
    'details'     => ['email' => $account['email'], 'effect' => 'The new password is used on the next sign-in.'],
]);

passwordReply(true, 'Your password was changed.');