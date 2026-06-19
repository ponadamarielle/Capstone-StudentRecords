<!DOCTYPE html>
<?php
include '../security_headers.php';
session_start();
if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Super Admin') {
    header("Location: ../signin.php");
    exit();
}

include 'navbar.php';
?>

<html>
<head>
    <title>Dashboard</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/dashboard-superadmin.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5 d-flex gap-5 justify-content-center">
        <div class="card custom-card">
            <div class="card-body">
                <h5 class="card-title">Total Students</h5>
                <p class="card-text">NUMBER</p>
            </div>
        </div>
        <div class="card custom-card">
            <div class="card-body">
                <h5 class="card-title">Active</h5>
                <p class="card-text">NUMBER</p>
            </div>
        </div>
        <div class="card custom-card">
            <div class="card-body">
                <h5 class="card-title">Inactive</h5>
                <p class="card-text">NUMBER</p>
            </div>
        </div>
        <div class="card custom-card">
            <div class="card-body">
                <h5 class="card-title">On leave</h5>
                <p class="card-text">NUMBER</p>
            </div>
        </div>
    </div>
</body>
</html>