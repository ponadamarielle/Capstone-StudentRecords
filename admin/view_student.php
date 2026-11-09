<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/course_catalog.php';
require_once __DIR__ . '/document_classifier.php';
require_once __DIR__ . '/ai_extraction.php';
require_once __DIR__ . '/archive.php';
require_once __DIR__ . '/requirement_status.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../student_notifications.php';
require_once __DIR__ . '/../access_control.php';

requireOfficeLogin(($_GET['action'] ?? $_POST['action'] ?? '') !== '');

require_once __DIR__ . '/../account_guard.php';
requireActiveAccount(($_GET['action'] ?? $_POST['action'] ?? '') !== '');
requirePermission('students.view', ($_GET['action'] ?? $_POST['action'] ?? '') !== '');

const VIEW_ACTION_PERMISSIONS = [
    'ocr_extract'             => 'documents.upload',
    'add_documents'           => 'documents.upload',
    'delete_document_record'  => 'documents.delete',
    'replace_document'        => 'documents.replace',
    'commit_replace_document' => 'documents.replace',
    'set_document_type'       => 'documents.set_type',
    'update_student_info'     => 'students.fill_blank',
    'change_status'           => 'students.status',
];
$requestedAction = $_GET['action'] ?? $_POST['action'] ?? '';
if (isset(VIEW_ACTION_PERMISSIONS[$requestedAction])) {
    requirePermission(VIEW_ACTION_PERMISSIONS[$requestedAction], true);
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

    register_shutdown_function(function () use ($action) {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        error_log("view_student.php action '$action' fatal error: " . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $error['message'] . ' (' . basename($error['file']) . ' line ' . $error['line'] . ')',
        ]);
    });

    try {
    switch ($action) {
        case 'ocr_extract':
            handleOcrExtract($conn);
            break;
        case 'delete_document_record':
            handleDeleteDocumentRecord($conn);
            break;
        case 'delete_staged_document':
            handleDeleteStagedDocument($conn);
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
        case 'check_student_number':
            handleCheckEditStudentNumber($conn);
            break;
        case 'archive_if_graduated':
            handleArchiveIfGraduated($conn);
            break;
        case 'commit_replace_document':
            handleCommitReplaceDocument($conn);
            break;
        case 'notify_requirements':
            handleNotifyRequirements($conn);
            break;
        case 'change_status':
            handleChangeStatus($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }
    } catch (Throwable $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            try {
                mysqli_rollback($conn);
            } catch (Throwable $ignored) {
            }
        }
        error_log("view_student.php action '$action' failed: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ' line ' . $e->getLine() . ')',
        ]);
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
    if (isStagedDocument((string) $path)) {
        return true;
    }
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
    $stmt = mysqli_prepare($conn, 'SELECT status' . (ensureStudentArchivedAt($conn) ? ', archived_at' : '') . ' FROM students WHERE student_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row && isStudentArchived($row);
}

function rejectIfArchived($conn, $studentId)
{
    if (isArchivedStudent($conn, $studentId)) {
        echo json_encode(['success' => false, 'message' => 'This student is archived. Archived records can no longer be edited.']);
        return true;
    }
    $id   = (int) $studentId;
    $stmt = mysqli_prepare($conn, 'SELECT status FROM students WHERE student_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (($row['status'] ?? '') !== 'graduated') {
        return false;
    }
    echo json_encode(['success' => false, 'message' => 'This student has already graduated. Graduate records can no longer be updated.']);
    return true;
}

function sarRegionPixels($pdfPath, $page, $x, $y, $w, $h, ?array $store = null)
{
    static $region = null;
    $key = $pdfPath . '#' . (int) $page;
    if ($store !== null) {
        $region = ['key' => $key] + $store;
        return null;
    }
    if ($region === null || $region['key'] !== $key) {
        return null;
    }
    $ox = (int) $x - $region['x'];
    $oy = (int) $y - $region['y'];
    $w  = (int) $w;
    $h  = (int) $h;
    if ($ox < 0 || $oy < 0 || $w <= 0 || $h <= 0 || $ox + $w > $region['width'] || $oy + $h > $region['height']) {
        return null;
    }
    $rowLen = $region['width'] * 3;
    $data = '';
    for ($r = 0; $r < $h; $r++) {
        $data .= substr($region['data'], ($oy + $r) * $rowLen + $ox * 3, $w * 3);
    }
    return ['width' => $w, 'height' => $h, 'data' => $data];
}

function sarFrameLines($mask, $w, $h, $minRun, $maxGap, $band)
{
    $rowsMask = str_split($mask, $w);
    $zero = str_repeat("\0", $w);
    $hb = [];
    foreach ($rowsMask as $r) {
        $o = $r;
        for ($b = 1; $b < $band && $b < $w; $b++) {
            $o |= substr($r, $b) . str_repeat("\0", $b);
        }
        $hb[] = $o;
    }

    $hit = $zero;
    if ($minRun <= 1) {
        foreach ($hb as $r) {
            $hit |= $r;
        }
    } else {
        $next = [];
        for ($y = 0; $y < $h; $y++) {
            $o = $zero;
            for ($k = 1; $k <= $maxGap + 1 && $y + $k < $h; $k++) {
                $o |= $hb[$y + $k];
            }
            $next[] = $o;
        }
        $len = $minRun - 1;
        $res = null;
        $off = 0;
        $pow = $next;
        $p = 1;
        while ($len > 0) {
            if ($len & 1) {
                if ($res === null) {
                    $res = $pow;
                } else {
                    $new = [];
                    foreach ($res as $y => $s) {
                        if (!isset($pow[$y + $off])) {
                            break;
                        }
                        $new[] = $s & $pow[$y + $off];
                    }
                    $res = $new;
                }
                $off += $p;
            }
            $len >>= 1;
            if ($len > 0) {
                $np = [];
                $c = count($pow);
                for ($y = 0; $y + $p < $c; $y++) {
                    $np[] = $pow[$y] & $pow[$y + $p];
                }
                $pow = $np;
                $p *= 2;
            }
        }
        foreach ($res as $y => $s) {
            $hit |= $hb[$y] & $s;
        }
    }
    $cols = array_keys(array_filter(str_split($hit), function ($c) { return $c === "\1"; }));

    $rows = [];
    $pattern = '/\x01(?:\x00{0,' . (int) $maxGap . '}\x01)*/';
    for ($y = 0; $y < $h; $y++) {
        $o = $rowsMask[$y];
        for ($b = 1; $b < $band && $y + $b < $h; $b++) {
            $o |= $rowsMask[$y + $b];
        }
        if (strpos($o, "\1") === false) {
            continue;
        }
        if (preg_match_all($pattern, $o, $m) && max(array_map('strlen', $m[0])) >= $minRun) {
            $rows[] = $y;
        }
    }
    return [$cols, $rows];
}

