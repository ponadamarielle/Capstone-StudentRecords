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
    <title>Backup & Logs</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

</body>
</html>