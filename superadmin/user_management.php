<?php
//php mailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../PHPMailer-7.0.1/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer-7.0.1/src/SMTP.php';
require __DIR__ . '/../PHPMailer-7.0.1/src/Exception.php';

// config.json
$configPath = __DIR__ . '/config.json';
$config = json_decode(file_get_contents($configPath), true);

$gmailUser = $config['gmail_user'];
$gmailAppPassword = $config['gmail_app_password'];

session_start();
include 'navbar.php';

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// create account
$pass = '';
if (isset($_POST['add_admin'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];

    $pass = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $hashedPass = password_hash($pass, PASSWORD_DEFAULT);

    $role = "Registrar";
    $status = "Active";

    $sql = "INSERT INTO accounts (name, email, pass, role, status) 
        VALUES ('$name', '$email', '$hashedPass', '$role', '$status')";

    if (mysqli_query($conn, $sql)) {
        $success = true;

        // send gmail via PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $gmailUser;
            $mail->Password = $gmailAppPassword;  
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('pupbc.superadmin@gmail.com', 'PUP eRecords - Admin Module');
            $mail->addAddress($email, $name);

            $refNumber = random_int(1000, 9999);

            $mail->isHTML(true);
            $mail->Subject = "PUP eRecords Admin Module Password [Ref: {$refNumber}]";
            $mail->Body    = "
                <h3>Hi {$name}, below is your login credentials:</h3>
                <p><strong>Email:</strong> $email</p>
                <p><strong>Password:</strong> $pass</p>
            ";

            $mail->send();
        } catch (Exception $e) {
            $error = "Email could not be sent: {$mail->ErrorInfo}";
        }
    } else {
        $error = "Could not create account: " . mysqli_error($conn);
    }
}

// toggle status
if (isset($_POST['toggle_status'])) {
    $email = $_POST['email'];
    $currentStatus = $_POST['current_status'];
    $newStatus = ($currentStatus === 'Active') ? 'Inactive' : 'Active';

    $sql = "UPDATE accounts SET status='$newStatus' WHERE email='$email' AND role='Registrar'";
    mysqli_query($conn, $sql);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>User Management</title>

    <style>
    .btn-add {
        background-color: #2E2E2E;
        color: #ffffff;
        font-weight: 700;
        text-align: center;
        padding: 5px 35px; 
        border: none;
        margin-right: 50px;
    }
    .btn-add i {
        margin-right: 10px;
        color: #ffde59;
    }
    .admin-table {
        width: 91%;         
        margin: 20px auto;    
        border-collapse: collapse;
        font-size: 14px;   
    }
    .admin-table th, 
    .admin-table td {
        border: 1px solid #ccc;
        padding: 8px 12px;    
        text-align: left;
        border-right: none;
        border-left: none; 
    }
    .admin-table th {
        background-color: #808080;
        color: white;
        font-size: 15px;
    }
    .admin-table td {
        background-color: #f9f9f9;
    }
    .deactivate-btn {
        background-color: #2E2E2E;
        color: white;
        border: none;
        padding: 5px 12px;
        font-size: 13px;
        border-radius: 5px;
    }
    #addStudentModal .btn-custom {
        background-color: #2E2E2E; 
        color: #ffffff;          
        font-weight: 700;
    }
    #addStudentModal .btn-custom:hover {
        background-color: #444444; 
    }
    .filter-tabs {
        display: flex;
        gap: 4px;
        background: #f0f0f0;
        padding: 4px;
        border-radius: 8px;
        border: 1px solid #ddd;
    }
    .filter-tab {
        padding: 6px 18px;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        border: none;
        background: transparent;
        color: #555;
    }
    .filter-tab:hover { 
        background: #ffde59; 
        color: #2E2E2E; 
    }
    .filter-tab.active { 
        background: #2E2E2E; 
        color: white; 
        font-weight: 600; 
    }
    .activate-btn {
        background-color: white;
        color: #2E2E2E;
        border: 1px solid #2E2E2E;
        padding: 5px 12px;
        font-size: 13px;
        border-radius: 5px;
    }
    .filter-add {
        padding-top: 50px;
        padding-left: 68px;
        padding-right: 20px;
    }
    #confirmToggleModal .modal-dialog {
        max-width: 350px;
    }
    </style>
