<!DOCTYPE html>
<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../account_email.php';
require_once __DIR__ . '/../access_control.php';

$envFile = __DIR__ . '/../.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;

        list($key, $value) = explode('=', $line, 2);

        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

// csrf token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Super Admin') {
    header("Location: ../signin.php");
    exit();
}

$statusParam = in_array($_GET['status'] ?? '', ['all', 'active', 'inactive'], true) ? $_GET['status'] : 'all';
$roleParam = in_array($_GET['role'] ?? '', OFFICE_ROLES, true) ? $_GET['role'] : '';
$listQuery = http_build_query(array_filter(['status' => $statusParam === 'all' ? '' : $statusParam, 'role' => $roleParam], 'strlen'));
$listUrl = 'admin_management.php' . ($listQuery !== '' ? '?' . $listQuery : '');

function accountRoleTerms($role)
{
    return $role === 'Admin'
        ? ['off' => 'Deactivate', 'on' => 'Activate', 'offLog' => 'Deactivated', 'onLog' => 'Activated', 'note' => 'Deactivated']
        : ['off' => 'Remove access', 'on' => 'Restore access', 'offLog' => 'Removed the access of', 'onLog' => 'Restored the access of', 'note' => 'Access removed'];
}

//php mailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../PHPMailer-7.0.1/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer-7.0.1/src/SMTP.php';
require __DIR__ . '/../PHPMailer-7.0.1/src/Exception.php';

// .env
$gmailUser = $_ENV['GMAIL_USER'];
$gmailAppPassword = $_ENV['GMAIL_APP_PASSWORD'];

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// create account
$pass = '';
if (isset($_POST['add_admin'])) {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("CSRF validation failed.");
    }

    $name = trim(preg_replace('/\s+/u', ' ', $_POST['name'] ?? ''));
    $email = trim($_POST['email'] ?? '');

    $pass = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $hashedPass = password_hash($pass, PASSWORD_DEFAULT);

    $role = in_array($_POST['role'] ?? '', OFFICE_ROLES, true) ? $_POST['role'] : '';
    $status = "Active";

    if ($role === '') {
        $roleError = "Please choose a role.";
    }

    if ($name === '' || !preg_match("/^[\p{L}][\p{L} .'\-]*$/u", $name) || mb_strlen($name) > 100) {
        $nameError = "Name can only contain letters (including ñ), spaces, hyphens (-), periods (.) and apostrophes (').";
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !preg_match('/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', $email)) {
        $emailError = "Please enter a valid email address (e.g. name@gmail.com).";
    }

    $checkStmt = $conn->prepare("SELECT id FROM accounts WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkStmt->store_result();

    if (isset($nameError) || isset($emailError) || isset($roleError)) {
        $error = $nameError ?? $emailError ?? $roleError;
    } elseif ($checkStmt->num_rows > 0) {
        $error = "This email is already associated with an existing account.";
    } else {
        $insertStmt = $conn->prepare("INSERT INTO accounts (name, email, pass, role, status) VALUES (?, ?, ?, ?, ?)");
        $insertStmt->bind_param("sssss", $name, $email, $hashedPass, $role, $status);

        if ($insertStmt->execute()) {
            $success = true;
            $newAccountId = $insertStmt->insert_id;
            logActivity('account_created', 'Created ' . $role . ' account of ' . $name . ' (' . $email . ')', [
                'target_type' => 'account',
                'target_id'   => $newAccountId,
                'details'     => ['name' => $name, 'email' => $email, 'role' => $role, 'status' => $status],
            ]);

            // send gmail via PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = $gmailUser;
                $mail->Password = $gmailAppPassword;
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('pupbc.superadmin@gmail.com', 'PUP eRecords');
                $mail->addAddress($email, $name);

                $refNumber = random_int(1000, 9999);

                $mail->CharSet = 'UTF-8';
                $mail->isHTML(true);
                $mail->Subject = "Your PUP eRecords {$role} account [Ref: {$refNumber}]";
                [$mail->Body, $mail->AltBody] = buildTemporaryPasswordEmail($name, $email, $pass, $refNumber);

                $mail->send();
                logActivity('email_sent', 'Sent the temporary password email to ' . $name . ' (' . $email . ')', [
                    'category'    => 'audit',
                    'target_type' => 'account',
                    'target_id'   => $newAccountId,
                    'details'     => ['notification' => $role . ' account temporary password', 'recipient' => $email, 'reference' => $refNumber],
                ]);
            } catch (Exception $e) {
                $error = "Email could not be sent: {$mail->ErrorInfo}";
                logActivity('email_failed', 'Could not send the temporary password email to ' . $name . ' (' . $email . ')', [
                    'category'    => 'audit',
                    'target_type' => 'account',
                    'target_id'   => $newAccountId,
                    'details'     => ['notification' => $role . ' account temporary password', 'recipient' => $email, 'error' => $mail->ErrorInfo],
                ]);
            }
        } else {
            $error = "Could not create account: " . $insertStmt->error;
        }
    }
    $checkStmt->close();
}

