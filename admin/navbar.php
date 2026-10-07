<?php 
    $userName = $_SESSION['name'];
    $userRole = $_SESSION['role'];
    $currentPage = basename($_SERVER['PHP_SELF']);
?>

<link href="../css/font.css" rel="stylesheet">
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="../js/navbar-admin.js"></script>

<nav class="navbar navbar-expand-lg navbar">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="#">
            <img src="../images/logo.png" alt="PUP Logo" class="logo">
            <span class="pup">PUP <span class="erecords">eRecords</span></span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarMenu" aria-controls="navbarMenu"
                aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMenu">
            <ul class="navbar-nav navbar-menu">
                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage == 'student_directory.php') ? 'active' : ''; ?>" href="student_directory.php">Student Directory</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentPage == 'archive.php') ? 'active' : ''; ?>" href="archive.php">Archive</a>
                </li>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item d-flex flex-column text-start me-3">
                    <span class="fw-bold text-white navbar-username"><?php echo htmlspecialchars($userName); ?></span>
                    <small class="text-white-50 navbar-role"><?php echo htmlspecialchars($userRole); ?></small>
                </li>

                <!-- Icons -->
                <li class="nav-item dropdown me-3 user-dropdown">
                    <span class="nav-link user-icon" id="userDropdown" tabindex="0" aria-haspopup="true">
                        <i class="bi bi-person-fill"></i>
                    </span>

                <!-- Dropdown Box -->
                <div class="dropdown-menu user-dropdown-menu p-3">
                    <a class="dropdown-item signout-link" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
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