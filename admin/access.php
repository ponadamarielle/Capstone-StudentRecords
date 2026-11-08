<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../account_email.php';
require_once __DIR__ . '/../access_control.php';

$action = $_POST['action'] ?? '';
$isAjaxAction = $action !== '';

requireOfficeLogin($isAjaxAction);
require_once __DIR__ . '/../account_guard.php';
requireActiveAccount($isAjaxAction);

if (!$isAjaxAction && isset($_SESSION['is_first_login']) && $_SESSION['is_first_login'] == 1) {
    header('Location: ../change_password.php');
    exit();
}
requirePermission('access.manage', $isAjaxAction);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        if (getenv(trim($key)) === false) {
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer-7.0.1/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-7.0.1/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer-7.0.1/src/Exception.php';

$conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
if (!$conn) {
    if ($isAjaxAction) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Connection failed: ' . mysqli_connect_error()]);
        exit();
    }
    die('Connection failed: ' . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');
ensureAccountRoles($conn);

function accessPeople($conn)
{
    $people = [];
    $result = mysqli_query($conn, "SELECT id, name, email, role, status FROM accounts
        WHERE role IN ('Staff', 'OJT Intern')
        ORDER BY FIELD(status, 'Active', 'Inactive'), FIELD(role, 'Staff', 'OJT Intern'), name ASC");
    $me = strtolower((string) ($_SESSION['email'] ?? ''));
    $removedAt = [];
    try {
        $logResult = mysqli_query($conn, "SELECT created_at, details FROM activity_logs
            WHERE action = 'account_status_changed' ORDER BY created_at DESC, log_id DESC");
        while ($logResult && ($logRow = mysqli_fetch_assoc($logResult))) {
            $details = json_decode((string) $logRow['details'], true);
            $logEmail = strtolower((string) ($details['email'] ?? ''));
            if ($logEmail === '' || array_key_exists($logEmail, $removedAt)) {
                continue;
            }
            $removedAt[$logEmail] = ($details['changes']['Status']['to'] ?? '') === 'Inactive' ? $logRow['created_at'] : null;
        }
    } catch (Throwable $e) {
    }
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $emailKey = strtolower((string) $row['email']);
        $removedMs = null;
        if ($row['status'] !== 'Active' && !empty($removedAt[$emailKey])) {
            $removedMs = (new DateTime($removedAt[$emailKey], new DateTimeZone('Asia/Manila')))->getTimestamp() * 1000;
        }
        $parts = preg_split('/[\s,]+/', trim((string) $row['name']), -1, PREG_SPLIT_NO_EMPTY);
        $people[] = [
            'id'       => (int) $row['id'],
            'name'     => $row['name'],
            'email'    => $row['email'],
            'role'     => $row['role'],
            'status'   => $row['status'],
            'initials' => strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[count($parts) - 1] ?? '', 0, 1)),
            'avatar'   => abs(crc32($row['email'])) % 4,
            'you'      => strtolower($row['email']) === $me,
            'removed_at' => $removedMs,
        ];
    }
    return $people;
}

function accessTempPassword()
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pass = '';
    for ($i = 0; $i < 8; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

function accessSendEmail($email, $name, $password, $role, $reset)
{
    $mail = new PHPMailer(true);
    $refNumber = random_int(1000, 9999);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['GMAIL_USER'] ?? getenv('GMAIL_USER');
        $mail->Password = $_ENV['GMAIL_APP_PASSWORD'] ?? getenv('GMAIL_APP_PASSWORD');
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom($_ENV['GMAIL_USER'] ?? 'pupbc.superadmin@gmail.com', 'PUP eRecords');
        $mail->addAddress($email, $name);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = $reset
            ? "Your PUP eRecords password was reset [Ref: {$refNumber}]"
            : "Your PUP eRecords {$role} account [Ref: {$refNumber}]";
        [$mail->Body, $mail->AltBody] = buildTemporaryPasswordEmail($name, $email, $password, $refNumber, $reset, $role, 'Admin');
        $mail->send();
        return [true, '', $refNumber];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo, $refNumber];
    }
}

