<?php
include 'security_headers.php';
session_start();
require_once __DIR__ . '/activity_log.php';

try {
    if (headers_sent()) {
        throw new Exception('Headers already sent before logout redirect.');
    }

    if (!empty($_SESSION)) {
        if (isset($_SESSION['name'])) {
            logActivity('logout', 'Signed out', [
                'details' => ['email' => $_SESSION['email'] ?? ''],
            ]);
        }
        session_unset();
        session_destroy();
    }

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

    header("Location: signin.php");
    exit();

} catch (Throwable $e) {

    $errorRef = uniqid('LOGOUT_', true);

    error_log(
        "[{$errorRef}] Logout Error: " .
        $e->getMessage() .
        " in " . $e->getFile() .
        " on line " . $e->getLine()
    );

    http_response_code(500);

    echo "
    <!DOCTYPE html>
    <html>
    <head>
        <title>System Error</title>
    </head>
    <body>
        <h2>Something went wrong.</h2>
        <p>Please contact the administrator and provide the reference code below.</p>
        <p><strong>Reference ID:</strong> {$errorRef}</p>
    </body>
    </html>";
    exit();
}
?>