</head>

<body>
    <div class="d-flex justify-content-between align-items-center filter-add">
    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <button class="filter-tab <?= (!isset($_GET['status']) || $_GET['status'] == 'all') ? 'active' : '' ?>"
            onclick="window.location='?status=all'">All</button>
        <button class="filter-tab <?= (isset($_GET['status']) && $_GET['status'] == 'active') ? 'active' : '' ?>"
            onclick="window.location='?status=active'">Active</button>
        <button class="filter-tab <?= (isset($_GET['status']) && $_GET['status'] == 'inactive') ? 'active' : '' ?>"
            onclick="window.location='?status=inactive'">Inactive</button>
    </div>

    <!-- ADD Button -->
    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addStudentModal">
        <i class="bi bi-plus-circle"></i>ADD
    </button>
</div>

    <!-- table -->
    <table class="admin-table">
    <tr>
        <th>Name</th>
        <th>Email Address</th>
        <th>Status</th>
        <th>Actions</th>
    </tr>

    <?php
    $filter = $_GET['status'] ?? 'all';
    $where = ($filter === 'active') ? "AND status='Active'" : (($filter === 'inactive') ? "AND status='Inactive'" : "");

    $result = mysqli_query($conn, "SELECT name, email, status FROM accounts WHERE role='Registrar' $where");
    while ($row = mysqli_fetch_assoc($result)) {
        $isActive = $row['status'] === 'Active';
        $btnClass = $isActive ? 'deactivate-btn' : 'activate-btn';
        $btnLabel = $isActive ? 'Deactivate' : 'Activate';

        echo "<tr>
        <td>{$row['name']}</td>
        <td>{$row['email']}</td>
        <td>{$row['status']}</td>
        <td>
            <button class='$btnClass'
                data-bs-toggle='modal'
                data-bs-target='#confirmToggleModal'
                data-email='{$row['email']}'
                data-status='{$row['status']}'
                data-label='$btnLabel'>
                $btnLabel
            </button>
        </td>
      </tr>";
    }
    ?>
</table>

<!-- add registrar modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true">
  <div class="modal-dialog  modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addStudentModalLabel">Add new admin</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form name = "signin" method = "POST">
            <div class = "input-container">
            <div class = "form-floating mb-3">
                <input type="text" class="form-control" id="name" name="name" placeholder="name" required>
                <label for="name">Name</label>
            </div>

            <div class = "form-floating mb-3">
                <input type="email" class="form-control" id="email" name="email" placeholder="email" required>
                <label for="email">Email Address</label>
            </div>
            <button type="submit" name="add_admin" class="btn btn-custom w-100 mt-3">Create Account</button>
            </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Confirm Toggle Modal -->
<div class="modal fade" id="confirmToggleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">

      <div class="modal-body mb-3">
        Are you sure you want to change this account's status?
      </div>

      <form method="POST">
        <input type="hidden" name="email" id="modalEmail">
        <input type="hidden" name="current_status" id="modalStatus">

        <div class="d-flex justify-content-center gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="toggle_status" class="btn btn-danger" id="modalConfirmBtn">Confirm</button>
        </div>
      </form>

    </div>
  </div>
</div>

<script>
document.getElementById('confirmToggleModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('modalEmail').value = btn.getAttribute('data-email');
    document.getElementById('modalStatus').value = btn.getAttribute('data-status');
    document.getElementById('modalConfirmBtn').innerText = btn.getAttribute('data-label');
});
</script>
</body>
</html>