// toggle status
if (isset($_POST['toggle_status']) && $_POST['toggle_status'] === '1') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("CSRF validation failed.");
    }

    $email = trim($_POST['email']);
    $currentStatus = $_POST['current_status'];
    $newStatus = ($currentStatus === 'Active') ? 'Inactive' : 'Active';

    $targetStmt = $conn->prepare("SELECT name, role FROM accounts WHERE email=? LIMIT 1");
    $targetStmt->bind_param("s", $email);
    $targetStmt->execute();
    $target = $targetStmt->get_result()->fetch_assoc();
    $targetStmt->close();
    $targetRole = ($target && in_array($target['role'], OFFICE_ROLES, true)) ? $target['role'] : '';

    if ($targetRole === '') {
        $toggleError = "The account was not found.";
    } else {
        if ($currentStatus === 'Active' && $targetRole === 'Admin') {
            $countSql = "SELECT COUNT(*) as cnt FROM accounts WHERE role='Admin' AND status='Active'";
            $countResult = mysqli_query($conn, $countSql);
            $countRow = mysqli_fetch_assoc($countResult);
            if ($countRow['cnt'] <= 1) {
                $toggleError = "Cannot deactivate the last active Admin account.";
            }
        }
        if (!isset($toggleError)) {
            $stmt = $conn->prepare("UPDATE accounts SET status=? WHERE email=? AND role=?");
            $stmt->bind_param("sss", $newStatus, $email, $targetRole);
            $stmt->execute();
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
        }
    }

    if (!empty($changed)) {
        $terms = accountRoleTerms($targetRole);
        $accountName = $target['name'] ?? $email;
        logActivity('account_status_changed',
            ($targetRole === 'Admin'
                ? ($newStatus === 'Active' ? 'Activated' : 'Deactivated') . ' Admin account of '
                : ($newStatus === 'Active' ? $terms['onLog'] : $terms['offLog']) . ' ' . $targetRole . ' account ')
            . $accountName . ' (' . $email . ')',
            [
                'target_type' => 'account',
                'details'     => [
                    'email'   => $email,
                    'role'    => $targetRole,
                    'changes' => ['Status' => ['from' => $currentStatus, 'to' => $newStatus]],
                    'effect'  => $newStatus === 'Active'
                        ? 'The ' . $targetRole . ' can sign in again.'
                        : 'The ' . $targetRole . ' is signed out automatically and can no longer sign in.',
                ],
            ]);
    }
}

