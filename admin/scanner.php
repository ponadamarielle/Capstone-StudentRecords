<?php
session_start();

const SCAN_TOKEN_PATTERN = '/^[a-f0-9]{32}$/';
const SCAN_KEEP_SECONDS  = 7200;
const SCAN_TIMEOUT       = 180;

function scanJson(array $data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit();
}

if (!isset($_SESSION['name'], $_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    scanJson(['success' => false, 'message' => 'Unauthorized. Please sign in again.'], 403);
}

require_once __DIR__ . '/../account_guard.php';
requireActiveAccount(true);

function scanEnv($key, $default)
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = __DIR__ . '/../.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $file[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    return isset($file[$key]) && $file[$key] !== '' ? $file[$key] : $default;
}

function scanConsolePath()
{
    $candidates = array_filter([
        scanEnv('SCANNER_NAPS2_PATH', ''),
        'C:\\Program Files\\NAPS2\\NAPS2.Console.exe',
        'C:\\Program Files (x86)\\NAPS2\\NAPS2.Console.exe',
    ]);
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function scanMode()
{
    return strtolower(scanEnv('SCANNER_MODE', 'folder')) === 'naps2' ? 'naps2' : 'folder';
}

function scanWatchFolder()
{
    return rtrim(scanEnv('SCANNER_WATCH_FOLDER', 'C:\\Users\\Public\\Documents\\ScanDoc'), '/\\');
}

function scanWatchFiles()
{
    $folder = scanWatchFolder();
    $files = [];
    if (!is_dir($folder)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            function ($current) {
                return !($current->isDir() && strtolower($current->getFilename()) === 'imported');
            }
        )
    );
    $iterator->setMaxDepth(2);
    foreach ($iterator as $info) {
        $ext = strtolower($info->getExtension());
        if ($info->isFile() && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'bmp'], true)) {
            $files[$info->getPathname()] = $info->getMTime() . ':' . $info->getSize();
        }
    }
    return $files;
}

function scanPageCount($path)
{
    $content = @file_get_contents($path);
    return $content === false ? 0 : preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $content);
}

function scanIsPdf($path)
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }
    $head = fread($handle, 1024);
    fclose($handle);
    return strpos((string) $head, '%PDF') !== false;
}

function scanImportFiles(array $files, $token, $appendToken)
{
    $dir = scanTokenDir($token);
    @mkdir($dir, 0700, true);
    $pages = [];
    $nextPage = function () use ($dir, &$pages) {
        return $dir . DIRECTORY_SEPARATOR . sprintf('page_%03d.jpg', count($pages) + 1);
    };

    if ($appendToken !== '') {
        $previous = scanPageImages($appendToken);
        if (!$previous) {
            return 'The previous pages could not be found. Please click RESCAN and scan again.';
        }
        foreach ($previous as $file) {
            $target = $nextPage();
            if (@copy($file, $target)) {
                $pages[] = $target;
            }
        }
    }

    $console = scanConsolePath();
    $passThroughPdf = null;
    foreach ($files as $i => $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $sources = [];
        if ($ext === 'pdf') {
            if (!scanIsPdf($file)) {
                return 'The scanned file "' . basename($file) . '" is not a valid PDF.';
            }
            if ($console !== null) {
                $prefix = $dir . DIRECTORY_SEPARATOR . 'src' . ($i + 1) . '_';
                scanRun([$console, '-i', $file, '-o', $prefix . '$(nnn).jpg', '--jpegquality', '92', '-f']);
                $sources = glob($prefix . '*.jpg') ?: [];
                natsort($sources);
                $sources = array_values($sources);
            }
            if (!$sources) {
                error_log('Scanner: could not convert ' . $file . ' to images for border trimming.');
                if (count($files) === 1 && !$pages) {
                    $passThroughPdf = $file;
                    continue;
                }
                return 'The scanned PDF could not be processed. Set the scanner to save as JPEG instead.';
            }
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $sources = [$file];
        } else {
            $image = $ext === 'png' ? @imagecreatefrompng($file) : (function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($file) : false);
            $converted = $dir . DIRECTORY_SEPARATOR . sprintf('conv_%03d.jpg', $i + 1);
            if (!$image || !imagejpeg($image, $converted, 92)) {
                return 'The scanned file "' . basename($file) . '" could not be read. Set the scanner to save as PDF or JPEG.';
            }
            imagedestroy($image);
            $sources = [$converted];
        }
        foreach ($sources as $source) {
            $target = $nextPage();
            scanAutoCrop($source, $target);
            if (is_file($target)) {
                $pages[] = $target;
            }
            if (strpos($source, $dir . DIRECTORY_SEPARATOR) === 0) {
                @unlink($source);
            }
        }
    }

    $output = scanFilePath($token);
    if ($passThroughPdf !== null) {
        if (!@copy($passThroughPdf, $output)) {
            return 'The scanned document could not be copied.';
        }
    } elseif (!$pages || !scanBuildPdf($pages, $output, (int) scanEnv('SCANNER_DPI', '300'))) {
        return 'The scanned pages could not be converted to PDF.';
    }
    if (!is_file($output) || !scanIsPdf($output)) {
        return 'The scanned document could not be prepared.';
    }

    $imported = scanWatchFolder() . DIRECTORY_SEPARATOR . 'imported';
    if (!is_dir($imported)) {
        @mkdir($imported, 0777, true);
    }
    foreach ($files as $file) {
        $target = $imported . DIRECTORY_SEPARATOR . date('Ymd_His_') . basename($file);
        if (!@rename($file, $target)) {
            @unlink($file);
        }
    }
    return null;
}

