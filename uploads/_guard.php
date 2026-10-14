<?php
session_start(['read_and_close' => true]);

function guardDeny($status, $message, $showSignin = true)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    $signin = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/signin.php';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Access denied</title></head>'
        . '<body><h3>' . htmlspecialchars($message) . '</h3>'
        . ($status === 403 && $showSignin ? '<p><a href="' . htmlspecialchars($signin) . '">Sign in</a></p>' : '')
        . '</body></html>';
    exit();
}

if (!isset($_SESSION['name'], $_SESSION['role'])) {
    guardDeny(403, 'Please sign in to open this file.');
}
if ($_SESSION['role'] !== 'Admin') {
    guardDeny(403, 'You do not have permission to open this file.', false);
}

try {
    $conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
    if ($conn) {
        $email = $_SESSION['email'] ?? '';
        if ($email !== '') {
            $stmt = mysqli_prepare($conn, 'SELECT status FROM accounts WHERE email = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 's', $email);
        } else {
            $name = $_SESSION['name'];
            $role = $_SESSION['role'];
            $stmt = mysqli_prepare($conn, 'SELECT status FROM accounts WHERE name = ? AND role = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'ss', $name, $role);
        }
        mysqli_stmt_execute($stmt);
        $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_close($conn);
        if (!$account || $account['status'] !== 'Active') {
            guardDeny(403, 'Your account is not active. Please sign in again.');
        }
    }
} catch (Throwable $e) {
    error_log('Upload guard: account check failed: ' . $e->getMessage());
}

$relative = str_replace('\\', '/', (string) ($_GET['f'] ?? ''));
$baseDir  = realpath(__DIR__);
$filePath = $relative !== '' ? realpath(__DIR__ . '/' . $relative) : false;

if ($baseDir === false || $filePath === false || !is_file($filePath)
    || strpos($filePath, $baseDir . DIRECTORY_SEPARATOR) !== 0
    || in_array(strtolower(basename($filePath)), ['.htaccess', '_guard.php'], true)) {
    guardDeny(404, 'File not found.');
}

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$types = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];
$mime = $types[$extension] ?? 'application/octet-stream';
$inline = isset($types[$extension]);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', basename($filePath)) . '"');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
header('X-Content-Type-Options: nosniff');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($filePath);
exit();