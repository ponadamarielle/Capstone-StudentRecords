<?php 
    require_once __DIR__ . '/../account_guard.php';
    requireActiveAccount(false);
    $userName = $_SESSION['name'];
    $userRole = $_SESSION['role'];
    $currentPage = basename($_SERVER['PHP_SELF']);
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    }
?>

<link href="../css/font.css" rel="stylesheet">
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="../js/sidebar.js"></script>
<script src="../js/navbar-admin.js"></script>

<?php
    $pageTitles = [
        'student_directory.php' => ['Student Directory', 'Search, view and manage student records.'],
        'archive.php'           => ['Archived Records', 'Records of students who have been archived.'],
        'view_student.php'      => ['Student Profile', 'View and update the student record and documents.'],
    ];
    [$pageTitle, $pageSubtitle] = $pageTitles[$currentPage] ?? ['PUP eRecords', ''];
    if ($currentPage === 'view_student.php' && !empty($student['name'])) {
        $pageSubtitle = 'Student Directory / ' . $student['name'];
    }
    $nameParts = preg_split('/[\s,]+/', trim((string) $userName), -1, PREG_SPLIT_NO_EMPTY);
    $userInitials = strtoupper(substr($nameParts[0] ?? '', 0, 1) . substr($nameParts[count($nameParts) - 1] ?? '', 0, 1));
?>

<aside class="offcanvas-lg offcanvas-start admin-sidebar" id="adminSidebar" tabindex="-1" aria-label="Main menu">
    <button class="admin-collapse-toggle d-none d-lg-inline-flex" type="button" id="sidebarCollapseBtn" aria-controls="adminSidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">
        <i class="bi bi-chevron-double-left"></i>
    </button>
    <div class="admin-sidebar-inner">
        <div class="admin-brand">
            <img src="../images/logo.png" alt="PUP Logo" class="admin-brand-logo">
            <div class="admin-brand-text">
                <span class="admin-brand-name">PUP eRecords</span>
                <span class="admin-brand-sub">Student Records System</span>
            </div>
            <button type="button" class="admin-sidebar-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close menu">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <p class="admin-menu-label">Main menu</p>
        <nav class="admin-menu">
            <a class="admin-menu-item <?php echo ($currentPage == 'student_directory.php' || $currentPage == 'view_student.php') ? 'active' : ''; ?>" href="student_directory.php" title="Student Directory">
                <i class="bi bi-mortarboard"></i><span>Student Directory</span>
            </a>
            <a class="admin-menu-item <?php echo ($currentPage == 'archive.php') ? 'active' : ''; ?>" href="archive.php" title="Archived Records">
                <i class="bi bi-archive"></i><span>Archived Records</span>
            </a>
        </nav>

        <div class="admin-sidebar-bottom">
            <div class="admin-privacy">
                <p class="admin-privacy-title">Data Privacy Notice</p>
                <p class="admin-privacy-text">Student records are protected under RA 10173. Handle with care.</p>
            </div>
            <a class="admin-logout" href="#" title="Log out" data-bs-toggle="modal" data-bs-target="#logoutModal">
                <i class="bi bi-box-arrow-right"></i><span>Log out</span>
            </a>
        </div>
    </div>
</aside>

<header class="admin-topbar">
    <button class="admin-menu-toggle d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Open menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="admin-topbar-title">
        <h1 class="admin-page-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
        <?php if ($pageSubtitle !== ''): ?>
            <p class="admin-page-subtitle"><?php echo htmlspecialchars($pageSubtitle); ?></p>
        <?php endif; ?>
    </div>

    <div class="admin-user">
        <span class="admin-user-trigger" id="userDropdown">
            <span class="admin-avatar"><?php echo htmlspecialchars($userInitials); ?></span>
            <span class="admin-user-text">
                <span class="navbar-username"><?php echo htmlspecialchars($userName); ?></span>
                <small class="navbar-role"><?php echo htmlspecialchars($userRole); ?></small>
            </span>
        </span>
    </div>
</header>

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