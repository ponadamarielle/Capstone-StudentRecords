<?php
    $userName = $_SESSION['name'];
    $userRole = $_SESSION['role'];
    $currentPage = basename($_SERVER['PHP_SELF']);
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    }
?>

<link href="../css/bootstrap-icons.css" rel="stylesheet">
<link href="../css/navbar-superadmin.css" rel="stylesheet">
<link href="../css/update-password.css?v=<?= @filemtime(__DIR__ . '/../css/update-password.css') ?>" rel="stylesheet">
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="../js/sidebar.js"></script>
<script src="../js/update-password.js?v=<?= @filemtime(__DIR__ . '/../js/update-password.js') ?>" defer></script>

<?php
    $pageTitles = [
        'dashboard.php'       => ['Dashboard', 'Overview of student records and document requirements.'],
        'user_management.php' => ['User Management', 'Manage Admin and OJT Intern accounts and who can access records.'],
        'course_management.php' => ['Course Management', 'Manage the programs offered and how they are recognized in documents.'],
        'requirements.php'    => ['Requirements', 'Manage the documents required for regular, shiftee and transferee students.'],
        'backup_logs.php'     => ['Backup & Logs', 'Keep records safe and see who did what, and when.'],
        'report_template.php' => ['Report Template', 'Change the letterhead used on the student reports.'],
    ];
    [$pageTitle, $pageSubtitle] = $pageTitles[$currentPage] ?? ['PUP eRecords', ''];
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
            <a class="admin-menu-item <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php" title="Dashboard">
                <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
            </a>
            <a class="admin-menu-item <?php echo ($currentPage == 'user_management.php') ? 'active' : ''; ?>" href="user_management.php" title="User Management">
                <i class="bi bi-people"></i><span>User Management</span>
            </a>
            <a class="admin-menu-item <?php echo ($currentPage == 'course_management.php') ? 'active' : ''; ?>" href="course_management.php" title="Programs Offered">
                <i class="bi bi-mortarboard"></i><span>Programs Offered</span>
            </a>
            <a class="admin-menu-item <?php echo ($currentPage == 'requirements.php') ? 'active' : ''; ?>" href="requirements.php" title="Requirements">
                <i class="bi bi-file-earmark-check"></i><span>Requirements</span>
            </a>
            <a class="admin-menu-item <?php echo ($currentPage == 'report_template.php') ? 'active' : ''; ?>" href="report_template.php" title="Report Template">
                <i class="bi bi-file-earmark-richtext"></i><span>Report Template</span>
            </a>
            <a class="admin-menu-item <?php echo ($currentPage == 'backup_logs.php') ? 'active' : ''; ?>" href="backup_logs.php" title="Backup &amp; Logs">
                <i class="bi bi-database"></i><span>Backup &amp; Logs</span>
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

    <div class="admin-user dropdown">
        <button type="button" class="admin-user-trigger account-menu-trigger" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="admin-avatar"><?php echo htmlspecialchars($userInitials); ?></span>
            <span class="admin-user-text">
                <span class="navbar-username"><?php echo htmlspecialchars($userName); ?></span>
                <small class="navbar-role"><?php echo htmlspecialchars($userRole); ?></small>
            </span>
            <i class="bi bi-chevron-down account-menu-caret"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end account-menu" aria-labelledby="userDropdown">
            <li>
                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="bi bi-key"></i>Change password
                </button>
            </li>
        </ul>
    </div>
</header>

<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="changePasswordForm" action="update_password.php" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modal-header cp-header">
                    <span class="cp-icon"><i class="bi bi-key"></i></span>
                    <div>
                        <h5 class="modal-title" id="changePasswordTitle">Change password</h5>
                        <p class="cp-subtitle">Use at least 8 characters with at least one letter and one number.</p>
                    </div>
                </div>
                <div class="modal-body cp-body">
                    <div class="cp-success d-none" id="cpSuccess" role="status">
                        <i class="bi bi-check-circle-fill"></i><span>Your password was changed.</span>
                    </div>
                    <div class="cp-fields" id="cpFields">
                        <?php foreach (['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Confirm new password'] as $cpName => $cpLabel): ?>
                        <div class="cp-field">
                            <label class="cp-label" for="cp_<?php echo $cpName; ?>"><?php echo $cpLabel; ?> <span class="cp-req">*</span></label>
                            <div class="cp-input">
                                <input type="password" class="form-control" id="cp_<?php echo $cpName; ?>" name="<?php echo $cpName; ?>" maxlength="72"
                                       autocomplete="<?php echo $cpName === 'current_password' ? 'current-password' : 'new-password'; ?>" required>
                                <button type="button" class="cp-toggle" data-target="cp_<?php echo $cpName; ?>" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="cp-error" data-error-for="<?php echo $cpName; ?>"></div>
                        </div>
                        <?php endforeach; ?>
                        <div class="cp-error cp-error-general" data-error-for=""></div>
                    </div>
                </div>
                <div class="modal-footer cp-footer">
                    <button type="button" class="btn cp-cancel" data-bs-dismiss="modal" id="cpCancelBtn">Cancel</button>
                    <button type="submit" class="btn cp-save" id="cpSaveBtn">Save password</button>
                </div>
            </form>
        </div>
    </div>
</div>

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

<?php include __DIR__ . '/year_end_gate.php'; ?>