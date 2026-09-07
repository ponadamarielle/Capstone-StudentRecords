<?php
include '../security_headers.php';
session_start();

$state = $_GET['state'] ?? '';
$stateFile = $state !== '' ? sys_get_temp_dir() . '/oauth_state_' . preg_replace('/[^a-f0-9]/', '', $state) : '';

if ($stateFile === '' || !file_exists($stateFile)) {
    header("Location: ../signin.php");
    exit();
}
unlink($stateFile);

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

$code = $_GET['code'] ?? null;
if (!$code) {
    die('Authorization failed: no code received. ' . htmlspecialchars($_GET['error'] ?? ''));
}

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'code'          => $code,
    'client_id'     => getenv('GOOGLE_OAUTH_CLIENT_ID'),
    'client_secret' => getenv('GOOGLE_OAUTH_CLIENT_SECRET'),
    'redirect_uri'  => 'http://localhost/CAPSTONE/admin/google_oauth_callback.php',
    'grant_type'    => 'authorization_code',
]));
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (!empty($data['refresh_token'])) {
    file_put_contents(getenv('GOOGLE_OAUTH_REFRESH_TOKEN_FILE'), trim($data['refresh_token']));
    echo "Authorization successful! You can close this tab and try uploading a document now.";
} else {
    echo "No refresh token received.<br>Response: <pre>" . htmlspecialchars($response) . "</pre>";
    echo "<br>If you've authorized this app before, revoke access at "
       . "<a href='https://myaccount.google.com/permissions' target='_blank'>myaccount.google.com/permissions</a> "
       . "and try again.";
}