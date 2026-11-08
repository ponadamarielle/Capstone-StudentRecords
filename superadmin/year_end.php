<?php
session_start();
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../admin/archive.php';

header('Content-Type: application/json');

function yearEndReply($success, $message, array $extra = [])
{
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit();
}

if (!isset($_SESSION['name'], $_SESSION['role']) || $_SESSION['role'] !== 'Super Admin') {
    http_response_code(403);
    yearEndReply(false, 'Only the Super Admin can confirm the year-end update.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    yearEndReply(false, 'Your session expired. Reload the page and try again.');
}

try {
    $conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
} catch (Throwable $e) {
    $conn = false;
}
if (!$conn) {
    yearEndReply(false, 'The database could not be reached. Try again.');
}
$GLOBALS['conn'] = $conn;

$endYear = pendingYearEnd($conn);
if ($endYear === null) {
    yearEndReply(true, 'The year-end update is already done.');
}

$result = applyYearEnd($conn, (string) $_SESSION['name']);
if (empty($result['success'])) {
    yearEndReply(false, $result['message'] ?? 'The year-end update could not be saved.');
}

logActivity('year_end_confirmed', 'Confirmed the year-end update of ' . schoolYearLabel($endYear), [
    'category'    => 'audit',
    'target_type' => 'school_year',
    'details'     => [
        'school_year' => schoolYearLabel($endYear),
        'graduated'   => $result['graduated'],
        'moved_up'    => $result['moved_up'],
    ],
]);

yearEndReply(true, $result['graduated'] . ' students marked as graduate and ' . $result['moved_up'] . ' moved up a year level.');