function scanTempDir()
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'pup_erecords_scans';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function scanFilePath($token)
{
    return scanTempDir() . DIRECTORY_SEPARATOR . $token . '.pdf';
}

function scanTokenDir($token)
{
    return scanTempDir() . DIRECTORY_SEPARATOR . $token;
}

function scanPageImages($token)
{
    if (!is_string($token) || !preg_match(SCAN_TOKEN_PATTERN, $token)) {
        return [];
    }
    $files = glob(scanTokenDir($token) . DIRECTORY_SEPARATOR . 'page_*.jpg') ?: [];
    sort($files);
    return $files;
}

function scanRemoveToken($token)
{
    if (!is_string($token) || !preg_match(SCAN_TOKEN_PATTERN, $token)) {
        return;
    }
    @unlink(scanFilePath($token));
    $dir = scanTokenDir($token);
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

function scanCleanup()
{
    foreach (glob(scanTempDir() . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
        $name = basename($entry, '.pdf');
        if (preg_match(SCAN_TOKEN_PATTERN, $name) && filemtime($entry) < time() - SCAN_KEEP_SECONDS) {
            scanRemoveToken($name);
        }
    }
}

function scanJpegDpi($file)
{
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return 0;
    }
    $head = fread($handle, 64 * 1024);
    fclose($handle);
    $pos = strpos((string) $head, "JFIF\0");
    if ($pos === false || strlen($head) < $pos + 12) {
        return 0;
    }
    $units = ord($head[$pos + 7]);
    $x = (ord($head[$pos + 8]) << 8) | ord($head[$pos + 9]);
    if ($units === 1) {
        return $x;
    }
    if ($units === 2) {
        return (int) round($x * 2.54);
    }
    return 0;
}

function scanFillDarkEdges($image, $limit)
{
    $width  = imagesx($image);
    $height = imagesy($image);
    $white  = imagecolorallocate($image, 255, 255, 255);
    $dark = function ($x, $y) use ($image, $limit) {
        $rgb = imagecolorat($image, $x, $y);
        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF) < $limit;
    };
    $maxY = max(1, (int) ($height * 0.04));
    $maxX = max(1, (int) ($width * 0.04));
    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $maxY && $dark($x, $y); $y++) imagesetpixel($image, $x, $y, $white);
        for ($y = $height - 1; $y >= $height - $maxY && $dark($x, $y); $y--) imagesetpixel($image, $x, $y, $white);
    }
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $maxX && $dark($x, $y); $x++) imagesetpixel($image, $x, $y, $white);
        for ($x = $width - 1; $x >= $width - $maxX && $dark($x, $y); $x--) imagesetpixel($image, $x, $y, $white);
    }
}

