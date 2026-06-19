<?php 
    $userName = $_SESSION['name'];
    $userRole = $_SESSION['role'];
    $currentPage = basename($_SERVER['PHP_SELF']);
?>

<link href="../css/navbar-admin.css" rel="stylesheet">
<script src="../js/bootstrap.bundle.min.js"></script>

<nav class="navbar navbar-expand-lg navbar">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="#">
            <img src="../images/logo.png" alt="PUP Logo" class="logo">
            <span class="pup">PUP <span class="erecords">eRecords</span></span>
        </a>

        <div class="collapse navbar-collapse" id="navbarMenu">
            <ul class="navbar-nav navbar-menu">
                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">Dashboard</a>
                </li>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item d-flex flex-column text-start me-3">
                    <span class="fw-bold text-white"><?php echo htmlspecialchars($userName); ?></span>
                    <small class="text-white-50"><?php echo htmlspecialchars($userRole); ?></small>
                </li>

                <!-- Icons -->
                <li class="nav-item dropdown me-3 user-dropdown">
                    <a class="nav-link" href="#" id="userDropdown">
                        <i class="bi bi-person-fill"></i>
                    </a>

                    <!-- Dropdown Box -->
                    <div class="dropdown-menu user-dropdown-menu p-3">
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                            <i class="bi bi-box-arrow-right me-2"></i> Sign Out
                        </a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Logout Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">

            <div class="modal-body mb-3">
                Are you sure you want to sign out?
            </div>

            <div class="d-flex justify-content-center gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="../logout.php" class="btn btn-danger">Sign Out</a>
            </div>

        </div>
    </div>
</div>