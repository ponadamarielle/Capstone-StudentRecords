<?php
include '../security_headers.php';

require_once __DIR__ . '/course_catalog.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isAjax = in_array($action, ['archive_student', 'restore_student'], true);

function loadEnv($path)
{
    if (!file_exists($path)) {
        error_log('.env file not found at ' . $path . ' — falling back to defaults.');
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

loadEnv(__DIR__ . '/../.env');

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Connection failed: ' . mysqli_connect_error()]);
        exit();
    }
    die("Connection failed: " . mysqli_connect_error());
}

class GoogleDriveException extends Exception {}

const GDRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';
const GDRIVE_API         = 'https://www.googleapis.com/drive/v3';
const GDRIVE_UPLOAD_API  = 'https://www.googleapis.com/upload/drive/v3';
const GDRIVE_ROOT_NAME   = 'Student Records';
const GDRIVE_SCOPE       = 'https://www.googleapis.com/auth/drive';

function gdriveEnv($key, $fallback = '')
{
    $value = getenv($key);
    if ($value === false) {
        return $fallback;
    }
    $value = trim($value, " \t\"'");
    return $value === '' ? $fallback : $value;
}

function gdriveClientId()
{
    return gdriveEnv('GOOGLE_DRIVE_CLIENT_ID', gdriveEnv('GOOGLE_OAUTH_CLIENT_ID', gdriveEnv('GOOGLE_CLIENT_ID')));
}

function gdriveClientSecret()
{
    return gdriveEnv('GOOGLE_DRIVE_CLIENT_SECRET', gdriveEnv('GOOGLE_OAUTH_CLIENT_SECRET', gdriveEnv('GOOGLE_CLIENT_SECRET')));
}

function gdriveIsConfigured()
{
    return gdriveClientId() !== ''
        && gdriveClientSecret() !== ''
        && gdriveEnv('GOOGLE_REFRESH_TOKEN') !== '';
}

function gdriveHttp($method, $url, array $headers = [], $body = null)
{
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new GoogleDriveException('Could not reach Google: ' . $error);
    }
    return [$status, $response, $responseHeaders];
}

function gdriveAccessToken()
{
    static $cache = null;
    if ($cache !== null && $cache['expires'] > time() + 60) {
        return $cache['token'];
    }

    if (!gdriveIsConfigured()) {
        throw new GoogleDriveException(
            'Google Drive is not connected yet. Open the Archive page and click "Connect Google Drive".'
        );
    }

    [$status, $body] = gdriveHttp(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id'     => gdriveClientId(),
            'client_secret' => gdriveClientSecret(),
            'refresh_token' => gdriveEnv('GOOGLE_REFRESH_TOKEN'),
            'grant_type'    => 'refresh_token',
        ])
    );

    $data = json_decode($body, true);
    if ($status !== 200 || empty($data['access_token'])) {
        $message = $data['error_description'] ?? ($data['error'] ?? ('HTTP ' . $status));
        if (($data['error'] ?? '') === 'invalid_grant') {
            $message .= ' (the refresh token expired or was revoked; reconnect Google Drive on the Archive page)';
        }
        throw new GoogleDriveException('Google sign-in failed: ' . $message);
    }

    $cache = [
        'token'   => $data['access_token'],
        'expires' => time() + (int) ($data['expires_in'] ?? 3600),
    ];
    return $cache['token'];
}

function gdriveApi($method, $url, $payload = null)
{
    $headers = ['Authorization: Bearer ' . gdriveAccessToken()];
    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json; charset=UTF-8';
        $body = json_encode($payload);
    }

    [$status, $response] = gdriveHttp($method, $url, $headers, $body);
    $data = json_decode($response, true);

    if ($status >= 400) {
        $message = $data['error']['message'] ?? ('HTTP ' . $status);
        if ($status === 403 && stripos($message, 'insufficient') !== false) {
            $message .= ' (the saved token has no Drive permission; reconnect Google Drive on the Archive page)';
        }
        throw new GoogleDriveException('Google Drive error: ' . $message, $status);
    }
    return is_array($data) ? $data : [];
}

