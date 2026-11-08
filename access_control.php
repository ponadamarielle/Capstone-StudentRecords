<?php
const OFFICE_ROLES = ['Admin', 'Staff', 'OJT Intern'];
const SHAREABLE_ROLES = ['Staff', 'OJT Intern'];

const ROLE_PERMISSIONS = [
    'Admin' => [
        'students.list', 'students.add', 'students.view', 'students.edit', 'students.fill_blank',
        'documents.view', 'documents.upload', 'documents.set_type', 'documents.replace', 'documents.delete',
        'students.status', 'archive.view', 'archive.restore', 'reports', 'access.manage',
    ],
    'Staff' => [
        'students.list', 'students.add', 'students.view', 'students.fill_blank',
        'documents.view', 'documents.upload', 'documents.set_type', 'documents.replace',
    ],
    'OJT Intern' => [
        'students.add', 'documents.upload',
    ],
];

function currentRole()
{
    return (string) ($_SESSION['role'] ?? '');
}

function isOfficeRole($role = null)
{
    return in_array($role ?? currentRole(), OFFICE_ROLES, true);
}

function can($permission, $role = null)
{
    $role = $role ?? currentRole();
    return in_array($permission, ROLE_PERMISSIONS[$role] ?? [], true);
}

function roleLabel($role)
{
    return (string) $role;
}

function requireOfficeLogin($isAjax = false)
{
    if (isset($_SESSION['name'], $_SESSION['role']) && isOfficeRole()) {
        return;
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please sign in again.']);
        exit();
    }
    header('Location: ../signin.php');
    exit();
}

function requirePermission($permission, $isAjax = false, $redirect = 'student_directory.php')
{
    if (can($permission)) {
        return;
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your role (' . roleLabel(currentRole()) . ') is not allowed to do this.']);
        exit();
    }
    header('Location: ' . $redirect);
    exit();
}

function ensureAccountRoles($conn)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $result = mysqli_query($conn, "SHOW COLUMNS FROM accounts LIKE 'role'");
        $column = $result ? mysqli_fetch_assoc($result) : null;
        if ($column && strpos(strtolower((string) $column['Type']), 'enum(') === 0
            && (stripos($column['Type'], "'Staff'") === false || stripos($column['Type'], "'OJT Intern'") === false)) {
            mysqli_query($conn, "ALTER TABLE accounts MODIFY role VARCHAR(20) NOT NULL");
        }
        mysqli_query($conn, "UPDATE accounts SET role = 'OJT Intern' WHERE role IN ('OJT', 'Intern')");
    } catch (Throwable $e) {
        error_log('Updating accounts.role failed: ' . $e->getMessage());
    }
}

function isSavedRecordFile($conn, $path)
{
    $real = realpath((string) $path);
    if ($real === false || !$conn) {
        return false;
    }
    $name = '%' . basename(str_replace('\\', '/', $real));
    foreach (["SELECT file_path FROM documents WHERE file_path LIKE ?", "SELECT photo_path FROM students WHERE photo_path LIKE ?"] as $sql) {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            continue;
        }
        mysqli_stmt_bind_param($stmt, 's', $name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($result && ($row = mysqli_fetch_row($result))) {
            $saved = realpath((string) $row[0]);
            if ($saved !== false && strcasecmp($saved, $real) === 0) {
                return true;
            }
        }
    }
    return false;
}