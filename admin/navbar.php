<?php 
    $userName = $_SESSION['name'];
    $userRole = $_SESSION['role'];

    $currentPage = basename($_SERVER['PHP_SELF']);
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
        color: #ffffff !important;
    }
    .nav-link.active {  
        font-weight: bold;
        border-bottom: 3px solid #ffde59;
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
    .user-dropdown .dropdown-menu {
        display: none;
        position: absolute;
        top: 100%;
        right: 0;
        min-width: 220px;     
        border-radius: 10px;     
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .user-dropdown:hover .dropdown-menu {
        display: block;
    }
    .user-dropdown .dropdown-item{
        background-color: transparent;
        color: #000;
    }
    .user-dropdown-menu .dropdown-item:focus,
    .user-dropdown-menu .dropdown-item:active,
    .user-dropdown-menu .dropdown-item:focus-visible {
        outline: none !important;
        box-shadow: none !important;
        background-color: transparent !important;
        color: inherit !important;
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
            <a class="nav-link <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">Dashboard</a>
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
        <a class="nav-link" href="#" id="userDropdown">
            <i class="bi bi-person-fill"></i>
        </a>

        <!-- Dropdown Box -->
        <div class="dropdown-menu user-dropdown-menu p-3">
            <a class="dropdown-item" href="#">Profile</a>
            <hr class="dropdown-divider">
            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">Sign Out</a>
        </div>
        </li>
    </div>
    </nav>

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