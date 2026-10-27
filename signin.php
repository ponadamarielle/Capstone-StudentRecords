<!DOCTYPE html>
<?php
include 'security_headers.php';
session_start();
require_once __DIR__ . '/activity_log.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

const MAX_LOGIN_ATTEMPTS    = 3;
const LOGIN_LOCK_MINUTES    = 5;
const LOGIN_ATTEMPT_WINDOW  = 15;

if (isset($_SESSION['name'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'Admin' && ($_SESSION['is_first_login'] ?? 0) == 1) {
        header("Location: change_password.php");
        exit();
    }
    if ($_SESSION['role'] === 'Super Admin') {
        header("Location: superadmin/dashboard.php");
        exit();
    }
    if ($_SESSION['role'] === 'Admin') {
        header("Location: admin/student_directory.php");
        exit();
    }
}

// csrf token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = "";
if (isset($_GET['deactivated'])) {
    $error = "Your account has been deactivated. Please contact the Super Admin.";
}

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);

if(!$conn) {
    die("Connection failed: " .mysqli_connect_error());
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS login_attempts (
    email        VARCHAR(255) NOT NULL PRIMARY KEY,
    attempts     INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    last_attempt DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function loginLockSeconds($conn, $email)
{
    $window = LOGIN_ATTEMPT_WINDOW;
    $stmt = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE email = ?
        AND ((locked_until IS NOT NULL AND locked_until <= NOW())
          OR (locked_until IS NULL AND last_attempt < NOW() - INTERVAL $window MINUTE))");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) FROM login_attempts
        WHERE email = ? AND locked_until > NOW()");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    return $row ? max(1, (int) $row[0]) : 0;
}

function recordFailedLogin($conn, $email)
{
    $stmt = mysqli_prepare($conn, "INSERT INTO login_attempts (email, attempts, last_attempt) VALUES (?, 1, NOW())
        ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "SELECT attempts FROM login_attempts WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $attempts = (int) (mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0] ?? 1);

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $minutes = LOGIN_LOCK_MINUTES;
        $stmt = mysqli_prepare($conn, "UPDATE login_attempts SET locked_until = NOW() + INTERVAL $minutes MINUTE WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
    }
    return $attempts;
}

function clearFailedLogins($conn, $email)
{
    $stmt = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
}

function lockMessage($seconds)
{
    $minutes = (int) ceil($seconds / 60);
    return "Too many failed sign-in attempts. This account is temporarily locked. Please try again in "
        . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}

if($_SERVER['REQUEST_METHOD'] == 'POST'){

 if (
    !isset($_POST['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    die("CSRF validation failed.");
}

    $email = trim($_POST['email']);
    $pass = $_POST['password'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

    $attemptKey  = strtolower($email);
    $lockSeconds = loginLockSeconds($conn, $attemptKey);

    $stmt = mysqli_prepare($conn, "SELECT * FROM accounts WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    if ($lockSeconds > 0) {
        $error = lockMessage($lockSeconds);
    } elseif ($row && password_verify($pass, $row['pass'])) {
        clearFailedLogins($conn, $attemptKey);
        if($row['status'] !== 'Active') {
            $error = "Your account is inactive. Please contact super admin.";
        } else {
            session_regenerate_id(true);
            $_SESSION['email'] = $row['email'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['name'] = $row['name'];
            $_SESSION['is_first_login'] = $row['is_first_login']; 

            logActivity('login', 'Signed in as ' . $row['role'], [
                'user_id'   => $row['id'] ?? null,
                'user_name' => $row['name'],
                'user_role' => $row['role'],
                'details'   => ['email' => $row['email']],
            ]);

            if($row['is_first_login'] == 1 && $row['role'] == 'Admin') {
                header("Location: change_password.php");
                exit();
            }

            if ($row['role'] == 'Super Admin') {
                header("Location: superadmin/dashboard.php");
                exit();
            } elseif ($row['role'] == 'Admin') {
                header("Location: admin/student_directory.php");
                exit();
            }
        }
    } elseif (!$row) {
        logActivity('login_failed', 'Failed sign-in attempt for ' . $email . ' (email not registered)', [
            'user_name' => $email,
            'user_role' => 'Unknown account',
            'details'   => ['email' => $email, 'reason' => 'Email not registered'],
        ]);
        $error = "Invalid email or password.";
    } else {
        $attempts  = recordFailedLogin($conn, $attemptKey);
        $remaining = MAX_LOGIN_ATTEMPTS - $attempts;

        logActivity('login_failed', 'Failed sign-in attempt ' . min($attempts, MAX_LOGIN_ATTEMPTS) . ' of ' . MAX_LOGIN_ATTEMPTS . ' for ' . $email, [
            'user_id'   => $row['id'] ?? null,
            'user_name' => $row['name'] ?? $email,
            'user_role' => $row['role'] ?? 'Unknown account',
            'details'   => ['email' => $email, 'reason' => $row ? 'Wrong password' : 'Email not registered'],
        ]);

        if ($remaining <= 0) {
            $error = lockMessage(LOGIN_LOCK_MINUTES * 60);
            logActivity('account_locked', 'Sign-in locked for ' . LOGIN_LOCK_MINUTES . ' minutes after ' . MAX_LOGIN_ATTEMPTS . ' failed attempts for ' . $email, [
                'user_id'   => $row['id'] ?? null,
                'user_name' => $row['name'] ?? $email,
                'user_role' => $row['role'] ?? 'Unknown account',
                'details'   => ['email' => $email, 'locked_minutes' => LOGIN_LOCK_MINUTES],
            ]);
        } else {
            $error = "Invalid email or password. You have " . $remaining . " attempt" . ($remaining === 1 ? '' : 's') . " left.";
        }
    }
    }
}
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PUP Student Records</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/font.css" rel="stylesheet">
    <link href="css/signin.css?v=<?= @filemtime(__DIR__ . '/css/signin.css') ?>" rel="stylesheet">
</head>
<body class="auth-body">

    <main class="auth-page">
        <aside class="auth-side">
            <div class="auth-brand">
                <img src="images/logo.png" class="auth-brand-logo" alt="PUP Logo">
                <span class="auth-brand-name">PUP eRecords</span>
            </div>

            <div class="auth-side-content">
                <p class="auth-eyebrow">Admission and Registration Office</p>
                <h1 class="auth-headline">Every student record, safe and easy to find.</h1>
                <p class="auth-lead">Digitize, manage and back up student files in one secure place.</p>
                <ul class="auth-features">
                    <li><span class="auth-feature-icon"><i class="bi bi-file-earmark-text"></i></span>Digitized student files and documents</li>
                    <li><span class="auth-feature-icon"><i class="bi bi-stars"></i></span>Smart extraction from uploaded PDFs</li>
                    <li><span class="auth-feature-icon"><i class="bi bi-cloud-check"></i></span>Automatic scheduled backups</li>
                </ul>
            </div>

            <p class="auth-copyright">&copy; <?= date('Y') ?> Polytechnic University of the Philippines</p>
            <div class="auth-rings" aria-hidden="true"></div>
        </aside>

        <section class="auth-main">
            <div class="auth-form-wrap">
                <div class="auth-mobile-brand">
                    <img src="images/logo.png" class="auth-brand-logo" alt="PUP Logo">
                    <span class="auth-brand-name">PUP eRecords</span>
                </div>

                <h2 class="auth-title">Welcome back</h2>
                <p class="auth-subtitle">Sign in to continue to PUP eRecords.</p>

                <form method="POST" id="signinForm" class="auth-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo $_SESSION['csrf_token']; ?>"
                    >

                    <label class="auth-label" for="signinEmail">Email address</label>
                    <div class="auth-input">
                        <i class="bi bi-envelope auth-input-icon"></i>
                        <input
                            type="email"
                            class="form-control"
                            id="signinEmail"
                            name="email"
                            placeholder="name@example.com"
                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                            pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$"
                            title="Please enter a valid email address"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <label class="auth-label" for="signinPassword">Password</label>
                    <div class="auth-input">
                        <i class="bi bi-lock auth-input-icon"></i>
                        <input
                            type="password"
                            class="form-control"
                            id="signinPassword"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="auth-eye" data-toggle-password="signinPassword" aria-label="Show password">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>

                    <div class="auth-row">
                        <label class="auth-check">
                            <input type="checkbox" id="rememberMe">
                            <span>Remember me</span>
                        </label>
                        <button type="button" class="auth-link" id="forgotPasswordBtn">Forgot password?</button>
                    </div>
                    <div class="auth-note" id="forgotPasswordNote" hidden>
                        <i class="bi bi-info-circle"></i>
                        <span>Passwords are managed by the Super Admin. Please contact the Super Admin to reset your password.</span>
                    </div>

                    <?php if($error != ''): ?>
                        <div class="alert alert-danger auth-alert" role="alert">
                            <i class="bi bi-exclamation-circle"></i>
                            <span><?php echo htmlspecialchars($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="auth-submit">Sign in</button>
                </form>

            </div>
        </section>
    </main>

<script src="js/auth.js?v=<?= @filemtime(__DIR__ . '/js/auth.js') ?>"></script>
</body>
</html>