function scanAutoCrop($source, $target)
{
    if (!function_exists('imagecreatefromjpeg')) {
        error_log('Scanner: the PHP GD extension is not enabled, so black borders cannot be removed.');
        return @copy($source, $target);
    }
    $dpi = scanJpegDpi($source);
    $image = @imagecreatefromjpeg($source);
    if (!$image) {
        $data = @file_get_contents($source);
        $image = $data !== false ? @imagecreatefromstring($data) : false;
    }
    if (!$image) {
        $console = scanConsolePath();
        if ($console !== null) {
            $normalized = $target . '.norm.jpg';
            scanRun([$console, '-i', $source, '-o', $normalized, '--jpegquality', '92', '-f']);
            $image = is_file($normalized) ? @imagecreatefromjpeg($normalized) : false;
            @unlink($normalized);
        }
    }
    if (!$image) {
        $info = @getimagesize($source);
        error_log('Scanner: could not open ' . basename($source) . ' for border trimming (' . ($info['mime'] ?? 'unknown type') . ', channels ' . ($info['channels'] ?? '?') . ').');
        return @copy($source, $target);
    }
    $width  = imagesx($image);
    $height = imagesy($image);
    $step   = max(1, intdiv(min($width, $height), 600));
    $limit  = (int) scanEnv('SCANNER_DARK_LEVEL', '90');

    $isDark = function ($x, $y) use ($image, $limit) {
        $rgb = imagecolorat($image, $x, $y);
        $lum = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
        return $lum < $limit;
    };
    $rowDark = function ($y, $left, $right) use ($isDark, $step) {
        $dark = 0; $total = 0;
        for ($x = $left; $x <= $right; $x += $step) {
            $total++;
            if ($isDark($x, $y)) $dark++;
        }
        return $total > 0 && $dark / $total >= 0.6;
    };
    $colDark = function ($x, $top, $bottom) use ($isDark, $step) {
        $dark = 0; $total = 0;
        for ($y = $top; $y <= $bottom; $y += $step) {
            $total++;
            if ($isDark($x, $y)) $dark++;
        }
        return $total > 0 && $dark / $total >= 0.6;
    };

    $band = function ($length, callable $lineIsDark) {
        $maxBand = (int) ($length * 0.15);
        $lead = max(3, (int) ($length * 0.02));
        $gapLimit = max(2, (int) ($length * 0.004));
        $last = -1;
        $gap = 0;
        for ($i = 0; $i < $maxBand; $i++) {
            if ($lineIsDark($i)) {
                $last = $i;
                $gap = 0;
            } else {
                $gap++;
                if ($last >= 0 ? $gap > $gapLimit : $i > $lead) {
                    break;
                }
            }
        }
        return $last + 1;
    };

    $top = 0; $bottom = $height - 1; $left = 0; $right = $width - 1;
    for ($pass = 0; $pass < 2; $pass++) {
        $h = $bottom - $top + 1;
        $w = $right - $left + 1;
        $cutTop = $band($h, fn($i) => $rowDark($top + $i, $left, $right));
        $cutBottom = $band($h, fn($i) => $rowDark($bottom - $i, $left, $right));
        $top += $cutTop;
        $bottom -= $cutBottom;
        $cutLeft = $band($w, fn($i) => $colDark($left + $i, $top, $bottom));
        $cutRight = $band($w, fn($i) => $colDark($right - $i, $top, $bottom));
        $left += $cutLeft;
        $right -= $cutRight;
    }

    error_log('Scanner: trimming ' . basename($source) . " {$width}x{$height} -> top {$top}, bottom " . ($height - 1 - $bottom) . ", left {$left}, right " . ($width - 1 - $right));
    if ($top === 0 && $left === 0 && $bottom === $height - 1 && $right === $width - 1) {
        imagedestroy($image);
        return @copy($source, $target);
    }

    $inset = max(1, intdiv(min($width, $height), 400));
    $top    = $top > 0 ? min($bottom, $top + $inset) : 0;
    $left   = $left > 0 ? min($right, $left + $inset) : 0;
    $bottom = $bottom < $height - 1 ? max($top, $bottom - $inset) : $bottom;
    $right  = $right < $width - 1 ? max($left, $right - $inset) : $right;
    $cropW  = $right - $left + 1;
    $cropH  = $bottom - $top + 1;

    if ($cropW < $width * 0.3 || $cropH < $height * 0.2) {
        imagedestroy($image);
        return @copy($source, $target);
    }

    $cropped = imagecrop($image, ['x' => $left, 'y' => $top, 'width' => $cropW, 'height' => $cropH]);
    imagedestroy($image);
    if (!$cropped) {
        return @copy($source, $target);
    }
    scanFillDarkEdges($cropped, $limit);
    if ($dpi > 0 && function_exists('imageresolution')) {
        imageresolution($cropped, $dpi, $dpi);
    }
    $saved = imagejpeg($cropped, $target, 92);
    imagedestroy($cropped);
    return $saved ?: @copy($source, $target);
}

