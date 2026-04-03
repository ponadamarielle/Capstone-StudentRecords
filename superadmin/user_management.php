<?php
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
$tempPass = '';
if (isset($_POST['add_admin'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];

    $tempPass = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $role = "Registrar";
    $status = "Active";

    $sql = "INSERT INTO accounts (name, email, pass, role, status) 
            VALUES ('$name', '$email', '$tempPass', '$role', '$status')";

    if (mysqli_query($conn, $sql)) {
        $success = true;
    } else {
        $error = "Could not create account: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <style>
    .btn-add {
        background-color: #2E2E2E;
        color: #ffffff;
        font-weight: 700;
        text-align: center;
        padding: 5px 35px; 
        border: none;
        margin-right: 50px;
        margin-top: 25px;
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
    </style>
</head>

<body>
    <div class="d-flex justify-content-end p-3">
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
    $result = mysqli_query($conn, "SELECT name, email, status FROM accounts WHERE role='Registrar'");
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>
                <td>{$row['name']}</td>
                <td>{$row['email']}</td>
                <td>{$row['status']}</td>
                <td><button class='deactivate-btn'>Deactivate</button></td>
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
        <form method="POST">
        <div class="mb-3">
            <label for="studentName" class="form-label">Name</label>
            <input type="text" class="form-control" id="studentName" name="name" required>
        </div>
        <div class="mb-3">
            <label for="studentEmail" class="form-label">Email Address</label>
            <input type="email" class="form-control" id="studentEmail" name="email" required>
        </div>
        <button type="submit" name="add_admin" class="btn btn-primary w-100">Create Account</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- temp pass modal -->
<?php if (!empty($tempPass) && isset($success) && $success): ?>
<div class="modal fade show" id="tempPassModal" style="display:block;" tabindex="-1" aria-labelledby="tempPassModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tempPassModalLabel">Temporary Password</h5>
        <a href="user_management.php" class="btn-close"></a>
      </div>
      <div class="modal-body text-center">
        <p>Your temporary password is:</p>
        <h4 class="fw-bold text-primary"><?= $tempPass ?></h4>
        <p class="text-muted">Please change it after your first login.</p>
      </div>
      <div class="modal-footer">
        <a href="user_management.php" class="btn btn-success w-100">OK</a>
      </div>
    </div>
  </div>
</div>


<script>
    var tempModal = new bootstrap.Modal(document.getElementById('tempPassModal'));
    tempModal.show();
</script>
<?php endif; ?>

</body>
</html>