<?php
include '../security_headers.php';
session_start();
require_once __DIR__ . '/course_catalog.php';
require_once __DIR__ . '/document_classifier.php';
require_once __DIR__ . '/ai_extraction.php';
require_once __DIR__ . '/archive.php';
require_once __DIR__ . '/requirement_status.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../database_backup.php';
require_once __DIR__ . '/../student_notifications.php';
require_once __DIR__ . '/../access_control.php';
require_once __DIR__ . '/../year_end_notice.php';

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

$action = $_GET['action'] ?? '';
$isAjaxAction = $action !== '';

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || !isOfficeRole()) {
    if ($isAjaxAction) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit();
    }
    header("Location: ../signin.php");
    exit();
}

require_once __DIR__ . '/../account_guard.php';
requireActiveAccount($isAjaxAction);

if (!$isAjaxAction && isset($_SESSION['is_first_login']) && $_SESSION['is_first_login'] == 1) {
    header("Location: ../change_password.php");
    exit();
}

$DBHost = "localhost";
$DBUser = "root";
$DBPass = "";
$DBName = "db_pup_eRecords";

define('PDFTOPPM_BIN', 'C:\\xampp\\htdocs\\CAPSTONE\\poppler-26.02.0\\Library\\bin\\pdftoppm.exe');

loadEnv(__DIR__ . '/../.env');

$conn = mysqli_connect($DBHost, $DBUser, $DBPass, $DBName);

if(!$conn) {
    if ($isAjaxAction) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Connection failed: ' . mysqli_connect_error()]);
        exit();
    }
    die("Connection failed: " .mysqli_connect_error());
}