function accessTarget($conn, $id, $status = 'Active')
{
    $stmt = mysqli_prepare($conn, "SELECT id, name, email, role, status, pass, is_first_login FROM accounts WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row || !in_array($row['role'], SHAREABLE_ROLES, true) || $row['status'] !== $status) {
        return null;
    }
    return $row;
}

function accessReply($conn, $success, $message, array $extra = [])
{
    echo json_encode(['success' => $success, 'message' => $message, 'people' => accessPeople($conn)] + $extra);
    exit();
}

if ($isAjaxAction) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please reload the page and try again.']);
        exit();
    }
    @set_time_limit(60);

    if ($action === 'add') {
        $name  = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role  = (string) ($_POST['role'] ?? '');
        if (!in_array($role, SHAREABLE_ROLES, true)) {
            accessReply($conn, false, 'Choose Staff or OJT Intern.');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !preg_match('/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', $email)) {
            accessReply($conn, false, 'Please enter a valid email address (e.g. name@gmail.com).', ['field' => 'email']);
        }
        if ($name === '' || !preg_match("/^[\p{L}][\p{L} .'\-]*$/u", $name) || mb_strlen($name) > 100) {
            accessReply($conn, false, "Name can only contain letters (including ñ), spaces, hyphens (-), periods (.) and apostrophes (').", ['field' => 'name']);
        }

        $stmt = mysqli_prepare($conn, "SELECT id, name, role, status, pass, is_first_login FROM accounts WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($existing && !in_array($existing['role'], SHAREABLE_ROLES, true)) {
            accessReply($conn, false, 'This email belongs to ' . ($existing['role'] === 'Admin' ? 'an Admin' : 'the ' . $existing['role']) . ' account.', ['field' => 'email']);
        }
        if ($existing && $existing['status'] === 'Active') {
            accessReply($conn, false, 'This email is already associated with an existing account.', ['field' => 'email']);
        }

        $password = accessTempPassword();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($existing) {
            $id = (int) $existing['id'];
            $update = mysqli_prepare($conn, "UPDATE accounts SET name = ?, role = ?, status = 'Active', pass = ?, is_first_login = 1 WHERE id = ?");
            mysqli_stmt_bind_param($update, 'sssi', $name, $role, $hash, $id);
            mysqli_stmt_execute($update);
        } else {
            $status = 'Active';
            $insert = mysqli_prepare($conn, "INSERT INTO accounts (name, email, pass, role, status, is_first_login) VALUES (?, ?, ?, ?, ?, 1)");
            mysqli_stmt_bind_param($insert, 'sssss', $name, $email, $hash, $role, $status);
            if (!mysqli_stmt_execute($insert)) {
                accessReply($conn, false, 'Could not create the account: ' . mysqli_error($conn));
            }
            $id = (int) mysqli_insert_id($conn);
        }

        [$sent, $mailError, $ref] = accessSendEmail($email, $name, $password, $role, false);
        if (!$sent) {
            if ($existing) {
                $undo = mysqli_prepare($conn, "UPDATE accounts SET name = ?, role = ?, status = ?, pass = ?, is_first_login = ? WHERE id = ?");
                mysqli_stmt_bind_param($undo, 'ssssii', $existing['name'], $existing['role'], $existing['status'], $existing['pass'], $existing['is_first_login'], $id);
                mysqli_stmt_execute($undo);
            } else {
                $undo = mysqli_prepare($conn, "DELETE FROM accounts WHERE id = ?");
                mysqli_stmt_bind_param($undo, 'i', $id);
                mysqli_stmt_execute($undo);
            }
            logActivity('email_failed', 'Could not send the ' . $role . ' account email to ' . $name . ' (' . $email . ')', [
                'category' => 'audit', 'target_type' => 'account', 'target_id' => $id,
                'details'  => ['recipient' => $email, 'error' => $mailError, 'effect' => 'Access was not given.'],
            ]);
            accessReply($conn, false, 'Access was not given because the email could not be sent. Check the internet connection and try again.');
        }

        logActivity($existing ? 'account_status_changed' : 'account_created',
            ($existing ? 'Gave access again to ' : 'Gave access to ') . $name . ' (' . $email . ') as ' . $role, [
            'target_type' => 'account', 'target_id' => $id,
            'details'     => ['name' => $name, 'email' => $email, 'role' => $role, 'status' => 'Active']
                + ($existing ? ['changes' => ['Status' => ['from' => $existing['status'], 'to' => 'Active']]] : []),
        ]);
        logActivity('email_sent', 'Sent the temporary password email to ' . $name . ' (' . $email . ')', [
            'category' => 'audit', 'target_type' => 'account', 'target_id' => $id,
            'details'  => ['notification' => $role . ' account temporary password', 'recipient' => $email, 'reference' => $ref],
        ]);
        accessReply($conn, true, $name . ' can now sign in as ' . $role . '. A temporary password was emailed to ' . $email . '.');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $target = $id > 0 ? accessTarget($conn, $id, $action === 'activate' ? 'Inactive' : 'Active') : null;
    if (!$target) {
        accessReply($conn, false, 'This person was not found or no longer has access.');
    }

    if ($action === 'activate') {
        $stmt = mysqli_prepare($conn, "UPDATE accounts SET status = 'Active' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        logActivity('account_status_changed', 'Restored the access of ' . $target['role'] . ' account ' . $target['name'] . ' (' . $target['email'] . ')', [
            'target_type' => 'account', 'target_id' => $id,
            'details'     => ['email' => $target['email'], 'role' => $target['role'],
                              'changes' => ['Status' => ['from' => 'Inactive', 'to' => 'Active']],
                              'effect' => 'They can sign in again.'],
        ]);
        accessReply($conn, true, $target['name'] . ' has access again and can sign in.');
    }

    if ($action === 'remove') {
        $stmt = mysqli_prepare($conn, "UPDATE accounts SET status = 'Inactive' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        logActivity('account_status_changed', 'Removed the access of ' . $target['role'] . ' account ' . $target['name'] . ' (' . $target['email'] . ')', [
            'target_type' => 'account', 'target_id' => $id,
            'details'     => ['email' => $target['email'], 'role' => $target['role'],
                              'changes' => ['Status' => ['from' => 'Active', 'to' => 'Inactive']],
                              'effect' => 'They are signed out and can no longer sign in until the account is activated again.'],
        ]);
        accessReply($conn, true, $target['name'] . ' no longer has access and cannot sign in.');
    }

    if ($action === 'reset') {
        $password = accessTempPassword();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE accounts SET pass = ?, is_first_login = 1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $hash, $id);
        mysqli_stmt_execute($stmt);
        [$sent, $mailError, $ref] = accessSendEmail($target['email'], $target['name'], $password, $target['role'], true);
        if (!$sent) {
            $undo = mysqli_prepare($conn, "UPDATE accounts SET pass = ?, is_first_login = ? WHERE id = ?");
            mysqli_stmt_bind_param($undo, 'sii', $target['pass'], $target['is_first_login'], $id);
            mysqli_stmt_execute($undo);
            accessReply($conn, false, 'The password was not reset because the email could not be sent. Check the internet connection and try again.');
        }
        try {
            $lower = strtolower($target['email']);
            $unlock = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE email = ?");
            mysqli_stmt_bind_param($unlock, 's', $lower);
            mysqli_stmt_execute($unlock);
        } catch (Throwable $ignored) {
        }
        logActivity('password_reset', 'Reset the password of ' . $target['role'] . ' account ' . $target['name'] . ' (' . $target['email'] . ')', [
            'target_type' => 'account', 'target_id' => $id,
            'details'     => ['email' => $target['email'], 'reference' => $ref,
                              'effect' => 'A temporary password was emailed. They must set a new password on the next sign-in.'],
        ]);
        accessReply($conn, true, 'Password reset. A temporary password was emailed to ' . $target['email'] . '.');
    }

    accessReply($conn, false, 'Invalid action.');
}