function gdriveEscapeQuery($value)
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
}

function gdriveFolderName($value, $fallback)
{
    $value = trim(preg_replace('/\s+/', ' ', (string) $value));
    return $value === '' ? $fallback : $value;
}

function gdriveFolderUrl($folderId)
{
    return 'https://drive.google.com/drive/folders/' . $folderId;
}

function gdriveFindOrCreateFolder($name, $parentId)
{
    $query = sprintf(
        "name = '%s' and '%s' in parents and mimeType = '%s' and trashed = false",
        gdriveEscapeQuery($name),
        gdriveEscapeQuery($parentId),
        GDRIVE_FOLDER_MIME
    );

    $found = gdriveApi('GET', GDRIVE_API . '/files?' . http_build_query([
        'q'                         => $query,
        'fields'                    => 'files(id,name)',
        'pageSize'                  => 1,
        'supportsAllDrives'         => 'true',
        'includeItemsFromAllDrives' => 'true',
    ]));

    if (!empty($found['files'][0]['id'])) {
        return $found['files'][0]['id'];
    }

    $created = gdriveApi('POST', GDRIVE_API . '/files?fields=id&supportsAllDrives=true', [
        'name'     => $name,
        'mimeType' => GDRIVE_FOLDER_MIME,
        'parents'  => [$parentId],
    ]);

    if (empty($created['id'])) {
        throw new GoogleDriveException('Google Drive did not return an ID for folder "' . $name . '".');
    }
    return $created['id'];
}

function gdriveRootFolderId()
{
    static $rootId = null;
    if ($rootId === null) {
        $configured = gdriveEnv('GOOGLE_DRIVE_FOLDER_ID');
        $rootId = $configured !== '' ? $configured : gdriveFindOrCreateFolder(GDRIVE_ROOT_NAME, 'root');
    }
    return $rootId;
}

function gdriveSendFile($localPath, $fileName, $parentId, $existingFileId = null)
{
    $size = filesize($localPath);
    $mime = function_exists('mime_content_type') ? (mime_content_type($localPath) ?: 'application/octet-stream') : 'application/octet-stream';

    if ($existingFileId === null) {
        $method   = 'POST';
        $url      = GDRIVE_UPLOAD_API . '/files?uploadType=resumable&supportsAllDrives=true&fields=id,webViewLink';
        $metadata = ['name' => $fileName, 'parents' => [$parentId]];
    } else {
        $method   = 'PATCH';
        $url      = GDRIVE_UPLOAD_API . '/files/' . rawurlencode($existingFileId)
                  . '?uploadType=resumable&supportsAllDrives=true&fields=id,webViewLink';
        $metadata = ['name' => $fileName];
    }

    [$status, $response, $headers] = gdriveHttp(
        $method,
        $url,
        [
            'Authorization: Bearer ' . gdriveAccessToken(),
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mime,
            'X-Upload-Content-Length: ' . $size,
        ],
        json_encode($metadata)
    );

    if ($status !== 200 || empty($headers['location'])) {
        $data = json_decode($response, true);
        $message = $data['error']['message'] ?? ('HTTP ' . $status);
        throw new GoogleDriveException('Could not start upload of "' . $fileName . '": ' . $message, $status);
    }

    $contents = file_get_contents($localPath);
    if ($contents === false) {
        throw new GoogleDriveException('Could not read "' . $fileName . '" from the server.');
    }

    [$status, $response] = gdriveHttp(
        'PUT',
        $headers['location'],
        ['Content-Type: ' . $mime, 'Content-Length: ' . strlen($contents)],
        $contents
    );

    $data = json_decode($response, true);
    if (($status !== 200 && $status !== 201) || empty($data['id'])) {
        $message = $data['error']['message'] ?? ('HTTP ' . $status);
        throw new GoogleDriveException('Upload of "' . $fileName . '" failed: ' . $message, $status);
    }

    return [
        'id'  => $data['id'],
        'url' => $data['webViewLink'] ?? ('https://drive.google.com/file/d/' . $data['id'] . '/view'),
    ];
}