function scanBuildPdf(array $images, $output, $dpi)
{
    $objects = [];
    $kids = [];
    $next = 3;
    foreach ($images as $file) {
        $info = @getimagesize($file);
        $data = @file_get_contents($file);
        if (!$info || $data === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
            return false;
        }
        [$w, $h] = $info;
        $pageDpi = scanJpegDpi($file);
        $pageDpi = ($pageDpi >= 72 && $pageDpi <= 1200) ? $pageDpi : ($dpi > 0 ? $dpi : 300);
        $colorSpace = ($info['channels'] ?? 3) === 1 ? '/DeviceGray' : (($info['channels'] ?? 3) === 4 ? '/DeviceCMYK' : '/DeviceRGB');
        $pw = round($w * 72 / $pageDpi, 2);
        $ph = round($h * 72 / $pageDpi, 2);
        $pageId = $next++;
        $imageId = $next++;
        $contentId = $next++;
        $kids[] = $pageId . ' 0 R';
        $content = "q {$pw} 0 0 {$ph} 0 0 cm /Im0 Do Q";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] /Resources << /XObject << /Im0 {$imageId} 0 R >> >> /Contents {$contentId} 0 R >>";
        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace {$colorSpace} /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>
stream
" . $data . "
endstream";
        $objects[$contentId] = "<< /Length " . strlen($content) . " >>
stream
" . $content . "
endstream";
    }
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4
%âãÏÓ
";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj
" . $body . "
endobj
";
    }
    $xref = strlen($pdf);
    $pdf .= "xref
0 " . ($next) . "
0000000000 65535 f 
";
    for ($i = 1; $i < $next; $i++) {
        $pdf .= sprintf("%010d 00000 n 
", $offsets[$i]);
    }
    $pdf .= "trailer
<< /Size {$next} /Root 1 0 R >>
startxref
{$xref}
%%EOF
";
    return file_put_contents($output, $pdf) !== false;
}

function scanOwnsToken($token)
{
    return is_string($token) && preg_match(SCAN_TOKEN_PATTERN, $token)
        && isset($_SESSION['scan_tokens'][$token]);
}

function scanRun(array $command)
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return [false, 'Could not start the scanning program.'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $started = time();
    $exitCode = null;
    while (true) {
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if (time() - $started > SCAN_TIMEOUT) {
            proc_terminate($process);
            $output .= "\nThe scanner did not respond in time.";
            break;
        }
        usleep(200000);
    }
    $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [$exitCode === 0, trim($output)];
}

