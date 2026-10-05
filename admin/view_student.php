<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/course_catalog.php';
require_once __DIR__ . '/document_classifier.php';
require_once __DIR__ . '/ai_extraction.php';
require_once __DIR__ . '/archive.php';
require_once __DIR__ . '/requirement_status.php';

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../signin.php");
    exit();
}

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

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

define('PDFTOPPM_BIN', 'C:\\xampp\\htdocs\\CAPSTONE\\poppler-26.02.0\\Library\\bin\\pdftoppm.exe');

loadEnv(__DIR__ . '/../.env');

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json');

    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    ob_start();

    switch ($action) {
        case 'ocr_extract':
            handleOcrExtract($conn);
            break;
        case 'delete_document_record':
            handleDeleteDocumentRecord($conn);
            break;
        case 'delete_staged_document':
            handleDeleteStagedDocument();
            break;
        case 'replace_document':
            handleReplaceDocument($conn);
            break;
        case 'add_documents':
            handleAddDocuments($conn);
            break;
        case 'set_document_type':
            handleSetDocumentType($conn);
            break;
        case 'update_student_info':
            handleUpdateStudentInfo($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }

    $buffered = ob_get_clean();
    $jsonStart = strpos($buffered, '{');
    $arrStart  = strpos($buffered, '[');
    if ($arrStart !== false && ($jsonStart === false || $arrStart < $jsonStart)) {
        $jsonStart = $arrStart;
    }
    if ($jsonStart === false) {
        error_log("view_student.php action '$action' produced no JSON output: " . $buffered);
        echo json_encode(['success' => false, 'message' => 'Unexpected server error. Please try again.']);
    } elseif ($jsonStart > 0) {
        error_log("view_student.php action '$action' had unexpected output before JSON: " . substr($buffered, 0, $jsonStart));
        echo substr($buffered, $jsonStart);
    } else {
        echo $buffered;
    }
    exit();
}

function isPathInsideUploads($path)
{
    $uploadDir = realpath('../uploads/documents/');
    $target    = realpath($path);
    return $uploadDir !== false && $target !== false && strpos($target, $uploadDir) === 0;
}

function isArchivedStatus($status)
{
    return in_array($status, ARCHIVED_STATUSES, true);
}

