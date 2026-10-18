<!DOCTYPE html>
<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../account_email.php';

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

    $role = "Admin";
    $status = "Active";

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

    if (isset($nameError) || isset($emailError)) {
        $error = $nameError ?? $emailError;
    } elseif ($checkStmt->num_rows > 0) {
        $error = "This email is already associated with an existing account.";
    } else {
        $insertStmt = $conn->prepare("INSERT INTO accounts (name, email, pass, role, status) VALUES (?, ?, ?, ?, ?)");
        $insertStmt->bind_param("sssss", $name, $email, $hashedPass, $role, $status);

        if ($insertStmt->execute()) {
            $success = true;
            $newAccountId = $insertStmt->insert_id;
            logActivity('account_created', 'Created Admin account of ' . $name . ' (' . $email . ')', [
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
                $mail->Subject = "Your PUP eRecords Admin account [Ref: {$refNumber}]";
                [$mail->Body, $mail->AltBody] = buildTemporaryPasswordEmail($name, $email, $pass, $refNumber);

                $mail->send();
                logActivity('email_sent', 'Sent the temporary password email to ' . $name . ' (' . $email . ')', [
                    'category'    => 'audit',
                    'target_type' => 'account',
                    'target_id'   => $newAccountId,
                    'details'     => ['notification' => 'Admin account temporary password', 'recipient' => $email, 'reference' => $refNumber],
                ]);
            } catch (Exception $e) {
                $error = "Email could not be sent: {$mail->ErrorInfo}";
                logActivity('email_failed', 'Could not send the temporary password email to ' . $name . ' (' . $email . ')', [
                    'category'    => 'audit',
                    'target_type' => 'account',
                    'target_id'   => $newAccountId,
                    'details'     => ['notification' => 'Admin account temporary password', 'recipient' => $email, 'error' => $mail->ErrorInfo],
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

    if ($currentStatus === 'Active') {
        $countSql = "SELECT COUNT(*) as cnt FROM accounts WHERE role='Admin' AND status='Active'";
        $countResult = mysqli_query($conn, $countSql);
        $countRow = mysqli_fetch_assoc($countResult);

        if ($countRow['cnt'] <= 1) {
            $toggleError = "Cannot deactivate the last active Admin account.";
        } else {
            $stmt = $conn->prepare("UPDATE accounts SET status=? WHERE email=? AND role='Admin'");
            $stmt->bind_param("ss", $newStatus, $email);
            $stmt->execute();
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("UPDATE accounts SET status=? WHERE email=? AND role='Admin'");
        $stmt->bind_param("ss", $newStatus, $email);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
    }

    if (!empty($changed)) {
        $nameStmt = $conn->prepare("SELECT name FROM accounts WHERE email=? LIMIT 1");
        $nameStmt->bind_param("s", $email);
        $nameStmt->execute();
        $accountName = $nameStmt->get_result()->fetch_assoc()['name'] ?? $email;
        $nameStmt->close();
        logActivity('account_status_changed',
            ($newStatus === 'Active' ? 'Activated' : 'Deactivated') . ' Admin account of ' . $accountName . ' (' . $email . ')',
            [
                'target_type' => 'account',
                'details'     => [
                    'email'   => $email,
                    'changes' => ['Status' => ['from' => $currentStatus, 'to' => $newStatus]],
                    'effect'  => $newStatus === 'Active' ? 'The Admin can sign in again.' : 'The Admin is signed out on their next page load and can no longer sign in.',
                ],
            ]);
    }
}
?>

<html>
<head>
    <title>User Management</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-superadmin.css" rel="stylesheet">
    <link href="../css/user_management.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="d-flex justify-content-between align-items-center filter-add">
    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <button class="filter-tab <?= (!isset($_GET['status']) || $_GET['status'] == 'all') ? 'active' : '' ?>"
            data-filter="all">All</button>
        <button class="filter-tab <?= (isset($_GET['status']) && $_GET['status'] == 'active') ? 'active' : '' ?>"
            data-filter="active">Active</button>
        <button class="filter-tab <?= (isset($_GET['status']) && $_GET['status'] == 'inactive') ? 'active' : '' ?>"
            data-filter="inactive">Inactive</button>
    </div>

    <!-- ADD Button -->
    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addStudentModal">
        <i class="bi bi-plus-circle"></i>ADD
    </button>
</div>

<!-- table -->
<table class="admin-table">
    <tr>
        <th>Name</th>
        <th>Email Address</th>
        <th>Status</th>
        <th>Actions</th>
    </tr>

    <?php
    $filter = $_GET['status'] ?? 'all';
    $where = ($filter === 'active') ? "AND status='Active'" : (($filter === 'inactive') ? "AND status='Inactive'" : "");

    $result = mysqli_query($conn, "SELECT name, email, status FROM accounts WHERE role='Admin' $where");
    while ($row = mysqli_fetch_assoc($result)) {
        $isActive = $row['status'] === 'Active';
        $btnClass = $isActive ? 'deactivate-btn' : 'activate-btn';
        $btnLabel = $isActive ? 'Deactivate' : 'Activate';
        $safeEmail = htmlspecialchars($row['email'], ENT_QUOTES);
        $safeName = htmlspecialchars($row['name'], ENT_QUOTES);
        $safeStatus = htmlspecialchars($row['status'], ENT_QUOTES);

        echo "<tr>
        <td>{$safeName}</td>
        <td>{$safeEmail}</td>
        <td>{$safeStatus}</td>
        <td>
            <button class='$btnClass'
                data-bs-toggle='modal'
                data-bs-target='#confirmToggleModal'
                data-email='{$safeEmail}'
                data-status='{$safeStatus}'
                data-label='$btnLabel'>
                $btnLabel
            </button>
        </td>
      </tr>";
    }
    ?>
</table>

<!-- add admin -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addStudentModalLabel">Add new admin</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <form name="signin" id="addAdminForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" novalidate>
        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo $_SESSION['csrf_token']; ?>"
        >
            <div class="input-container">
            <div class="form-floating mb-3">
                <input type="text" class="form-control<?= isset($nameError) ? ' is-invalid' : '' ?>" id="name" name="name" placeholder="name" required
                       maxlength="100" autocomplete="off"
                       value="<?= isset($error) && isset($_POST['add_admin']) ? htmlspecialchars($_POST['name'] ?? '') : '' ?>">
                <label for="name">Name</label>
                <div class="invalid-feedback" id="nameFeedback"><?= isset($nameError) ? htmlspecialchars($nameError) : '' ?></div>
            </div>

            <div class="form-floating mb-3">
                <input type="email" class="form-control<?= isset($emailError) ? ' is-invalid' : '' ?>" id="email" name="email" placeholder="email" required
                       maxlength="150" autocomplete="off"
                       value="<?= isset($error) && isset($_POST['add_admin']) ? htmlspecialchars($_POST['email'] ?? '') : '' ?>">
                <label for="email">Email Address</label>
                <div class="invalid-feedback" id="emailFeedback"><?= isset($emailError) ? htmlspecialchars($emailError) : '' ?></div>
            </div>

            <?php if (isset($error) && isset($_POST['add_admin']) && !isset($nameError) && !isset($emailError)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <button type="submit" name="add_admin" class="btn btn-custom w-100 mt-3">Create Account</button>
            </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Confirm Toggle Modal -->
<div class="modal fade" id="confirmToggleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">

      <div class="modal-body mb-3">
        Are you sure you want to change this account's status?
      </div>

      <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . (isset($_GET['status']) ? '?status=' . htmlspecialchars($_GET['status']) : ''); ?>">
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

<!-- Toggle Error Modal -->
<div class="modal fade" id="toggleErrorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">
      <div class="modal-body mb-3">
        <i class="bi bi-exclamation-triangle-fill text-danger fs-3 mb-2 d-block"></i>
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
<script src="../js/user_management.js"></script>
</body>
</html>