// reset password
if (isset($_POST['reset_password']) && $_POST['reset_password'] === '1') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("CSRF validation failed.");
    }

    $resetEmail = trim($_POST['email'] ?? '');
    $accountStmt = $conn->prepare("SELECT id, name, role, pass, is_first_login FROM accounts WHERE email=? AND role IN ('Admin', 'OJT Intern') AND status='Active' LIMIT 1");
    $accountStmt->bind_param("s", $resetEmail);
    $accountStmt->execute();
    $account = $accountStmt->get_result()->fetch_assoc();
    $accountStmt->close();

    if (!$account) {
        $resetFlash = ['type' => 'error', 'message' => 'The password could not be reset. The account was not found or is not active.'];
    } else {
        $tempPass = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $tempHash = password_hash($tempPass, PASSWORD_DEFAULT);
        $resetStmt = $conn->prepare("UPDATE accounts SET pass=?, is_first_login=1 WHERE id=?");
        $resetStmt->bind_param("si", $tempHash, $account['id']);
        $resetStmt->execute();
        $resetStmt->close();

        $refNumber = random_int(1000, 9999);
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $gmailUser;
            $mail->Password = $gmailAppPassword;
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            $mail->setFrom('pupbc.superadmin@gmail.com', 'PUP eRecords');
            $mail->addAddress($resetEmail, $account['name']);
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);
            $mail->Subject = "Your PUP eRecords password was reset [Ref: {$refNumber}]";
            [$mail->Body, $mail->AltBody] = buildTemporaryPasswordEmail($account['name'], $resetEmail, $tempPass, $refNumber, true);
            $mail->send();

            try {
                $unlockStmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
                $lowerEmail = strtolower($resetEmail);
                $unlockStmt->bind_param("s", $lowerEmail);
                $unlockStmt->execute();
                $unlockStmt->close();
            } catch (Throwable $unlockError) {
            }

            logActivity('password_reset', 'Reset the password of ' . $account['role'] . ' account ' . $account['name'] . ' (' . $resetEmail . ')', [
                'target_type' => 'account',
                'target_id'   => $account['id'],
                'details'     => ['email' => $resetEmail, 'effect' => 'A temporary password was emailed. The ' . $account['role'] . ' must set a new password on the next sign-in.'],
            ]);
            logActivity('email_sent', 'Sent the password reset email to ' . $account['name'] . ' (' . $resetEmail . ')', [
                'category'    => 'audit',
                'target_type' => 'account',
                'target_id'   => $account['id'],
                'details'     => ['notification' => $account['role'] . ' password reset', 'recipient' => $resetEmail, 'reference' => $refNumber],
            ]);
            $resetFlash = ['type' => 'success', 'message' => 'Password reset. A temporary password was emailed to ' . $resetEmail . '.'];
        } catch (Exception $e) {
            $restoreStmt = $conn->prepare("UPDATE accounts SET pass=?, is_first_login=? WHERE id=?");
            $restoreStmt->bind_param("sii", $account['pass'], $account['is_first_login'], $account['id']);
            $restoreStmt->execute();
            $restoreStmt->close();
            logActivity('email_failed', 'Could not send the password reset email to ' . $account['name'] . ' (' . $resetEmail . ')', [
                'category'    => 'audit',
                'target_type' => 'account',
                'target_id'   => $account['id'],
                'details'     => ['notification' => $account['role'] . ' password reset', 'recipient' => $resetEmail, 'error' => $mail->ErrorInfo, 'effect' => 'The password was not changed.'],
            ]);
            $resetFlash = ['type' => 'error', 'message' => 'The password was not reset because the email could not be sent. Check the internet connection and try again.'];
        }
    }

    $_SESSION['um_reset_flash'] = $resetFlash;
    header('Location: ' . $listUrl);
    exit();
}

$resetFlash = $_SESSION['um_reset_flash'] ?? null;
unset($_SESSION['um_reset_flash']);
?>

<html>
<head>
    <title>User Management</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-superadmin.css" rel="stylesheet">
    <link href="../css/admin_management.css?v=<?= @filemtime(__DIR__ . '/../css/admin_management.css') ?>" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>


<div class="um-container">

<?php if ($resetFlash): ?>
    <div class="um-flash um-flash-<?= $resetFlash['type'] === 'success' ? 'success' : 'error' ?>" role="status">
        <i class="bi <?= $resetFlash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
        <span><?= htmlspecialchars($resetFlash['message']) ?></span>
    </div>