function gdriveUploadFile($localPath, $fileName, $parentId)
{
    return gdriveSendFile($localPath, $fileName, $parentId, null);
}

function driveRedirectUri()
{
    $configured = gdriveEnv('GOOGLE_DRIVE_REDIRECT_URI');
    if ($configured !== '') {
        return $configured;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
}

function setArchiveFlash($type, $message, $token = '')
{
    $_SESSION['archive_flash'] = ['type' => $type, 'message' => $message, 'token' => $token];
}

function driveBase64Url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function driveMakeState()
{
    $payload = driveBase64Url(json_encode(['t' => time(), 'n' => bin2hex(random_bytes(8))]));
    return $payload . '.' . hash_hmac('sha256', $payload, gdriveClientSecret());
}

function driveStateIsValid($state)
{
    [$payload, $signature] = array_pad(explode('.', (string) $state, 2), 2, '');
    if ($payload === '' || !hash_equals(hash_hmac('sha256', $payload, gdriveClientSecret()), $signature)) {
        return false;
    }
    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $issued = (int) ($data['t'] ?? 0);
    return $issued > 0 && time() - $issued <= 900;
}

function saveRefreshTokenToEnv($token)
{
    $path  = __DIR__ . '/../.env';
    $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) {
        return false;
    }

    $found = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*GOOGLE_REFRESH_TOKEN\s*=/', $line)) {
            $lines[$i] = 'GOOGLE_REFRESH_TOKEN=' . $token;
            $found = true;
        }
    }
    if (!$found) {
        $lines[] = 'GOOGLE_REFRESH_TOKEN=' . $token;
    }

    return file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) !== false;
}

function startDriveAuth()
{
    if (gdriveClientId() === '' || gdriveClientSecret() === '') {
        setArchiveFlash('danger', 'GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET must be set in .env before connecting Google Drive.');
        header('Location: archive.php');
        exit();
    }

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => gdriveClientId(),
        'redirect_uri'  => driveRedirectUri(),
        'response_type' => 'code',
        'scope'         => GDRIVE_SCOPE,
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => driveMakeState(),
    ]));
    exit();
}

function handleDriveAuthCallback()
{
    $ok      = false;
    $message = '';
    $token   = '';

    if (!driveStateIsValid($_GET['state'] ?? '')) {
        $message = 'This Google Drive connection link expired or is invalid. Please try again from the Archive page.';
    } elseif (isset($_GET['error'])) {
        $message = 'Google returned an error: ' . $_GET['error'];
    } else {
        try {
            [$status, $body] = gdriveHttp(
                'POST',
                'https://oauth2.googleapis.com/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query([
                    'code'          => $_GET['code'] ?? '',
                    'client_id'     => gdriveClientId(),
                    'client_secret' => gdriveClientSecret(),
                    'redirect_uri'  => driveRedirectUri(),
                    'grant_type'    => 'authorization_code',
                ])
            );
            $data = json_decode($body, true);

            if ($status !== 200 || empty($data['refresh_token'])) {
                $message = 'Could not get a refresh token: '
                    . ($data['error_description'] ?? ($data['error'] ?? ('HTTP ' . $status)));
            } elseif (saveRefreshTokenToEnv($data['refresh_token'])) {
                $ok      = true;
                $message = 'Google Drive is connected. The token was saved to your .env file.';
            } else {
                $ok      = true;
                $token   = $data['refresh_token'];
                $message = 'Google Drive is connected, but .env could not be updated automatically. Add this line to .env, then restart Apache:';
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
        }
    }

    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Google Drive</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/archive-admin.css" rel="stylesheet">