function scanUsbPresent()
{
    if (PHP_OS_FAMILY !== 'Windows' || scanEnv('SCANNER_SKIP_USB_CHECK', '') === '1') {
        return [true, ''];
    }
    $pattern = scanEnv('SCANNER_USB_MATCH', 'VID_07B3|Plustek|S410');
    $script = '$p = ' . "'" . str_replace("'", "''", $pattern) . "'" . '; '
        . 'Get-PnpDevice -PresentOnly -ErrorAction SilentlyContinue | '
        . 'Where-Object { $_.InstanceId -match $p -or $_.FriendlyName -match $p } | '
        . 'ForEach-Object { $_.FriendlyName }';
    [$ok, $output] = scanRun(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script]);
    $names = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $output))));
    if (!$ok) {
        return [null, ''];
    }
    return [count($names) > 0, implode(', ', array_unique($names))];
}

function scanFriendlyError($output)
{
    $text = strtolower($output);
    if ($text === '') {
        return 'No page was scanned. Insert the document first and wait until the scanner holds the paper, then click START SCAN.';
    }
    if (strpos($text, 'no scanned pages') !== false || strpos($text, 'no pages') !== false || strpos($text, 'paper') !== false || strpos($text, 'feeder') !== false) {
        return 'No page was scanned. Insert the document first and wait until the scanner holds the paper, then click START SCAN.';
    }
    if (strpos($text, 'not found') !== false || strpos($text, 'no device') !== false || strpos($text, 'offline') !== false || strpos($text, 'disconnected') !== false) {
        return 'The scanner was not found. Check that the Plustek scanner is plugged in and turned on.';
    }
    if (strpos($text, 'in use') !== false || strpos($text, 'busy') !== false) {
        return 'The scanner is busy. Close other scanning programs and try again.';
    }
    if (strpos($text, 'did not respond') !== false) {
        return 'The scanner did not respond in time. Check the scanner and try again.';
    }
    return 'Scanning failed: ' . mb_substr($output, 0, 300);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'preview') {
    $token = $_GET['token'] ?? '';
    session_write_close();
    if (!scanOwnsToken($token) || !is_file(scanFilePath($token))) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Scanned document not found. Please scan again.';
        exit();
    }
    $path = scanFilePath($token);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="scanned-document.pdf"');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    readfile($path);
    exit();
}

if ($action === 'page') {
    $token = $_GET['token'] ?? '';
    $number = (int) ($_GET['n'] ?? 0);
    session_write_close();
    $images = scanOwnsToken($token) ? scanPageImages($token) : [];
    if ($number < 1 || !isset($images[$number - 1])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Page not found.';
        exit();
    }
    $path = $images[$number - 1];
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit();
}

if ($action === 'discard') {
    $token = $_POST['token'] ?? '';
    if (scanOwnsToken($token)) {
        scanRemoveToken($token);
        unset($_SESSION['scan_tokens'][$token]);
    }
    session_write_close();
    scanJson(['success' => true]);
}

if ($action === 'status') {
    session_write_close();
    $console = scanConsolePath();
    scanJson([
        'success'   => true,
        'available' => $console !== null,
        'message'   => $console !== null ? '' : 'The scanning program (NAPS2) is not installed on this computer.',
    ]);
}

if ($action === 'devices' && scanMode() === 'folder') {
    session_write_close();
    $folder = scanWatchFolder();
    if (!is_dir($folder)) {
        @mkdir($folder, 0777, true);
    }
    [$plugged, $usbName] = scanUsbPresent();
    $folderOk = is_dir($folder) && is_writable($folder);
    scanJson([
        'success'   => true,
        'mode'      => 'folder',
        'connected' => $plugged !== false && $folderOk,
        'driver'    => true,
        'plugged'   => $plugged,
        'device'    => $usbName !== '' ? $usbName : 'Plustek scanner',
        'devices'   => [],
        'folder'    => $folder,
        'warning'   => function_exists('imagecreatefromjpeg') ? '' : 'Black borders cannot be removed because the PHP GD extension is turned off. Enable extension=gd in php.ini and restart Apache.',
        'message'   => $folderOk ? '' : 'The scan folder ' . $folder . ' cannot be used. Check that it exists and is not read-only.',
    ]);
}