<?php endif; ?>

<div class="filter-add">
    <!-- Filter Tabs -->
    <div class="um-tab-groups">
    <div class="filter-tabs">
        <button class="filter-tab <?= $statusParam === 'all' ? 'active' : '' ?>"
            data-filter="all">All</button>
        <button class="filter-tab <?= $statusParam === 'active' ? 'active' : '' ?>"
            data-filter="active">Active</button>
        <button class="filter-tab <?= $statusParam === 'inactive' ? 'active' : '' ?>"
            data-filter="inactive">Inactive</button>
    </div>
    <div class="filter-tabs" aria-label="Filter by role">
        <button class="filter-tab role-tab <?= $roleParam === '' ? 'active' : '' ?>" data-role="">All roles</button>
        <?php foreach (OFFICE_ROLES as $tabRole): ?>
        <button class="filter-tab role-tab <?= $roleParam === $tabRole ? 'active' : '' ?>" data-role="<?= htmlspecialchars($tabRole) ?>"><?= htmlspecialchars($tabRole) ?></button>
        <?php endforeach; ?>
    </div>
    </div>

    <!-- ADD Button -->
    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addStudentModal">
        <i class="bi bi-person-plus"></i>ADD USER
    </button>
</div>

<!-- table -->
<div class="admin-table-wrap">
<table class="admin-table">
    <tr>
        <th>Name</th>
        <th>Email Address</th>
        <th>Role</th>
        <th>Status</th>
        <th>Actions</th>
    </tr>

    <?php
    $where = ($statusParam === 'active') ? "AND status='Active'" : (($statusParam === 'inactive') ? "AND status='Inactive'" : "");
    $roleWhere = $roleParam !== '' ? "role='" . mysqli_real_escape_string($conn, $roleParam) . "'" : "role IN ('Admin', 'OJT Intern')";

    $result = mysqli_query($conn, "SELECT name, email, role, status FROM accounts WHERE $roleWhere $where ORDER BY FIELD(role, 'Admin', 'OJT Intern'), name ASC");
    $deactivatedAt = [];
    try {
        $logResult = mysqli_query($conn, "SELECT created_at, details FROM activity_logs WHERE action = 'account_status_changed' ORDER BY created_at DESC, log_id DESC");
        while ($logResult && ($logRow = mysqli_fetch_assoc($logResult))) {
            $logDetails = json_decode((string) $logRow['details'], true);
            $logEmail = strtolower((string) ($logDetails['email'] ?? ''));
            if ($logEmail === '' || array_key_exists($logEmail, $deactivatedAt)) {
                continue;
            }
            $deactivatedAt[$logEmail] = ($logDetails['changes']['Status']['to'] ?? '') === 'Inactive' ? $logRow['created_at'] : null;
        }
    } catch (Throwable $logError) {
    }
    $shownAccounts = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $shownAccounts++;
        $isActive = $row['status'] === 'Active';
        $btnClass = $isActive ? 'deactivate-btn' : 'activate-btn';
        $terms = accountRoleTerms($row['role']);
        $btnLabel = $isActive ? $terms['off'] : $terms['on'];
        $safeRole = htmlspecialchars($row['role'], ENT_QUOTES);
        $safeEmail = htmlspecialchars($row['email'], ENT_QUOTES);
        $safeName = htmlspecialchars($row['name'], ENT_QUOTES);
        $safeStatus = htmlspecialchars($row['status'], ENT_QUOTES);
        $rowNameParts = preg_split('/[\s,]+/', trim((string) $row['name']), -1, PREG_SPLIT_NO_EMPTY);
        $rowInitials = htmlspecialchars(strtoupper(mb_substr($rowNameParts[0] ?? '', 0, 1) . mb_substr($rowNameParts[count($rowNameParts) - 1] ?? '', 0, 1)), ENT_QUOTES);
        $avatarClass = 'um-avatar-' . (abs(crc32($row['email'])) % 4);
        $statusClass = $isActive ? 'um-status-active' : 'um-status-inactive';
        $deactivatedNote = '';
        $rowEmailKey = strtolower($row['email']);
        if (!$isActive && !empty($deactivatedAt[$rowEmailKey])) {
            $deactivatedTs = (new DateTime($deactivatedAt[$rowEmailKey], new DateTimeZone('Asia/Manila')))->getTimestamp();
            $deactivatedNote = "<span class='um-status-note' data-note-label='" . htmlspecialchars($terms['note'], ENT_QUOTES) . "' data-deactivated-at='" . ($deactivatedTs * 1000) . "'>" . htmlspecialchars($terms['note'], ENT_QUOTES) . " on " . htmlspecialchars(date('M j, Y', $deactivatedTs), ENT_QUOTES) . "</span>";
        }

        echo "<tr>
        <td>
            <div class='um-name'>
                <span class='um-avatar {$avatarClass}'>{$rowInitials}</span>
                <span>{$safeName}</span>
            </div>
        </td>
        <td class='um-email'>{$safeEmail}</td>
        <td class='um-role'>{$safeRole}</td>
        <td><span class='um-status {$statusClass}'>{$safeStatus}</span></td>
        <td>
            <div class='um-actions'>{$deactivatedNote}
            " . ($isActive ? "<button type='button' class='reset-btn'
                data-bs-toggle='modal'
                data-bs-target='#resetPasswordModal'
                data-email='{$safeEmail}'
                data-name='{$safeName}'
                title='Reset password'>
                <i class='bi bi-key'></i> Reset password
            </button>" : "") . "
            <button class='$btnClass'
                data-bs-toggle='modal'
                data-bs-target='#confirmToggleModal'
                data-email='{$safeEmail}'
                data-status='{$safeStatus}'
                data-label='$btnLabel'>
                $btnLabel
            </button>
            </div>
        </td>
      </tr>";
    }
    if ($shownAccounts === 0) {
        echo "<tr><td colspan='5' class='um-empty'>No accounts found.</td></tr>";
    }
    ?>