function isArchivedStudent($conn, $studentId)
{
    $id   = (int) $studentId;
    $stmt = mysqli_prepare($conn, 'SELECT status FROM students WHERE student_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row && isArchivedStatus($row['status']);
}

function rejectIfArchived($conn, $studentId)
{
    if (!isArchivedStudent($conn, $studentId)) {
        return false;
    }
    echo json_encode(['success' => false, 'message' => 'This student is archived. Archived records can no longer be edited.']);
    return true;
}

function detectSarPhotoFrame($pdfPath, $page = 1)
{
    $sx = 1650;
    $sy = 250;
    $sw = 896;
    $sh = 950;

    $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('sarframe_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -r 300 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' -x ' . $sx . ' -y ' . $sy . ' -W ' . $sw . ' -H ' . $sh
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1';
    exec($cmd, $cmdOut, $cmdCode);

    $ppmFile = $tmpBase . '-1.ppm';
    if (!file_exists($ppmFile)) {
        $ppmFile = $tmpBase . '.ppm';
    }
    if (!file_exists($ppmFile)) {
        return null;
    }
    $ppm = readPpmPixels($ppmFile);
    @unlink($ppmFile);
    if ($ppm === null) {
        return null;
    }

    $w = $ppm['width'];
    $h = $ppm['height'];
    $data = $ppm['data'];
    $n = $w * $h;
    if (strlen($data) < $n * 3) {
        return null;
    }

    $mask = str_repeat("\0", $n);
    for ($i = 0; $i < $n; $i++) {
        $o = $i * 3;
        if (ord($data[$o]) + ord($data[$o + 1]) + ord($data[$o + 2]) < 480) {
            $mask[$i] = "\1";
        }
    }

    $minRun = 350;
    $maxGap = 4;
    $band   = 8;
    $longestRun = function ($start, $count, $step, $across, $acrossLeft) use ($mask, $maxGap, $band) {
        $reach = min($band, $acrossLeft);
        $best = 0;
        $run = 0;
        $gap = 0;
        for ($k = 0, $i = $start; $k < $count; $k++, $i += $step) {
            $isDark = false;
            for ($b = 0, $j = $i; $b < $reach; $b++, $j += $across) {
                if ($mask[$j] === "\1") {
                    $isDark = true;
                    break;
                }
            }
            if ($isDark) {
                $run += $gap + 1;
                $gap = 0;
                if ($run > $best) {
                    $best = $run;
                }
            } elseif ($run > 0 && $gap < $maxGap) {
                $gap++;
            } else {
                $run = 0;
                $gap = 0;
            }
        }
        return $best;
    };

    $cols = [];
    for ($x = 0; $x < $w; $x++) {
        if ($longestRun($x, $h, $w, 1, $w - $x) >= $minRun) {
            $cols[] = $x;
        }
    }
    $rows = [];
    for ($y = 0; $y < $h; $y++) {
        if ($longestRun($y * $w, $w, 1, $w, $h - $y) >= $minRun) {
            $rows[] = $y;
        }
    }
    if (count($cols) < 2 || !$rows) {
        return null;
    }

    $left  = min($cols) + $band - 1;
    $right = max($cols);
    $top   = min($rows) + $band - 1;
    $width = $right - $left;
    if ($width < 450 || $width > 750) {
        return null;
    }
    $below  = array_filter($rows, function ($r) use ($top) { return $r > $top + 400; });
    $bottom = $below ? min($below) + $band - 1 : $top + $width;

    $inset = 9; 
    return [
        'x'      => $sx + $left + $inset,
        'y'      => $sy + $top + $inset,
        'width'  => $width - 2 * $inset,
        'height' => ($bottom - $top) - 2 * $inset,
    ];
}

function sarPhotoBox(array $frame)
{
    $ZOOM = 0.95;

    $size = (int) round(min($frame['width'], $frame['height']) * $ZOOM);
    return [
        'x'      => (int) $frame['x'] + (int) round(($frame['width'] - $size) / 2),
        'y'      => (int) $frame['y'] + 2,
        'width'  => $size,
        'height' => $size,
    ];
}

function writePngRgb($path, $width, $height, $rgb)
{
    $rowLen = $width * 3;
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\0" . substr($rgb, $y * $rowLen, $rowLen);
    }
    $chunk = function ($type, $data) {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $png = "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 6))
        . $chunk('IEND', '');
    return file_put_contents($path, $png) !== false;
}

function savePhotoWithTopSpace($pdfPath, $page, array $box, $photoBase, $spaceRatio = 0.10)
{
    if (!function_exists('gzcompress')) {
        return null;
    }

    $size  = (int) $box['width'];
    $space = (int) round((int) $box['height'] * $spaceRatio);
    $cropH = max(1, (int) $box['height'] - $space);

    $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('sarphoto_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -r 300 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' -x ' . (int) $box['x'] . ' -y ' . (int) $box['y']
        . ' -W ' . $size . ' -H ' . $cropH
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1';
    exec($cmd, $cmdOut, $cmdCode);

    $ppmFile = $tmpBase . '-1.ppm';
    if (!file_exists($ppmFile)) {
        $ppmFile = $tmpBase . '.ppm';
    }
    if (!file_exists($ppmFile)) {
        return null;
    }
    $ppm = readPpmPixels($ppmFile);
    @unlink($ppmFile);
    if ($ppm === null) {
        return null;
    }

    $w = $ppm['width'];
    $h = $ppm['height'];
    $data = $ppm['data'];
    if (strlen($data) < $w * $h * 3) {
        return null;
    }

    $sum = [0, 0, 0];
    $count = 0;
    $rows = min(6, $h);
    for ($i = 0; $i < $w * $rows; $i++) {
        $r = ord($data[$i * 3]);
        $g = ord($data[$i * 3 + 1]);
        $b = ord($data[$i * 3 + 2]);
        if ($r + $g + $b > 540) {
            $sum[0] += $r;
            $sum[1] += $g;
            $sum[2] += $b;
            $count++;
        }
    }
    $bg = $count > 0
        ? chr((int) ($sum[0] / $count)) . chr((int) ($sum[1] / $count)) . chr((int) ($sum[2] / $count))
        : "\xFF\xFF\xFF";

    $rgb = str_repeat(str_repeat($bg, $w), $space) . substr($data, 0, $w * $h * 3);
    $path = $photoBase . '.png';
    return writePngRgb($path, $w, $h + $space, $rgb) ? $path : null;
}

function extractIdPhoto($pdfPath, $docType, $page = 1)
{
    static $idPhotoBoxes = [
        'sar_form' => ['x' => 1866, 'y' => 440, 'width' => 577, 'height' => 573],
        'health_information_form' => ['x' => 1845, 'y' => 50, 'width' => 540, 'height' => 735],
    ];

    if (!isset($idPhotoBoxes[$docType])) {
        return null;
    }
    $box = $idPhotoBoxes[$docType];
    $isSar = $docType === 'sar_form';
    if ($isSar) {
        $frame = detectSarPhotoFrame($pdfPath, $page) ?? $box;
        $box = sarPhotoBox($frame);
    }

    $photoDir = '../uploads/photos/';
    if (!is_dir($photoDir)) {
        mkdir($photoDir, 0755, true);
    }
    $photoBase = $photoDir . uniqid('photo_', true);

    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -jpeg -r 300 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' -x ' . escapeshellarg($box['x'])
        . ' -y ' . escapeshellarg($box['y'])
        . ' -W ' . escapeshellarg($box['width'])
        . ' -H ' . escapeshellarg($box['height'])
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($photoBase) . ' 2>&1';
    exec($cmd, $cmdOut, $cmdCode);

    $generated = $photoBase . '-1.jpg';
    if (!file_exists($generated)) {
        $generated = $photoBase . '.jpg';
    }

    if (!file_exists($generated)) {
        return null;
    }

    if (!cropLooksLikePersonPhoto($pdfPath, $box, 0.04, $page)) {
        @unlink($generated);
        return null;
    }

    if ($isSar) {
        $withSpace = savePhotoWithTopSpace($pdfPath, $page, $box, $photoBase, 0.075);
        if ($withSpace !== null) {
            @unlink($generated);
            return $withSpace;
        }
    }

    if (!$isSar && function_exists('savePaddedPhoto') && function_exists('gzcompress')) {
        $padded = savePaddedPhoto($pdfPath, $page, $box, $photoBase, 0.06, 480, 0.5, 2.0);
        if ($padded !== null) {
            @unlink($generated);
            return $padded;
        }
    }

    return $generated;
}

function requirementsForClient()
{
    return array_values(array_map(function ($r) {
        $text = ($r['label'] ?? '') . ' ' . ($r['code'] ?? '');
        if (preg_match('/chest[\s_-]*x[\s_-]*-?ray|xray/i', $text)) {
            $r['required_for'] = [];
            $r['clinic_only']  = true;  
        }
        return $r;
    }, getDocumentRequirementsForClient()));
}

function readPpmPixels($path)
{
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 2 || substr($data, 0, 2) !== 'P6') {
        return null;
    }

    $pos = 2;
    $len = strlen($data);
    $tokens = [];

    while (count($tokens) < 3 && $pos < $len) {
        while ($pos < $len && ctype_space($data[$pos])) {
            $pos++;
        }
        if ($pos < $len && $data[$pos] === '#') {
            while ($pos < $len && $data[$pos] !== "\n") {
                $pos++;
            }
            continue;
        }
        $start = $pos;
        while ($pos < $len && !ctype_space($data[$pos])) {
            $pos++;
        }
        if ($pos > $start) {
            $tokens[] = substr($data, $start, $pos - $start);
        }
    }

    if (count($tokens) < 3) {
        return null;
    }

    $pos++;

    $width = (int) $tokens[0];
    $height = (int) $tokens[1];
    if ($width <= 0 || $height <= 0) {
        return null;
    }

    return ['width' => $width, 'height' => $height, 'data' => substr($data, $pos)];
}

function cropLooksLikePersonPhoto($pdfPath, $box, $minSkinRatio = 0.04, $page = 1)
{
    $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('ppmcheck_', true);

    $cmd = escapeshellarg(PDFTOPPM_BIN)
        . ' -r 300 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' -x ' . escapeshellarg($box['x'])
        . ' -y ' . escapeshellarg($box['y'])
        . ' -W ' . escapeshellarg($box['width'])
        . ' -H ' . escapeshellarg($box['height'])
        . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1';
    exec($cmd, $cmdOut, $cmdCode);

    $generated = $tmpBase . '-1.ppm';
    if (!file_exists($generated)) {
        $generated = $tmpBase . '.ppm';
    }
    if (!file_exists($generated)) {
        return true;
    }

    $ppm = readPpmPixels($generated);
    @unlink($generated);

    if ($ppm === null) {
        return true;
    }

    $totalPixels = $ppm['width'] * $ppm['height'];
    $bytes = $ppm['data'];
    if ($totalPixels <= 0 || strlen($bytes) < $totalPixels * 3) {
        return true;
    }

    $step = max(1, (int) floor($totalPixels / 6000));
    $skinCount = 0;
    $sampleCount = 0;

    for ($i = 0; $i < $totalPixels; $i += $step) {
        $offset = $i * 3;
        $r = ord($bytes[$offset]);
        $g = ord($bytes[$offset + 1]);
        $b = ord($bytes[$offset + 2]);

        $sampleCount++;

        $maxC = max($r, $g, $b);
        $minC = min($r, $g, $b);
        $isSkin = $r > 95 && $g > 40 && $b > 20
            && ($maxC - $minC) > 15
            && abs($r - $g) > 15
            && $r > $g && $r > $b;

        if ($isSkin) {
            $skinCount++;
        }
    }

    if ($sampleCount === 0) {
        return false;
    }

    return ($skinCount / $sampleCount) >= $minSkinRatio;
}

function saveUploadedPdf($field, $uploadDir)
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return 'No file uploaded or an upload error occurred.';
    }
    $originalName = $_FILES[$field]['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf' || mime_content_type($_FILES[$field]['tmp_name']) !== 'application/pdf') {
        return 'Unsupported file type. Only PDF files are allowed.';
    }
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $destPath = $uploadDir . uniqid('doc_', true) . '.pdf';
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $destPath)) {
        return 'Failed to save the uploaded file.';
    }
    return [$destPath, $originalName];
}