if ($isAjaxAction) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');

    switch ($action) {
        case 'ocr_extract':
            handleOcrExtract();
            break;
        case 'check_student_number':
            handleCheckStudentNumber($conn);
            break;
        case 'add_student':
            handleAddStudent($conn);
            break;
        case 'update_status':
            requirePermission('students.status', true);
            handleUpdateStatus($conn);
            break;
        case 'delete_document':
            handleDeleteDocument($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }
    exit();
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

function findHealthPhotoBox(array $ppm)
{
    $w = $ppm['width'];
    $h = $ppm['height'];
    $data = $ppm['data'];
    $hw = intdiv($w, 2);
    $hh = intdiv($h, 2);
    if ($hw < 300 || $hh < 300) {
        return null;
    }

    $lum = function ($x, $y) use ($data, $w, $h) {
        if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
            return 765;
        }
        $o = ($y * $w + $x) * 3;
        return ord($data[$o]) + ord($data[$o + 1]) + ord($data[$o + 2]);
    };
    $rows = [];   // horizontal line pixels
    $vrows = [];  // vertical line pixels
    for ($y = 0; $y < $hh; $y++) {
        $row = str_repeat("\0", $hw);
        $vrow = $row;
        $fy = $y * 2;
        for ($x = 0; $x < $hw; $x++) {
            $fx = $x * 2;
            if (min($lum($fx, $fy), $lum($fx + 1, $fy), $lum($fx, $fy + 1), $lum($fx + 1, $fy + 1)) >= 560) {
                continue;
            }
            if ($lum($fx, $fy - 8) > 600 || $lum($fx, $fy + 8) > 600) {
                $row[$x] = "\1";
            }
            if ($lum($fx - 8, $fy) > 600 || $lum($fx + 8, $fy) > 600) {
                $vrow[$x] = "\1";
            }
        }
        $rows[] = $row;
        $vrows[] = $vrow;
    }

    $band = 6;
    $gap = 3;
    $minRun = 160;

    $wide = [];
    foreach ($vrows as $row) {
        $o = $row;
        for ($b = 1; $b <= $band; $b++) {
            $o |= substr($row, $b) . str_repeat("\0", $b);
            $o |= str_repeat("\0", $b) . substr($row, 0, $hw - $b);
        }
        $wide[] = $o;
    }
    $colRun = array_fill(0, $hw, 0);
    for ($x = 0; $x < $hw; $x++) {
        $run = 0;
        $miss = 0;
        $best = 0;
        for ($y = 0; $y < $hh; $y++) {
            if ($wide[$y][$x] === "\1") {
                $run += $miss + 1;
                $miss = 0;
                if ($run > $best) {
                    $best = $run;
                }
            } elseif ($run > 0 && ++$miss > $gap) {
                $run = 0;
                $miss = 0;
            }
        }
        $colRun[$x] = $best;
    }
    $lines = function (array $runs, $min) {
        $found = [];
        $start = null;
        foreach ($runs as $i => $run) {
            if ($run >= $min) {
                if ($start === null) {
                    $start = $i;
                }
                $end = $i;
            } elseif ($start !== null) {
                $found[] = (int) round(($start + $end) / 2);
                $start = null;
            }
        }
        if ($start !== null) {
            $found[] = (int) round(($start + $end) / 2);
        }
        return $found;
    };
    $cols = $lines($colRun, $minRun);

    $best = null;
    foreach ($cols as $i => $left) {
        foreach (array_slice($cols, $i + 1) as $right) {
            $size = $right - $left;
            if ($size < 225 || $size > 400) {
                continue;
            }
            $span = $size + 1;
            $rowHits = array_fill(0, $hh, 0);
            for ($y = 0; $y < $hh; $y++) {
                $o = $rows[$y];
                for ($b = 1; $b <= $band; $b++) {
                    if ($y + $b < $hh) {
                        $o |= $rows[$y + $b];
                    }
                    if ($y - $b >= 0) {
                        $o |= $rows[$y - $b];
                    }
                }
                $piece = substr($o, $left, $span);
                if (substr_count($piece, "\1") < $span * 0.8) {
                    continue;
                }
                if (preg_match_all('/\x01(?:\x00{0,' . $gap . '}\x01)*/', $piece, $m) && max(array_map('strlen', $m[0])) >= $span * 0.8) {
                    $rowHits[$y] = 1;
                }
            }
            $hLines = $lines($rowHits, 1);
            foreach ($hLines as $top) {
                foreach ($hLines as $bottom) {
                    if ($bottom - $top >= $size * 0.75 && $bottom - $top <= $size * 1.25) {
                        $score = abs(($bottom - $top) - $size) + abs($size - 300) * 0.2;
                        if ($best === null || $score < $best['score']) {
                            $best = ['score' => $score, 'l' => $left, 'r' => $right, 't' => $top, 'b' => $bottom];
                        }
                        break;
                    }
                }
            }
        }
    }
    if ($best === null) {
        return null;
    }

    $inset = 18;
    $box = [
        'x'      => $best['l'] * 2 + $inset,
        'y'      => $best['t'] * 2 + $inset,
        'width'  => ($best['r'] - $best['l']) * 2 - 2 * $inset,
        'height' => ($best['b'] - $best['t']) * 2 - 2 * $inset,
    ];
    return $box['y'] <= 450 ? $box : null;
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
    $followed = findHealthPhotoBox($ppm);
    if ($followed !== null) {
        $candidates[] = ['photo box', $followed];
    }
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
        $fullSize = $square['width'] >= 450 && $square['width'] <= 720;
        if ($fullSize && $ratio >= 0.03 && ($best === null || empty($best['full']))) {
            $best = ['method' => $method, 'box' => $square, 'crop' => $crop, 'ratio' => $ratio, 'full' => true];
        } elseif (($best === null || empty($best['full'])) && ($best === null || $ratio > $best['ratio'] + 0.02)) {
            $best = ['method' => $method, 'box' => $square, 'crop' => $crop, 'ratio' => $ratio, 'full' => false];
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

    [$outW, $outH, $outData] = tidyHealthPhotoCrop($box['width'], $box['height'], $crop);

    $path = $photoBase . '.png';
    return writePngRgb($path, $outW, $outH, $outData) ? $path : null;
}

function tidyHealthPhotoCrop($w, $h, $data, $spaceRatio = 0.02)
{
    $isDark = function ($x, $y) use ($data, $w) {
        $o = ($y * $w + $x) * 3;
        return ord($data[$o]) + ord($data[$o + 1]) + ord($data[$o + 2]) < 450;
    };
    $rowIsLine = function ($y, $x0, $x1) use ($isDark) {
        $hits = 0;
        $n = 0;
        for ($x = $x0; $x < $x1; $x += 2) {
            $n++;
            if ($isDark($x, $y)) {
                $hits++;
            }
        }
        return $n > 0 && $hits >= $n * 0.85;
    };
    $colIsLine = function ($x, $y0, $y1) use ($isDark) {
        $hits = 0;
        $n = 0;
        for ($y = $y0; $y < $y1; $y += 2) {
            $n++;
            if ($isDark($x, $y)) {
                $hits++;
            }
        }
        return $n > 0 && $hits >= $n * 0.85;
    };

    $top = 0;
    $bottom = $h;
    $left = 0;
    $right = $w;
    $window = min(40, (int) ($h / 10), (int) ($w / 10));

    if ($window > 0) {
        for ($y = 0; $y < $window; $y++) {
            if ($rowIsLine($y, 0, $w)) {
                $top = $y + 1;
            }
        }
        for ($y = $h - $window; $y < $h; $y++) {
            if ($rowIsLine($y, 0, $w)) {
                $bottom = $y;
                break;
            }
        }
        for ($x = 0; $x < $window; $x++) {
            if ($colIsLine($x, $top, $bottom)) {
                $left = $x + 1;
            }
        }
        for ($x = $w - $window; $x < $w; $x++) {
            if ($colIsLine($x, $top, $bottom)) {
                $right = $x;
                break;
            }
        }
    }

    $cw = $right - $left;
    $ch = $bottom - $top;
    $size = min($cw, $ch);
    if ($size < 100) {
        return [$w, $h, $data];
    }
    $x0 = $left + intdiv($cw - $size, 2);
    $y0 = $top;

    $sum = [0, 0, 0];
    $count = 0;
    for ($y = $y0; $y < $y0 + min(6, $size); $y++) {
        for ($x = $x0; $x < $x0 + $size; $x++) {
            $o = ($y * $w + $x) * 3;
            $r = ord($data[$o]);
            $g = ord($data[$o + 1]);
            $b = ord($data[$o + 2]);
            if ($r + $g + $b > 540) {
                $sum[0] += $r;
                $sum[1] += $g;
                $sum[2] += $b;
                $count++;
            }
        }
    }
    $bg = $count > 0
        ? chr((int) ($sum[0] / $count)) . chr((int) ($sum[1] / $count)) . chr((int) ($sum[2] / $count))
        : "\xFF\xFF\xFF";

    $space = (int) round($size * $spaceRatio);
    $keep = $size - $space;
    $out = str_repeat(str_repeat($bg, $size), $space);
    for ($y = $y0; $y < $y0 + $keep; $y++) {
        $out .= substr($data, ($y * $w + $x0) * 3, $size * 3);
    }
    return [$size, $size, $out];
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
    if ($docType === 'sar_form') {
        $frame = detectSarPhotoFrame($pdfPath, $page) ?? $box;
        $box = sarPhotoBox($frame);
    }

    $photoBase = stagedPhotoBase();

    if ($docType === 'health_information_form') {
        return extractHealthPhoto($pdfPath, $page, $box, $photoBase);
    }

    if ($docType === 'sar_form') {
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

    if ($docType === 'sar_form') {
        $withSpace = savePhotoWithTopSpace($pdfPath, $page, $box, $photoBase, 0.075);
        if ($withSpace !== null) {
            @unlink($generated);
            return $withSpace;
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

function handleOcrExtract()
{
    @set_time_limit(300);

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or an upload error occurred.']);
        return;
    }

    $originalName = $_FILES['document']['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext !== 'pdf' || mime_content_type($_FILES['document']['tmp_name']) !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'Unsupported file type. Only PDF files are allowed.']);
        return;
    }

    $uploadDir = stagedDocumentDir();

    $destPath = $uploadDir . uniqid('doc_', true) . '.pdf';
    if (!move_uploaded_file($_FILES['document']['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded file.']);
        return;
    }

    echo json_encode(attachAiStatus(processDocumentUpload($destPath, $originalName, $uploadDir, ['single_file' => ($_POST['scanned'] ?? '') === '1' || preg_match('/^scanned_\d{4}-\d{2}-\d{2}_\d{6}(-\d+)?\.pdf$/i', $originalName), 'skip_sar_photo' => ($_POST['has_photo'] ?? '') === '1'])));
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

function handleCheckStudentNumber($conn)
{
    $studentNumber = trim($_GET['student_number'] ?? '');
    if ($studentNumber === '') {
        echo json_encode(['success' => false, 'message' => 'No student number provided.']);
        return;
    }

    $sql = "SELECT student_id FROM students WHERE student_number = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $studentNumber);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    echo json_encode([
        'success' => true,
        'exists'  => mysqli_stmt_num_rows($stmt) > 0,
    ]);
}

function handleDeleteDocument($conn = null)
{
    $filePath = $_POST['file_path'] ?? '';
    $imagePath = $_POST['image_path'] ?? '';
    $photoPath = $_POST['photo_path'] ?? '';
    if ($filePath === '' && $imagePath === '' && $photoPath === '') {
        echo json_encode(['success' => false, 'message' => 'No file path provided.']);
        return;
    }

    $uploadDir = realpath('../uploads/documents/') ?: realpath(stagedDocumentDir());
    $photoDir = realpath('../uploads/photos/');
    if ($uploadDir === false) {
        echo json_encode(['success' => false, 'message' => 'Upload directory not found.']);
        return;
    }

    $paths = array_filter([$filePath, $imagePath], fn($p) => $p !== '');
    $deleted = true;

    foreach ($paths as $path) {
        $targetPath = realpath($path);
        if (!isStagedDocument($path) && ($targetPath === false || strpos($targetPath, $uploadDir) !== 0 || isSavedRecordFile($conn, $targetPath))) {
            $deleted = false;
            continue;
        }
        if (file_exists($targetPath) && !unlink($targetPath)) {
            $deleted = false;
        }
    }

    if ($photoPath !== '') {
        $targetPhotoPath = realpath($photoPath);
        if (!isStagedPhoto($photoPath) && ($photoDir === false || $targetPhotoPath === false || strpos($targetPhotoPath, $photoDir) !== 0
            || isSavedRecordFile($conn, $targetPhotoPath))) {
            $deleted = false;
        } elseif (file_exists($targetPhotoPath) && !unlink($targetPhotoPath)) {
            $deleted = false;
        }
    }

    if ($deleted) {
        echo json_encode(['success' => true, 'message' => 'File deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete file.']);
    }
}

function handleUpdateStatus($conn)
{
    $studentId = $_POST['student_id'] ?? '';
    $status    = $_POST['status'] ?? '';

    if ($studentId === '' || !ctype_digit((string) $studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student.']);
        return;
    }

    if ($status === 'active') {
        $status = 'enrollee';
    }
    if ($status !== 'enrollee') {
        echo json_encode(['success' => false, 'message' => 'GRADUATE is set automatically when the Year of Graduation school year ends.']);
        return;
    }

    $current = activityFetchStudent($conn, (int) $studentId);
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        return;
    }
    if (($current['status'] ?? '') === 'graduated') {
        echo json_encode(['success' => false, 'message' => 'This student has already graduated. The status cannot be changed.']);
        return;
    }
    if (in_array($current['status'] ?? '', ARCHIVED_STATUSES, true)) {
        echo json_encode(['success' => false, 'message' => 'This student is archived. Archived records cannot be changed here.']);
        return;
    }

    $sql = "UPDATE students SET status = ? WHERE student_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'si', $status, $studentId);

    $before = activityFetchStudent($conn, (int) $studentId);
    if (mysqli_stmt_execute($stmt)) {
        if (($before['status'] ?? '') !== $status) {
            $studentOpts = activityStudentOpts($conn, $studentId);
            logActivity('student_status_changed',
                'Changed status of ' . activityStudentLabel($studentOpts) . ' to ' . strtoupper($status === 'enrollee' ? 'Enrollee' : $status),
                $studentOpts + [
                    'target_type' => 'student',
                    'target_id'   => $studentId,
                    'details'     => ['changes' => ['Status' => ['from' => (string) ($before['status'] ?? ''), 'to' => $status]]],
                ]);
        }
        echo json_encode(['success' => true, 'message' => 'Status updated.', 'status' => $status, 'archived' => false]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . mysqli_error($conn)]);
    }
}

function buildFullName($lastName, $firstName, $middleName)
{
    $given = trim($firstName . ' ' . $middleName);
    if ($lastName === '') {
        return $given;
    }
    return $given === '' ? $lastName : $lastName . ', ' . $given;
}

function minimumBirthDate()
{
    return ((int) date('Y') - 17) . '-12-31';
}

function handleAddStudent($conn)
{
    $studentNumber = trim($_POST['student_number'] ?? '');
    $lastName      = trim($_POST['last_name'] ?? '');
    $firstName     = trim($_POST['first_name'] ?? '');
    $middleName    = trim($_POST['middle_name'] ?? '');
    $name          = buildFullName($lastName, $firstName, $middleName);
    $course        = normalizeCourseName($_POST['course'] ?? '', true);
    $yearSection   = trim($_POST['year_section'] ?? '');

    $missing = [];
    if ($studentNumber === '') $missing[] = 'Student Number';
    if ($lastName === '')      $missing[] = 'Last Name';
    if ($firstName === '')     $missing[] = 'First Name';
    if ($course === '')        $missing[] = 'Course';
    if ($yearSection === '')   $missing[] = 'Year & Section';
    if (trim($_POST['email'] ?? '') === '') $missing[] = 'Email Address';

    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please fill in: ' . implode(', ', $missing) . '.',
        ]);
        return;
    }

    $studentNumber = strtoupper($studentNumber);
    if (!preg_match('/^\d{4}-\d{5}-BN-\d$/', $studentNumber)) {
        echo json_encode(['success' => false, 'invalid_student_number' => true, 'message' => 'Invalid student number format. Use 0000-00000-BN-0 (e.g. 2021-00123-BN-0).']);
        return;
    }

    if (!preg_match('/^\d-\d$/', $yearSection)) {
        echo json_encode(['success' => false, 'message' => 'Year & Section must be one number, a dash, and one number (e.g. 1-1).']);
        return;
    }

    $checkSql = "SELECT student_id FROM students WHERE student_number = ? LIMIT 1";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "s", $studentNumber);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    if (mysqli_stmt_num_rows($checkStmt) > 0) {
        echo json_encode(['success' => false, 'duplicate' => true, 'message' => 'That student number already exists.']);
        return;
    }

    $sex              = trim($_POST['sex'] ?? '');
    $dob              = dateOfBirthForDb(trim($_POST['date_of_birth'] ?? ''));
    if ($dob !== null && $dob > minimumBirthDate()) {
        echo json_encode(['success' => false, 'message' => 'Date of Birth is too recent. The student must turn at least 17 years old this year.']);
        return;
    }
    $placeOfBirth     = trim(preg_replace('/\s+/u', ' ', preg_replace("/[^\\p{L}\\p{M} .,'\\-]/u", '', (string) ($_POST['place_of_birth'] ?? ''))));
    $address          = trim(preg_replace('/\s+/u', ' ', preg_replace("/[^\\p{L}\\p{M}0-9\\s.,'\\-#\\/()]/u", '', (string) ($_POST['address'] ?? ''))));
    $mobileNumber    = trim($_POST['mobile_number'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $status           = 'enrollee';
    $admissionTrack   = normalizeAdmissionTrack($_POST['admission_track'] ?? '');
    ensureStudentTypeColumns($conn);
    $typeDetails = readStudentTypeInput($_POST, $course);
    if ($typeDetails['error'] !== null) {
        echo json_encode(['success' => false, 'student_type_error' => true, 'message' => $typeDetails['error']]);
        return;
    }
    $admissionTrack = $typeDetails['track'];

    $photoBinary = null;
    $photoPathFromOcr = null;

    $photoData = $_POST['photo'] ?? '';
    if ($photoData !== '' && str_starts_with($photoData, 'data:image')) {
        $base64 = explode(',', $photoData, 2)[1] ?? '';
        if ($base64 !== '') {
            $photoBinary = base64_decode($base64);
        }
    }

    if ($photoBinary === null && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $photoBinary = file_get_contents($_FILES['photo']['tmp_name']);
        }
    }

    if ($photoBinary === null && $photoData !== '' && !str_starts_with($photoData, 'data:image')) {
        if (isStagedPhoto($photoData)) {
            $photoPathFromOcr = $photoData;
        }
    }

    $submittedDocs = json_decode($_POST['documents'] ?? '[]', true);
    if (!is_array($submittedDocs)) {
        $submittedDocs = [];
    }

    if (count($submittedDocs) === 0) {
        echo json_encode(['success' => false, 'no_documents' => true, 'message' => 'Please upload at least one document before saving the student record.']);
        return;
    }

    $documents   = [];
    $byRequirement = [];
    $byHash        = [];
    foreach ($submittedDocs as $doc) {
        $filePath = $doc['file_path'] ?? '';
        $fileName = $doc['file_name'] ?? basename($filePath);
        if ($filePath === '' || !is_file($filePath) || (!isStagedDocument($filePath) && !pathIsInside($filePath, '../uploads/documents/'))) {
            echo json_encode(['success' => false, 'message' => 'The file "' . $fileName . '" is missing on the server. Please upload it again.']);
            return;
        }

        $code = $doc['requirement_code'] ?? null;
        if ($code === '') {
            $code = null;
        }
        if ($code !== null && !isStorableRequirement($code)) {
            echo json_encode(['success' => false, 'message' => 'Unknown document type for "' . $fileName . '".']);
            return;
        }
        if ($code !== null && isset($byRequirement[$code])) {
            echo json_encode([
                'success' => false,
                'duplicate_document' => true,
                'message' => '"' . $byRequirement[$code] . '" and "' . $fileName . '" are both tagged as '
                    . requirementLabel($code) . '. Keep only one.',
            ]);
            return;
        }

        $hash = pdfContentFingerprint($filePath);
        if (isset($byHash[$hash])) {
            echo json_encode([
                'success' => false,
                'duplicate_document' => true,
                'message' => '"' . $byHash[$hash] . '" and "' . $fileName . '" are the same file. Remove one of them.',
            ]);
            return;
        }

        if ($code !== null) {
            $byRequirement[$code] = $fileName;
        }
        $byHash[$hash] = $fileName;
        $documents[] = [
            'file_path'        => $filePath,
            'file_name'        => $code !== null ? requirementLabel($code) . '.pdf' : $fileName,
            'requirement_code' => $code,
            'document_type'    => requirementLabel($code),
            'file_hash'        => $hash,
        ];
    }

    foreach ($documents as $i => $doc) {
        $claimed = claimStagedDocument($doc['file_path']);
        if ($claimed === null) {
            echo json_encode(['success' => false, 'message' => 'The file "' . $doc['file_name'] . '" could not be saved. Please upload it again.']);
            return;
        }
        $documents[$i]['file_path'] = $claimed;
    }

    $cleanupLocalFiles = function () use ($documents) {
        foreach ($documents as $doc) {
            @unlink($doc['file_path']);
        }
    };

    mysqli_begin_transaction($conn);
    try {
        $sql = "INSERT INTO students
                (student_number, name, last_name, first_name, middle_name, course, year_section, status, sex, date_of_birth,
                 place_of_birth, address, mobile_number, email, admission_track)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "sssssssssssssss",
            $studentNumber, $name, $lastName, $firstName, $middleName, $course, $yearSection, $status,
            $sex, $dob, $placeOfBirth, $address, $mobileNumber, $email, $admissionTrack
        );
        mysqli_stmt_execute($stmt);

        if (mysqli_errno($conn) === 1062) {
            mysqli_rollback($conn);
            echo json_encode(['success' => false, 'duplicate' => true, 'message' => 'That student number already exists.']);
            return;
        }

        $studentId = mysqli_insert_id($conn);
        saveStudentTypeDetails($conn, $studentId, $typeDetails);

        if (count($documents) > 0) {
            $docSql = "INSERT INTO documents
                       (student_id, document_type, requirement_code, file_name, file_path, file_hash, added_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?)";
            $docStmt = mysqli_prepare($conn, $docSql);
            $addedBy = $_SESSION['name'] ?? '';

            foreach ($documents as $doc) {
                mysqli_stmt_bind_param(
                    $docStmt, "issssss",
                    $studentId, $doc['document_type'], $doc['requirement_code'],
                    $doc['file_name'], $doc['file_path'], $doc['file_hash'], $addedBy
                );
                mysqli_stmt_execute($docStmt);
            }
        }

        $photoPath = null;
        if ($photoBinary !== null) {
            $photoDir = '../uploads/photos/';
            if (!is_dir($photoDir)) {
                mkdir($photoDir, 0755, true);
            }
            $photoExt = 'jpg';
            if (isset($_FILES['photo']['name'])) {
                $uploadedExt = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($uploadedExt, ['jpg', 'jpeg', 'png'], true)) {
                    $photoExt = $uploadedExt;
                }
            }
            $photoPath = $photoDir . uniqid('photo_', true) . '.' . $photoExt;
            file_put_contents($photoPath, $photoBinary);
        } elseif ($photoPathFromOcr !== null) {
            $photoPath = claimStagedPhoto($photoPathFromOcr);
        }

        if ($photoPath !== null) {
            $updatePhotoSql = "UPDATE students SET photo_path = ? WHERE student_id = ?";
            $updatePhotoStmt = mysqli_prepare($conn, $updatePhotoSql);
            mysqli_stmt_bind_param($updatePhotoStmt, "si", $photoPath, $studentId);
            mysqli_stmt_execute($updatePhotoStmt);
        }

        mysqli_commit($conn);

        if (in_array('graduation_year', studentColumns($conn), true)) {
            $enteredLevel = yearLevelOf($yearSection);
            $autoEndYear  = expectedGraduationYear(['student_number' => $studentNumber]);
            $autoLevel    = $autoEndYear !== null ? yearLevelForGraduation($autoEndYear) : null;
            if ($enteredLevel !== null && $enteredLevel !== $autoLevel) {
                $gradForLevel = graduationYearForLevel($enteredLevel);
                $gradStmt = mysqli_prepare($conn, "UPDATE students SET graduation_year = ? WHERE student_id = ?");
                mysqli_stmt_bind_param($gradStmt, "ii", $gradForLevel, $studentId);
                mysqli_stmt_execute($gradStmt);
            }
            fillGraduationYears($conn, (int) $studentId);
        }

        $studentOpts = activityStudentOpts($conn, $studentId);
        logActivity('student_created', 'Created student record ' . activityStudentLabel($studentOpts),
            $studentOpts + [
                'target_type' => 'student',
                'target_id'   => $studentId,
                'details'     => [
                    'course'          => $course,
                    'year_section'    => $yearSection,
                    'admission_track' => $admissionTrack,
                    'documents'       => count($documents),
                ],
            ]);
        foreach ($documents as $doc) {
            logActivity('document_uploaded',
                'Uploaded ' . $doc['document_type'] . ' "' . $doc['file_name'] . '" for ' . activityStudentLabel($studentOpts),
                $studentOpts + [
                    'target_type' => 'document',
                    'details'     => ['file_name' => $doc['file_name'], 'document_type' => $doc['document_type'], 'file_path' => $doc['file_path']],
                ]);
        }

        $notification = notifyStudentRequirements($conn, $studentId, requirementsForClient());

        $archiveBlocked = null;

        echo json_encode([
            'success'         => true,
            'message'         => 'Student record saved successfully.',
            'student_id'      => $studentId,
            'notification'    => $notification,
            'archive_blocked' => $archiveBlocked,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);

        if ($e->getCode() === 1062) {
            if (stripos($e->getMessage(), 'uq_student_requirement') !== false || stripos($e->getMessage(), 'uq_student_file_hash') !== false) {
                echo json_encode(['success' => false, 'duplicate_document' => true, 'message' => 'A document for the same requirement was attached twice. Remove the duplicate and save again.']);
            } else {
                echo json_encode(['success' => false, 'duplicate' => true, 'message' => 'That student number already exists.']);
            }
            return;
        }

        $cleanupLocalFiles();
        echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $e->getMessage()]);
    }
}

