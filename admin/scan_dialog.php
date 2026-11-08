<?php
session_start();
require_once __DIR__ . '/../access_control.php';
if (!isset($_SESSION['name'], $_SESSION['role']) || !isOfficeRole()) {
    http_response_code(403);
    exit();
}
session_write_close();
header('Content-Type: application/json');

if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
    echo json_encode(['success' => false]);
    exit();
}

$dir = rtrim(sys_get_temp_dir(), '/\\');
$heartbeat = $dir . DIRECTORY_SEPARATOR . 'pup_scan_dialog.heartbeat';
$running = $dir . DIRECTORY_SEPARATOR . 'pup_scan_dialog.running';
$script = __DIR__ . DIRECTORY_SEPARATOR . 'scan_dialog_hider.ps1';

function scanDialogEnv($key, $default)
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    $path = __DIR__ . '/../.env';
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === $key && trim(trim($v), "\"'") !== '') {
                return trim(trim($v), "\"'");
            }
        }
    }
    return $default;
}

$folder = scanDialogEnv('SCANNER_CLOSE_FOLDER', '1') === '0'
    ? ''
    : rtrim(scanDialogEnv('SCANNER_WATCH_FOLDER', 'C:\\Users\\Public\\Documents\\ScanDoc'), '/\\');

if (($_POST['action'] ?? '') === 'stop') {
    @unlink($heartbeat);
    echo json_encode(['success' => true]);
    exit();
}

@touch($heartbeat);
clearstatcache();
$alive = is_file($running) && time() - (int) @filemtime($running) < 5;
if (!$alive && is_file($script)) {
    @touch($running);
    $cmd = 'start "" /B powershell -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File '
        . escapeshellarg($script) . ' -Heartbeat ' . escapeshellarg($heartbeat) . ' -Running ' . escapeshellarg($running)
        . ($folder !== '' ? ' -Folder ' . escapeshellarg($folder) : '');
    $handle = @popen($cmd, 'r');
    if ($handle) {
        pclose($handle);
    }
}
echo json_encode(['success' => true, 'started' => !$alive, 'script' => is_file($script), 'log' => $dir . DIRECTORY_SEPARATOR . 'pup_scan_dialog.log']);