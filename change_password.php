<!DOCTYPE html>
<?php
include 'security_headers.php';
session_start();

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

    $new_pass = $_POST['new_password'];
    $confirm  = $_POST['confirm_password'];

    if(strlen($new_pass) < 8){
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

        $sql = "UPDATE accounts SET pass='$hashed', is_first_login=0 WHERE email='$email'";
        if(mysqli_query($conn, $sql)){
            $_SESSION['is_first_login'] = 0;
            $role = $_SESSION['role'];
            if($role == 'Super Admin') header("Location: superadmin/dashboard.php");
            else header("Location: admin/dashboard.php");
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
    <link href="css/change_password.css" rel="stylesheet">

</head>
<body>

    <div class="bg-wrapper"></div>

    <div class="page-center">
        <div class="login-card">

            <img src="images/logo.png" class="brand-logo" alt="PUP Logo">
            <div class="brand-name">
                <span class="pup">PUP </span><span class="erecords">eRecords</span>
            </div>
            <p class="card-subtitle">Change Password</p>

            <form method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo $_SESSION['csrf_token']; ?>"
                >
                <div class="input-gap">

                    <input type="password" class="form-control" name="new_password" id="new_password" placeholder="New Password" required>
                    <label class="pass-label">
                        <i class="bi bi-lightbulb-fill" tip-icon></i>
                        Password Tips: Minimum 8 chars, upper &amp; lowercase, number &amp; symbol
                    </label>
                    <small id="err_new" class="field-error"></small>

                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" placeholder="Confirm New Password" required>
                    <small id="err_confirm" class="field-error"></small>

                </div>

                <button type="submit" class="btn-login" id="save-btn">Save Changes</button>
            </form>

        </div>
    </div>

<script src="js/change_password.js"></script>

</body>
</html>