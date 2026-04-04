<?php
session_start();

if (!isset($_SESSION['name']) || !isset($_SESSION['role'])) {
    header("Location: ../signin.php");
    exit();
}

include 'navbar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">

    <style>
        .textNButton-row {
        display: flex;    
        justify-content: space-between;
        align-items: flex-start; 
    }
    .body-title {
        font-size: 20px;
        font-family: "Cambria";
        font-weight: 900;
    }
    .btn-add {
        background-color: #800503;
        color: #ffffff;
        font-weight: 700;
        text-align: center;
        padding: 5px 35px; 
        border: none;
    }
    .btn-add i {
        margin-right: 10px;
    }
    .main-container{
        display: flex;
        flex-direction: column; 
        gap: 15px;           
        padding: 50px 60px;
    }
    .filter {
        width: 100%;
        padding: 7px 14px;
        border: 2px solid #ccc;
        border-radius: 5px;
        background-color: white;
        cursor: pointer;
    }
    .searchbar{
        width: 600px;
        padding: 10px 14px;
        border: 2px solid #ccc;
        border-right: none;
        border-radius: 5px 0 0 5px;
    }
    .search-icon{
        width: 50px;
        padding: 10px 14px;
        border: 2px solid #800503;
        border-left: none;
        border-radius: 0 5px 5px 0;
        background-color: #800503;
    }
    .search-icon i {
        font-size: 16px;
        color: #ffffff;
    }
    .search-group {
        display: flex;
        align-items: center;
    }
    .add-filter{
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 30px;
    }
    .student-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    .student-table th, .student-table td {
        border: 1px solid #ccc;
        padding: 10px;
        text-align: left;
    }
    .student-table th {
        background-color: #800503;
        color: white;
    }
    .student-table td:last-child {
        text-align: center;
    }
    .view-btn {
        background-color: #800503;
        color: #ffffff;
        border: none;
        align-items: center;
        border-radius: 5px;
        padding: 3px 10px;
    }
    .view-btn i {
        color: #ffffff;
        margin-right: 6px;
    }
    .status-fliter {
        padding: 7px;
        border: 2px solid #ccc;
        border-radius: 5px;
        background-color: white;
        cursor: pointer;
    }
    </style>
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