function detectSarPhotoFrame($pdfPath, $page = 1)
{
    $sx = 1650;
    $sy = 150;
    $sw = 896;
    $sh = 1650;

    $ppmFile = takeSarPhotoRender($pdfPath, $page);
    if ($ppmFile === null) {
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
    }
    if (!file_exists($ppmFile)) {
        return null;
    }
    $ppm = readPpmPixels($ppmFile);
    @unlink($ppmFile);
    if ($ppm === null) {
        return null;
    }
    sarRegionPixels($pdfPath, $page, 0, 0, 0, 0, ['x' => $sx, 'y' => $sy, 'width' => $ppm['width'], 'height' => $ppm['height'], 'data' => $ppm['data']]);

    $w = $ppm['width'];
    $h = $ppm['height'];
    $data = $ppm['data'];
    $n = $w * $h;
    if (strlen($data) < $n * 3) {
        return null;
    }

    $minRun = 350;
    $maxGap = 4;
    $band   = 8;
    $cols   = [];
    $rows   = [];
    foreach ([480, 600] as $threshold) {
        $mask = str_repeat("\0", $n);
        for ($i = 0; $i < $n; $i++) {
            $o = $i * 3;
            if (ord($data[$o]) + ord($data[$o + 1]) + ord($data[$o + 2]) < $threshold) {
                $mask[$i] = "\1";
            }
        }
        [$cols, $rows] = sarFrameLines($mask, $w, $h, $minRun, $maxGap, $band);
        if (count($cols) >= 2 && $rows) {
            break;
        }
    }

    if (!$cols || !$rows) {
        return null;
    }

    $left  = min($cols) + $band - 1;
    $right = max($cols);
    $top   = min($rows) + $band - 1;
    $width = $right - $left;
    if ($width < 450) {
        $width = 600;
        $right = $left + $width;
    }
    if ($width > 750) {
        return null;
    }
    $below  = array_filter($rows, function ($r) use ($top) { return $r > $top + 400; });
    $bottom = $below ? min($below) + $band - 1 : $top + $width;
    if (!$below && $top + $width > $h && $top - $width >= 0) {
        $bottom = $top;
        $top    = $bottom - $width;
    }

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

    $ppm = sarRegionPixels($pdfPath, $page, (int) $box['x'], (int) $box['y'], $size, $cropH);
    if ($ppm === null) {
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
    }
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

function findHealthPhotoFrame(array $ppm, $threshold = 300)
{
    $w = $ppm['width'];
    $h = $ppm['height'];
    $data = $ppm['data'];
    $dark = function ($x, $y) use ($data, $w, $threshold) {
        $o = ($y * $w + $x) * 3;
        return ord($data[$o]) + ord($data[$o + 1]) + ord($data[$o + 2]) < $threshold;
    };

    $samples = 60;
    $colHits = array_fill(0, $w, 0);
    for ($i = 0; $i < $samples; $i++) {
        $y = (int) (($i + 0.5) * $h / $samples);
        for ($x = 0; $x < $w; $x++) {
            if ($dark($x, $y)) {
                $colHits[$x]++;
            }
        }
    }
    $cols = [];
    foreach ($colHits as $x => $hits) {
        if ($hits >= $samples * 0.3) {
            $cols[] = $x;
        }
    }
    if (count($cols) < 2) {
        return null;
    }
    $left = min($cols);
    $right = max($cols);
    if ($right - $left < 400 || $right - $left > 850) {
        return null;
    }

    $probe = [];
    for ($i = 0; $i < 40; $i++) {
        $probe[] = $left + 10 + (int) (($i + 0.5) * ($right - $left - 20) / 40);
    }
    $rows = [];
    for ($y = 0; $y < $h; $y++) {
        $hits = 0;
        foreach ($probe as $x) {
            if ($dark($x, $y)) {
                $hits++;
            }
        }
        if ($hits >= 36) {
            $rows[] = $y;
        }
    }
    if (count($rows) < 2) {
        return null;
    }
    $top = min($rows);
    $below = array_filter($rows, fn($y) => $y > $top + 350);
    if (!$below) {
        return null;
    }
    $bottom = min($below);
    if ($bottom - $top > 900) {
        return null;
    }

    $inset = 8;
    return [
        'x'      => $left + $inset,
        'y'      => $top + $inset,
        'width'  => $right - $left - $inset * 2,
        'height' => $bottom - $top - $inset * 2,
    ];
}

function findHealthPhotoArea(array $ppm)
{
    $w = $ppm['width'];
    $h = $ppm['height'];
    $data = $ppm['data'];
    $step = 3;
    $colCount = array_fill(0, $w, 0);
    for ($y = 0; $y < $h; $y += $step) {
        $row = $y * $w * 3;
        for ($x = 0; $x < $w; $x++) {
            $o = $row + $x * 3;
            if (ord($data[$o]) + ord($data[$o + 1]) + ord($data[$o + 2]) < 600) {
                $colCount[$x]++;
            }
        }
    }
    $need = (int) (($h / $step) * 0.18);
    $best = [0, 0];
    $start = null;
    for ($x = 0; $x <= $w; $x++) {
        $on = $x < $w && $colCount[$x] >= $need;
        if ($on && $start === null) {
            $start = $x;
        } elseif (!$on && $start !== null) {
            if ($x - $start > $best[1] - $best[0]) {
                $best = [$start, $x];
            }
            $start = null;
        }
    }
    [$left, $right] = $best;
    if ($right - $left < 350 || $right - $left > 900) {
        return null;
    }
    $rowNeed = (int) (($right - $left) / $step * 0.25);
    $rows = [];
    for ($y = 0; $y < $h; $y++) {
        $count = 0;
        $row = $y * $w * 3;
        for ($x = $left; $x < $right; $x += $step) {
            $o = $row + $x * 3;
            if (ord($data[$o]) + ord($data[$o + 1]) + ord($data[$o + 2]) < 600) {
                $count++;
            }
        }
        if ($count >= $rowNeed) {
            $rows[] = $y;
        }
    }
    if (count($rows) < 2) {
        return null;
    }
    $top = min($rows);
    $bottom = max($rows);
    if ($bottom - $top < 350 || $bottom - $top > 950) {
        return null;
    }
    return ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top];
}

function healthPhotoSquare(array $box)
{
    $size = min($box['width'], $box['height']);
    return [
        'x'      => $box['x'] + (int) round(($box['width'] - $size) / 2),
        'y'      => $box['y'],
        'width'  => $size,
        'height' => $size,
    ];
}

function startHealthPhotoRender($pdfPath, $page)
{
    $base = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('hifpre_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN) . ' -scale-to-x 2550 -scale-to-y -1 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' -x 1450 -y 0 -W 1100 -H 1250 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base);
    $pipes = [];
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return;
    }
    fclose($pipes[0]);
    $GLOBALS['healthPhotoRenders'][$pdfPath][(int) $page] = ['proc' => $proc, 'pipes' => $pipes, 'base' => $base];
}

function takeHealthPhotoRender($pdfPath, $page)
{
    $job = $GLOBALS['healthPhotoRenders'][$pdfPath][(int) $page] ?? null;
    if ($job === null) {
        return null;
    }
    unset($GLOBALS['healthPhotoRenders'][$pdfPath][(int) $page]);
    stream_get_contents($job['pipes'][1]);
    stream_get_contents($job['pipes'][2]);
    fclose($job['pipes'][1]);
    fclose($job['pipes'][2]);
    proc_close($job['proc']);
    foreach (['-1.ppm', '.ppm'] as $suffix) {
        if (file_exists($job['base'] . $suffix)) {
            return $job['base'] . $suffix;
        }
    }
    return null;
}

function startSarPhotoRender($pdfPath, $page)
{
    $base = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('sarpre_', true);
    $cmd = escapeshellarg(PDFTOPPM_BIN) . ' -r 300 -singlefile -f ' . (int) $page . ' -l ' . (int) $page
        . ' -x 1650 -y 150 -W 896 -H 1650 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base);
    $pipes = [];
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return;
    }
    fclose($pipes[0]);
    $GLOBALS['sarPhotoRenders'][$pdfPath][(int) $page] = ['proc' => $proc, 'pipes' => $pipes, 'base' => $base];
}

function takeSarPhotoRender($pdfPath, $page)
{
    $job = $GLOBALS['sarPhotoRenders'][$pdfPath][(int) $page] ?? null;
    if ($job === null) {
        return null;
    }
    unset($GLOBALS['sarPhotoRenders'][$pdfPath][(int) $page]);
    stream_get_contents($job['pipes'][1]);
    stream_get_contents($job['pipes'][2]);
    fclose($job['pipes'][1]);
    fclose($job['pipes'][2]);
    proc_close($job['proc']);
    foreach (['-1.ppm', '.ppm'] as $suffix) {
        if (file_exists($job['base'] . $suffix)) {
            return $job['base'] . $suffix;
        }
    }
    return null;
}

function prepareSarPhotoEarly($pdfPath, $page)
{
    $GLOBALS['sarPhotoEarly'][$pdfPath][(int) $page] = extractIdPhoto($pdfPath, 'sar_form', $page);
}

function prepareHealthPhotoEarly($pdfPath, $page)
{
    $GLOBALS['healthPhotoEarly'][$pdfPath][(int) $page] = extractHealthPhoto(
        $pdfPath, $page, ['x' => 1848, 'y' => 73, 'width' => 592, 'height' => 571], stagedPhotoBase()
    );
}

function finishHealthPhotoRenders($pdfPath)
{
    foreach (['healthPhotoEarly', 'sarPhotoEarly'] as $earlyKey) {
        foreach ($GLOBALS[$earlyKey][$pdfPath] ?? [] as $earlyPath) {
            if ($earlyPath !== null) {
                @unlink($earlyPath);
            }
        }
        unset($GLOBALS[$earlyKey][$pdfPath]);
    }
    foreach (array_keys($GLOBALS['sarPhotoRenders'][$pdfPath] ?? []) as $sarPage) {
        $sarFile = takeSarPhotoRender($pdfPath, $sarPage);
        if ($sarFile !== null) {
            @unlink($sarFile);
        }
    }
    foreach (array_keys($GLOBALS['healthPhotoRenders'][$pdfPath] ?? []) as $page) {
        $file = takeHealthPhotoRender($pdfPath, $page);
        if ($file !== null) {
            @unlink($file);
        }
    }
}