function handleOcrExtract($conn)
{
    @set_time_limit(300);

    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    if (rejectIfArchived($conn, $studentId)) {
        return;
    }

    $uploadDir = '../uploads/documents/';
    $saved = saveUploadedPdf('document', $uploadDir);
    if (is_string($saved)) {
        echo json_encode(['success' => false, 'message' => $saved]);
        return;
    }
    [$destPath, $originalName] = $saved;

    $result = processDocumentUpload($destPath, $originalName, $uploadDir, ['preview_image' => true]);

    if (!empty($result['documents'])) {
        foreach ($result['documents'] as &$doc) {
            $doc['duplicate_of'] = null;
            $doc['on_file']      = null;

            $sameFile = findStoredFileHash($conn, (int) $studentId, $doc['file_hash']);
            if ($sameFile) {
                $doc['duplicate_of'] = $sameFile['file_name'] . ' (' . $sameFile['document_type'] . ')';
                continue;
            }
            if ($doc['requirement_code'] !== null) {
                $existing = findStoredRequirement($conn, (int) $studentId, $doc['requirement_code']);
                if ($existing) {
                    $doc['on_file'] = ['document_id' => (int) $existing['document_id'], 'file_name' => $existing['file_name']];
                }
            }
        }
        unset($doc);
    }

    echo json_encode(attachAiStatus($result));
}

function dateOfBirthForDb($value)
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function buildFullName($lastName, $firstName, $middleName)
{
    $given = trim($firstName . ' ' . $middleName);
    if ($lastName === '') {
        return $given;
    }
    return $given === '' ? $lastName : $lastName . ', ' . $given;
}

function splitFullName($name)
{
    $name = trim((string) $name);
    if (strpos($name, ',') === false) {
        return ['', $name, ''];
    }
    [$last, $given] = array_map('trim', explode(',', $name, 2));
    $middle = '';
    if (preg_match('/^(.*\S)\s+([A-Za-zÑñ]\.?)$/u', $given, $m)) {
        $given  = $m[1];
        $middle = $m[2];
    }
    return [$last, $given, $middle];
}

function minimumBirthDate()
{
    return date('Y-m-d', strtotime('-17 years'));
}

function handleUpdateStudentInfo($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    if (rejectIfArchived($conn, $studentId)) {
        return;
    }

    $lastName    = trim($_POST['last_name'] ?? '');
    $firstName   = trim($_POST['first_name'] ?? '');
    $middleName  = trim($_POST['middle_name'] ?? '');
    if ($lastName === '' || $firstName === '') {
        echo json_encode(['success' => false, 'message' => 'Please fill in the Last Name and First Name.']);
        return;
    }
    $name        = buildFullName($lastName, $firstName, $middleName);
    $studentNum  = trim($_POST['student_number'] ?? '');
    $course      = normalizeCourseName($_POST['course'] ?? '');
    $yearSection = trim($_POST['year_section'] ?? '');
    if ($yearSection !== '' && !preg_match('/^\d-\d$/', $yearSection)) {
        echo json_encode(['success' => false, 'message' => 'Year & Section must be one number, a dash, and one number (e.g. 1-1).']);
        return;
    }
    $sex         = trim($_POST['sex'] ?? '');
    $dob         = dateOfBirthForDb(trim($_POST['date_of_birth'] ?? ''));
    if ($dob !== null && $dob > minimumBirthDate()) {
        echo json_encode(['success' => false, 'message' => 'Date of Birth is too recent. The student must be at least 17 years old.']);
        return;
    }
    $pob         = trim($_POST['place_of_birth'] ?? '');
    $mobile      = trim($_POST['mobile_number'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $track       = normalizeAdmissionTrack($_POST['admission_track'] ?? '');

    if ($studentNum !== '') {
        $dupSql  = "SELECT student_id FROM students WHERE student_number = ? AND student_id != ? LIMIT 1";
        $dupStmt = mysqli_prepare($conn, $dupSql);
        mysqli_stmt_bind_param($dupStmt, "si", $studentNum, $studentId);
        mysqli_stmt_execute($dupStmt);
        mysqli_stmt_store_result($dupStmt);

        if (mysqli_stmt_num_rows($dupStmt) > 0) {
            echo json_encode(['success' => false, 'message' => 'That student number is already used by another record.']);
            return;
        }
    }

    $newPhotoPath = null;
    $oldPhotoPath = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            echo json_encode(['success' => false, 'message' => 'Unsupported photo type. Use JPG or PNG.']);
            return;
        }

        $lookupSql  = "SELECT photo_path FROM students WHERE student_id = ?";
        $lookupStmt = mysqli_prepare($conn, $lookupSql);
        mysqli_stmt_bind_param($lookupStmt, "i", $studentId);
        mysqli_stmt_execute($lookupStmt);
        $lookupRow    = mysqli_fetch_assoc(mysqli_stmt_get_result($lookupStmt));
        $oldPhotoPath = $lookupRow['photo_path'] ?? null;

        $photoDir = '../uploads/photos/';
        if (!is_dir($photoDir)) {
            mkdir($photoDir, 0755, true);
        }

        $safeName = uniqid('photo_', true) . '.' . $ext;
        $destPath = $photoDir . $safeName;

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destPath)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded photo.']);
            return;
        }

        $newPhotoPath = $destPath;
    }

    $photoIsStaged = false;
    if ($newPhotoPath === null) {
        $stagedPhoto = trim($_POST['photo_path'] ?? '');
        if ($stagedPhoto !== '') {
            $realPhotoDir = realpath('../uploads/photos/');
            $realStaged   = realpath($stagedPhoto);

            if ($realPhotoDir === false || $realStaged === false || !is_file($realStaged)
                || strpos($realStaged, $realPhotoDir . DIRECTORY_SEPARATOR) !== 0) {
                echo json_encode(['success' => false, 'message' => 'The scanned photo could not be found. Please scan the document again.']);
                return;
            }

            $lookupSql  = "SELECT photo_path FROM students WHERE student_id = ?";
            $lookupStmt = mysqli_prepare($conn, $lookupSql);
            mysqli_stmt_bind_param($lookupStmt, "i", $studentId);
            mysqli_stmt_execute($lookupStmt);
            $lookupRow    = mysqli_fetch_assoc(mysqli_stmt_get_result($lookupStmt));
            $oldPhotoPath = $lookupRow['photo_path'] ?? null;

            $newPhotoPath  = '../uploads/photos/' . basename($realStaged);
            $photoIsStaged = true;
        }
    }

    if ($newPhotoPath !== null) {
        $sql  = "UPDATE students
                 SET name = ?, last_name = ?, first_name = ?, middle_name = ?, student_number = ?, course = ?, year_section = ?, sex = ?,
                     date_of_birth = ?, place_of_birth = ?, mobile_number = ?, email = ?, address = ?,
                     admission_track = ?, photo_path = ?
                 WHERE student_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "sssssssssssssssi",
            $name, $lastName, $firstName, $middleName, $studentNum, $course, $yearSection, $sex, $dob, $pob, $mobile, $email, $address, $track, $newPhotoPath, $studentId
        );
    } else {
        $sql  = "UPDATE students
                 SET name = ?, last_name = ?, first_name = ?, middle_name = ?, student_number = ?, course = ?, year_section = ?, sex = ?,
                     date_of_birth = ?, place_of_birth = ?, mobile_number = ?, email = ?, address = ?,
                     admission_track = ?
                 WHERE student_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "sssssssssssssssi",
            $name, $lastName, $firstName, $middleName, $studentNum, $course, $yearSection, $sex, $dob, $pob, $mobile, $email, $address, $track, $studentId
        );
    }

    if (mysqli_stmt_execute($stmt)) {
        if (in_array('graduation_year', studentColumns($conn), true)) {
            $gradInput = trim($_POST['graduation_year'] ?? '');
            $gradYear  = ctype_digit($gradInput) ? (int) $gradInput : null;
            $gradStmt  = mysqli_prepare($conn, "UPDATE students SET graduation_year = ? WHERE student_id = ?");
            mysqli_stmt_bind_param($gradStmt, "ii", $gradYear, $studentId);
            mysqli_stmt_execute($gradStmt);
        }
        if ($newPhotoPath !== null && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath
            && strpos($oldPhotoPath, '../uploads/photos/') === 0 && is_file($oldPhotoPath)) {
            @unlink($oldPhotoPath);
        }
        echo json_encode([
            'success'    => true,
            'message'    => 'Student information updated.',
            'photo_path' => $newPhotoPath,
        ]);
    } else {
        if ($newPhotoPath !== null && !$photoIsStaged) {
            @unlink($newPhotoPath);
        }
        echo json_encode(['success' => false, 'message' => 'Failed to update: ' . mysqli_error($conn)]);
    }
}