function scanCloseFolderWindows()
{
    if (PHP_OS_FAMILY !== 'Windows' || scanEnv('SCANNER_CLOSE_FOLDER', '1') === '0') {
        return;
    }
    $folder = str_replace("'", "''", scanWatchFolder());
    $script = '$f = ' . "'" . $folder . "'" . '; '
        . 'try { (New-Object -ComObject Shell.Application).Windows() | ForEach-Object { '
        . 'try { $p = $_.Document.Folder.Self.Path; if ($p -and $p.ToLower().StartsWith($f.ToLower())) { $_.Quit() } } catch {} } } catch {}';
    scanRun(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script]);
}

if ($action === 'close_folder') {
    session_write_close();
    scanCloseFolderWindows();
    scanJson(['success' => true]);
}

if ($action === 'watch_start') {
    $_SESSION['scan_watch'] = ['since' => time(), 'known' => scanWatchFiles()];
    session_write_close();
    scanJson(['success' => true]);
}

if ($action === 'watch_poll') {
    $watch = $_SESSION['scan_watch'] ?? null;
    if (!$watch) {
        session_write_close();
        scanJson(['success' => false, 'message' => 'Scanning was not started. Please try again.']);
    }
    $appendToken = $_POST['append'] ?? '';
    if ($appendToken !== '' && (!scanOwnsToken($appendToken) || !is_file(scanFilePath($appendToken)))) {
        session_write_close();
        scanJson(['success' => false, 'message' => 'The previous scan is no longer available. Please scan again.']);
    }

    $new = [];
    $settling = false;
    foreach (scanWatchFiles() as $file => $stamp) {
        if (isset($watch['known'][$file]) && $watch['known'][$file] === $stamp) {
            continue;
        }
        $mtime = (int) strtok($stamp, ':');
        if ($mtime < $watch['since'] - 1) {
            continue;
        }
        if ($mtime > time() - 2 || filesize($file) === 0) {
            $settling = true;
            continue;
        }
        $new[$file] = $mtime;
    }
    if (!$new || $settling) {
        session_write_close();
        if ($settling) {
            scanCloseFolderWindows();
        }
        scanJson(['success' => true, 'waiting' => true, 'receiving' => $settling]);
    }

    asort($new);
    $token = bin2hex(random_bytes(16));
    $_SESSION['scan_tokens'][$token] = time();
    unset($_SESSION['scan_watch']);
    session_write_close();
    scanCloseFolderWindows();

    @set_time_limit(SCAN_TIMEOUT + 60);
    @ini_set('memory_limit', '512M');
    $error = scanImportFiles(array_keys($new), $token, $appendToken);
    if ($error !== null) {
        scanRemoveToken($token);
        scanJson(['success' => false, 'message' => $error]);
    }
    if ($appendToken !== '') {
        scanRemoveToken($appendToken);
    }
    scanJson([
        'success' => true,
        'token'   => $token,
        'pages'   => max(1, scanPageCount(scanFilePath($token))),
        'url'     => 'scanner.php?action=preview&token=' . $token,
        'images'  => count(scanPageImages($token)),
    ]);
}

if ($action === 'devices') {
    session_write_close();
    $console = scanConsolePath();
    if ($console === null) {
        scanJson(['success' => false, 'message' => 'The scanning program (NAPS2) is not installed on this computer.']);
    }
    @set_time_limit(120);
    [$ok, $output] = scanRun([$console, '--listdevices', '--driver', scanEnv('SCANNER_DRIVER', 'twain')]);
    $devices = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $output))));
    $wanted = scanEnv('SCANNER_DEVICE', 'S410');
    $match = null;
    foreach ($devices as $device) {
        if (stripos($device, $wanted) !== false) {
            $match = $device;
            break;
        }
    }
    [$plugged, $usbName] = $match !== null ? scanUsbPresent() : [null, ''];
    scanJson([
        'success'   => $ok,
        'mode'      => 'naps2',
        'connected' => $ok && $match !== null && $plugged !== false,
        'driver'    => $match !== null,
        'plugged'   => $plugged,
        'usb_name'  => $usbName,
        'device'    => $match,
        'devices'   => $devices,
        'wanted'    => $wanted,
        'message'   => $ok ? '' : scanFriendlyError($output),
    ]);
}