fillGraduationYears($conn);
archiveGraduatedStudents($conn);
updateYearLevels($conn);
organizeStudentDocuments($conn);
ensureStudentTypeColumns($conn);
schoolYearReminderIfDue();
yearEndNotifyIfDue($conn);
runScheduledBackupIfDue();

$search            = isset($_GET['search']) ? trim(preg_replace('/\s+/u', ' ', preg_replace("/[^\\p{L}\\p{M}0-9\\s.,'\\-]/u", '', $_GET['search']))) : '';
$courseFilter      = isset($_GET['course']) ? trim($_GET['course']) : '';
$yearSectionFilter = isset($_GET['year_section']) ? trim($_GET['year_section']) : '';
$statusFilter      = in_array($_GET['status'] ?? '', ['enrollee', 'graduated'], true) ? $_GET['status'] : '';
$typeFilter        = isset(STUDENT_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$canSearchStudents = can('students.list') || can('students.search');
$showStudentTable  = can('students.list') || (can('students.search') && $search !== '');

$conditions = [directoryStudentCondition($conn)];
$params     = [];
$types      = '';

if ($search !== '') {
    $conditions[] = '(student_number LIKE ? OR name LIKE ?)';
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
        $conditions[] = 'course IN (?, ?, ?)';
        $params[] = $filterCourse['code'];
        $params[] = $filterCourse['name'];
        $params[] = courseDisplayName($filterCourse);
        $types   .= 'sss';
    } else {
        $conditions[] = 'course = ?';
        $params[] = $courseFilter;
        $types   .= 's';
    }
}