</head>
<body class="archive-connect-page">
    <div class="archive-connect-card">
        <i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?> archive-connect-icon"></i>
        <h5><?= $ok ? 'Google Drive connected' : 'Could not connect Google Drive' ?></h5>
        <p><?= htmlspecialchars($message) ?></p>
        <?php if ($token !== ''): ?>
            <pre class="archive-token">GOOGLE_REFRESH_TOKEN=<?= htmlspecialchars($token) ?></pre>
        <?php endif; ?>
        <a href="archive.php" class="btn archive-connect-btn">Go to Archive</a>
    </div>
</body>
</html>
    <?php
}

function studentColumns($conn)
{
    static $names = null;
    if ($names === null) {
        $names = [];
        $result = mysqli_query($conn, "SHOW COLUMNS FROM students");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $names[] = $row['Field'];
            }
        }
    }
    return $names;
}

function studentDriveFolderColumn($conn)
{
    foreach (['drive_folder_link', 'drive_folder_url', 'drive_link'] as $candidate) {
        if (in_array($candidate, studentColumns($conn), true)) {
            return $candidate;
        }
    }
    return null;
}

function studentHasArchivedAt($conn)
{
    return in_array('archived_at', studentColumns($conn), true);
}

function archiveStudentToDrive($conn, $studentId)
{
    $stmt = mysqli_prepare($conn,
        "SELECT student_id, student_number, name, course, year_section FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$student) {
        throw new GoogleDriveException('Student not found.');
    }

    $rootId          = gdriveRootFolderId();
    $courseFolderId  = gdriveFindOrCreateFolder(gdriveFolderName($student['course'], 'No Course'), $rootId);
    $sectionFolderId = gdriveFindOrCreateFolder(gdriveFolderName($student['year_section'], 'No Year & Section'), $courseFolderId);

    $studentLabel    = trim(($student['student_number'] ?? '') . ' - ' . ($student['name'] ?? ''), " -");
    $studentFolderId = gdriveFindOrCreateFolder(gdriveFolderName($studentLabel, 'Student ' . $studentId), $sectionFolderId);
    $folderUrl       = gdriveFolderUrl($studentFolderId);

    $folderColumn = studentDriveFolderColumn($conn);
    if ($folderColumn !== null) {
        $saveStmt = mysqli_prepare($conn, "UPDATE students SET `$folderColumn` = ? WHERE student_id = ?");
        mysqli_stmt_bind_param($saveStmt, "si", $folderUrl, $studentId);
        mysqli_stmt_execute($saveStmt);
    }

    $docsStmt = mysqli_prepare($conn,
        "SELECT document_id, file_name, file_path, drive_file_id FROM documents
         WHERE student_id = ?
           AND (drive_file_id IS NULL OR drive_file_id = '' OR drive_link IS NULL OR drive_link = '')
         ORDER BY document_id ASC");
    mysqli_stmt_bind_param($docsStmt, "i", $studentId);
    mysqli_stmt_execute($docsStmt);
    $documents = mysqli_fetch_all(mysqli_stmt_get_result($docsStmt), MYSQLI_ASSOC);

    $markStmt = mysqli_prepare($conn,
        "UPDATE documents
         SET is_archived = 1, drive_file_id = ?, drive_link = ?, archived_at = NOW()
         WHERE document_id = ?");

    $uploaded = 0;
    $skipped  = [];
    foreach ($documents as $doc) {
        $path = $doc['file_path'] ?? '';
        $name = ($doc['file_name'] ?? '') !== '' ? $doc['file_name'] : basename($path);

        if ($path === '' || !is_file($path)) {
            $skipped[] = $name;
            continue;
        }

        $oldFileId = (string) ($doc['drive_file_id'] ?? '');
        if ($oldFileId !== '') {
            try {
                $file = gdriveSendFile($path, $name, $studentFolderId, $oldFileId);
            } catch (GoogleDriveException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
                $file = gdriveUploadFile($path, $name, $studentFolderId);
            }
        } else {
            $file = gdriveUploadFile($path, $name, $studentFolderId);
        }

        $documentId = (int) $doc['document_id'];
        mysqli_stmt_bind_param($markStmt, "ssi", $file['id'], $file['url'], $documentId);
        mysqli_stmt_execute($markStmt);
        $uploaded++;
    }

    $remarkStmt = mysqli_prepare($conn,
        "UPDATE documents SET is_archived = 1, archived_at = NOW()
         WHERE student_id = ? AND drive_file_id IS NOT NULL AND drive_file_id <> ''
           AND drive_link IS NOT NULL AND drive_link <> ''
           AND (is_archived IS NULL OR is_archived = 0)");
    mysqli_stmt_bind_param($remarkStmt, "i", $studentId);
    mysqli_stmt_execute($remarkStmt);

    return [
        'folder_url' => $folderUrl,
        'uploaded'   => $uploaded,
        'skipped'    => $skipped,
    ];
}

function handleArchiveStudent($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    $status    = $_POST['status'] ?? '';

    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    if ($status !== '' && !in_array($status, ['inactive', 'onleave'], true)) {
        echo json_encode(['success' => false, 'message' => 'Only IN-ACTIVE or ON-LEAVE students can be archived.']);
        return;
    }

    session_write_close();
    @set_time_limit(300);

    try {
        $result = archiveStudentToDrive($conn, (int) $studentId);
    } catch (Exception $e) {
        error_log('Archive to Google Drive failed for student ' . $studentId . ': ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Could not upload to Google Drive, so the student was not archived. ' . $e->getMessage(),
        ]);
        return;
    }

    if ($status !== '') {
        $stmt = mysqli_prepare($conn, studentHasArchivedAt($conn)
            ? "UPDATE students SET status = ?, archived_at = NOW() WHERE student_id = ?"
            : "UPDATE students SET status = ? WHERE student_id = ?");
        mysqli_stmt_bind_param($stmt, "si", $status, $studentId);
        if (!mysqli_stmt_execute($stmt)) {
            echo json_encode([
                'success' => false,
                'message' => 'Documents were uploaded to Google Drive, but the status could not be updated: ' . mysqli_error($conn),
            ]);
            return;
        }
    }

    echo json_encode([
        'success'    => true,
        'archived'   => true,
        'status'     => $status,
        'message'    => 'Student archived to Google Drive.',
        'folder_url' => $result['folder_url'],
        'uploaded'   => $result['uploaded'],
        'skipped'    => $result['skipped'],
    ]);
}

function handleRestoreStudent($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    $studentId = (int) $studentId;

    $stmt = mysqli_prepare($conn, "SELECT status FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        return;
    }
    if (!in_array($student['status'], ['inactive', 'onleave'], true)) {
        echo json_encode(['success' => false, 'message' => 'This student is not archived.']);
        return;
    }

    mysqli_begin_transaction($conn);
    try {
        $studentStmt = mysqli_prepare($conn, studentHasArchivedAt($conn)
            ? "UPDATE students SET status = 'active', archived_at = NULL WHERE student_id = ?"
            : "UPDATE students SET status = 'active' WHERE student_id = ?");
        mysqli_stmt_bind_param($studentStmt, "i", $studentId);
        if (!mysqli_stmt_execute($studentStmt)) {
            throw new Exception(mysqli_error($conn));
        }

        $docsStmt = mysqli_prepare($conn,
            "UPDATE documents SET is_archived = 0, archived_at = NULL WHERE student_id = ?");
        mysqli_stmt_bind_param($docsStmt, "i", $studentId);
        if (!mysqli_stmt_execute($docsStmt)) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Could not restore the student: ' . $e->getMessage()]);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Student restored to the Student Directory.', 'status' => 'active']);
}

if (isset($_GET['state']) && (isset($_GET['code']) || isset($_GET['error']))) {
    handleDriveAuthCallback();
    exit();
}

session_start();

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please sign in again.']);
        exit();
    }
    header("Location: ../signin.php");
    exit();
}

