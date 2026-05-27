<?php
session_start();
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
    $email = $_POST['email'];
    $pass = $_POST['password'];

    $sql = "SELECT * FROM accounts WHERE email='$email'";
    $result = mysqli_query($conn, $sql);

    if(mysqli_num_rows($result) > 0){
        $row = mysqli_fetch_assoc($result);

        if(password_verify($pass, $row['pass'])){
            if($row['status'] !== 'Active') {
                $error = "Your account is inactive. Please contact admin.";
            } else {
                $_SESSION['email'] = $row['email'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['name'] = $row['name'];

                if ($row['role'] == 'Super Admin') {
                    header("Location: superadmin/dashboard.php");
                    exit();
                } elseif ($row['role'] == 'Registrar') {
                    header("Location: admin/dashboard.php");
                    exit();
                }
            }
        } else {
            $error = "Invalid Credentials";
        }

    } else {
        $error = "Invalid Credentials";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PUP Student Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body, html {
            height: 100%;
            font-family: 'Inter', sans-serif;
        }

        .bg-wrapper {
            position: fixed;
            inset: 0;
            background: url('pupbinan.jpg') no-repeat center center / cover;
            z-index: 0;
        }

        .bg-wrapper::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
        }

        .page-center {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-radius: 12px;
            padding: 48px 40px 44px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        .brand-logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
            margin-bottom: 4px;
        }

        .brand-name {
            font-family: 'Poppins', sans-serif;
            font-size: 25px;
            font-weight: 700;
            letter-spacing: -0.3px;
            margin-top: 12px;
        }

        .brand-name .pup {
            color: #1a1a2e;
        }

        .brand-name .erecords {
            color: #800503;
        }

        .card-subtitle {
            font-size: 12px;
            color: #666;
            margin-bottom: 28px;
            font-weight: 400;
            margin-top: 6px;
        }

        .form-control {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 12px 14px;
            font-size: 14px;
            color: #333;
            background: #fff;
            transition: border-color 0.2s;
            height: auto;
        }

        .form-control::placeholder {
            color: #aaa;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: #800503;
            box-shadow: 0 0 0 3px rgba(128, 5, 3, 0.1);
            outline: none;
        }

        .input-gap {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: #800503;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }

        .btn-login:hover {
            background: #6a0402;
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .alert-danger {
            font-size: 13px;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 14px;
            text-align: left;
        }
    </style>
</head>
<body>

    <div class="bg-wrapper"></div>

    <div class="page-center">
        <div class="login-card">

            <img src="logo.png" class="brand-logo" alt="PUP Logo">
            <div class="brand-name">
                <span class="pup">PUP </span><span class="erecords">eRecords</span>
            </div>
            <p class="card-subtitle">Sign in to your account</p>

            <form method="POST">
                <div class="input-gap">
                    <input
                        type="email"
                        class="form-control"
                        name="email"
                        placeholder="Email"
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
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