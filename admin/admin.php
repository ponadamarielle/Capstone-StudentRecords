<?php
    session_start();
    
    $userName = $_SESSION['name'];
    $userRole = $_SESSION['role'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <style>
    .navbar {
        background-color: #800503;
    }
    .erecords {
        color: #ffde59;
        font-weight: 700;
        font-size: 20px;
    }
    .pup {
        color: #ffffff;
        font-weight: 700;
        font-size: 20px
    }
    .nav-link {
        color: #ffffff;
    }
    .navbar-menu {
        margin-left: 350px;       
    }
    .nav-item a {
        font-size: 16px;
        font-weight: 700;
        color: #ffffff;
    }
    .nav-link i {
        font-size: 30px; 
        color: #ffffff; 
        margin-right: 20px;
    }
    .logo {
        width: 50px;
        height: 50px;
        margin-right: 10px;
        margin-left: 60px;
    }
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
    .user-dropdown .dropdown-menu {
        min-width: 220px;     
        border-radius: 10px;     
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        right: 0;                
        left: auto;         
    }
    .user-dropdown:hover .dropdown-menu {
        display: block;
    }
    .user-dropdown .dropdown-item{
        background-color: transparent;
        color: #000;
    }
    .dropdown-item:hover {
        color: #800503;
    }
    .modal-content {
        border: none;          
        border-radius: 10px;   
    }
    .btn-danger {
        background-color: #800503;
        border: none;
    }
    .btn-danger:hover {
        background-color: #a0060a;
    }
    .btn-secondary {
        background-color: #ccc;
        border: none;
    }
    .btn-secondary:hover {
        background-color: #999;
    }
    #logoutModal .modal-dialog {
        max-width: 350px;
    }

    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="#">
            <img src="pup_logo.png" alt="#" width="50" height="50" class="logo">
            <span class = "pup">PUP <span class="erecords">eRecords</span></span>
        </a>

        <div class="collapse navbar-collapse" id="navbarMenu">
        <ul class="navbar-nav navbar-menu">
          <li class ="nav-item">
            <a class="nav-link" href="#">Student Records</a>
          </li>
        </ul>

        <ul class="navbar-nav ms-auto align-items-center">
        <!-- Registrar info -->
        <li class="nav-item d-flex flex-column text-start me-3">
          <span class="fw-bold text-white"><?php echo htmlspecialchars($userName)?></span>
          <small class="text-white-50"><?php echo htmlspecialchars($userRole)?></small>
        </li>

        <!-- Icons -->
        <li class="nav-item dropdown me-3 user-dropdown">
        <a class="nav-link" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-fill"></i>
        </a>

        <!-- Custom Dropdown Box -->
        <div class="dropdown-menu user-dropdown-menu p-3">
            <a class="dropdown-item" href="#">Profile</a>
            <hr class="dropdown-divider">
            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">Sign Out</a>

        </div>
        </li>
    </div>
    </nav>

    <div class="main-container">
    <div class="textNButton-row">

      <div class="search-group">
        <input type="text" placeholder="STUDENT NUMBER / NAME" class="searchbar">

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

<!-- logout modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">
      
      <div class="modal-body mb-3">
        Are you sure you want to sign out?
      </div>

      <div class="d-flex justify-content-center gap-2">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <a href="../signin.php" class="btn btn-danger">Sign Out</a>
      </div>

    </div>
  </div>
</div>

</body>

</html>