if (!$isAjax && isset($_SESSION['is_first_login']) && $_SESSION['is_first_login'] == 1) {
    header("Location: ../change_password.php");
    exit();
}

if ($action === 'archive_student') {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    handleArchiveStudent($conn);
    exit();
}

if ($action === 'restore_student') {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    handleRestoreStudent($conn);
    exit();
}

if ($action === 'drive_auth') {
    startDriveAuth();
}

$flash = $_SESSION['archive_flash'] ?? null;
unset($_SESSION['archive_flash']);

$driveReady = gdriveIsConfigured();

$search            = isset($_GET['search']) ? trim($_GET['search']) : '';
$courseFilter      = isset($_GET['course']) ? trim($_GET['course']) : '';
$yearSectionFilter = isset($_GET['year_section']) ? trim($_GET['year_section']) : '';

$conditions = ["s.status IN ('inactive', 'onleave')"];
$params     = [];
$types      = '';

if ($search !== '') {
    $conditions[] = '(s.student_number LIKE ? OR s.name LIKE ?)';
    $likeTerm = "%$search%";
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $types   .= 'ss';
}

if ($courseFilter !== '') {
    $filterCourse = null;
    foreach (getCourseCatalog() as $catalogEntry) {
        if ($catalogEntry['code'] === $courseFilter) {
            $filterCourse = $catalogEntry;
            break;
        }
    }

    if ($filterCourse !== null) {
        $conditions[] = 's.course IN (?, ?)';
        $params[] = $filterCourse['code'];
        $params[] = $filterCourse['name'];
        $types   .= 'ss';
    } else {
        $conditions[] = 's.course = ?';
        $params[] = $courseFilter;
        $types   .= 's';
    }
}

