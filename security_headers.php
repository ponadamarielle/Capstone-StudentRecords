<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true, 
    'samesite' => 'Strict'
]);

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' https://cdn.jsdelivr.net; " .
    "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data:; " .
    "frame-ancestors 'none'; " .
    "object-src 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self';"
);
?>