<!DOCTYPE html>
<?php
include '../security_headers.php';
session_start();

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../signin.php");
    exit();
}

if (isset($_SESSION['is_first_login']) && $_SESSION['is_first_login'] == 1) {
    header("Location: ../change_password.php");
    exit();
}

include 'navbar.php';
?>

<html>
<head>
    <title>Dashboard</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/dashboard-admin.css" rel="stylesheet">
</head>

<body>
    <div class="main-container">
    <div class="textNButton-row">

      <div class="search-group">
        <input type="text" placeholder="STUDENT NUMBER / NAME" class="searchbar" required>

        <button type="submit" name="search_btn" class="search-icon">
            <i class="bi bi-search"></i>
        </button>    
      </div>

      <div class="add-filter">
        <button class="btn-add"> <i class="bi bi-plus-circle"></i>ADD</button>
    
      <select class="filter">
        <option value="">Filter</option>
        <option value="course">BSIT 1-1</option>
        <option value="course">BSIT 2-1</option>
        <option value="course">BSIT 3-1</option>
      </select>
    </div>

    </div>

    <!-- table -->
    <table class="student-table">
    <tr>
        <th>Student Number</th>
        <th>Name</th>
        <th>Course</th>
        <th>Status</th>
        <th></th>
    </tr>

    <tr>
        <td>Data</td>
        <td>Data</td>
        <td>Data</td>
        <td>
            <select class="status-fliter">
                <option value="active">ACTIVE</option>
                <option value="inactive">IN-ACTIVE</option>
                <option value="onleave">ON-LEAVE</option>
            </select>
        </td>
        <td>
           <button class="view-btn"><i class="bi bi-eye-fill"></i>View</button>
        </td>
    </tr>
    </table>
</body>
</html>