</table>
<div class="um-table-footer">
    Showing <?= $shownAccounts ?> account<?= $shownAccounts === 1 ? '' : 's' ?>
</div>
</div>

</div>

<!-- add admin -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form name="signin" id="addAdminForm" method="POST" action="<?php echo htmlspecialchars($listUrl); ?>" novalidate>
      <input
          type="hidden"
          name="csrf_token"
          value="<?php echo $_SESSION['csrf_token']; ?>"
      >
      <div class="modal-header um-modal-header">
        <span class="um-modal-icon"><i class="bi bi-person-plus"></i></span>
        <div>
          <h5 class="modal-title" id="addStudentModalLabel">Add new user</h5>
          <p class="um-modal-subtitle">They'll receive an email with a temporary password to sign in.</p>
        </div>
      </div>
      <div class="modal-body">
            <div class="input-container">
            <div class="um-field">
                <label for="name" class="um-label">Full name <span class="req-mark">*</span></label>
                <div class="um-input">
                    <i class="bi bi-person um-input-icon"></i>
                    <input type="text" class="form-control<?= isset($nameError) ? ' is-invalid' : '' ?>" id="name" name="name" placeholder="e.g. Juan Dela Cruz" required
                           maxlength="100" autocomplete="off"
                           value="<?= isset($error) && isset($_POST['add_admin']) ? htmlspecialchars($_POST['name'] ?? '') : '' ?>">
                    <div class="invalid-feedback" id="nameFeedback"><?= isset($nameError) ? htmlspecialchars($nameError) : '' ?></div>
                </div>
            </div>

            <div class="um-field">
                <label for="email" class="um-label">Email address <span class="req-mark">*</span></label>
                <div class="um-input">
                    <i class="bi bi-envelope um-input-icon"></i>
                    <input type="email" class="form-control<?= isset($emailError) ? ' is-invalid' : '' ?>" id="email" name="email" placeholder="e.g. name@gmail.com" required
                           maxlength="150" autocomplete="off"
                           value="<?= isset($error) && isset($_POST['add_admin']) ? htmlspecialchars($_POST['email'] ?? '') : '' ?>">
                    <div class="invalid-feedback" id="emailFeedback"><?= isset($emailError) ? htmlspecialchars($emailError) : '' ?></div>
                </div>
            </div>

            <div class="um-field">
                <label for="role" class="um-label">Role <span class="req-mark">*</span></label>
                <div class="um-input">
                    <i class="bi bi-person-badge um-input-icon"></i>
                    <select class="form-control form-select<?= isset($roleError) ? ' is-invalid' : '' ?>" id="role" name="role" required>
                        <option value="">Choose a role</option>
                        <?php foreach (OFFICE_ROLES as $optionRole): ?>
                        <option value="<?= htmlspecialchars($optionRole) ?>" <?= (isset($error) && isset($_POST['add_admin']) && ($_POST['role'] ?? '') === $optionRole) ? 'selected' : '' ?>><?= htmlspecialchars($optionRole) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback" id="roleFeedback"><?= isset($roleError) ? htmlspecialchars($roleError) : '' ?></div>
                </div>
            </div>

            <?php if (isset($error) && isset($_POST['add_admin']) && !isset($nameError) && !isset($emailError) && !isset($roleError)): ?>
                <div class="alert alert-danger um-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            </div>
      </div>
      <div class="modal-footer um-modal-footer">
        <button type="button" class="btn um-cancel-btn" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="add_admin" class="btn btn-custom"><i class="bi bi-check-lg"></i> Create account</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Confirm Toggle Modal -->
