<?php
include 'security_headers.php';
session_start();

try {
    if (headers_sent()) {
        throw new Exception('Headers already sent before logout redirect.');
    }

    if (!empty($_SESSION)) {
        session_unset();
        session_destroy();
    }

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