function handleDeleteStagedDocument()
{
    $filePath  = $_POST['file_path'] ?? '';
    $imagePath = $_POST['image_path'] ?? '';
    $photoPath = $_POST['photo_path'] ?? '';

    if ($filePath === '' && $imagePath === '' && $photoPath === '') {
        echo json_encode(['success' => false, 'message' => 'No file path provided.']);
        return;
    }

    $uploadDir = realpath('../uploads/documents/');
    if ($uploadDir === false) {
        echo json_encode(['success' => false, 'message' => 'Upload directory not found.']);
        return;
    }

    $paths   = array_filter([$filePath, $imagePath], fn($p) => $p !== '');
    $deleted = true;

    foreach ($paths as $path) {
        $targetPath = realpath($path);
        if ($targetPath === false || strpos($targetPath, $uploadDir) !== 0) {
            continue;
        }
        if (file_exists($targetPath) && !unlink($targetPath)) {
            $deleted = false;
        }
    }

    if ($photoPath !== '') {
        $photoDir        = realpath('../uploads/photos/');
        $targetPhotoPath = realpath($photoPath);
        if ($photoDir === false || $targetPhotoPath === false
            || strpos($targetPhotoPath, $photoDir . DIRECTORY_SEPARATOR) !== 0) {
            $deleted = false;
        } elseif (file_exists($targetPhotoPath) && !unlink($targetPhotoPath)) {
            $deleted = false;
        }
    }

    echo json_encode(['success' => $deleted]);
}

function handleDeleteDocumentRecord($conn)
{
    $documentId = $_POST['document_id'] ?? '';
    if ($documentId === '' || !ctype_digit((string) $documentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid document.']);
        return;
    }

    $sql  = "SELECT student_id, file_path FROM documents WHERE document_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $documentId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        return;
    }
    if (rejectIfArchived($conn, $row['student_id'])) {
        return;
    }

    $deleteSql  = "DELETE FROM documents WHERE document_id = ?";
    $deleteStmt = mysqli_prepare($conn, $deleteSql);
    mysqli_stmt_bind_param($deleteStmt, "i", $documentId);

    if (mysqli_stmt_execute($deleteStmt)) {
        if (isPathInsideUploads($row['file_path']) && file_exists($row['file_path'])) {
            @unlink($row['file_path']);
        }
        echo json_encode(['success' => true, 'message' => 'Document deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete: ' . mysqli_error($conn)]);
    }
}

function discardUploadResult(array $result)
{
    foreach ($result['documents'] ?? [] as $doc) {
        if (!empty($doc['file_path']) && isPathInsideUploads($doc['file_path'])) @unlink($doc['file_path']);
    }
    foreach ([$result['photo_path'] ?? null, $result['image_path'] ?? null] as $extra) {
        if ($extra && is_file($extra) && (strpos($extra, '../uploads/photos/') === 0 || isPathInsideUploads($extra))) {
            @unlink($extra);
        }
    }
}