if ($yearSectionFilter !== '') {
    $conditions[] = 'year_section = ?';
    $params[] = $yearSectionFilter;
    $types   .= 's';
}

if ($statusFilter === 'graduated') {
    $conditions[] = "status = 'graduated'";
} elseif ($statusFilter === 'enrollee') {
    $conditions[] = "(status IN ('enrollee', 'active') OR status IS NULL OR status = '')";
}

if ($typeFilter === 'shiftee' || $typeFilter === 'transferee') {
    $conditions[] = "admission_track = '" . $typeFilter . "'";
} elseif ($typeFilter === 'regular') {
    $conditions[] = "(admission_track IS NULL OR admission_track NOT IN ('shiftee', 'transferee'))";
}

$sql = "SELECT student_id, student_number, name, course, year_section, status, admission_track FROM students";
if (!empty($conditions)) {
    $sql .= " WHERE " . implode(' AND ', $conditions);
}
$sql .= ($search !== '' || $courseFilter !== '' || $yearSectionFilter !== '' || $statusFilter !== '' || $typeFilter !== '')
    ? " ORDER BY name ASC, student_id DESC"
    : " ORDER BY student_id DESC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}

$studentRows        = ($result && $showStudentTable) ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
$perPage            = 20;
$totalStudents      = count($studentRows);
$totalPages         = max(1, (int) ceil($totalStudents / $perPage));
$listPage        = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? min(max(1, (int) $_GET['page']), $totalPages) : 1;
$studentRows        = array_slice($studentRows, ($listPage - 1) * $perPage, $perPage);
$pageQueryBase      = array_filter(['search' => $search, 'course' => $courseFilter, 'year_section' => $yearSectionFilter, 'status' => $statusFilter, 'type' => $typeFilter], 'strlen');
$pageUrl            = function ($page) use ($pageQueryBase) {
    return 'student_directory.php?' . http_build_query($pageQueryBase + ($page > 1 ? ['page' => $page] : []));
};
$pageNumbers        = [];
foreach ([1, $listPage - 1, $listPage, $listPage + 1, $totalPages] as $pageNumber) {
    if ($pageNumber >= 1 && $pageNumber <= $totalPages) {
        $pageNumbers[$pageNumber] = true;
    }
}
$pageNumbers        = array_keys($pageNumbers);
sort($pageNumbers);
$clientRequirements = requirementsForClient();
$codesByStudent     = requirementCodesByStudent($conn, array_column($studentRows, 'student_id'));
foreach ($studentRows as &$studentRow) {
    $studentRow['completion'] = requirementCompletion(
        $clientRequirements,
        normalizeAdmissionTrack($studentRow['admission_track'] ?? ''),
        $codesByStudent[(int) $studentRow['student_id']] ?? []
    );
}
unset($studentRow);

