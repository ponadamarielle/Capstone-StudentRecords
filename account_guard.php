<?php
require_once __DIR__ . '/activity_log.php';

function requireActiveAccount($isAjax = false)
{
    if (!isset($_SESSION['name'], $_SESSION['role'])) {
        return;
    }

    $row = null;
    try {
        $conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
        if (!$conn) {
            return;
        }
        $email = $_SESSION['email'] ?? '';
        if ($email !== '') {
            $stmt = mysqli_prepare($conn, 'SELECT name, role, status FROM accounts WHERE email = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 's', $email);
        } else {
            $name = $_SESSION['name'];
            $role = $_SESSION['role'];
            $stmt = mysqli_prepare($conn, 'SELECT name, role, status FROM accounts WHERE name = ? AND role = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'ss', $name, $role);
        }
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_close($conn);
    } catch (Throwable $e) {
        error_log('Account check failed: ' . $e->getMessage());
        return;
    }

    if ($row && $row['status'] === 'Active' && $row['role'] === $_SESSION['role']) {
        return;
    }

    logActivity('logout', 'Signed out automatically: the account was deactivated', [
        'details' => ['email' => $_SESSION['email'] ?? '', 'reason' => $row ? 'Account status is ' . $row['status'] : 'Account no longer exists'],
    ]);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
    }
    session_destroy();

    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode([
            'success'  => false,
            'message'  => 'Your account has been deactivated. Please contact the Super Admin.',
            'redirect' => '../signin.php?deactivated=1',
        ]);
        exit();
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: ../signin.php?deactivated=1');
    exit();
}