$people = accessPeople($conn);
include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-admin.css" rel="stylesheet">
    <link href="../css/student-directory-admin.css?v=<?= @filemtime(__DIR__ . '/../css/student-directory-admin.css') ?>" rel="stylesheet">
    <link href="../css/admin_management.css?v=<?= @filemtime(__DIR__ . '/../css/admin_management.css') ?>" rel="stylesheet">
    <link href="../css/access.css?v=<?= @filemtime(__DIR__ . '/../css/access.css') ?>" rel="stylesheet">
</head>
<body>
<div class="main-container access-page">
    <div class="filter-add">
        <div class="access-filters">
            <div class="filter-tabs" role="tablist" aria-label="Status">
                <button type="button" class="filter-tab active" data-filter="all">All</button>
                <button type="button" class="filter-tab" data-filter="Active">Active</button>
                <button type="button" class="filter-tab" data-filter="Inactive">Inactive</button>
            </div>
            <div class="filter-tabs" role="tablist" aria-label="Role">
                <button type="button" class="filter-tab active" data-role-filter="all">All roles</button>
                <?php foreach (SHAREABLE_ROLES as $filterRole): ?>
                    <button type="button" class="filter-tab" data-role-filter="<?= htmlspecialchars($filterRole) ?>"><?= htmlspecialchars($filterRole) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <button class="btn-add" type="button" data-bs-toggle="modal" data-bs-target="#giveAccessModal">
            <i class="bi bi-person-plus"></i>ADD USER
        </button>
    </div>

    <div id="accessFlash" class="um-flash" role="status" hidden></div>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="accessRows"></tbody>
        </table>
        <div class="um-table-footer" id="accessCount"></div>
    </div>