<div class="modal fade" id="confirmToggleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">

      <div class="modal-body mb-3">
        <span class="um-confirm-icon"><i class="bi bi-person-gear"></i></span>
        Are you sure you want to change this account's status?
      </div>

      <form method="POST" action="<?php echo htmlspecialchars($listUrl); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="email" id="modalEmail">
        <input type="hidden" name="current_status" id="modalStatus">
        <input type="hidden" name="toggle_status" value="1">

        <div class="d-flex justify-content-center gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger" id="modalConfirmBtn">Confirm</button>
        </div>
      </form>

    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">

      <div class="modal-body mb-3">
        <span class="um-confirm-icon"><i class="bi bi-key"></i></span>
        <div class="um-reset-title">Reset password?</div>
        <p class="um-reset-text">
          A new temporary password will be emailed to <strong id="resetPasswordName"></strong>
          (<span id="resetPasswordEmail"></span>). They must set a new password on their next sign-in.
        </p>
      </div>

      <form method="POST" action="<?php echo htmlspecialchars($listUrl); ?>" id="resetPasswordForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="email" id="resetPasswordInput">
        <input type="hidden" name="reset_password" value="1">

        <div class="d-flex justify-content-center gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger" id="resetPasswordConfirm">Reset</button>
        </div>
      </form>

    </div>
  </div>
</div>

<!-- Toggle Error Modal -->
<div class="modal fade" id="toggleErrorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">
      <div class="modal-body mb-3">
        <span class="um-confirm-icon um-confirm-icon-danger"><i class="bi bi-exclamation-triangle"></i></span>
        <?= isset($toggleError) ? htmlspecialchars($toggleError) : '' ?>
      </div>
      <div class="d-flex justify-content-center">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<div id="jsFlags"
    data-show-add-modal="<?= (isset($error) && isset($_POST['add_admin'])) ? 'true' : 'false' ?>"
    data-show-toggle-error="<?= isset($toggleError) ? 'true' : 'false' ?>">
</div>
<script src="../js/admin_management.js?v=<?= @filemtime(__DIR__ . '/../js/admin_management.js') ?>"></script>
</body>
</html>