if ($yearSectionFilter !== '') {
    $conditions[] = 's.year_section = ?';
    $params[] = $yearSectionFilter;
    $types   .= 's';
}

$folderColumn = studentDriveFolderColumn($conn);
$folderSelect = $folderColumn !== null ? ", s.`$folderColumn` AS drive_folder_link" : ", NULL AS drive_folder_link";

$sql = "SELECT s.student_id, s.student_number, s.name, s.course, s.year_section, s.status
               $folderSelect,
               COUNT(d.document_id) AS doc_count,
               SUM(CASE WHEN d.is_archived = 1 AND d.drive_link IS NOT NULL AND d.drive_link <> '' THEN 1 ELSE 0 END) AS archived_docs,
               " . (studentHasArchivedAt($conn) ? "COALESCE(s.archived_at, MAX(d.archived_at))" : "MAX(d.archived_at)") . " AS archived_at
        FROM students s
        LEFT JOIN documents d ON d.student_id = s.student_id
        WHERE " . implode(' AND ', $conditions) . "
        GROUP BY s.student_id
        ORDER BY archived_at IS NULL, archived_at DESC, s.name ASC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}
$students = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

$driveFiles = [];
$studentIds = array_map('intval', array_column($students, 'student_id'));
if (!empty($studentIds)) {
    $idList = implode(',', $studentIds);
    $filesResult = mysqli_query($conn,
        "SELECT student_id, file_name, drive_link FROM documents
         WHERE student_id IN ($idList) AND is_archived = 1 AND drive_link IS NOT NULL AND drive_link <> ''
         ORDER BY document_id ASC");
    if ($filesResult) {
        while ($file = mysqli_fetch_assoc($filesResult)) {
            $driveFiles[(int) $file['student_id']][] = $file;
        }
    }
}

$courseOptions = [];
foreach (getCourseCatalog() as $catalogEntry) {
    $courseOptions[$catalogEntry['code']] = $catalogEntry['code'];
}

$yearSectionOptions = [];
$yearSectionResult = mysqli_query($conn,
    "SELECT DISTINCT year_section FROM students
     WHERE status IN ('inactive', 'onleave') AND year_section IS NOT NULL AND year_section <> ''
     ORDER BY year_section ASC");
if ($yearSectionResult) {
    while ($row = mysqli_fetch_assoc($yearSectionResult)) {
        $yearSectionOptions[] = $row['year_section'];
    }
}

$statusLabels = ['active' => 'ACTIVE', 'inactive' => 'IN-ACTIVE', 'onleave' => 'ON-LEAVE'];

include 'navbar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archived Records</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-admin.css" rel="stylesheet">
    <link href="../css/student-directory-admin.css" rel="stylesheet">
    <link href="../css/archive-admin.css" rel="stylesheet">
</head>

<body>
    <div class="main-container">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> archive-alert" role="alert">
            <?= htmlspecialchars($flash['message']) ?>
            <?php if (!empty($flash['token'])): ?>
                <pre class="archive-token">GOOGLE_REFRESH_TOKEN=<?= htmlspecialchars($flash['token']) ?></pre>
                <small class="text-muted">Keep this private. Anyone with it can access that Google Drive. It is shown only once.</small>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$driveReady && empty($flash['token'])): ?>
        <div class="alert alert-warning archive-alert d-flex align-items-center justify-content-between gap-3" role="alert">
            <span>
                <i class="bi bi-exclamation-triangle"></i>
                Google Drive isn't connected yet, so students can't be archived.
            </span>
            <a href="archive.php?action=drive_auth" class="btn btn-sm archive-connect-btn">
                <i class="bi bi-google"></i> Connect Google Drive
            </a>
        </div>
    <?php endif; ?>

    <div class="textNButton-row">

      <form method="GET" action="archive.php" class="search-group" id="archiveSearchForm">
        <div class="search-input-wrapper">
            <input type="text" name="search" id="archiveSearchInput" class="searchbar" placeholder="STUDENT NUMBER / NAME" value="<?= htmlspecialchars($search) ?>" required>
            <?php if ($search !== ''): ?>
                <a href="archive.php" class="clear-icon" title="Clear search"><i class="bi bi-x-circle"></i></a>
            <?php endif; ?>
        </div>
        <button type="submit" class="search-icon">
            <i class="bi bi-search"></i>
        </button>
      </form>

      <div class="add-filter">
        <form method="GET" action="archive.php" class="filter-row" id="archiveFilterForm">
          <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

          <select class="filter" id="archiveCourseFilter" name="course">
              <option value="">Course</option>
              <?php foreach ($courseOptions as $courseValue => $courseLabel): ?>
                  <option value="<?= htmlspecialchars($courseValue) ?>" <?= $courseFilter === $courseValue ? 'selected' : '' ?>>
                      <?= htmlspecialchars($courseLabel) ?>
                  </option>
              <?php endforeach; ?>
          </select>

          <select class="filter filter-year-section" id="archiveYearSectionFilter" name="year_section">
              <option value="">Year & Section</option>
              <?php foreach ($yearSectionOptions as $yearSectionOption): ?>
                  <option value="<?= htmlspecialchars($yearSectionOption) ?>" <?= $yearSectionFilter === $yearSectionOption ? 'selected' : '' ?>>
                      <?= htmlspecialchars($yearSectionOption) ?>
                  </option>
              <?php endforeach; ?>
          </select>
        </form>
      </div>

    </div>

    <!-- table -->
    <div class="table-scroll-wrapper">
    <table class="student-table archive-table">
    <tr>
        <th>Student Number</th>
        <th>Name</th>
        <th>Course</th>
        <th>Year & Section</th>
        <th>Status</th>
        <th>Date Archived</th>
        <th>Records</th>
    </tr>

    <?php if (!empty($students)): ?>
        <?php foreach ($students as $row): ?>
            <?php
                $sid        = (int) $row['student_id'];
                $files      = $driveFiles[$sid] ?? [];
                $pending    = (int) $row['doc_count'] - (int) $row['archived_docs'];
                $folderLink = $row['drive_folder_link'] ?? '';
            ?>
        <tr>
            <td><?= htmlspecialchars($row['student_number']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['course']) ?></td>
            <td><?= htmlspecialchars($row['year_section']) ?></td>
            <td>
                <span class="archive-status archive-status-<?= htmlspecialchars($row['status']) ?>">
                    <?= htmlspecialchars($statusLabels[$row['status']] ?? strtoupper((string) $row['status'])) ?>
                </span>
            </td>
            <td>
                <?php if (!empty($row['archived_at'])): ?>
                    <?php $archivedTs = strtotime($row['archived_at']); ?>
                    <span class="archive-date"><?= htmlspecialchars(date('M j, Y', $archivedTs)) ?></span>
                    <span class="archive-time"><?= htmlspecialchars(date('g:i A', $archivedTs)) ?></span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td>
                <div class="archive-records">
                    <?php if (!empty($files) || $folderLink !== ''): ?>
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle archive-drive-btn"
                                    data-bs-toggle="dropdown" aria-expanded="false" title="Open in Google Drive">
                                <i class="bi bi-google"></i> Drive
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end archive-drive-menu">
                                <?php if ($folderLink !== ''): ?>
                                    <li>
                                        <a class="dropdown-item" href="<?= htmlspecialchars($folderLink) ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-folder2-open"></i> Open student folder
                                        </a>
                                    </li>
                                    <?php if (!empty($files)): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($files)): ?>
                                    <li><h6 class="dropdown-header">Files on Drive</h6></li>
                                    <?php foreach ($files as $file): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= htmlspecialchars($file['drive_link']) ?>" target="_blank" rel="noopener">
                                                <i class="bi bi-file-earmark-pdf"></i> <?= htmlspecialchars($file['file_name']) ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <button type="button" class="btn btn-sm archive-restore-btn"
                            data-id="<?= $sid ?>" data-name="<?= htmlspecialchars($row['name']) ?>"
                            title="Move back to the Student Directory as ACTIVE">
                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                    </button>

                    <?php if ($pending > 0): ?>
                        <button type="button" class="btn btn-sm archive-upload-btn"
                                data-id="<?= $sid ?>" data-name="<?= htmlspecialchars($row['name']) ?>"
                                data-pending="<?= $pending ?>" <?= $driveReady ? '' : 'disabled' ?>
                                title="<?= $pending ?> document(s) not on Google Drive yet">
                            <i class="bi bi-cloud-arrow-up"></i> Upload
                        </button>
                    <?php endif; ?>
                </div>
                <?php if ($pending > 0): ?>
                    <small class="archive-pending"><?= $pending ?> file(s) not on Drive yet</small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="7" class="no-students-message">No archived records found.</td>
        </tr>
    <?php endif; ?>
    </table>
    </div>
    </div>

<!-- confirm upload modal -->
<div class="modal fade" id="archiveUploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Archive Upload</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Upload <strong id="archiveUploadCount"></strong> remaining document(s) of
                    <strong id="archiveUploadName"></strong> to Google Drive?
                </p>
                <div id="archiveUploadError" class="alert alert-danger small mb-0 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" id="archiveUploadCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-save" id="archiveUploadConfirmBtn">Upload</button>
            </div>
        </div>
    </div>
</div>

<!-- confirm restore modal -->
<div class="modal fade" id="archiveRestoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Restore</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Restore <strong id="archiveRestoreName"></strong> to the Student Directory?
                    Their status will be set to <strong>ACTIVE</strong>.
                </p>
                <div id="archiveRestoreError" class="alert alert-danger small mt-3 mb-0 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" id="archiveRestoreCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-save archive-nowrap-btn" id="archiveRestoreConfirmBtn">Restore</button>
            </div>
        </div>
    </div>
</div>

<script src="../js/archive.js"></script>
</body>
</html>