<?php
session_start();
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PUP eRecords - Change Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
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
        .brand-name .pup { color: #1a1a2e; }
        .brand-name .erecords { color: #800503; }
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
            width: 100%;
            margin-top: 12px;
        }
        .form-control:first-child {
            margin-top: 0;
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
            gap: 0;
            margin-bottom: 20px;
        }
        .pass-label {
            font-size: 9px;
            font-family: 'Inter';
            text-align: left;
            color: #666;
            margin-top: 4px;
        }
        .field-error {
            color: #dc3545;
            font-size: 11px;
            text-align: left;
            display: block;
            margin-top: 3px;
            min-height: 0;
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
        .btn-login:hover { background: #6a0402; }
        .btn-login:active { transform: scale(0.98); }
        .alert-danger {
            font-size: 13px;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 14px;
            text-align: left;
        }
        .btn-login:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
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
            <p class="card-subtitle">Change Password</p>

            <form method="POST">
                <div class="input-gap">

                    <input type="password" class="form-control" name="new_password" id="new_password" placeholder="New Password" required>
                    <label class="pass-label">
                        <i class="bi bi-lightbulb-fill" style="color: #FFD700;"></i>
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

<script>
    const newPass     = document.getElementById('new_password');
    const confirmPass = document.getElementById('confirm_password');
    const errNew      = document.getElementById('err_new');
    const errConfirm  = document.getElementById('err_confirm');
    const saveBtn     = document.getElementById('save-btn');

    function isPasswordValid(val) {
        return val.length >= 8 &&
               /[A-Z]/.test(val) &&
               /[a-z]/.test(val) &&
               /[0-9]/.test(val) &&
               /[\W_]/.test(val);
    }

    function checkAll() {
        const newVal     = newPass.value;
        const confirmVal = confirmPass.value;

        if (newVal === '' && confirmVal === '') {
            saveBtn.disabled = false;
            return;
        }

        const passOk  = isPasswordValid(newVal);
        const matchOk = confirmVal !== '' && confirmVal === newVal;
        saveBtn.disabled = !(passOk && matchOk);
    }

    newPass.addEventListener('input', function() {
        const val = this.value;

        if (val === '') {
            errNew.textContent = '';
            this.style.borderColor = '#ddd';
        } else if (val.length < 8) {
            errNew.textContent = 'At least 8 characters required.';
            this.style.borderColor = '#dc3545';
        } else if (!/[A-Z]/.test(val)) {
            errNew.textContent = 'Add at least one uppercase letter.';
            this.style.borderColor = '#dc3545';
        } else if (!/[a-z]/.test(val)) {
            errNew.textContent = 'Add at least one lowercase letter.';
            this.style.borderColor = '#dc3545';
        } else if (!/[0-9]/.test(val)) {
            errNew.textContent = 'Add at least one number.';
            this.style.borderColor = '#dc3545';
        } else if (!/[\W_]/.test(val)) {
            errNew.textContent = 'Add at least one symbol.';
            this.style.borderColor = '#dc3545';
        } else {
            errNew.textContent = '';
            this.style.borderColor = '#28a745';
        }

        checkAll();
    });

    confirmPass.addEventListener('input', function() {
        if (this.value === '') {
            errConfirm.textContent = '';
            this.style.borderColor = '#ddd';
        } else if (this.value !== newPass.value) {
            errConfirm.textContent = 'Passwords do not match.';
            this.style.borderColor = '#dc3545';
        } else {
            errConfirm.textContent = '';
            this.style.borderColor = '#28a745';
        }

        checkAll();
    });
</script>

</body>
</html>