function extractHealthPhoto($pdfPath, $page, array $fallback, $photoBase)
{
    if (isset($GLOBALS['healthPhotoEarly'][$pdfPath]) && array_key_exists((int) $page, $GLOBALS['healthPhotoEarly'][$pdfPath])) {
        $earlyPath = $GLOBALS['healthPhotoEarly'][$pdfPath][(int) $page];
        unset($GLOBALS['healthPhotoEarly'][$pdfPath][(int) $page]);
        return $earlyPath;
    }
    $sx = 1450;
    $sy = 0;
    $sw = 1100;
    $sh = 1250;

    $ppm = null;
    $prerendered = takeHealthPhotoRender($pdfPath, $page);
    if ($prerendered !== null) {
        $ppm = readPpmPixels($prerendered);
        @unlink($prerendered);
        if ($ppm !== null && strlen($ppm['data']) < $ppm['width'] * $ppm['height'] * 3) {
            $ppm = null;
        }
    }
    foreach ($ppm === null ? ['-scale-to-x 2550 -scale-to-y -1', '-r 300'] : [] as $scale) {
        $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid('hifphoto_', true);
        $cmd = escapeshellarg(PDFTOPPM_BIN)
            . ' ' . $scale . ' -singlefile -f ' . (int) $page . ' -l ' . (int) $page
            . ' -x ' . $sx . ' -y ' . $sy . ' -W ' . $sw . ' -H ' . $sh
            . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1';
        $cmdOut = [];
        exec($cmd, $cmdOut, $cmdCode);
        $ppmFile = $tmpBase . '-1.ppm';
        if (!file_exists($ppmFile)) {
            $ppmFile = $tmpBase . '.ppm';
        }
        if (!file_exists($ppmFile)) {
            error_log('Health form photo: page ' . (int) $page . ' could not be rendered (' . $scale . '): ' . implode(' ', $cmdOut));
            continue;
        }
        $ppm = readPpmPixels($ppmFile);
        @unlink($ppmFile);
        if ($ppm !== null && strlen($ppm['data']) >= $ppm['width'] * $ppm['height'] * 3) {
            break;
        }
        $ppm = null;
    }
    if ($ppm === null) {
        return null;
    }

    $candidates = [];
    foreach ([300, 450, 560, 650] as $threshold) {
        $frame = findHealthPhotoFrame($ppm, $threshold);
        if ($frame !== null && $frame['y'] <= 450) {
            $candidates[] = ['photo frame', $frame];
        }
    }
    $area = findHealthPhotoArea($ppm);
    if ($area !== null && $area['y'] <= 450) {
        $candidates[] = ['photo area', $area];
    }
    $candidates[] = ['fixed position', [
        'x'      => (int) $fallback['x'] - $sx,
        'y'      => (int) $fallback['y'] - $sy,
        'width'  => (int) $fallback['width'],
        'height' => (int) $fallback['height'],
    ]];

    $best = null;
    foreach ($candidates as [$method, $candidate]) {
        $square = healthPhotoSquare($candidate);
        $square['x'] = max(0, $square['x']);
        $square['y'] = max(0, $square['y']);
        $square['width'] = min($square['width'], $ppm['width'] - $square['x']);
        $square['height'] = min($square['height'], $ppm['height'] - $square['y']);
        if ($square['width'] < 100 || $square['height'] < 100) {
            continue;
        }
        $rowLen = $ppm['width'] * 3;
        $crop = '';
        for ($r = 0; $r < $square['height']; $r++) {
            $crop .= substr($ppm['data'], ($square['y'] + $r) * $rowLen + $square['x'] * 3, $square['width'] * 3);
        }
        $total = $square['width'] * $square['height'];
        $step = max(1, (int) floor($total / 6000));
        $skin = 0;
        $count = 0;
        for ($i = 0; $i < $total; $i += $step) {
            $o = $i * 3;
            $r = ord($crop[$o]);
            $g = ord($crop[$o + 1]);
            $b = ord($crop[$o + 2]);
            $count++;
            if ($r > 95 && $g > 40 && $b > 20 && (max($r, $g, $b) - min($r, $g, $b)) > 15
                && abs($r - $g) > 15 && $r > $g && $r > $b) {
                $skin++;
            }
        }
        $ratio = $count ? $skin / $count : 0;
        if ($best === null || $ratio > $best['ratio'] + 0.02) {
            $best = ['method' => $method, 'box' => $square, 'crop' => $crop, 'ratio' => $ratio];
        }
    }
    if ($best === null || $best['ratio'] < 0.03) {
        error_log('Health form photo: no area that looks like a photo was found (best skin ' . round(($best['ratio'] ?? 0) * 100, 1) . '%).');
        return null;
    }
    $box = $best['box'];
    $crop = $best['crop'];
    error_log('Health form photo: found by ' . $best['method'] . ', ' . $box['width'] . 'x' . $box['height'] . ' at '
        . ($box['x'] + $sx) . ',' . ($box['y'] + $sy) . ', skin ' . round($best['ratio'] * 100, 1) . '%.');

    $path = $photoBase . '.png';
    return writePngRgb($path, $box['width'], $box['height'], $crop) ? $path : null;
}

function extractIdPhoto($pdfPath, $docType, $page = 1)
{
    static $idPhotoBoxes = [
        'sar_form' => ['x' => 1866, 'y' => 440, 'width' => 577, 'height' => 573],
        'health_information_form' => ['x' => 1848, 'y' => 73, 'width' => 592, 'height' => 571],
    ];

    if (!isset($idPhotoBoxes[$docType])) {
        return null;
    }
    if ($docType === 'sar_form' && isset($GLOBALS['sarPhotoEarly'][$pdfPath])
        && array_key_exists((int) $page, $GLOBALS['sarPhotoEarly'][$pdfPath])) {
        $earlyPath = $GLOBALS['sarPhotoEarly'][$pdfPath][(int) $page];
        unset($GLOBALS['sarPhotoEarly'][$pdfPath][(int) $page]);
        return $earlyPath;
    }
    $box = $idPhotoBoxes[$docType];
    $isSar = $docType === 'sar_form';
    if ($isSar) {
        $frame = detectSarPhotoFrame($pdfPath, $page) ?? $box;
        $box = sarPhotoBox($frame);
    }

    $photoBase = stagedPhotoBase();

    if ($docType === 'health_information_form') {
        return extractHealthPhoto($pdfPath, $page, $box, $photoBase);
    }

    if ($isSar) {
        if (!cropLooksLikePersonPhoto($pdfPath, $box, 0.04, $page)) {
            return null;
        }
        $withSpace = savePhotoWithTopSpace($pdfPath, $page, $box, $photoBase, 0.075);
        if ($withSpace !== null) {
            return $withSpace;
        }
    }

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
            $r['clinic_only']  = true;   // handled by the clinic: hidden from the registrar checklist
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
    $ppm = sarRegionPixels($pdfPath, $page, (int) $box['x'], (int) $box['y'], (int) $box['width'], (int) $box['height']);
    if ($ppm === null) {
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
    }

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

    $uploadDir = stagedDocumentDir();
    $saved = saveUploadedPdf('document', $uploadDir);
    if (is_string($saved)) {
        echo json_encode(['success' => false, 'message' => $saved]);
        return;
    }
    [$destPath, $originalName] = $saved;

    $result = processDocumentUpload($destPath, $originalName, $uploadDir, ['single_file' => ($_POST['scanned'] ?? '') === '1' || preg_match('/^scanned_\d{4}-\d{2}-\d{2}_\d{6}(-\d+)?\.pdf$/i', $originalName), 'skip_sar_photo' => ($_POST['has_photo'] ?? '') === '1']);

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
    return ((int) date('Y') - 17) . '-12-31';
}

