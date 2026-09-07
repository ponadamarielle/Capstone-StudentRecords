<?php
include '../security_headers.php';
session_start();
if (!isset($_SESSION['name']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../signin.php");
    exit();
}

$state = bin2hex(random_bytes(16));
file_put_contents(sys_get_temp_dir() . '/oauth_state_' . $state, '1');

function loadEnv($path) {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key); $value = trim($value);
        if ($key !== '' && getenv($key) === false) putenv("$key=$value");
    }
}
loadEnv(__DIR__ . '/../.env');

$params = [
    'client_id'     => getenv('GOOGLE_OAUTH_CLIENT_ID'),
    'redirect_uri'  => 'http://localhost/CAPSTONE/admin/google_oauth_callback.php',
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/drive.file',
    'access_type'   => 'offline',
    'prompt'        => 'consent',
    'state'         => $state,
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;