function fetchDocumentRow($conn, $documentId)
{
    $stmt = mysqli_prepare($conn, 'SELECT document_id, student_id, requirement_code, file_path, file_name FROM documents WHERE document_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $documentId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function handleReplaceDocument($conn)
{
    @set_time_limit(300);

    $documentId = $_POST['document_id'] ?? '';
    if ($documentId === '' || !ctype_digit((string) $documentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid document.']);
        return;
    }
    $row = fetchDocumentRow($conn, (int) $documentId);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        return;
    }
    if (rejectIfArchived($conn, $row['student_id'])) {
        return;
    }

    $uploadDir = '../uploads/documents/';
    $saved = saveUploadedPdf('document', $uploadDir);
    if (is_string($saved)) {
        echo json_encode(['success' => false, 'message' => $saved]);
        return;
    }
    [$destPath, $originalName] = $saved;

    $result = processDocumentUpload($destPath, $originalName, $uploadDir);
    if (empty($result['success'])) {
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Could not read the file.']);
        return;
    }
    if (count($result['documents']) !== 1) {
        discardUploadResult($result);
        echo json_encode(['success' => false, 'message' => 'This PDF contains ' . count($result['documents'])
            . ' different documents. Use UPLOAD FILE instead, so each one goes to its own requirement.']);
        return;
    }
    if (!empty($result['photo_path']) && is_file($result['photo_path'])) @unlink($result['photo_path']);

    $new = $result['documents'][0];
    $studentId = (int) $row['student_id'];

    if ($row['requirement_code'] !== null && $new['requirement_code'] !== $row['requirement_code']) {
        @unlink($new['file_path']);
        echo json_encode(['success' => false, 'message' => 'Cannot replace: the new file is not the same type of document.']);
        return;
    }

    $sameFile = findStoredFileHash($conn, $studentId, $new['file_hash']);
    if ($sameFile && (int) $sameFile['document_id'] !== (int) $row['document_id']) {
        @unlink($new['file_path']);
        echo json_encode(['success' => false, 'message' => 'This exact file is already on record as ' . $sameFile['document_type'] . ' (' . $sameFile['file_name'] . ').']);
        return;
    }

    $warning = '';
    $code = $row['requirement_code'];
    if ($code === null && $new['requirement_code'] !== null) {
        $taken = findStoredRequirement($conn, $studentId, $new['requirement_code']);
        if (!$taken || (int) $taken['document_id'] === (int) $row['document_id']) {
            $code = $new['requirement_code'];
        } else {
            $warning = 'The new file looks like ' . requirementLabel($new['requirement_code']) . ', which is already on file, so it was kept as "Other".';
        }
    }

    $label = requirementLabel($code);
    $updateSql  = "UPDATE documents
                   SET file_name = ?, file_path = ?, file_hash = ?, requirement_code = ?, document_type = ?,
                       uploaded_at = NOW()
                   WHERE document_id = ?";
    $updateStmt = mysqli_prepare($conn, $updateSql);
    mysqli_stmt_bind_param($updateStmt, "sssssi", $originalName, $new['file_path'], $new['file_hash'], $code, $label, $documentId);

    try {
        mysqli_stmt_execute($updateStmt);
    } catch (Exception $e) {
        @unlink($new['file_path']);
        echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $e->getMessage()]);
        return;
    }

    if ($row['file_path'] !== '' && isPathInsideUploads($row['file_path']) && is_file($row['file_path'])) {
        @unlink($row['file_path']);
    }
    echo json_encode([
        'success'          => true,
        'message'          => 'Document replaced.',
        'warning'          => $warning,
        'file_name'        => $originalName,
        'file_path'        => $new['file_path'],
        'requirement_code' => $code,
        'document_type'    => $label,
    ]);
}

function handleSetDocumentType($conn)
{
    $documentId = $_POST['document_id'] ?? '';
    if ($documentId === '' || !ctype_digit((string) $documentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid document.']);
        return;
    }
    $row = fetchDocumentRow($conn, (int) $documentId);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        return;
    }
    if (rejectIfArchived($conn, $row['student_id'])) {
        return;
    }

    $code = trim($_POST['requirement_code'] ?? '');
    $code = $code === '' ? null : $code;
    if ($code !== null && !isStorableRequirement($code)) {
        echo json_encode(['success' => false, 'message' => 'Unknown document type.']);
        return;
    }
    if ($code !== null) {
        $taken = findStoredRequirement($conn, (int) $row['student_id'], $code);
        if ($taken && (int) $taken['document_id'] !== (int) $row['document_id']) {
            echo json_encode(['success' => false, 'message' => requirementLabel($code) . ' is already on file (' . $taken['file_name'] . '). Delete or retag that one first.']);
            return;
        }
    }

    $label = requirementLabel($code);
    $stmt = mysqli_prepare($conn, 'UPDATE documents SET requirement_code = ?, document_type = ? WHERE document_id = ?');
    mysqli_stmt_bind_param($stmt, 'ssi', $code, $label, $documentId);
    try {
        mysqli_stmt_execute($stmt);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $e->getMessage()]);
        return;
    }
    echo json_encode(['success' => true, 'requirement_code' => $code, 'document_type' => $label]);
}

function handleAddDocuments($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    $studentId = (int) $studentId;
    if (rejectIfArchived($conn, $studentId)) {
        return;
    }

    $submitted = json_decode($_POST['documents'] ?? '[]', true);
    if (!is_array($submitted) || !$submitted) {
        echo json_encode(['success' => false, 'message' => 'No documents to save.']);
        return;
    }

    $clean = [];
    $byRequirement = [];
    $byHash = [];
    foreach ($submitted as $doc) {
        $filePath = $doc['file_path'] ?? '';
        $fileName = trim($doc['file_name'] ?? '') ?: basename($filePath);
        if ($filePath === '' || !is_file($filePath) || !isPathInsideUploads($filePath)) {
            echo json_encode(['success' => false, 'message' => 'The file "' . $fileName . '" is missing on the server. Please upload it again.']);
            return;
        }
        $code = $doc['requirement_code'] ?? null;
        $code = ($code === '' ? null : $code);
        if ($code !== null && !isStorableRequirement($code)) {
            echo json_encode(['success' => false, 'message' => 'Unknown document type for "' . $fileName . '".']);
            return;
        }
        if ($code !== null && isset($byRequirement[$code])) {
            echo json_encode(['success' => false, 'message' => '"' . $byRequirement[$code] . '" and "' . $fileName . '" are both tagged as ' . requirementLabel($code) . '. Keep only one.']);
            return;
        }
        $hash = pdfContentFingerprint($filePath);
        if (isset($byHash[$hash])) {
            echo json_encode(['success' => false, 'message' => '"' . $byHash[$hash] . '" and "' . $fileName . '" are the same file.']);
            return;
        }

        $sameFile = findStoredFileHash($conn, $studentId, $hash);
        if ($sameFile) {
            echo json_encode(['success' => false, 'message' => '"' . $fileName . '" is already on record as ' . $sameFile['document_type'] . ' (' . $sameFile['file_name'] . ').']);
            return;
        }
        $existing = $code !== null ? findStoredRequirement($conn, $studentId, $code) : null;
        if ($existing) {
            echo json_encode(['success' => false, 'message' => requirementLabel($code) . ' is already on file (' . $existing['file_name'] . '). Use the Replace button on that document to change it.']);
            return;
        }

        if ($code !== null) $byRequirement[$code] = $fileName;
        $byHash[$hash] = $fileName;
        $clean[] = ['file_path' => $filePath, 'file_name' => $fileName, 'code' => $code,
                    'label' => requirementLabel($code), 'hash' => $hash];
    }

    $addedBy = $_SESSION['name'] ?? '';
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "INSERT INTO documents
                (student_id, document_type, requirement_code, file_name, file_path, file_hash, added_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($clean as $d) {
            mysqli_stmt_bind_param($stmt, 'issssss', $studentId, $d['label'], $d['code'], $d['file_name'], $d['file_path'], $d['hash'], $addedBy);
            mysqli_stmt_execute($stmt);
        }
        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $msg = $e->getCode() === 1062
            ? 'A document for the same requirement is already on file. Reload the page and try again.'
            : 'Failed to save documents: ' . $e->getMessage();
        echo json_encode(['success' => false, 'message' => $msg]);
        return;
    }

    echo json_encode(['success' => true, 'message' => count($clean) . ' document(s) saved.']);
}

$studentId = $_GET['id'] ?? '';
if ($studentId === '' || !ctype_digit((string) $studentId)) {
    header("Location: student_directory.php");
    exit();
}

$sql  = "SELECT * FROM students WHERE student_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $studentId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student) {
    header("Location: student_directory.php");
    exit();
}

