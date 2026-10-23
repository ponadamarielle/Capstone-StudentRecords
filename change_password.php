<!DOCTYPE html>
<?php
include 'security_headers.php';
session_start();
require_once __DIR__ . '/activity_log.php';

// csrf token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if(!isset($_SESSION['email'])) {
    header("Location: signin.php");
    exit();
}

$error = "";

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);

if(!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

if($_SERVER['REQUEST_METHOD'] == 'POST'){
     if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("CSRF validation failed.");
    }

    $new_pass = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $currentStmt = mysqli_prepare($conn, "SELECT pass FROM accounts WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($currentStmt, "s", $_SESSION['email']);
    mysqli_stmt_execute($currentStmt);
    $currentHash = mysqli_fetch_row(mysqli_stmt_get_result($currentStmt))[0] ?? '';

    if($currentHash !== '' && password_verify($new_pass, $currentHash)){
        $error = "Your new password must be different from your temporary password.";
    } elseif(strlen($new_pass) < 8){
        $error = "Password must be at least 8 characters.";
    } elseif(!preg_match('/[A-Z]/', $new_pass)){
        $error = "Password must contain at least one uppercase letter.";
    } elseif(!preg_match('/[a-z]/', $new_pass)){
        $error = "Password must contain at least one lowercase letter.";
    } elseif(!preg_match('/[0-9]/', $new_pass)){
        $error = "Password must contain at least one number.";
    } elseif(!preg_match('/[\W_]/', $new_pass)){
        $error = "Password must contain at least one symbol.";
    } elseif($new_pass !== $confirm){
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $email  = $_SESSION['email'];

        $updateStmt = mysqli_prepare($conn, "UPDATE accounts SET pass = ?, is_first_login = 0 WHERE email = ?");
        mysqli_stmt_bind_param($updateStmt, "ss", $hashed, $email);
        if(mysqli_stmt_execute($updateStmt)){
            $_SESSION['is_first_login'] = 0;
            logActivity('password_changed', 'Changed the temporary password', [
                'details' => ['email' => $email],
            ]);
            $role = $_SESSION['role'];
            if($role == 'Super Admin') header("Location: superadmin/dashboard.php");
            else header("Location: admin/student_directory.php");
            exit();
        } else {
            $error = "Something went wrong. Please try again.";
        }
    }
}
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PUP eRecords - Change Password</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/font.css" rel="stylesheet">
    <link href="css/change_password.css?v=<?= @filemtime(__DIR__ . '/css/change_password.css') ?>" rel="stylesheet">

</head>
<body class="auth-body">

    <main class="auth-page">
        <aside class="auth-side">
            <div class="auth-brand">
                <img src="images/logo.png" class="auth-brand-logo" alt="PUP Logo">
                <span class="auth-brand-name">PUP eRecords</span>
            </div>

            <div class="auth-side-content">
                <p class="auth-eyebrow">First-time sign in</p>
                <h1 class="auth-headline">Let's secure your account.</h1>
                <p class="auth-lead">You're signing in with a temporary password. Choose a new one that only you know. You'll use it every time you access student records.</p>
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

                <h2 class="auth-title">Set up your password</h2>
                <p class="auth-subtitle">Create a strong password before you continue.</p>

                <form method="POST" class="auth-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo $_SESSION['csrf_token']; ?>"
                    >

                    <label class="auth-label" for="new_password">New password</label>
                    <div class="auth-input">
                        <i class="bi bi-lock auth-input-icon"></i>
                        <input type="password" class="form-control" name="new_password" id="new_password" placeholder="Enter a new password" autocomplete="new-password" required>
                        <button type="button" class="auth-eye" data-toggle-password="new_password" aria-label="Show password">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                    <small id="err_new" class="field-error"></small>

                    <div class="auth-strength" id="passwordStrength">
                        <div class="auth-strength-head">
                            <span>Password strength</span>
                            <span class="auth-strength-label" id="passwordStrengthLabel"></span>
                        </div>
                        <div class="auth-strength-bars">
                            <span></span><span></span><span></span><span></span>
                        </div>
                    </div>

                    <ul class="auth-rules" id="passwordRules">
                        <li data-rule="length"><i class="bi bi-info-circle"></i> At least 8 characters</li>
                        <li data-rule="case"><i class="bi bi-info-circle"></i> Upper and lowercase letters</li>
                        <li data-rule="number"><i class="bi bi-info-circle"></i> At least one number</li>
                        <li data-rule="symbol"><i class="bi bi-info-circle"></i> At least one symbol (e.g. ! @ #)</li>
                    </ul>

                    <label class="auth-label" for="confirm_password">Confirm new password</label>
                    <div class="auth-input">
                        <i class="bi bi-lock auth-input-icon"></i>
                        <input type="password" class="form-control" name="confirm_password" id="confirm_password" placeholder="Re-enter the new password" autocomplete="new-password" required>
                        <button type="button" class="auth-eye" data-toggle-password="confirm_password" aria-label="Show password">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                    <small id="err_confirm" class="field-error"></small>

                    <?php if($error != ''): ?>
                        <div class="alert alert-danger auth-alert" role="alert">
                            <i class="bi bi-exclamation-circle"></i>
                            <span><?php echo htmlspecialchars($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="auth-submit" id="save-btn">Save and continue</button>
                </form>
            </div>
        </section>
    </main>

<script src="js/change_password.js"></script>
<script src="js/auth.js?v=<?= @filemtime(__DIR__ . '/js/auth.js') ?>"></script>

</body>
</html>