function keepFilledStudentFields($conn, $studentId)
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE student_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $studentId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row) {
        return;
    }
    $fields = ['last_name', 'first_name', 'middle_name', 'student_number', 'course', 'year_section', 'sex',
        'date_of_birth', 'place_of_birth', 'mobile_number', 'email', 'address', 'graduation_year',
        'shift_from_course', 'previous_school', 'previous_course', 'previous_year_level'];
    foreach ($fields as $field) {
        if (array_key_exists($field, $row) && trim((string) $row[$field]) !== '') {
            $_POST[$field] = (string) $row[$field];
        }
    }
    $track = trim((string) ($row['admission_track'] ?? ''));
    if ($track !== '') {
        $_POST['student_type'] = studentTypeOf($track);
        $_POST['admission_track'] = $track;
    }
    if (trim((string) ($row['photo_path'] ?? '')) !== '') {
        $_POST['photo_path'] = '';
        unset($_FILES['photo']);
    }
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

    $studentBefore = activityFetchStudent($conn, (int) $studentId);
    if (!can('students.edit')) {
        keepFilledStudentFields($conn, (int) $studentId);
    }

    $lastName    = trim($_POST['last_name'] ?? '');
    $firstName   = trim($_POST['first_name'] ?? '');
    $middleName  = trim($_POST['middle_name'] ?? '');
    if ($lastName === '' || $firstName === '') {
        echo json_encode(['success' => false, 'message' => 'Please fill in the Last Name and First Name.']);
        return;
    }
    $name        = buildFullName($lastName, $firstName, $middleName);
    $studentNum  = strtoupper(trim($_POST['student_number'] ?? ''));
    if ($studentNum !== '' && $studentNum !== strtoupper(trim($studentBefore['student_number'] ?? '')) && !preg_match('/^\d{4}-\d{5}-BN-\d$/', $studentNum)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student number format. Use 0000-00000-BN-0 (e.g. 2021-00123-BN-0).']);
        return;
    }
    $course      = normalizeCourseName($_POST['course'] ?? '', true);
    $yearSection = trim($_POST['year_section'] ?? '');
    if ($yearSection !== '' && !preg_match('/^\d-\d$/', $yearSection)) {
        echo json_encode(['success' => false, 'message' => 'Year & Section must be one number, a dash, and one number (e.g. 1-1).']);
        return;
    }
    $sex         = trim($_POST['sex'] ?? '');
    $dob         = dateOfBirthForDb(trim($_POST['date_of_birth'] ?? ''));
    if ($dob !== null && $dob > minimumBirthDate()) {
        echo json_encode(['success' => false, 'message' => 'Date of Birth is too recent. The student must turn at least 17 years old this year.']);
        return;
    }
    $pob         = trim(preg_replace('/\s+/u', ' ', preg_replace("/[^\\p{L}\\p{M} .,'\\-]/u", '', (string) ($_POST['place_of_birth'] ?? ''))));
    $mobile      = trim($_POST['mobile_number'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $address     = trim(preg_replace('/\s+/u', ' ', preg_replace("/[^\\p{L}\\p{M}0-9\\s.,'\\-#\\/()]/u", '', (string) ($_POST['address'] ?? ''))));
    $track       = normalizeAdmissionTrack($_POST['admission_track'] ?? '');
    ensureStudentTypeColumns($conn);
    $typeDetails = readStudentTypeInput($_POST, $course);
    if ($typeDetails['error'] !== null) {
        echo json_encode(['success' => false, 'student_type_error' => true, 'message' => $typeDetails['error']]);
        return;
    }
    $track = $typeDetails['track'];

    if ($studentNum !== '') {
        $duplicate = findDuplicateStudentNumber($conn, $studentNum, (int) $studentId);
        if ($duplicate !== null) {
            echo json_encode([
                'success'   => false,
                'duplicate' => true,
                'message'   => duplicateStudentNumberMessage($duplicate),
            ]);
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
            if (!isStagedPhoto($stagedPhoto)) {
                echo json_encode(['success' => false, 'message' => 'The scanned photo could not be found. Please scan the document again.']);
                return;
            }

            $lookupSql  = "SELECT photo_path FROM students WHERE student_id = ?";
            $lookupStmt = mysqli_prepare($conn, $lookupSql);
            mysqli_stmt_bind_param($lookupStmt, "i", $studentId);
            mysqli_stmt_execute($lookupStmt);
            $lookupRow    = mysqli_fetch_assoc(mysqli_stmt_get_result($lookupStmt));
            $oldPhotoPath = $lookupRow['photo_path'] ?? null;

            $newPhotoPath  = claimStagedPhoto($stagedPhoto);
            if ($newPhotoPath === null) {
                echo json_encode(['success' => false, 'message' => 'The scanned photo could not be saved. Please scan the document again.']);
                return;
            }
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
            "ssssssssssssssi",
            $name, $lastName, $firstName, $middleName, $studentNum, $course, $yearSection, $sex, $dob, $pob, $mobile, $email, $address, $track, $studentId
        );
    }

    if (mysqli_stmt_execute($stmt)) {
        saveStudentTypeDetails($conn, (int) $studentId, $typeDetails);
        if (in_array('graduation_year', studentColumns($conn), true)) {
            $gradInput = trim($_POST['graduation_year'] ?? '');
            $gradYear  = ctype_digit($gradInput) ? (int) $gradInput : null;
            $gradStmt  = mysqli_prepare($conn, "UPDATE students SET graduation_year = ? WHERE student_id = ?");
            mysqli_stmt_bind_param($gradStmt, "ii", $gradYear, $studentId);
            mysqli_stmt_execute($gradStmt);
        }
        if (in_array('graduation_year', studentColumns($conn), true)) {
            $gradPosted   = trim($_POST['graduation_year'] ?? '');
            $gradBefore   = (string) ($studentBefore['graduation_year'] ?? '');
            $levelBefore  = yearLevelOf($studentBefore['year_section'] ?? '');
            $levelPosted  = yearLevelOf($yearSection);
            if ($gradPosted === $gradBefore && $levelPosted !== null && $levelPosted !== $levelBefore) {
                $entryAfter   = entryYearFromStudentNumber($studentNum);
                $gradForLevel = $entryAfter !== null && $levelPosted === yearLevelForGraduation($entryAfter + PROGRAM_LENGTH_YEARS)
                    ? null
                    : graduationYearForLevel($levelPosted);
                $levelStmt = mysqli_prepare($conn, "UPDATE students SET graduation_year = ? WHERE student_id = ?");
                mysqli_stmt_bind_param($levelStmt, "ii", $gradForLevel, $studentId);
                mysqli_stmt_execute($levelStmt);
            }
        }
        fillGraduationYears($conn, (int) $studentId);
        applyYearLevel($conn, (int) $studentId);
        syncGraduateStatus($conn, (int) $studentId);
        if ($newPhotoPath !== null && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath
            && strpos($oldPhotoPath, '../uploads/photos/') === 0 && is_file($oldPhotoPath)) {
            @unlink($oldPhotoPath);
        }
        logStudentUpdate($conn, (int) $studentId, $studentBefore, $newPhotoPath !== null);
        echo json_encode([
            'success'    => true,
            'message'    => 'Student information updated.',
            'photo_path' => $newPhotoPath,
            'graduated'  => studentHasGraduated($conn, (int) $studentId),
        ]);
    } else {
        if ($newPhotoPath !== null) {
            @unlink($newPhotoPath);
        }
        echo json_encode(['success' => false, 'message' => 'Failed to update: ' . mysqli_error($conn)]);
    }
}

function findDuplicateStudentNumber($conn, $studentNumber, $exceptId)
{
    $stmt = mysqli_prepare($conn, "SELECT student_id, status FROM students WHERE student_number = ? AND student_id != ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "si", $studentNumber, $exceptId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ?: null;
}

function duplicateStudentNumberMessage(array $duplicate)
{
    return isArchivedStatus($duplicate['status'] ?? '')
        ? 'That student number is already used by an archived graduate. The original student number was kept.'
        : 'That student number is already used by another student. The original student number was kept.';
}

function handleCheckEditStudentNumber($conn)
{
    $studentNumber = strtoupper(trim($_GET['student_number'] ?? ''));
    $studentId     = (int) ($_GET['student_id'] ?? 0);
    if ($studentNumber === '') {
        echo json_encode(['success' => false, 'message' => 'No student number provided.']);
        return;
    }
    $duplicate = findDuplicateStudentNumber($conn, $studentNumber, $studentId);
    echo json_encode([
        'success'  => true,
        'exists'   => $duplicate !== null,
        'archived' => $duplicate !== null && isArchivedStatus($duplicate['status'] ?? ''),
        'message'  => $duplicate !== null ? duplicateStudentNumberMessage($duplicate) : '',
    ]);
}

function studentHasGraduated($conn, $studentId)
{
    $student = activityFetchStudent($conn, $studentId);
    if (!$student || isArchivedStudent($conn, $studentId)) {
        return false;
    }
    $endYear = expectedGraduationYear($student);
    return $endYear !== null && schoolYearHasEnded($endYear);
}

function handleNotifyRequirements($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    $notification = notifyStudentRequirements($conn, (int) $studentId, requirementsForClient(), ($_POST['force'] ?? '') === '1');
    echo json_encode(['success' => true, 'notification' => $notification, 'last' => studentNotificationSummary($conn, (int) $studentId)]);
}

function handleChangeStatus($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    $status    = $_POST['status'] ?? '';
    $labels    = ['withdrawn' => 'WITHDRAWN', 'onleave' => 'ON-LEAVE', 'transferred' => 'TRANSFERRED'];
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    if (!isset($labels[$status])) {
        echo json_encode(['success' => false, 'message' => 'Choose WITHDRAWN, ON-LEAVE or TRANSFERRED.']);
        return;
    }
    $studentId = (int) $studentId;
    if (rejectIfArchived($conn, $studentId)) {
        return;
    }
    $before = activityFetchStudent($conn, $studentId);
    if (!$before) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        return;
    }

    $outcome = archiveStudentRecord($conn, $studentId, $status);
    if (empty($outcome['success'])) {
        echo json_encode(['success' => false, 'message' => $outcome['message'] ?? 'Could not change the status.']);
        return;
    }

    $reason = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['reason'] ?? '')));
    $reason = mb_substr($reason, 0, 255);
    $studentOpts = activityStudentOpts($conn, $studentId);
    logActivity('student_status_changed',
        'Changed status of ' . activityStudentLabel($studentOpts) . ' to ' . $labels[$status] . ' and moved the record to Archive',
        $studentOpts + [
            'target_type' => 'student',
            'target_id'   => $studentId,
            'details'     => array_filter([
                'changes'         => ['Status' => ['from' => (string) ($before['status'] ?? 'enrollee'), 'to' => $status]],
                'documents_moved' => $outcome['documents'] ?? 0,
                'reason'          => $reason,
            ], fn($v) => $v !== '' && $v !== null),
        ]);

    echo json_encode([
        'success'  => true,
        'message'  => 'Status changed to ' . $labels[$status] . '. The record was moved to Archive.',
        'redirect' => 'archive.php',
    ]);
}

function handleArchiveIfGraduated($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }
    $studentId = (int) $studentId;
    if (!studentHasGraduated($conn, $studentId)) {
        echo json_encode(['success' => true, 'archived' => false]);
        return;
    }

    syncGraduateStatus($conn, $studentId);
    $student = activityFetchStudent($conn, $studentId);
    $endYear = expectedGraduationYear($student);
    if (!schoolYearHasEnded($endYear + GRADUATE_ARCHIVE_YEARS)) {
        echo json_encode([
            'success'   => true,
            'archived'  => false,
            'graduated' => true,
            'message'   => 'This student has already graduated. The status is now GRADUATE and the record stays in the Student Directory.',
        ]);
        return;
    }

    $outcome = archiveStudentRecord($conn, $studentId, 'graduated', true);
    if (empty($outcome['success'])) {
        echo json_encode(['success' => false, 'message' => $outcome['message'] ?? 'Could not move the student to Archive.']);
        return;
    }

    $studentOpts = activityStudentOpts($conn, $studentId);
    logActivity('student_auto_archived',
        'Archived ' . activityStudentLabel($studentOpts) . ' as GRADUATE after the record was updated',
        $studentOpts + [
            'target_type' => 'student',
            'target_id'   => $studentId,
            'details'     => [
                'new_status'      => 'graduated',
                'year_graduated'  => $endYear,
                'documents_moved' => $outcome['documents'] ?? 0,
                'reason'          => 'Graduated ' . GRADUATE_ARCHIVE_YEARS . ' or more years ago.',
            ],
        ]);

    echo json_encode([
        'success'  => true,
        'archived' => true,
        'message'  => 'This student graduated ' . GRADUATE_ARCHIVE_YEARS . ' or more years ago and was moved to Archive.',
    ]);
}