if (isArchivedStatus($student['status'] ?? '')) {
    header("Location: archive.php");
    exit();
}

$docsSql  = "SELECT document_id, document_type, requirement_code, file_name, file_path, uploaded_at
             FROM documents WHERE student_id = ? ORDER BY uploaded_at DESC, document_id DESC";
$docsStmt = mysqli_prepare($conn, $docsSql);
mysqli_stmt_bind_param($docsStmt, "i", $studentId);
mysqli_stmt_execute($docsStmt);
$documents = mysqli_stmt_get_result($docsStmt);
$documentRows = $documents ? mysqli_fetch_all($documents, MYSQLI_ASSOC) : [];

$admissionTrack = normalizeAdmissionTrack($student['admission_track'] ?? '');
[$editLastName, $editFirstName, $editMiddleName] = (trim($student['last_name'] ?? '') !== '' || trim($student['first_name'] ?? '') !== '')
    ? [$student['last_name'] ?? '', $student['first_name'] ?? '', $student['middle_name'] ?? '']
    : splitFullName($student['name'] ?? '');
$orderedDocs = $documentRows;

$completion = requirementCompletion(
    requirementsForClient(),
    $admissionTrack,
    array_column($documentRows, 'requirement_code')
);

$clientDocuments = array_map(fn($d) => [
    'document_id'      => (int) $d['document_id'],
    'requirement_code' => $d['requirement_code'],
    'file_name'        => $d['file_name'],
    'file_path'        => $d['file_path'],
], $documentRows);

$issuedSql  = "SELECT uploaded_at, added_by FROM documents
               WHERE student_id = ? ORDER BY uploaded_at DESC LIMIT 1";
$issuedStmt = mysqli_prepare($conn, $issuedSql);
mysqli_stmt_bind_param($issuedStmt, "i", $studentId);
mysqli_stmt_execute($issuedStmt);
$issuedInfo = mysqli_fetch_assoc(mysqli_stmt_get_result($issuedStmt));

$dateIssued = '';
if (!empty($issuedInfo['uploaded_at'])) {
    $dateIssued = date('F j, Y', strtotime($issuedInfo['uploaded_at']));
}
$issuedBy = $issuedInfo['added_by'] ?? '';

$statusLabels = ['active' => 'Enrollee', 'graduated' => 'Graduate', 'inactive' => 'In-active', 'onleave' => 'On-leave'];
$savedGradYear    = !empty($student['graduation_year']) ? (int) $student['graduation_year'] : null;
$expectedGradYear = expectedGraduationYear($student);
$gradYearOptions  = range(currentSchoolYearEndYear() - 1, currentSchoolYearEndYear() + 6);
if ($savedGradYear !== null && !in_array($savedGradYear, $gradYearOptions, true)) {
    $gradYearOptions[] = $savedGradYear;
    sort($gradYearOptions);
}
$statusLabel  = $statusLabels[$student['status']] ?? ucfirst($student['status'] ?? '');

$defaultAvatarSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
    . '<rect width="100" height="100" fill="#e9ecef"/>'
    . '<circle cx="50" cy="38" r="18" fill="#adb5bd"/>'
    . '<path d="M20 90c0-19 13.5-32 30-32s30 13 30 32" fill="#adb5bd"/>'
    . '</svg>';
$defaultAvatarUri = 'data:image/svg+xml;base64,' . base64_encode($defaultAvatarSvg);

$photoUrl = (!empty($student['photo_path']) && file_exists($student['photo_path']))
    ? $student['photo_path']
    : $defaultAvatarUri;

include 'navbar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Student - <?= htmlspecialchars($student['name']) ?></title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-admin.css" rel="stylesheet">
    <link href="../css/student-directory-admin.css?v=<?= @filemtime(__DIR__ . '/../css/student-directory-admin.css') ?>" rel="stylesheet">
    <link href="../css/view-student.css?v=<?= @filemtime(__DIR__ . '/../css/view-student.css') ?>" rel="stylesheet">
