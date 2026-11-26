<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../activity_log.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function profileReply($success, $message, $extra = []) {
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit();
}
if (($_SESSION['role'] ?? '') !== 'Super Admin' || empty($_SESSION['email'])) {
    http_response_code(401);
    profileReply(false, 'Your session has ended. Please sign in again.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    profileReply(false, 'Invalid request.');
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    profileReply(false, 'The page has expired. Please reload and try again.');
}
$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
    profileReply(false, 'Enter a valid full name of up to 100 characters.');
}
try {
    $conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
    mysqli_set_charset($conn, 'utf8mb4');
    $stmt = $conn->prepare("SELECT id, name FROM accounts WHERE email = ? AND role = 'Super Admin' LIMIT 1");
    $stmt->bind_param('s', $_SESSION['email']);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account) profileReply(false, 'Your account was not found.');
    $stmt = $conn->prepare("UPDATE accounts SET name = ? WHERE id = ? AND role = 'Super Admin'");
    $stmt->bind_param('si', $name, $account['id']);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    profileReply(false, 'Your name could not be saved. Please try again.');
}
$_SESSION['name'] = $name;
if ($account['name'] !== $name) {
    logActivity('profile_changed', 'Changed own name', ['target_type' => 'account', 'target_id' => $account['id'], 'details' => ['changes' => ['Name' => ['from' => $account['name'], 'to' => $name]]]]);
}
$parts = preg_split('/[\s,]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
$initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[count($parts)-1] ?? '', 0, 1));
profileReply(true, 'Your name was updated.', ['name' => $name, 'initials' => $initials]);
