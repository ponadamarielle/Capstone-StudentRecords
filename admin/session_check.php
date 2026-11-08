<?php
session_start();
require_once __DIR__ . '/../account_guard.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['name'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'redirect' => '../signin.php']);
    exit();
}

requireActiveAccount(true);
session_write_close();

echo json_encode(['success' => true]);