</head>
<body data-student-id="<?= (int) $studentId ?>">
    <div class="view-container">

    <div class="arrow-add">
        <a href="student_directory.php" class="back-arrow" title="Back to Student Directory">
            <i class="bi bi-arrow-left"></i>
        </a>
        <button class="btn-add" type="button" data-bs-toggle="modal" data-bs-target="#addRecordsModal">
            <i class="bi bi-pencil-square"></i> UPDATE
        </button>
    </div>

        <div class="view-content">

            <div class="view-sidebar">
                <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" class="view-photo">
                <div class="view-name"><?= htmlspecialchars($student['name']) ?></div>
                <div class="view-number"><?= htmlspecialchars($student['student_number']) ?></div>
                <div class="view-course"><?= htmlspecialchars($student['course']) ?></div>
            </div>

            <div class="view-details">
                <ul class="nav nav-tabs" id="viewTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="view-info-tab" data-bs-toggle="tab"
                                data-bs-target="#view-info" type="button" role="tab" aria-selected="true">
                            Student Information
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="view-docs-tab" data-bs-toggle="tab"
                                data-bs-target="#view-docs" type="button" role="tab" aria-selected="false">
                            Documents
                        </button>
                    </li>
                </ul>

                <div class="tab-content border border-top-0 p-3 pt-4 ps-4">

                    <div class="tab-pane fade show active" id="view-info" role="tabpanel">
                        <?php
                        $dobDisplay = !empty($student['date_of_birth']) && strtotime($student['date_of_birth'])
                            ? date('F j, Y', strtotime($student['date_of_birth'])) : '';
                        $infoLeft = [
                            'Year & Section' => $student['year_section'] ?? '',
                            'Gender'         => $student['sex'] ?? '',
                            'Date of Birth'  => $dobDisplay,
                            'Place of Birth' => $student['place_of_birth'] ?? '',
                            'Mobile Number'  => $student['mobile_number'] ?? '',
                            'Email Address'  => $student['email'] ?? '',
                        ];
                        $infoRight = [
                            'Date Issued'         => $dateIssued,
                            'Checked by'          => $issuedBy,
                            'Status'              => $statusLabel,
                            'Expected Graduation' => $expectedGradYear !== null ? schoolYearLabel($expectedGradYear) : '',
                        ];
                        $renderInfoField = function ($label, $value) {
                            $id = 'view_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
                            echo '<div class="d-flex align-items-center mb-3 view-info-field">'
                                . '<label for="' . $id . '" class="form-label form-label-custom">' . htmlspecialchars($label) . ':</label>'
                                . '<input type="text" id="' . $id . '" class="form-control input-custom view-readonly" readonly tabindex="-1" value="'
                                . htmlspecialchars((string) $value) . '">'
                                . '</div>';
                        };
                        ?>
                        <div class="row">
                            <div class="col-md-6">
                                <?php foreach ($infoLeft as $label => $value) { $renderInfoField($label, $value); } ?>
                                <div class="d-flex align-items-start mb-3 view-info-field">
                                    <label for="view_address" class="form-label form-label-custom">Address:</label>
                                    <textarea id="view_address" class="form-control input-custom view-readonly" rows="3" readonly tabindex="-1"><?= htmlspecialchars($student['address'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <?php foreach ($infoRight as $label => $value) { $renderInfoField($label, $value); } ?>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="view-docs" role="tabpanel">
                        <div class="requirement-summary">
                            <div class="requirement-summary-head">
                                <strong class="requirement-summary-title">REQUIREMENT STATUS</strong>
                                <span data-requirement-badge><?= requirementStatusBadge($completion) ?></span>
                            </div>
                            <small class="requirement-status-detail text-muted" data-requirement-detail>
                                <?= (int) $completion['fulfilled'] ?> of <?= (int) $completion['total'] ?> required documents<?= $completion['missing'] ? ' · Missing: ' . htmlspecialchars(implode(', ', $completion['missing'])) : '' ?>
                            </small>
                        </div>
                        <div id="documentList" class="d-flex flex-column gap-2">
                            <?php if (!empty($orderedDocs)): ?>
                                <?php foreach ($orderedDocs as $doc): ?>
                                    <div class="document-row requirement-view-row"
                                         data-document-id="<?= (int) $doc['document_id'] ?>"
                                         data-file-path="<?= htmlspecialchars($doc['file_path']) ?>">
                                        <i class="bi bi-file-earmark-text req-status"></i>
                                        <div class="req-body">
                                            <div class="req-label"><?= htmlspecialchars(isStorableRequirement($doc['requirement_code'] ?? null) ? requirementLabel($doc['requirement_code']) : ($doc['document_type'] ?: 'Other Document')) ?></div>
                                            <span class="document-name"><?= htmlspecialchars($doc['file_name']) ?></span>
                                            <?php if (!empty($doc['uploaded_at'])): ?>
                                                <small class="text-muted"> · <?= htmlspecialchars(date('M j, Y', strtotime($doc['uploaded_at']))) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="document-actions">
                                            <button type="button" class="btn btn-sm btn-outline-secondary document-view-btn" title="Preview file">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <a href="<?= htmlspecialchars($doc['file_path']) ?>" download
                                               class="btn btn-sm btn-outline-secondary" title="Download file">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted document-empty-state">No documents uploaded yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </div>

    <!-- update student record modal -->
    <div class="modal fade" id="addRecordsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">

                <div class="modal-header add-header">
                    <h5 class="modal-title add-title">Update Student Record</h5>

                    <div class="header-btn-group">
                        <button type="button" class="btn scan-document-btn" disabled title="Coming soon">
                            SCAN DOCUMENT
                        </button>

                        <input type="file" id="uploadFileInput" name="document" accept=".pdf,application/pdf" hidden>
                        <button type="button" class="btn upload-file-btn" id="uploadFileBtn">
                            UPLOAD FILE
                        </button>
                    </div>
                </div>

                <div id="ocrStatus" class="ocr-status"></div>

                <form method="POST" id="updateRecordForm">
                    <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
                    <input type="hidden" id="stagedPhotoPath" name="photo_path" value="">

                    <div class="modal-body">

                        <div class="student-contents">

                            <div class="student-sidebar">
                                <div class="photo-container" id="photoContainer">
                                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" class="photo-img" id="modalStudentPhoto">
                                </div>
                                <input type="file" id="replacePhotoInput" name="photo" accept=".jpg,.jpeg,.png" hidden>
                                <button type="button" class="btn btn-sm btn-outline-secondary replace-photo-btn" id="replacePhotoBtn">
                                    <i class="bi bi-arrow-repeat"></i> Replace Photo
                                </button>
                                <small id="photoFeedback" class="text-danger d-block mt-2"></small>
                            </div>

                            <div class="student-details">
                                <ul class="nav nav-tabs" id="editTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="edit-info-tab" data-bs-toggle="tab"
                                                data-bs-target="#edit-info" type="button" role="tab" aria-selected="true">
                                            Student Information
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="edit-docs-tab" data-bs-toggle="tab"
                                                data-bs-target="#edit-docs" type="button" role="tab" aria-selected="false">
                                            Documents
                                        </button>
                                    </li>
                                </ul>

                                <div class="tab-content border border-top-0 p-3 pt-4 ps-4">

                                    <div class="tab-pane fade show active" id="edit-info" role="tabpanel">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_last_name" class="form-label form-label-custom">Last Name:</label>
                                                    <input type="text" id="edit_last_name" name="last_name" class="form-control input-custom" required pattern="[A-Za-zÑñ\s.,'\-]+"
                                                           value="<?= htmlspecialchars($editLastName) ?>">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_first_name" class="form-label form-label-custom">First Name:</label>
                                                    <input type="text" id="edit_first_name" name="first_name" class="form-control input-custom" required pattern="[A-Za-zÑñ\s.,'\-]+"
                                                           value="<?= htmlspecialchars($editFirstName) ?>">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_middle_name" class="form-label form-label-custom">Middle Name:</label>
                                                    <input type="text" id="edit_middle_name" name="middle_name" class="form-control input-custom" pattern="[A-Za-zÑñ\s.,'\-]+"
                                                           value="<?= htmlspecialchars($editMiddleName) ?>" placeholder="(optional)">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_student_number" class="form-label form-label-custom">Student Number:</label>
                                                    <input type="text" id="edit_student_number" name="student_number" class="form-control input-custom" required
                                                           value="<?= htmlspecialchars($student['student_number'] ?? '') ?>" maxlength="15" placeholder="0000-00000-BN-0">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_course" class="form-label form-label-custom">Course:</label>
                                                    <input type="text" id="edit_course" name="course" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['course'] ?? '') ?>" pattern="[A-Za-z\s\-]+" placeholder="BSIT">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_year_section" class="form-label form-label-custom">Year &amp; Section:</label>
                                                    <input type="text" id="edit_year_section" name="year_section" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['year_section'] ?? '') ?>" pattern="[0-9]-[0-9]" inputmode="numeric" maxlength="3" title="One number for the year and one for the section, e.g. 1-1" placeholder="1-1">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_sex" class="form-label form-label-custom">Gender:</label>
                                                    <input type="text" id="edit_sex" name="sex" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['sex'] ?? '') ?>" pattern="[A-Za-z\s]+" placeholder="Female/Male">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_admission_track" class="form-label form-label-custom">Admission Type:</label>
                                                    <select id="edit_admission_track" name="admission_track" class="form-select input-custom">
                                                        <?php foreach (getAdmissionTracks() as $trackValue => $trackLabel): ?>
                                                            <option value="<?= htmlspecialchars($trackValue) ?>" <?= $trackValue === $admissionTrack ? 'selected' : '' ?>><?= htmlspecialchars($trackLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_date_of_birth" class="form-label form-label-custom">Date of Birth:</label>
                                                    <input type="date" id="edit_date_of_birth" name="date_of_birth" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['date_of_birth'] ?? '') ?>" min="1900-01-01" max="<?= minimumBirthDate() ?>"
                                                           title="The student must be at least 17 years old">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_place_of_birth" class="form-label form-label-custom">Place of Birth:</label>
                                                    <input type="text" id="edit_place_of_birth" name="place_of_birth" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['place_of_birth'] ?? '') ?>" placeholder="City/Municipality, Province">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_graduation_year" class="form-label form-label-custom">Graduation SY:</label>
                                                    <select id="edit_graduation_year" name="graduation_year" class="form-select input-custom"
                                                            title="The student is moved to Archive as GRADUATE after July 31 at the end of this school year">
                                                        <?php $autoYear = entryYearFromStudentNumber($student['student_number'] ?? ''); ?>
                                                        <option value="" <?= $savedGradYear === null ? 'selected' : '' ?>>
                                                            Auto<?= $autoYear !== null ? ' (' . htmlspecialchars(schoolYearLabel($autoYear + PROGRAM_LENGTH_YEARS)) . ')' : '' ?>
                                                        </option>
                                                        <?php foreach ($gradYearOptions as $gradOption): ?>
                                                            <option value="<?= (int) $gradOption ?>" <?= $savedGradYear === $gradOption ? 'selected' : '' ?>><?= htmlspecialchars(schoolYearLabel($gradOption)) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_mobile_number" class="form-label form-label-custom">Mobile Number:</label>
                                                    <input type="text" id="edit_mobile_number" name="mobile_number" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['mobile_number'] ?? '') ?>" pattern="[0-9]{11}" maxlength="11" inputmode="numeric" placeholder="09XXXXXXXXX">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_email" class="form-label form-label-custom">Email Address:</label>
                                                    <input type="email" id="edit_email" name="email" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['email'] ?? '') ?>" pattern="[^@\s]+@[^@\s]+\.[^@\s]+" title="Enter a valid email address" placeholder="name@example.com">
                                                </div>
                                                <small id="editEmailFeedback" class="text-danger d-block mb-3"></small>
                                                <div class="d-flex align-items-start mb-3">
                                                    <label for="edit_address" class="form-label form-label-custom">Home Address:</label>
                                                    <textarea id="edit_address" name="address" class="form-control input-custom" rows="3" placeholder="House/Unit No., Street Name, Barangay, City/Municipality, Province"><?= htmlspecialchars($student['address'] ?? '') ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tab-pane fade" id="edit-docs" role="tabpanel">
                                        <div class="documents-tab-layout">
                                            <div class="documents-list-col">
                                                <div id="requirementChecklist" class="requirement-checklist"></div>
                                                <div class="documents-section-label">On file</div>
                                                <div id="modalDocumentList" class="d-flex flex-column gap-2 mb-3">
                                                    <?php if (!empty($documentRows)): ?>
                                                        <?php foreach ($documentRows as $doc): ?>
                                                            <div class="document-row" data-document-id="<?= (int) $doc['document_id'] ?>"
                                                                 data-file-path="<?= htmlspecialchars($doc['file_path']) ?>"
                                                                 data-requirement-code="<?= htmlspecialchars($doc['requirement_code'] ?? '') ?>">
                                                                <div class="document-row-body flex-grow-1">
                                                                    <span class="document-row-title"><?= htmlspecialchars(requirementLabel($doc['requirement_code'] ?? null)) ?></span>
                                                                    <span class="document-name d-block text-muted small"><?= htmlspecialchars($doc['file_name']) ?></span>
                                                                    <select class="form-select form-select-sm document-type-select" aria-label="Document type"></select>
                                                                </div>
                                                                <div class="document-actions">
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary document-view-btn" title="Preview file">
                                                                        <i class="bi bi-eye"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary document-change-btn" title="Replace file">
                                                                        <i class="bi bi-arrow-repeat"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger document-delete-btn" title="Delete file">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="text-muted document-empty-state">No documents uploaded yet.</div>
                                                    <?php endif; ?>
                                                </div>

                                                <input type="file" id="replaceDocumentInput" accept=".pdf" hidden>

                                                <div class="documents-section-label">New uploads (saved when you click UPDATE)</div>
                                                <div id="scannedFilePreview" class="d-flex flex-column gap-2 mb-3">
                                                    <div class="text-muted document-empty-state">No new file attached yet.</div>
                                                </div>
                                                <small id="addDocumentFeedback" class="text-danger d-block mb-3"></small>
                                            </div>

                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" class="btn btn-save" id="saveDocumentBtn">UPDATE</button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- confirm update modal -->
    <div class="modal fade" id="confirmSaveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Student Record Update</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Please confirm the details before saving:</p>
                    <table class="table table-sm confirm-summary-table">
                        <tr><th>Student Number</th><td id="confirmSummaryNumber"></td></tr>
                        <tr><th>Name</th><td id="confirmSummaryName"></td></tr>
                        <tr><th>Course</th><td id="confirmSummaryCourse"></td></tr>
                        <tr><th>Year &amp; Section</th><td id="confirmSummarySection"></td></tr>
                        <tr><th>Admission Type</th><td id="confirmSummaryTrack"></td></tr>
                        <tr><th>Attached Documents</th><td id="confirmSummaryDocs"></td></tr>
                        <tr><th>Requirements Status</th><td id="confirmSummaryCompletion"></td></tr>
                        <tr><th>Missing Requirements</th><td id="confirmSummaryMissing"></td></tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Back to Edit</button>
                    <button type="button" class="btn btn-save" id="confirmSaveBtn">Confirm &amp; Update</button>
                </div>
            </div>
        </div>
    </div>

    <!-- confirm delete document modal -->
    <div class="modal fade" id="confirmDeleteDocModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete Document</h5>
                </div>
                <div class="modal-body">
                    <p>
                        Are you sure you want to delete <strong id="confirmDeleteDocName"></strong>?
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-save" id="confirmDeleteDocBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- docked document preview panel -->
    <aside id="documentPreviewPanel" class="document-preview-panel" aria-hidden="true" aria-label="Document preview" inert>
        <div class="document-preview-resize-handle" id="documentPreviewResize" title="Drag to resize"></div>
        <div class="document-preview-header">
            <span class="document-preview-title" id="documentPreviewTitle">Document Preview</span>
            <div class="document-preview-header-actions">
                <a id="documentPreviewOpenTab" href="#" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary" title="Open full size in a new tab">
                    <i class="bi bi-arrows-angle-expand"></i>
                </a>
                <button type="button" id="documentPreviewClose" class="btn-close" aria-label="Close preview"></button>
            </div>
        </div>
        <div class="document-preview-body" id="documentPreviewBody"></div>
    </aside>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <div id="documentPageData" hidden data-json="<?= htmlspecialchars(json_encode([
        'requirements' => requirementsForClient(),
        'groups'       => getDocumentRequirementGroups(),
        'tracks'       => getAdmissionTracks(),
        'documents'    => $clientDocuments,
    ]), ENT_QUOTES, 'UTF-8') ?>"></div>
    <script src="../js/view-student.js?v=<?= @filemtime(__DIR__ . '/../js/view-student.js') ?>"></script>
</body>
</html>