$courseOptions = [];
foreach (officialCourseCatalog() as $catalogEntry) {
    $courseOptions[$catalogEntry['code']] = courseDisplayName($catalogEntry);
}
$extraCourseResult = mysqli_query($conn, "SELECT DISTINCT course FROM students WHERE " . directoryStudentCondition($conn) . " AND course IS NOT NULL AND course <> '' ORDER BY course ASC");
if ($extraCourseResult) {
    while ($row = mysqli_fetch_assoc($extraCourseResult)) {
        $usedCourse = resolveCourse($row['course']);
        if ($usedCourse === null && !isset($courseOptions[$row['course']])) {
            $courseOptions[$row['course']] = $row['course'];
        } elseif ($usedCourse !== null) {
            $courseOptions[$usedCourse['code']] = courseDisplayName($usedCourse);
        }
    }
}

$yearSectionOptions = [];
$yearSectionResult = mysqli_query($conn, "SELECT DISTINCT year_section FROM students WHERE " . directoryStudentCondition($conn) . " AND year_section IS NOT NULL AND year_section <> '' ORDER BY year_section ASC");
if ($yearSectionResult) {
    while ($row = mysqli_fetch_assoc($yearSectionResult)) {
        $yearSectionOptions[] = $row['year_section'];
    }
}

include 'navbar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= can('students.list') ? 'Student Directory' : ($canSearchStudents ? 'Search &amp; Add Student' : 'Add Student') ?></title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/navbar-admin.css" rel="stylesheet">
    <link href="../css/student-directory-admin.css?v=<?= @filemtime(__DIR__ . '/../css/student-directory-admin.css') ?>" rel="stylesheet">
    <link href="../css/scanner.css?v=<?= @filemtime(__DIR__ . '/../css/scanner.css') ?>" rel="stylesheet">
    <link href="../css/course-filter.css?v=<?= @filemtime(__DIR__ . '/../css/course-filter.css') ?>" rel="stylesheet">
    <link href="../css/student-report.css?v=<?= @filemtime(__DIR__ . '/../css/student-report.css') ?>" rel="stylesheet">
    <?php if (!$showStudentTable): ?>
    <link href="../css/intern-add.css?v=<?= @filemtime(__DIR__ . '/../css/intern-add.css') ?>" rel="stylesheet">
    <?php endif; ?>
</head>