</div>

<div class="modal fade" id="giveAccessModal" tabindex="-1" aria-labelledby="giveAccessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="giveAccessForm" novalidate autocomplete="off">
                <div class="modal-header um-modal-header">
                    <span class="um-modal-icon"><i class="bi bi-person-plus"></i></span>
                    <div>
                        <h5 class="modal-title" id="giveAccessModalLabel">Add new user</h5>
                        <p class="um-modal-subtitle">They'll receive an email with a temporary password to sign in.</p>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="input-container">
                        <div class="um-field">
                            <label for="accessName" class="um-label">Full name <span class="req-mark">*</span></label>
                            <div class="um-input">
                                <i class="bi bi-person um-input-icon"></i>
                                <input type="text" class="form-control" id="accessName" placeholder="e.g. Juan Dela Cruz" maxlength="100" autocomplete="off">
                                <div class="invalid-feedback" id="accessNameFeedback"></div>
                            </div>
                        </div>

                        <div class="um-field">
                            <label for="accessEmail" class="um-label">Email address <span class="req-mark">*</span></label>
                            <div class="um-input">
                                <i class="bi bi-envelope um-input-icon"></i>
                                <input type="email" class="form-control" id="accessEmail" placeholder="e.g. name@gmail.com" maxlength="150" autocomplete="off">
                                <div class="invalid-feedback" id="accessEmailFeedback"></div>
                            </div>
                        </div>

                        <div class="um-field">
                            <label for="accessRole" class="um-label">Role <span class="req-mark">*</span></label>
                            <div class="um-input">
                                <i class="bi bi-person-badge um-input-icon"></i>
                                <select class="form-control access-add-role" id="accessRole">
                                    <?php foreach (SHAREABLE_ROLES as $shareRole): ?>
                                        <option value="<?= htmlspecialchars($shareRole) ?>"><?= htmlspecialchars($shareRole) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="alert alert-danger um-alert" id="giveAccessError" hidden></div>
                    </div>
                </div>
                <div class="modal-footer um-modal-footer">
                    <button type="button" class="btn um-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-custom" id="giveAccessBtn"><i class="bi bi-check-lg"></i> Create account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="accessConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header confirm-header">
                <div class="add-heading">
                    <span class="add-heading-icon"><i class="bi" id="accessConfirmIcon"></i></span>
                    <div class="add-heading-text">
                        <h5 class="modal-title confirm-title" id="accessConfirmTitle"></h5>
                    </div>
                </div>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="accessConfirmText"></p>
            </div>
            <div class="modal-footer confirm-footer">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-save" id="accessConfirmBtn"></button>
            </div>
        </div>
    </div>
</div>

<div id="accessPageData" hidden data-json="<?= htmlspecialchars(json_encode([
    'people' => $people,
    'csrf'   => $_SESSION['csrf_token'],
]), ENT_QUOTES, 'UTF-8') ?>"></div>
<script src="../js/access.js?v=<?= @filemtime(__DIR__ . '/../js/access.js') ?>"></script>
</body>
</html>