if ($action === 'scan') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        scanJson(['success' => false, 'message' => 'Invalid request.'], 405);
    }
    $console = scanConsolePath();
    if ($console === null) {
        session_write_close();
        scanJson(['success' => false, 'message' => 'The scanning program (NAPS2) is not installed on this computer. Install it from naps2.com, then try again.']);
    }

    [$plugged] = scanUsbPresent();
    if ($plugged === false) {
        session_write_close();
        scanJson(['success' => false, 'message' => 'The scanner is not plugged in. Connect the Plustek scanner to this computer with its USB cable, then try again.']);
    }

    $appendToken = $_POST['append'] ?? '';
    $previousPages = [];
    if ($appendToken !== '') {
        $previousPages = scanPageImages($appendToken);
        if (!scanOwnsToken($appendToken) || !$previousPages) {
            session_write_close();
            scanJson(['success' => false, 'message' => 'The previous scan is no longer available. Please scan again.']);
        }
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['scan_tokens'][$token] = time();
    session_write_close();

    scanCleanup();
    $lock = @fopen(scanTempDir() . DIRECTORY_SEPARATOR . 'scanner.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        scanJson(['success' => false, 'message' => 'The scanner is busy with another scan. Please wait and try again.']);
    }

    @set_time_limit(SCAN_TIMEOUT + 120);
    @ini_set('memory_limit', '512M');
    $dir = scanTokenDir($token);
    @mkdir($dir, 0700, true);
    $dpi = (int) scanEnv('SCANNER_DPI', '300');
    $dpi = $dpi > 0 ? $dpi : 300;
    $command = [
        $console,
        '--noprofile',
        '--driver', scanEnv('SCANNER_DRIVER', 'twain'),
        '--device', scanEnv('SCANNER_DEVICE', 'S410'),
        '--source', scanEnv('SCANNER_SOURCE', 'feeder'),
        '--dpi', (string) $dpi,
        '--bitdepth', scanEnv('SCANNER_COLOR', 'color'),
        '--pagesize', scanEnv('SCANNER_PAGESIZE', '8.5x14in'),
        '--jpegquality', '90',
        '-o', $dir . DIRECTORY_SEPARATOR . 'raw.jpg',
        '-f',
    ];

    [$ok, $log] = scanRun($command);
    flock($lock, LOCK_UN);
    fclose($lock);

    $raw = glob($dir . DIRECTORY_SEPARATOR . 'raw*.jpg') ?: [];
    natsort($raw);
    $raw = array_values($raw);
    if (!$raw) {
        scanRemoveToken($token);
        error_log('Scanner: scan failed (exit ' . ($ok ? '0' : 'non-zero') . '): ' . $log);
        scanJson(['success' => false, 'message' => scanFriendlyError($log), 'details' => mb_substr($log, 0, 500)]);
    }

    $pages = [];
    foreach ($previousPages as $file) {
        $target = $dir . DIRECTORY_SEPARATOR . sprintf('page_%03d.jpg', count($pages) + 1);
        if (@copy($file, $target)) {
            $pages[] = $target;
        }
    }
    foreach ($raw as $file) {
        $target = $dir . DIRECTORY_SEPARATOR . sprintf('page_%03d.jpg', count($pages) + 1);
        scanAutoCrop($file, $target);
        @unlink($file);
        if (is_file($target)) {
            $pages[] = $target;
        }
    }

    if (!$pages || !scanBuildPdf($pages, scanFilePath($token), $dpi)) {
        scanRemoveToken($token);
        scanJson(['success' => false, 'message' => 'The scanned pages could not be converted to PDF. Please scan again.']);
    }

    if ($appendToken !== '') {
        scanRemoveToken($appendToken);
    }

    scanJson([
        'success' => true,
        'token'   => $token,
        'pages'   => count($pages),
        'url'     => 'scanner.php?action=preview&token=' . $token,
        'images'  => count(scanPageImages($token)),
    ]);
}

session_write_close();
scanJson(['success' => false, 'message' => 'Invalid action.'], 400);