<body>
    <div class="main-container">
    <?php if ($canSearchStudents): ?>
    <div class="textNButton-row">

      <?php if ($canSearchStudents): ?>
      <form method="GET" action="" class="search-group" id="directoryFilterForm">
        <div class="search-input-wrapper">
            <input type="text" name="search" class="searchbar" placeholder="STUDENT NUMBER / NAME" value="<?= htmlspecialchars($search) ?>" required
                   maxlength="100" autocomplete="off" pattern="[A-Za-zÑñ0-9\s.,'\-]+">
            <?php if ($search !== ''): ?>
                <a href="student_directory.php" class="clear-icon" title="Clear search"><i class="bi bi-x-circle"></i></a>
            <?php endif; ?>
        </div>
        <button type="submit" name="search_btn" class="search-icon">
            <i class="bi bi-search"></i>
        </button>
      </form>
      <?php endif; ?>

      <div class="add-filter">
        <?php if (can('reports')): ?>
        <button class="btn-add btn-report" type="button" id="classReportBtn">
            <i class="bi bi-file-earmark-pdf"></i>GENERATE REPORT
        </button>
        <?php endif; ?>
        <button class="btn-add" type="button" data-bs-toggle="modal" data-bs-target="#addRecordsModal">
            <i class="bi bi-plus-circle"></i>ADD STUDENT
        </button>
      
      <?php if (can('students.list')): ?>
      <form method="GET" action="" class="filter-row" id="directoryFilterOnlyForm">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

        <div class="filter-wrap">
        <select class="filter<?= $courseFilter !== '' ? ' has-value' : '' ?>" id="courseFilterSelect" name="course">
            <option value="">Course</option>
            <?php foreach ($courseOptions as $courseValue => $courseLabel): ?>
                <option value="<?= htmlspecialchars($courseValue) ?>" <?= $courseFilter === $courseValue ? 'selected' : '' ?>>
                    <?= htmlspecialchars($courseLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($courseFilter !== ''): ?>
            <a href="student_directory.php?<?= htmlspecialchars(http_build_query(array_filter(['search' => $search, 'year_section' => $yearSectionFilter, 'status' => $statusFilter, 'type' => $typeFilter], 'strlen'))) ?>" class="clear-icon filter-clear-icon" title="Clear course"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
        </div>

        <div class="filter-wrap filter-wrap-year-section">
        <select class="filter filter-year-section<?= $yearSectionFilter !== '' ? ' has-value' : '' ?>" id="yearSectionFilterSelect" name="year_section">
            <option value="">Year & Section</option>
            <?php foreach ($yearSectionOptions as $yearSectionOption): ?>
                <option value="<?= htmlspecialchars($yearSectionOption) ?>" <?= $yearSectionFilter === $yearSectionOption ? 'selected' : '' ?>>
                    <?= htmlspecialchars($yearSectionOption) ?>
                </option>
            <?php endforeach; ?>
            </select>
        <?php if ($yearSectionFilter !== ''): ?>
            <a href="student_directory.php?<?= htmlspecialchars(http_build_query(array_filter(['search' => $search, 'course' => $courseFilter, 'status' => $statusFilter, 'type' => $typeFilter], 'strlen'))) ?>" class="clear-icon filter-clear-icon" title="Clear year &amp; section"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
        </div>

        <div class="filter-wrap filter-wrap-status">
        <select class="filter filter-status<?= $statusFilter !== '' ? ' has-value' : '' ?>" id="statusFilterSelect" name="status">
            <option value="">All Status</option>
            <option value="enrollee" <?= $statusFilter === 'enrollee' ? 'selected' : '' ?>>Enrollee</option>
            <option value="graduated" <?= $statusFilter === 'graduated' ? 'selected' : '' ?>>Graduate</option>
        </select>
        <?php if ($statusFilter !== ''): ?>
            <a href="student_directory.php?<?= htmlspecialchars(http_build_query(array_filter(['search' => $search, 'course' => $courseFilter, 'year_section' => $yearSectionFilter, 'type' => $typeFilter], 'strlen'))) ?>" class="clear-icon filter-clear-icon" title="Clear status"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
        </div>

        <div class="filter-wrap filter-wrap-type">
        <select class="filter filter-type<?= $typeFilter !== '' ? ' has-value' : '' ?>" id="typeFilterSelect" name="type">
            <option value="">All Types</option>
            <?php foreach (STUDENT_TYPES as $typeValue => $typeLabel): ?>
                <option value="<?= htmlspecialchars($typeValue) ?>" <?= $typeFilter === $typeValue ? 'selected' : '' ?>><?= htmlspecialchars($typeLabel) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($typeFilter !== ''): ?>
            <a href="student_directory.php?<?= htmlspecialchars(http_build_query(array_filter(['search' => $search, 'course' => $courseFilter, 'year_section' => $yearSectionFilter, 'status' => $statusFilter], 'strlen'))) ?>" class="clear-icon filter-clear-icon" title="Clear type"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
        </div>
      </form>
      <?php endif; ?>
    </div>

    </div>
    <?php endif; ?>

    <?php if (!$showStudentTable && $canSearchStudents): ?>
    <div class="intern-add">
        <div class="intern-add-card">
            <span class="intern-add-icon"><i class="bi bi-search"></i></span>
            <p class="intern-add-text">Search a student number or name to view or update a record, or click ADD STUDENT to add a new student.</p>
        </div>
    </div>
    <?php elseif (!$showStudentTable): ?>
    <div class="intern-add">
        <div class="intern-add-card">
            <span class="intern-add-icon"><i class="bi bi-person-plus"></i></span>
            <p class="intern-add-text">Click ADD STUDENT to scan or upload the documents of a new student.</p>
            <button class="btn-add" type="button" id="internAddStudentBtn" data-bs-toggle="modal" data-bs-target="#addRecordsModal">
                <i class="bi bi-plus-circle"></i>ADD STUDENT
            </button>
        </div>
    </div>
    <?php else: ?>
    <!-- table -->
    <div class="table-scroll-wrapper">
    <table class="student-table">
    <tr>
        <th>Student Number</th>
        <th>Name</th>
        <th>Course</th>
        <th>Student Type</th>
        <th>Status</th>
        <th>Requirements</th>
        <th>Records</th>
    </tr>

    <?php if (!empty($studentRows)): ?>
        <?php foreach ($studentRows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['student_number']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['course']) ?></td>
            <td><?= htmlspecialchars(STUDENT_TYPES[studentTypeOf($row['admission_track'] ?? '')]) ?></td>
            <?php if (($row['status'] ?? '') === 'graduated'): ?>
            <td class="status-graduate">GRADUATE</td>
            <?php else: ?>
            <td>ENROLLEE</td>
            <?php endif; ?>
            <td><?= requirementStatusBadge($row['completion']) ?></td>
            <td>
               <button class="view-btn" data-id="<?= htmlspecialchars($row['student_id']) ?>"><i class="bi bi-eye-fill"></i>View</button>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="7" class="no-students-message">No students found.</td>
        </tr>
    <?php endif; ?>
    </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav class="directory-pager" aria-label="Student list pages">
        <div class="directory-pager-links">
            <?php if ($listPage > 1): ?>
                <a class="directory-pager-btn" href="<?= htmlspecialchars($pageUrl($listPage - 1)) ?>"><i class="bi bi-chevron-left"></i>Previous</a>
            <?php else: ?>
                <span class="directory-pager-btn is-disabled"><i class="bi bi-chevron-left"></i>Previous</span>
            <?php endif; ?>
            <?php $previousNumber = 0; ?>
            <?php foreach ($pageNumbers as $pageNumber): ?>
                <?php if ($pageNumber - $previousNumber > 1): ?>
                    <span class="directory-pager-gap">…</span>
                <?php endif; ?>
                <?php if ($pageNumber === $listPage): ?>
                    <span class="directory-pager-num is-current" aria-current="page"><?= $pageNumber ?></span>
                <?php else: ?>
                    <a class="directory-pager-num" href="<?= htmlspecialchars($pageUrl($pageNumber)) ?>"><?= $pageNumber ?></a>
                <?php endif; ?>
                <?php $previousNumber = $pageNumber; ?>
            <?php endforeach; ?>
            <?php if ($listPage < $totalPages): ?>
                <a class="directory-pager-btn" href="<?= htmlspecialchars($pageUrl($listPage + 1)) ?>">Next<i class="bi bi-chevron-right"></i></a>
            <?php else: ?>
                <span class="directory-pager-btn is-disabled">Next<i class="bi bi-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
    <?php endif; ?>


<!-- add new records modal -->
<div class="modal fade" id="addRecordsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">

            <div class="modal-header add-header">
                <div class="add-heading">
                    <span class="add-heading-icon"><i class="bi bi-person-plus"></i></span>
                    <div class="add-heading-text">
                        <h5 class="modal-title add-title">Add new student record</h5>
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

            <form method="POST" action="student_directory.php?action=add_student" id="addStudentForm" enctype="multipart/form-data">
                <input type="hidden" id="documentsData" name="documents" value="[]">

                <div class="modal-body">
                
                <div class="student-contents">

                <div class="student-sidebar">
                <div class="photo-container" id="photoContainer">
                    <div class="photo-placeholder">
                        <i class="bi bi-person"></i>
                    </div>
                </div>
                <span class="photo-caption">Student photo</span>
                <small class="photo-hint">JPG or PNG</small>
                <input type="file" id="replacePhotoInput" name="photo" accept=".jpg,.jpeg,.png" hidden>
                <button type="button" class="btn btn-sm btn-outline-secondary replace-photo-btn" id="replacePhotoBtn">
                    <i class="bi bi-upload"></i> Upload photo
                </button>

                </div>

                <!-- tabs -->
                <div class="student-details">
                <ul class="nav nav-tabs" id="studentTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active"
                            id="student-info-tab"
                            data-bs-toggle="tab"
                            data-bs-target="#student-info"
                            type="button"
                            role="tab"
                            aria-controls="student-info"
                            aria-selected="true">
                        Student Information
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link"
                            id="documents-tab"
                            data-bs-toggle="tab"
                            data-bs-target="#documents"
                            type="button"
                            role="tab"
                            aria-controls="documents"
                            aria-selected="false">
                        Documents
                    </button>
                </li>
            </ul>

            <div class="tab-content border border-top-0 p-3 pt-4 ps-4">
                <div class="tab-pane fade show active" id="student-info" role="tabpanel">
                    <!-- student info -->

                    <div class="row">

                    <div class="col-md-6">
                    <div class="d-flex align-items-center mb-3">
                        <label for="last_name" class="form-label form-label-custom">
                            Last name <span class="req-mark">*</span>
                        </label>
                        <input type="text" id="last_name" name="last_name" class="form-control input-custom" required
                               pattern="[A-Za-zÑñ\s.,'\-]+">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="first_name" class="form-label form-label-custom">
                            First name <span class="req-mark">*</span>
                        </label>
                        <input type="text" id="first_name" name="first_name" class="form-control input-custom" required
                               pattern="[A-Za-zÑñ\s.,'\-]+">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="middle_name" class="form-label form-label-custom">
                            Middle name
                        </label>
                        <input type="text" id="middle_name" name="middle_name" class="form-control input-custom"
                               pattern="[A-Za-zÑñ\s.,'\-]+" placeholder="(optional)">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="student_number" class="form-label form-label-custom">
                            Student number <span class="req-mark">*</span>
                        </label>
                        <input type="text" id="student_number" name="student_number" class="form-control input-custom" required
                               maxlength="15" placeholder="0000-00000-BN-0">
                    </div>
                    <small id="studentNumberFeedback" class="text-danger d-block mb-3"></small>

                    <div class="d-flex align-items-center mb-3" id="courseRow">
                        <label for="course" class="form-label form-label-custom">
                            Course <span class="req-mark">*</span>
                        </label>
                        <select id="course" name="course" class="form-select input-custom">
                            <option value="">Select course</option>
                            <?php foreach (getCourseCatalog() as $courseEntry): ?>
                                <?php if (($courseEntry['status'] ?? 'active') !== 'active') continue; ?>
                                <option value="<?= htmlspecialchars(courseDisplayName($courseEntry)) ?>"><?= htmlspecialchars(courseDisplayName($courseEntry)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="year_section" class="form-label form-label-custom">
                            Year & section <span class="req-mark">*</span>
                        </label>
                        <input type="text" id="year_section" name="year_section" class="form-control input-custom"
                               pattern="[0-9]-[0-9]" inputmode="numeric" maxlength="3"
                               title="One number for the year and one for the section, e.g. 1-1" placeholder="1-1">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="graduation_sy_display" class="form-label form-label-custom">
                            Year of Graduation
                        </label>
                        <input type="text" id="graduation_sy_display" class="form-control input-custom graduation-display bg-light"
                               readonly tabindex="-1" placeholder="Based on the student number">
                    </div>
                    <small id="graduationNote" class="text-danger d-block mb-3"></small>

                    <div class="d-flex align-items-center mb-3">
                        <label for="sex" class="form-label form-label-custom">
                            Gender
                        </label>
                        <input type="text" id="sex" name="sex" class="form-control input-custom"
                               pattern="[A-Za-z\s]+" placeholder="Female/Male">
                    </div>
                    </div>

                    <div class="col-md-6">
                    <div class="d-flex align-items-center mb-3">
                        <label for="student_type" class="form-label form-label-custom">
                            Student type
                        </label>
                        <select id="student_type" name="student_type" class="form-select input-custom">
                            <?php foreach (STUDENT_TYPES as $typeValue => $typeLabel): ?>
                                <option value="<?= htmlspecialchars($typeValue) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex align-items-center mb-3" id="admissionTrackRow">
                        <label for="admission_track" class="form-label form-label-custom">
                            Admission type
                        </label>
                        <select id="admission_track" name="admission_track" class="form-select input-custom">
                            <?php foreach (regularAdmissionTracks() as $trackValue => $trackLabel): ?>
                                <option value="<?= htmlspecialchars($trackValue) ?>"><?= htmlspecialchars($trackLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="student-type-fields" data-student-type="shiftee" hidden>
                        <div class="d-flex align-items-center mb-3">
                            <label for="shift_from_course" class="form-label form-label-custom">
                                Shifting from <span class="req-mark">*</span>
                            </label>
                            <select id="shift_from_course" name="shift_from_course" class="form-select input-custom">
                                <option value="">Select previous course</option>
                                <?php foreach (getCourseCatalog() as $courseEntry): ?>
                                    <option value="<?= htmlspecialchars(courseDisplayName($courseEntry)) ?>"><?= htmlspecialchars(courseDisplayName($courseEntry)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <label for="shift_to_course" class="form-label form-label-custom">
                                Shifting to <span class="req-mark">*</span>
                            </label>
                            <select id="shift_to_course" class="form-select input-custom" data-mirrors="course">
                                <option value="">Select new course</option>
                                <?php foreach (getCourseCatalog() as $courseEntry): ?>
                                    <?php if (($courseEntry['status'] ?? 'active') !== 'active') continue; ?>
                                    <option value="<?= htmlspecialchars(courseDisplayName($courseEntry)) ?>"><?= htmlspecialchars(courseDisplayName($courseEntry)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="student-type-fields" data-student-type="transferee" hidden>
                        <div class="d-flex align-items-center mb-3">
                            <label for="previous_school" class="form-label form-label-custom">
                                Previous school <span class="req-mark">*</span>
                            </label>
                            <input type="text" id="previous_school" name="previous_school" class="form-control input-custom"
                                   maxlength="255" placeholder="School the student transferred from">
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <label for="previous_course" class="form-label form-label-custom">
                                Previous course <span class="req-mark">*</span>
                            </label>
                            <input type="text" id="previous_course" name="previous_course" class="form-control input-custom"
                                   maxlength="255" placeholder="Course taken in the previous school">
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <label for="previous_year_level" class="form-label form-label-custom">
                                Last year level <span class="req-mark">*</span>
                            </label>
                            <select id="previous_year_level" name="previous_year_level" class="form-select input-custom">
                                <option value="">Select year level</option>
                                <?php foreach (PREVIOUS_YEAR_LEVELS as $levelLabel): ?>
                                    <option value="<?= htmlspecialchars($levelLabel) ?>"><?= htmlspecialchars($levelLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="date_of_birth" class="form-label form-label-custom">
                            Date of birth
                        </label>
                        <input type="date" id="date_of_birth" name="date_of_birth" class="form-control input-custom"
                               min="1900-01-01" max="<?= minimumBirthDate() ?>"
                               title="The student must turn at least 17 years old this year">
                    </div>
                    <div class="d-flex align-items-center mb-3">
                        <label for="place_of_birth" class="form-label form-label-custom">
                            Place of birth
                        </label>
                        <input type="text" id="place_of_birth" name="place_of_birth" class="form-control input-custom"
                               placeholder="City/Municipality, Province">
                    </div>


                    <div class="d-flex align-items-center mb-3">
                        <label for="mobile_number" class="form-label form-label-custom">
                            Mobile number
                        </label>
                        <input type="text" id="mobile_number" name="mobile_number" class="form-control input-custom"
                               pattern="[0-9]{11}" maxlength="11" inputmode="numeric" placeholder="09XXXXXXXXX">
                    </div>

                    <div class="d-flex align-items-center mb-3">
                        <label for="email" class="form-label form-label-custom">
                            Email address <span class="req-mark">*</span>
                        </label>
                        <input type="email" id="email" name="email" class="form-control input-custom" required
                               pattern="[^@\s]+@[^@\s]+\.[^@\s]+" title="Enter a valid email address" placeholder="name@example.com">
                    </div>
                    <small id="emailFeedback" class="text-danger d-block mb-3"></small>

                    <div class="d-flex align-items-start mb-3">
                        <label for="address" class="form-label form-label-custom">
                            Home address
                        </label>
                        <textarea id="address" name="address" class="form-control input-custom" rows="3"
                                  placeholder="House/Unit No., Street Name, Barangay, City/Municipality, Province"></textarea>
                    </div>
                    </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="documents" role="tabpanel">

                <div id="requirementChecklist" class="requirement-checklist"></div>

                <div id="documentList" class="d-flex flex-column gap-2"></div>

                <div id="documentEmptyState" class="text-center text-muted document-empty-state">
                    No documents uploaded yet.
                </div>

                <template id="documentRowTemplate">
                    <div class="d-flex align-items-center gap-3 border rounded p-2 document-row">
                        <i class="bi bi-file-earmark-text document-row-icon"></i>
                        <div class="flex-grow-1 document-row-body">
                            <div class="document-row-title"></div>
                            <small class="text-muted document-row-filename"></small>
                            <select class="form-select form-select-sm document-type-select" aria-label="Document type"></select>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary document-view-btn" title="View file">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary document-change-btn" title="Change file">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger document-delete-btn" title="Delete file">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </template>

            </div>
            </div>
            </div>
            </div>
                </div>

                <div class="modal-footer add-footer">
                    <span class="add-footer-note"><i class="bi bi-info-circle"></i> Fields marked <span class="req-mark">*</span> are required</span>
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">CANCEL</button>
                    <button type="button" class="btn btn-save" id="saveStudentBtn">SAVE</button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- confirm modal -->
<div class="modal fade" id="confirmSaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header confirm-header">
                <div class="add-heading">
                    <span class="add-heading-icon"><i class="bi bi-clipboard-check"></i></span>
                    <div class="add-heading-text">
                        <h5 class="modal-title confirm-title">Confirm new student record</h5>
                        <p class="add-subtitle">Please review the details before saving.</p>
                    </div>
                </div>
            </div>
            <div class="modal-body">
                <table class="table table-sm confirm-summary-table">
                    <tr><th>Student Number</th><td id="confirmSummaryNumber"></td></tr>
                    <tr><th>Name</th><td id="confirmSummaryName"></td></tr>
                    <tr><th>Course</th><td id="confirmSummaryCourse"></td></tr>
                    <tr><th>Year &amp; Section</th><td id="confirmSummarySection"></td></tr>
                    <tr><th>Admission Type</th><td id="confirmSummaryTrack"></td></tr>
                    <tr><th>Year of Graduation</th><td id="confirmSummaryGraduation"></td></tr>
                    <tr><th>Attached Documents</th><td id="confirmSummaryDocs"></td></tr>
                    <tr><th>Requirements Status</th><td id="confirmSummaryCompletion"></td></tr>
                    <tr><th>Missing Requirements</th><td id="confirmSummaryMissing"></td></tr>
                </table>
            </div>
            <div class="modal-footer confirm-footer">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Back to Edit</button>
                <button type="button" class="btn btn-save" id="confirmSaveBtn">Confirm & Save</button>
            </div>
        </div>
    </div>
</div>

<!-- confirm status / archive modal -->
<div class="modal fade" id="confirmStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmStatusTitle">Confirm Status Change</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Change <strong id="confirmStatusStudentName"></strong>'s status
                    from <strong id="confirmStatusOldValue"></strong>
                    to <strong id="confirmStatusNewValue"></strong>?
                </p>
                <div id="confirmStatusArchiveNote" class="alert alert-warning small mb-0 d-none">
                    <i class="bi bi-archive"></i>
                    The record will be moved to Archive and their documents moved to the archive folder.
                </div>
                <div id="confirmStatusError" class="alert alert-danger small mt-3 mb-0 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" id="confirmStatusCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-save" id="confirmStatusChangeBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- document preview panel -->
<div id="documentPreviewPanel" class="document-preview-panel" aria-hidden="true">
    <div id="documentPreviewResizeHandle" class="document-preview-resize-handle" title="Drag to resize"></div>
    <div class="document-preview-header">
        <span id="documentPreviewTitle" class="document-preview-title">Document Preview</span>
        <div class="document-preview-header-actions">
            <button type="button" id="documentPreviewExpandBtn" class="btn btn-sm btn-outline-secondary" title="Open full size in a new tab">
                <i class="bi bi-arrows-angle-expand"></i>
            </button>
            <button type="button" id="documentPreviewCloseBtn" class="btn-close" aria-label="Close preview"></button>
        </div>
    </div>
    <div class="document-preview-body">
        <iframe id="documentPreviewFrame" title="Document preview" src="about:blank"></iframe>
    </div>
</div>

<div id="documentPageData" hidden data-json="<?= htmlspecialchars(json_encode([
    'requirements' => requirementsForClient(),
    'groups'       => getDocumentRequirementGroups(),
    'tracks'       => getAdmissionTracks(),
    'current_sy_end' => currentSchoolYearEndYear(),
]), ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="modal fade report-modal" id="classReportModal" tabindex="-1" aria-labelledby="classReportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header report-header">
                <div class="report-heading">
                    <span class="report-heading-icon"><i class="bi bi-file-earmark-bar-graph"></i></span>
                    <div class="report-heading-text">
                        <h5 class="modal-title report-title" id="classReportTitle">Students record report</h5>
                        <p class="report-subtitle" id="classReportSubtitle"></p>
                    </div>
                </div>
            </div>
            <div class="modal-body">
                <div class="report-status" id="classReportStatus" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Generating the report...</span></div>
                <iframe class="report-frame" id="classReportFrame" title="Students record report preview"></iframe>
            </div>
            <div class="modal-footer report-footer">
                <button type="button" class="btn report-secondary-btn" data-bs-dismiss="modal">Close</button>
                <a class="btn report-secondary-btn report-download-btn" id="classReportExcel" href="#"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Download Excel</a>
                <a class="btn btn-save report-download-btn" id="classReportDownload" href="#"><i class="bi bi-download me-2"></i>Download PDF</a>
            </div>
        </div>
    </div>
</div>

<script src="../js/add-student.js?v=<?= @filemtime(__DIR__ . '/../js/add-student.js') ?>"></script>
<script src="../js/student-directory-filters.js?v=<?= @filemtime(__DIR__ . '/../js/student-directory-filters.js') ?>"></script>
<script src="../js/student-directory.js?v=<?= @filemtime(__DIR__ . '/../js/student-directory.js') ?>"></script>
<script src="../js/scanner.js?v=<?= @filemtime(__DIR__ . '/../js/scanner.js') ?>"></script>
<script src="../js/course-filter.js?v=<?= @filemtime(__DIR__ . '/../js/course-filter.js') ?>"></script>
<script src="../js/class-report.js?v=<?= @filemtime(__DIR__ . '/../js/class-report.js') ?>"></script>
    </div>
</body>
</html>