function handleDeleteStagedDocument($conn = null)
{
    $filePath  = $_POST['file_path'] ?? '';
    $imagePath = $_POST['image_path'] ?? '';
    $photoPath = $_POST['photo_path'] ?? '';

    if ($filePath === '' && $imagePath === '' && $photoPath === '') {
        echo json_encode(['success' => false, 'message' => 'No file path provided.']);
        return;
    }

    $uploadDir = realpath('../uploads/documents/') ?: realpath(stagedDocumentDir());
    if ($uploadDir === false) {
        echo json_encode(['success' => false, 'message' => 'Upload directory not found.']);
        return;
    }

    $paths   = array_filter([$filePath, $imagePath], fn($p) => $p !== '');
    $deleted = true;

    foreach ($paths as $path) {
        $targetPath = realpath($path);
        if (!isStagedDocument($path) && ($targetPath === false || strpos($targetPath, $uploadDir) !== 0 || isSavedRecordFile($conn, $targetPath))) {
            continue;
        }
        if (file_exists($targetPath) && !unlink($targetPath)) {
            $deleted = false;
        }
    }

    if ($photoPath !== '') {
        $photoDir        = realpath('../uploads/photos/');
        $targetPhotoPath = realpath($photoPath);
        if (!isStagedPhoto($photoPath) && ($photoDir === false || $targetPhotoPath === false
            || strpos($targetPhotoPath, $photoDir . DIRECTORY_SEPARATOR) !== 0
            || isSavedRecordFile($conn, $targetPhotoPath))) {
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

    $countStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM documents WHERE student_id = ?");
    mysqli_stmt_bind_param($countStmt, "i", $row['student_id']);
    mysqli_stmt_execute($countStmt);
    $countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
    if ((int) ($countRow['total'] ?? 0) <= 1) {
        echo json_encode([
            'success'       => false,
            'last_document' => true,
            'message'       => 'A student record must have at least one document. Upload another document first, or use Replace to change this file.',
        ]);
        return;
    }

    $deletedDoc = fetchDocumentRow($conn, (int) $documentId);

    $deleteSql  = "DELETE FROM documents WHERE document_id = ?";
    $deleteStmt = mysqli_prepare($conn, $deleteSql);
    mysqli_stmt_bind_param($deleteStmt, "i", $documentId);

    if (mysqli_stmt_execute($deleteStmt)) {
        if (isPathInsideUploads($row['file_path']) && file_exists($row['file_path'])) {
            @unlink($row['file_path']);
        }
        $studentOpts = activityStudentOpts($conn, $row['student_id']);
        logActivity('document_deleted',
            'Deleted ' . requirementLabel($deletedDoc['requirement_code'] ?? null) . ' "' . ($deletedDoc['file_name'] ?? '') . '" of ' . activityStudentLabel($studentOpts),
            $studentOpts + [
                'target_type' => 'document',
                'target_id'   => $documentId,
                'details'     => [
                    'file_name'     => $deletedDoc['file_name'] ?? '',
                    'document_type' => requirementLabel($deletedDoc['requirement_code'] ?? null),
                    'file_path'     => $row['file_path'],
                ],
            ]);
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
        if ($extra && is_file($extra) && (isStagedPhoto($extra) || strpos($extra, '../uploads/photos/') === 0 || isPathInsideUploads($extra))) {
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

    $uploadDir = stagedDocumentDir();
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
    if (empty($result['documents']) && !empty($result['extract_only'])) {
        discardUploadResult($result);
        echo json_encode(['success' => false, 'message' => 'The Health Information Form is only used to fill in the student information and is not stored, so it cannot replace a document.']);
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
    if (!empty($result['offline']) && $new['requirement_code'] === null && $row['requirement_code'] !== null) {
        $new['requirement_code'] = $row['requirement_code'];
        $new['requirement_label'] = requirementLabel($row['requirement_code']);
    }

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

    if (!empty($result['offline']) && $warning === '') {
        $warning = 'There is no internet or it is too slow, so the new file was not checked by AI; it was kept as ' . requirementLabel($code) . '.';
    }

    if (($_POST['stage'] ?? '') === '1') {
        echo json_encode([
            'success'          => true,
            'staged'           => true,
            'warning'          => $warning,
            'file_name'        => $originalName,
            'file_path'        => $new['file_path'],
            'requirement_code' => $code,
            'document_type'    => requirementLabel($code),
        ]);
        return;
    }

    $claimedPath = claimStagedDocument($new['file_path']);
    if ($claimedPath === null) {
        echo json_encode(['success' => false, 'message' => 'The new file could not be saved. Please replace it again.']);
        return;
    }
    $new['file_path'] = $claimedPath;

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
    $studentOpts = activityStudentOpts($conn, $studentId);
    logActivity('document_replaced',
        'Replaced ' . $label . ' of ' . activityStudentLabel($studentOpts) . ': "' . $row['file_name'] . '" → "' . $originalName . '"',
        $studentOpts + [
            'target_type' => 'document',
            'target_id'   => $documentId,
            'details'     => [
                'changes' => array_filter([
                    'File'          => ['from' => $row['file_name'], 'to' => $originalName],
                    'Document Type' => requirementLabel($row['requirement_code']) !== $label
                        ? ['from' => requirementLabel($row['requirement_code']), 'to' => $label] : null,
                ]),
                'new_file_path' => $new['file_path'],
            ],
        ]);
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

function handleCommitReplaceDocument($conn)
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

    $filePath = $_POST['file_path'] ?? '';
    $fileName = trim($_POST['file_name'] ?? '') ?: basename($filePath);
    if ($filePath === '' || !is_file($filePath) || !isPathInsideUploads($filePath)) {
        echo json_encode(['success' => false, 'message' => 'The replacement for "' . $row['file_name'] . '" is missing on the server. Please replace it again.']);
        return;
    }
    if ($filePath === $row['file_path']) {
        echo json_encode(['success' => true, 'message' => 'Nothing to replace.']);
        return;
    }

    $studentId = (int) $row['student_id'];
    $code = $row['requirement_code'];
    if ($code === null) {
        $posted = trim($_POST['requirement_code'] ?? '');
        if ($posted !== '' && isStorableRequirement($posted)) {
            $taken = findStoredRequirement($conn, $studentId, $posted);
            if (!$taken || (int) $taken['document_id'] === (int) $row['document_id']) {
                $code = $posted;
            }
        }
    }

    $hash = pdfContentFingerprint($filePath);
    $sameFile = findStoredFileHash($conn, $studentId, $hash);
    if ($sameFile && (int) $sameFile['document_id'] !== (int) $row['document_id']) {
        echo json_encode(['success' => false, 'message' => 'This exact file is already on record as ' . $sameFile['document_type'] . ' (' . $sameFile['file_name'] . ').']);
        return;
    }

    $claimedPath = claimStagedDocument($filePath);
    if ($claimedPath === null) {
        echo json_encode(['success' => false, 'message' => 'The replacement for "' . $row['file_name'] . '" could not be saved. Please replace it again.']);
        return;
    }
    $filePath = $claimedPath;

    $label = requirementLabel($code);
    $updateStmt = mysqli_prepare($conn, "UPDATE documents
                   SET file_name = ?, file_path = ?, file_hash = ?, requirement_code = ?, document_type = ?,
                       uploaded_at = NOW()
                   WHERE document_id = ?");
    mysqli_stmt_bind_param($updateStmt, "sssssi", $fileName, $filePath, $hash, $code, $label, $documentId);
    try {
        mysqli_stmt_execute($updateStmt);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to replace "' . $row['file_name'] . '": ' . $e->getMessage()]);
        return;
    }

    if ($row['file_path'] !== '' && isPathInsideUploads($row['file_path']) && is_file($row['file_path'])) {
        @unlink($row['file_path']);
    }
    $studentOpts = activityStudentOpts($conn, $studentId);
    logActivity('document_replaced',
        'Replaced ' . $label . ' of ' . activityStudentLabel($studentOpts) . ': "' . $row['file_name'] . '" → "' . $fileName . '"',
        $studentOpts + [
            'target_type' => 'document',
            'target_id'   => $documentId,
            'details'     => [
                'changes' => array_filter([
                    'File'          => ['from' => $row['file_name'], 'to' => $fileName],
                    'Document Type' => requirementLabel($row['requirement_code']) !== $label
                        ? ['from' => requirementLabel($row['requirement_code']), 'to' => $label] : null,
                ]),
                'new_file_path' => $filePath,
            ],
        ]);
    echo json_encode(['success' => true, 'message' => 'Document replaced.']);
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
    $oldLabel = requirementLabel($row['requirement_code']);
    if ($oldLabel !== $label) {
        $studentOpts = activityStudentOpts($conn, $row['student_id']);
        logActivity('document_type_changed',
            'Changed type of "' . $row['file_name'] . '" (' . activityStudentLabel($studentOpts) . ') from ' . $oldLabel . ' to ' . $label,
            $studentOpts + [
                'target_type' => 'document',
                'target_id'   => $documentId,
                'details'     => ['changes' => ['Document Type' => ['from' => $oldLabel, 'to' => $label]],
                                  'file_name' => $row['file_name']],
            ]);
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

    foreach ($clean as $i => $d) {
        $claimedPath = claimStagedDocument($d['file_path']);
        if ($claimedPath === null) {
            echo json_encode(['success' => false, 'message' => 'The file "' . $d['file_name'] . '" could not be saved. Please upload it again.']);
            return;
        }
        $clean[$i]['file_path'] = $claimedPath;
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

    $studentOpts = activityStudentOpts($conn, $studentId);
    foreach ($clean as $d) {
        logActivity('document_uploaded',
            'Uploaded ' . $d['label'] . ' "' . $d['file_name'] . '" for ' . activityStudentLabel($studentOpts),
            $studentOpts + [
                'target_type' => 'document',
                'details'     => ['file_name' => $d['file_name'], 'document_type' => $d['label'], 'file_path' => $d['file_path']],
            ]);
    }
    echo json_encode(['success' => true, 'message' => count($clean) . ' document(s) saved.']);
}

$studentId = $_GET['id'] ?? '';
if ($studentId === '' || !ctype_digit((string) $studentId)) {
    header("Location: student_directory.php");
    exit();
}

ensureStudentArchivedAt($conn);
ensureStudentTypeColumns($conn);
fillGraduationYears($conn);
organizeStudentDocuments($conn, (int) $studentId);
$sql  = "SELECT * FROM students WHERE student_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $studentId);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student) {
    header("Location: student_directory.php");
    exit();
}

$isArchivedView = isStudentArchived($student);
if ($isArchivedView && !can('archive.view')) {
    header("Location: student_directory.php");
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
if ($issuedBy !== '') {
    $issuedRoleStmt = mysqli_prepare($conn, "SELECT role FROM accounts WHERE name = ? ORDER BY FIELD(role, 'Admin', 'Staff', 'OJT Intern', 'Super Admin') LIMIT 1");
    mysqli_stmt_bind_param($issuedRoleStmt, "s", $issuedBy);
    mysqli_stmt_execute($issuedRoleStmt);
    $issuedRole = mysqli_fetch_assoc(mysqli_stmt_get_result($issuedRoleStmt))['role'] ?? '';
    if ($issuedRole !== '') {
        $issuedBy .= ' (' . $issuedRole . ')';
    }
}

$statusLabels = ['enrollee' => 'Enrollee', 'active' => 'Enrollee', 'graduated' => 'Graduate', 'withdrawn' => 'Withdrawn', 'inactive' => 'Withdrawn', 'onleave' => 'On-leave', 'transferred' => 'Transferred'];
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
    <link href="../css/document-sort.css?v=<?= @filemtime(__DIR__ . '/../css/document-sort.css') ?>" rel="stylesheet">
    <link href="../css/scanner.css?v=<?= @filemtime(__DIR__ . '/../css/scanner.css') ?>" rel="stylesheet">
    <link href="../css/student-report.css?v=<?= @filemtime(__DIR__ . '/../css/student-report.css') ?>" rel="stylesheet">
    <link href="../css/student-profile.css?v=<?= @filemtime(__DIR__ . '/../css/student-profile.css') ?>" rel="stylesheet">
    <link href="../css/course-filter.css?v=<?= @filemtime(__DIR__ . '/../css/course-filter.css') ?>" rel="stylesheet">
</head>
<body data-student-id="<?= (int) $studentId ?>">
    <div class="view-container profile-page">

        <?php if ($isArchivedView): ?>
        <a href="archive.php" class="profile-back" title="Back to Archived Records">
            <i class="bi bi-arrow-left"></i> Back to Archived Records
        </a>
        <?php else: ?>
        <a href="student_directory.php" class="profile-back" title="Back to Student Directory">
            <i class="bi bi-arrow-left"></i> Back to Student Directory
        </a>
        <?php endif; ?>

        <section class="profile-header">
            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" class="profile-photo">
            <div class="profile-identity">
                <div class="profile-name-row">
                    <h2 class="profile-name"><?= htmlspecialchars($student['name']) ?></h2>
                    <?php if ($isArchivedView): ?>
                    <span class="profile-status profile-status-<?= htmlspecialchars(in_array($student['status'] ?? '', ['inactive', 'withdrawn'], true) ? 'withdrawn' : (($student['status'] ?? '') === 'graduated' ? 'graduate' : ($student['status'] ?? ''))) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    <?php elseif (($student['status'] ?? '') === 'graduated'): ?>
                    <span class="profile-status profile-status-graduate"><?= htmlspecialchars($statusLabel) ?></span>
                    <?php elseif (!can('students.status')): ?>
                    <span class="profile-status"><?= htmlspecialchars($statusLabel) ?></span>
                    <?php else: ?>
                    <div class="dropdown status-dropdown">
                        <button type="button" class="profile-status status-dropdown-toggle dropdown-toggle" aria-haspopup="true" aria-expanded="false" aria-label="Change status">
                            <?= htmlspecialchars($statusLabel) ?>
                        </button>
                        <div class="course-filter-menu status-dropdown-menu" role="listbox" hidden>
                            <div class="course-filter-option is-selected" role="option" aria-selected="true" tabindex="-1"><?= htmlspecialchars($statusLabel) ?></div>
                            <div class="course-filter-option status-option" role="option" tabindex="-1" data-status="withdrawn">Withdrawn</div>
                            <div class="course-filter-option status-option" role="option" tabindex="-1" data-status="onleave">On-leave</div>
                            <div class="course-filter-option status-option" role="option" tabindex="-1" data-status="transferred">Transferred</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="profile-meta">
                    <span><i class="bi bi-file-earmark-text"></i> <?= htmlspecialchars($student['student_number']) ?></span>
                    <?php if (!empty($student['course'])): ?>
                        <span><i class="bi bi-mortarboard"></i> <?= htmlspecialchars($student['course']) ?></span>
                    <?php endif; ?>
                    <?php if (($student['status'] ?? '') === 'graduated' && $expectedGradYear !== null): ?>
                        <span><i class="bi bi-mortarboard-fill"></i> Graduated <?= (int) $expectedGradYear ?></span>
                    <?php elseif (!empty($student['year_section'])): ?>
                        <span><i class="bi bi-people"></i> Year &amp; Section: <?= htmlspecialchars($student['year_section']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$isArchivedView): ?>
            <div class="profile-actions">
                <?php if (can('reports')): ?>
                <button class="profile-btn profile-btn-secondary" type="button" id="generateReportBtn" data-student-id="<?= (int) $studentId ?>">
                    <i class="bi bi-file-earmark-pdf"></i> Generate report
                </button>
                <?php endif; ?>
                <?php if (($student['status'] ?? '') !== 'graduated'): ?>
                <button class="profile-btn profile-btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addRecordsModal">
                    <i class="bi bi-pencil"></i> Update record
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="profile-card">
            <ul class="nav profile-tabs" id="viewTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="view-info-tab" data-bs-toggle="tab"
                            data-bs-target="#view-info" type="button" role="tab" aria-selected="true">
                        Student information
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="view-docs-tab" data-bs-toggle="tab"
                            data-bs-target="#view-docs" type="button" role="tab" aria-selected="false">
                        Documents <span class="profile-tab-count"><?= count($orderedDocs) ?></span>
                    </button>
                </li>
                <?php if (count($orderedDocs) > 1): ?>
                <li class="nav-item doc-sort-item" role="presentation" hidden>
                    <span class="doc-sort-prefix">Sort by</span>
                    <div class="doc-sort-tabs" role="group" aria-label="Sort documents">
                        <button type="button" class="doc-sort-tab active" aria-pressed="true" data-sort="default">Default</button>
                        <button type="button" class="doc-sort-tab" aria-pressed="false" data-sort="name_asc">A-Z</button>
                        <button type="button" class="doc-sort-tab" aria-pressed="false" data-sort="newest">Newest</button>
                        <button type="button" class="doc-sort-tab" aria-pressed="false" data-sort="oldest">Oldest</button>
                    </div>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content profile-tab-content">

                <div class="tab-pane fade show active" id="view-info" role="tabpanel">
                    <?php
                    $dobDisplay = !empty($student['date_of_birth']) && strtotime($student['date_of_birth'])
                        ? date('F j, Y', strtotime($student['date_of_birth'])) : '';
                    $infoGroups = [
                        ['Personal', 'bi-person', [
                            'Gender'        => $student['sex'] ?? '',
                            'Date of birth' => $dobDisplay,
                            'Place of birth'=> $student['place_of_birth'] ?? '',
                        ]],
                        ['Contact', 'bi-telephone', [
                            'Mobile number' => $student['mobile_number'] ?? '',
                            'Email address' => $student['email'] ?? '',
                            'Home address'  => $student['address'] ?? '',
                        ]],
                        ['Academic', 'bi-mortarboard', [
                            'Student type'        => studentTypeSummary($student),
                            (($student['status'] ?? '') === 'graduated' ? 'Last year & section' : 'Year & section') => $student['year_section'] ?? '',
                            'Year of graduation'  => $expectedGradYear !== null ? (string) $expectedGradYear : '',
                            'Date issued'         => $dateIssued,
                            'Prepared by'         => $issuedBy,
                        ]],
                    ];
                    ?>
                    <div class="profile-info-grid">
                        <?php foreach ($infoGroups as [$groupTitle, $groupIcon, $groupFields]): ?>
                            <div class="profile-info-card">
                                <p class="profile-info-title"><i class="bi <?= $groupIcon ?>"></i> <?= htmlspecialchars($groupTitle) ?></p>
                                <dl class="profile-info-list">
                                    <?php foreach ($groupFields as $fieldLabel => $fieldValue): ?>
                                        <dt><?= htmlspecialchars($fieldLabel) ?></dt>
                                        <dd><?= (string) $fieldValue !== '' ? htmlspecialchars((string) $fieldValue) : '<span class="profile-empty">Not provided</span>' ?></dd>
                                    <?php endforeach; ?>
                                </dl>
                            </div>
                        <?php endforeach; ?>
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
                        <?php $emailSummary = studentNotificationSummary($conn, $studentId); ?>
                        <?php $emailFailed = $emailSummary && !$emailSummary['success']; ?>
                        <?php $emailPending = $emailFailed ? null : studentNotificationPending($conn, (int) $studentId, requirementsForClient()); ?>
                        <small class="d-block mt-1 <?= $emailFailed || $emailPending ? 'text-danger' : 'text-muted' ?>" data-email-note<?= $emailSummary || $emailPending ? '' : ' hidden' ?>>
                            <i class="bi bi-envelope"></i> <span data-email-note-text><?= $emailPending ? htmlspecialchars($emailPending['text']) : ($emailSummary ? htmlspecialchars($emailSummary['text']) : '') ?></span>
                        </small>
                        <button type="button" class="btn btn-sm btn-outline-danger mt-2" id="resendEmailBtn" data-student-id="<?= (int) $studentId ?>"<?= ($emailFailed || $emailPending) && !$isArchivedView ? '' : ' hidden' ?>><i class="bi bi-send"></i> <?= $emailPending ? 'SEND EMAIL' : 'SEND EMAIL AGAIN' ?></button>
                    </div>
                    <div id="documentList" class="d-flex flex-column gap-2">
                        <?php if (!empty($orderedDocs)): ?>
                            <?php foreach ($orderedDocs as $doc): ?>
                                <div class="document-row requirement-view-row"
                                     data-document-id="<?= (int) $doc['document_id'] ?>"
                                     data-file-path="<?= htmlspecialchars($doc['file_path']) ?>"
                                     data-sort-name="<?= htmlspecialchars(isStorableRequirement($doc['requirement_code'] ?? null) ? requirementLabel($doc['requirement_code']) : ($doc['document_type'] ?: 'Other Document')) ?>"
                                     data-uploaded="<?= !empty($doc['uploaded_at']) ? (int) strtotime($doc['uploaded_at']) : 0 ?>">
                                    <i class="bi bi-file-earmark-text req-status"></i>
                                    <div class="req-body">
                                        <div class="req-label"><?= htmlspecialchars(isStorableRequirement($doc['requirement_code'] ?? null) ? requirementLabel($doc['requirement_code']) : ($doc['document_type'] ?: 'Other Document')) ?></div>
                                    </div>
                                    <div class="document-actions">
                                        <?php if (!empty($doc['uploaded_at'])): ?>
                                            <time class="doc-uploaded-ago" datetime="<?= htmlspecialchars(date('c', strtotime($doc['uploaded_at']))) ?>"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['uploaded_at']))) ?></time>
                                        <?php endif; ?>
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
        </section>
    </div>

    <!-- update student record modal -->
    <div class="modal fade" id="addRecordsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">

                <div class="modal-header add-header">
                    <div class="add-heading">
                        <span class="add-heading-icon"><i class="bi bi-pencil"></i></span>
                        <div class="add-heading-text">
                            <h5 class="modal-title add-title">Update student record</h5>
                            <p class="add-subtitle">Scan or upload the student's documents to auto-fill the information.</p>
                        </div>
                    </div>

                    <div class="header-btn-group">
                        <button type="button" class="btn scan-document-btn">
                            SCAN DOCUMENT
                        </button>

                        <input type="file" id="uploadFileInput" name="document" accept=".pdf,application/pdf" hidden>
                        <button type="button" class="btn upload-file-btn" id="uploadFileBtn">
                            UPLOAD FILE
                        </button>
                    </div>
                </div>

                <div id="ocrStatus" class="ocr-status"></div>

                <form method="POST" id="updateRecordForm" data-can-edit-info="<?= can('students.edit') ? '1' : '0' ?>" data-has-photo="<?= $photoUrl !== $defaultAvatarUri ? '1' : '0' ?>">
                    <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
                    <input type="hidden" id="stagedPhotoPath" name="photo_path" value="">

                    <div class="modal-body">

                        <div class="student-contents">

                            <div class="student-sidebar">
                                <div class="photo-container" id="photoContainer">
                                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student photo" class="photo-img" id="modalStudentPhoto">
                                </div>
                                <span class="photo-caption">Student photo</span>
                                <?php if (can('students.edit')): ?>
                                <small class="photo-hint">JPG or PNG</small>
                                <?php endif; ?>
                                <input type="file" id="replacePhotoInput" name="photo" accept=".jpg,.jpeg,.png" hidden>
                                <button type="button" class="btn btn-sm btn-outline-secondary replace-photo-btn" id="replacePhotoBtn"<?= can('students.edit') ? '' : ' hidden' ?>>
                                    <?php if ($photoUrl !== $defaultAvatarUri): ?>
                                        <i class="bi bi-arrow-repeat"></i> Replace photo
                                    <?php else: ?>
                                        <i class="bi bi-upload"></i> Upload photo
                                    <?php endif; ?>
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
                                        <?php if (!can('students.edit')): ?>
                                            <div class="alert alert-secondary small mb-3">Only the Admin can edit student information. Blank fields are filled in from the documents you upload.</div>
                                        <?php endif; ?>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_last_name" class="form-label form-label-custom">Last name <span class="req-mark">*</span></label>
                                                    <input type="text" id="edit_last_name" name="last_name" class="form-control input-custom" required pattern="[A-Za-zÑñ\s.,'\-]+"
                                                           value="<?= htmlspecialchars($editLastName) ?>">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_first_name" class="form-label form-label-custom">First name <span class="req-mark">*</span></label>
                                                    <input type="text" id="edit_first_name" name="first_name" class="form-control input-custom" required pattern="[A-Za-zÑñ\s.,'\-]+"
                                                           value="<?= htmlspecialchars($editFirstName) ?>">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_middle_name" class="form-label form-label-custom">Middle name</label>
                                                    <input type="text" id="edit_middle_name" name="middle_name" class="form-control input-custom" pattern="[A-Za-zÑñ\s.,'\-]+"
                                                           value="<?= htmlspecialchars($editMiddleName) ?>" placeholder="(optional)">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_student_number" class="form-label form-label-custom">Student number <span class="req-mark">*</span></label>
                                                    <input type="text" id="edit_student_number" name="student_number" class="form-control input-custom" required
                                                           value="<?= htmlspecialchars($student['student_number'] ?? '') ?>" maxlength="15" placeholder="0000-00000-BN-0" inputmode="numeric" autocomplete="off">
                                                </div>
                                                <small id="editStudentNumberFeedback" class="text-danger d-block mb-3" hidden></small>
                                                <div class="d-flex align-items-center mb-3" id="editCourseRow">
                                                    <label for="edit_course" class="form-label form-label-custom">Course</label>
                                                    <?php
                                                        $currentCourse = normalizeCourseName($student['course'] ?? '');
                                                        $courseChoices = [];
                                                        foreach (getCourseCatalog() as $courseEntry) {
                                                            if (($courseEntry['status'] ?? 'active') === 'active') {
                                                                $courseChoices[] = courseDisplayName($courseEntry);
                                                            }
                                                        }
                                                        if ($currentCourse !== '' && !in_array($currentCourse, $courseChoices, true)) {
                                                            $courseChoices[] = $currentCourse;
                                                        }
                                                    ?>
                                                    <select id="edit_course" name="course" class="form-select input-custom">
                                                        <option value="">Select course</option>
                                                        <?php foreach ($courseChoices as $courseChoice): ?>
                                                            <option value="<?= htmlspecialchars($courseChoice) ?>" <?= $courseChoice === $currentCourse ? 'selected' : '' ?>><?= htmlspecialchars($courseChoice) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_year_section" class="form-label form-label-custom">Year &amp; section</label>
                                                    <input type="text" id="edit_year_section" name="year_section" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['year_section'] ?? '') ?>" pattern="[0-9]-[0-9]" inputmode="numeric" maxlength="3" title="One number for the year and one for the section, e.g. 1-1" placeholder="1-1">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_graduation_year" class="form-label form-label-custom">Year of Graduation</label>
                                                    <select id="edit_graduation_year" name="graduation_year" class="form-select input-custom"
                                                            data-current-sy-end="<?= (int) currentSchoolYearEndYear() ?>"
                                                            title="The status changes to GRADUATE after the Year-End Commencement of the Year of Graduation">
                                                        <?php $autoYear = entryYearFromStudentNumber($student['student_number'] ?? ''); ?>
                                                        <option value="" <?= $savedGradYear === null ? 'selected' : '' ?>>
                                                            Auto<?= $autoYear !== null ? ' (' . (int) ($autoYear + PROGRAM_LENGTH_YEARS) . ')' : '' ?>
                                                        </option>
                                                        <?php foreach ($gradYearOptions as $gradOption): ?>
                                                            <option value="<?= (int) $gradOption ?>" <?= $savedGradYear === $gradOption ? 'selected' : '' ?>><?= (int) $gradOption ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <small id="editGraduationNote" class="text-danger d-block mb-3" hidden></small>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_sex" class="form-label form-label-custom">Gender</label>
                                                    <input type="text" id="edit_sex" name="sex" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['sex'] ?? '') ?>" pattern="[A-Za-z\s]+" placeholder="Female/Male">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <?php $editStudentType = studentTypeOf($admissionTrack); ?>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_student_type" class="form-label form-label-custom">Student type</label>
                                                    <select id="edit_student_type" name="student_type" class="form-select input-custom">
                                                        <?php foreach (STUDENT_TYPES as $typeValue => $typeLabel): ?>
                                                            <option value="<?= htmlspecialchars($typeValue) ?>" <?= $typeValue === $editStudentType ? 'selected' : '' ?>><?= htmlspecialchars($typeLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="d-flex align-items-center mb-3" id="editAdmissionTrackRow">
                                                    <label for="edit_admission_track" class="form-label form-label-custom">Admission type</label>
                                                    <select id="edit_admission_track" name="admission_track" class="form-select input-custom">
                                                        <?php foreach (regularAdmissionTracks() as $trackValue => $trackLabel): ?>
                                                            <option value="<?= htmlspecialchars($trackValue) ?>" <?= $trackValue === $admissionTrack ? 'selected' : '' ?>><?= htmlspecialchars($trackLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="student-type-fields" data-student-type="shiftee" <?= $editStudentType === 'shiftee' ? '' : 'hidden' ?>>
                                                    <div class="d-flex align-items-center mb-3">
                                                        <label for="edit_shift_from_course" class="form-label form-label-custom">Shifting from <span class="req-mark">*</span></label>
                                                        <select id="edit_shift_from_course" name="shift_from_course" class="form-select input-custom">
                                                            <option value="">Select previous course</option>
                                                            <?php $shiftFrom = (string) ($student['shift_from_course'] ?? ''); $shiftFromListed = false; ?>
                                                            <?php foreach (getCourseCatalog() as $courseEntry): ?>
                                                                <?php $shiftOption = courseDisplayName($courseEntry); $shiftFromListed = $shiftFromListed || $shiftOption === $shiftFrom; ?>
                                                                <option value="<?= htmlspecialchars($shiftOption) ?>" <?= $shiftOption === $shiftFrom ? 'selected' : '' ?>><?= htmlspecialchars($shiftOption) ?></option>
                                                            <?php endforeach; ?>
                                                            <?php if ($shiftFrom !== '' && !$shiftFromListed): ?>
                                                                <option value="<?= htmlspecialchars($shiftFrom) ?>" selected><?= htmlspecialchars($shiftFrom) ?></option>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                    <div class="d-flex align-items-center mb-3">
                                                        <label for="edit_shift_to_course" class="form-label form-label-custom">Shifting to <span class="req-mark">*</span></label>
                                                        <select id="edit_shift_to_course" class="form-select input-custom" data-mirrors="edit_course">
                                                            <option value="">Select new course</option>
                                                            <?php foreach ($courseChoices as $courseChoice): ?>
                                                                <option value="<?= htmlspecialchars($courseChoice) ?>" <?= $courseChoice === $currentCourse ? 'selected' : '' ?>><?= htmlspecialchars($courseChoice) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="student-type-fields" data-student-type="transferee" <?= $editStudentType === 'transferee' ? '' : 'hidden' ?>>
                                                    <div class="d-flex align-items-center mb-3">
                                                        <label for="edit_previous_school" class="form-label form-label-custom">Previous school <span class="req-mark">*</span></label>
                                                        <input type="text" id="edit_previous_school" name="previous_school" class="form-control input-custom" maxlength="255"
                                                               value="<?= htmlspecialchars($student['previous_school'] ?? '') ?>" placeholder="School the student transferred from">
                                                    </div>
                                                    <div class="d-flex align-items-center mb-3">
                                                        <label for="edit_previous_course" class="form-label form-label-custom">Previous course <span class="req-mark">*</span></label>
                                                        <input type="text" id="edit_previous_course" name="previous_course" class="form-control input-custom" maxlength="255"
                                                               value="<?= htmlspecialchars($student['previous_course'] ?? '') ?>" placeholder="Course taken in the previous school">
                                                    </div>
                                                    <div class="d-flex align-items-center mb-3">
                                                        <label for="edit_previous_year_level" class="form-label form-label-custom">Last year level <span class="req-mark">*</span></label>
                                                        <select id="edit_previous_year_level" name="previous_year_level" class="form-select input-custom">
                                                            <option value="">Select year level</option>
                                                            <?php foreach (PREVIOUS_YEAR_LEVELS as $levelLabel): ?>
                                                                <option value="<?= htmlspecialchars($levelLabel) ?>" <?= ($student['previous_year_level'] ?? '') === $levelLabel ? 'selected' : '' ?>><?= htmlspecialchars($levelLabel) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_date_of_birth" class="form-label form-label-custom">Date of birth</label>
                                                    <input type="date" id="edit_date_of_birth" name="date_of_birth" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['date_of_birth'] ?? '') ?>" min="1900-01-01" max="<?= minimumBirthDate() ?>"
                                                           title="The student must turn at least 17 years old this year">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_place_of_birth" class="form-label form-label-custom">Place of birth</label>
                                                    <input type="text" id="edit_place_of_birth" name="place_of_birth" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['place_of_birth'] ?? '') ?>" placeholder="City/Municipality, Province">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_mobile_number" class="form-label form-label-custom">Mobile number</label>
                                                    <input type="text" id="edit_mobile_number" name="mobile_number" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['mobile_number'] ?? '') ?>" pattern="[0-9]{11}" maxlength="11" inputmode="numeric" placeholder="09XXXXXXXXX">
                                                </div>
                                                <div class="d-flex align-items-center mb-3">
                                                    <label for="edit_email" class="form-label form-label-custom">Email address</label>
                                                    <input type="email" id="edit_email" name="email" class="form-control input-custom"
                                                           value="<?= htmlspecialchars($student['email'] ?? '') ?>" pattern="[^@\s]+@[^@\s]+\.[^@\s]+" title="Enter a valid email address" placeholder="name@example.com">
                                                </div>
                                                <small id="editEmailFeedback" class="text-danger d-block mb-3"></small>
                                                <div class="d-flex align-items-start mb-3">
                                                    <label for="edit_address" class="form-label form-label-custom">Home address</label>
                                                    <textarea id="edit_address" name="address" class="form-control input-custom" rows="3" placeholder="House/Unit No., Street Name, Barangay, City/Municipality, Province"><?= htmlspecialchars($student['address'] ?? '') ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <small id="editInfoFeedback" class="text-danger d-block" hidden></small>
                                    </div>

                                    <div class="tab-pane fade" id="edit-docs" role="tabpanel">
                                        <div class="documents-tab-layout">
                                            <div class="documents-list-col">
                                                <div id="requirementChecklist" class="requirement-checklist"></div>
                                                <div class="documents-section-label">New uploads (saved when you click UPDATE)</div>
                                                <div id="scannedFilePreview" class="d-flex flex-column gap-2 mb-3">
                                                    <div class="text-muted document-empty-state">No new file attached yet.</div>
                                                </div>
                                                <small id="addDocumentFeedback" class="text-danger d-block mb-3"></small>
                                                <div class="documents-section-label">On file</div>
                                                <div id="modalDocumentList" class="d-flex flex-column gap-2 mb-3">
                                                    <?php if (!empty($documentRows)): ?>
                                                        <?php foreach ($documentRows as $doc): ?>
                                                            <div class="document-row" data-document-id="<?= (int) $doc['document_id'] ?>"
                                                                 data-file-path="<?= htmlspecialchars($doc['file_path']) ?>"
                                                                 data-requirement-code="<?= htmlspecialchars($doc['requirement_code'] ?? '') ?>">
                                                                <div class="document-row-body flex-grow-1">
                                                                    <span class="document-row-title d-block"><?= htmlspecialchars(requirementLabel($doc['requirement_code'] ?? null)) ?></span>
                                                                    <select class="form-select form-select-sm document-type-select" aria-label="Document type"></select>
                                                                </div>
                                                                <div class="document-actions">
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary document-view-btn" title="Preview file">
                                                                        <i class="bi bi-eye"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary document-change-btn" title="Replace file">
                                                                        <i class="bi bi-arrow-repeat"></i>
                                                                    </button>
                                                                    <?php if (can('documents.delete')): ?>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger document-delete-btn" title="Delete file">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="text-muted document-empty-state">No documents uploaded yet.</div>
                                                    <?php endif; ?>
                                                </div>

                                                <input type="file" id="replaceDocumentInput" accept=".pdf" hidden>

                                            </div>

                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="modal-footer add-footer">
                        <span class="add-footer-note"><i class="bi bi-info-circle"></i> Fields marked <span class="req-mark">*</span> are required</span>
                        <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" class="btn btn-save" id="saveDocumentBtn">UPDATE</button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- confirm update modal -->
    <div class="modal fade" id="confirmSaveModal" tabindex="-1" aria-hidden="true" data-bs-focus="false">
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

    <div class="modal fade" id="typeChangeModal" tabindex="-1" aria-hidden="true" data-bs-focus="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header confirm-header">
                    <div class="add-heading">
                        <span class="add-heading-icon"><i class="bi bi-arrow-left-right"></i></span>
                        <div class="add-heading-text">
                            <h5 class="modal-title confirm-title" id="typeChangeTitle">Change student type</h5>
                            <p class="add-subtitle" id="typeChangeNote"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Changing the student type will change the required documents. Continue?</p>
                </div>
                <div class="modal-footer confirm-footer">
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-save" id="typeChangeConfirmBtn">Continue</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (($student['status'] ?? '') !== 'graduated' && !$isArchivedView && can('students.status')): ?>
    <div class="modal fade" id="changeStatusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header confirm-header">
                    <div class="add-heading">
                        <span class="add-heading-icon"><i class="bi bi-arrow-left-right"></i></span>
                        <div class="add-heading-text">
                            <h5 class="modal-title confirm-title" id="changeStatusTitle">Change status</h5>
                            <p class="add-subtitle" id="changeStatusNote"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-body">
                    <p class="mb-0" id="changeStatusMessage" data-student-name="<?= htmlspecialchars($student['name']) ?>"></p>
                    <div id="changeStatusError" class="alert alert-danger small mt-3 mb-0 d-none"></div>
                </div>
                <div class="modal-footer confirm-footer">
                    <button type="button" class="btn btn-cancel" id="changeStatusCancelBtn" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-save" id="changeStatusConfirmBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- confirm delete document modal -->
    <div class="modal fade" id="confirmDeleteDocModal" tabindex="-1" aria-hidden="true" data-bs-focus="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete Document</h5>
                </div>
                <div class="modal-body">
                    <p>
                        Are you sure you want to delete <strong id="confirmDeleteDocName"></strong>?
                    </p>
                    <small class="text-muted d-block">The document will only be removed when you click UPDATE.</small>
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
    <script src="../js/document-sort.js?v=<?= @filemtime(__DIR__ . '/../js/document-sort.js') ?>"></script>
    <script src="../js/scanner.js?v=<?= @filemtime(__DIR__ . '/../js/scanner.js') ?>"></script>
    <script src="../js/student-report.js?v=<?= @filemtime(__DIR__ . '/../js/student-report.js') ?>"></script>
</body>
</html>