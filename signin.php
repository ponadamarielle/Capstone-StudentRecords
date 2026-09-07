<!DOCTYPE html>
<?php
include 'security_headers.php';
session_start();

// csrf token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = "";

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);

if(!$conn) {
    die("Connection failed: " .mysqli_connect_error());
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

    $sql = "SELECT * FROM accounts WHERE email='$email'";
    $result = mysqli_query($conn, $sql);

    if(mysqli_num_rows($result) > 0){
        $row = mysqli_fetch_assoc($result);

        if(password_verify($pass, $row['pass'])){
            if($row['status'] !== 'Active') {
                $error = "Your account is inactive. Please contact super admin.";
            } else {
                $_SESSION['email'] = $row['email'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['name'] = $row['name'];
                $_SESSION['is_first_login'] = $row['is_first_login']; 

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
        } else {
            $error = "Invalid email or password";
        }

    } else {
        $error = "Invalid email or password";
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
    <link href="css/font.css" rel="stylesheet">
    <link href="css/signin.css" rel="stylesheet">
</head>
<body>

    <div class="bg-wrapper"></div>

    <div class="page-center">
        <div class="login-card">

            <img src="images/logo.png" class="brand-logo" alt="PUP Logo">
            <div class="brand-name">
                <span class="pup">PUP </span><span class="erecords">eRecords</span>
            </div>
            <p class="card-subtitle">Sign in to your account</p>

            <form method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo $_SESSION['csrf_token']; ?>"
            >
                <div class="input-gap">
                    <input
                        type="email"
                        class="form-control"
                        name="email"
                        placeholder="Email"
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                        pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$"
                        title="Please enter a valid email address"
                        required
                    >
                    <input
                        type="password"
                        class="form-control"
                        name="password"
                        placeholder="Password"
                        required
                    >
                </div>

                <?php if($error != ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-login">Sign in</button>
            </form>

        </div>
    </div>

</body>
</html>