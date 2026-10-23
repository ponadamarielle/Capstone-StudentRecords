<?php
include '../security_headers.php';
session_start();

if (!isset($_SESSION['name']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Super Admin') {
    header("Location: ../signin.php");
    exit();
}

require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../database_backup.php';

$conn = activityLogConnection();
if (!$conn) {
    die('Could not connect to the database.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$backupConn = backupConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        die('CSRF validation failed.');
    }
    $flash = ['type' => 'error', 'message' => 'Could not connect to the database.'];
    if ($backupConn) {
        switch ($_POST['backup_action']) {
            case 'backup_now':
                $result = runDatabaseBackup('manual', $_SESSION['name']);
                $flash = ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']];
                break;
            case 'save_schedule':
                $error = saveBackupSettings($backupConn, $_POST['frequency'] ?? '', $_POST['time'] ?? '', $_POST['weekday'] ?? '1');
                $flash = $error
                    ? ['type' => 'error', 'message' => $error]
                    : ['type' => 'success', 'message' => 'Backup schedule saved: ' . backupScheduleLabel(backupSettings($backupConn)) . '.'];
                break;
            case 'dismiss_alerts':
                dismissBackupAlerts($backupConn);
                $flash = null;
                break;
        }
    }
    $_SESSION['backup_flash'] = $flash;
    header('Location: backup_logs.php#backups');
    exit();
}

if (isset($_GET['download_backup']) && $backupConn) {
    $backupId = (int) $_GET['download_backup'];
    $stmt = mysqli_prepare($backupConn, 'SELECT * FROM database_backups WHERE backup_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $backupId);
    mysqli_stmt_execute($stmt);
    $backup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $path = $backup ? backupFilePath($backup) : null;
    if (!$path) {
        $_SESSION['backup_flash'] = ['type' => 'error', 'message' => 'That backup file is no longer available.'];
        header('Location: backup_logs.php#backups');
        exit();
    }
    logActivity('backup_downloaded', 'Downloaded database backup ' . $backup['file_name'], [
        'target_type' => 'backup',
        'target_id'   => $backupId,
        'details'     => ['file_name' => $backup['file_name'], 'checksum' => $backup['checksum']],
    ]);
    header('Content-Type: ' . (preg_match('/\.zip$/i', $backup['file_name']) ? 'application/zip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . basename($backup['file_name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit();
}

$backupFlash = $_SESSION['backup_flash'] ?? null;
unset($_SESSION['backup_flash']);

$backupSettings = ['frequency' => 'off', 'time' => '02:00', 'weekday' => '1'];
$backupAlerts = [];
$lastSuccess = null;
$nextScheduled = null;
if ($backupConn) {
    runScheduledBackupIfDue();
    $backupSettings = backupSettings($backupConn);
    $backupAlerts = backupFailureAlerts($backupConn);
    $nextScheduled = backupNextSlot($backupSettings);
    $result = mysqli_query($backupConn, "SELECT * FROM database_backups WHERE status = 'success' ORDER BY started_at DESC, backup_id DESC LIMIT 1");
    $lastSuccess = $result ? mysqli_fetch_assoc($result) : null;
}

$downloadableBackups = [];
if ($backupConn) {
    $result = mysqli_query($backupConn, "SELECT * FROM database_backups WHERE status = 'success' AND file_deleted = 0");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        if (backupFilePath($row)) {
            $downloadableBackups[(int) $row['backup_id']] = true;
        }
    }
}

$today = (new DateTime('now', new DateTimeZone(ACTIVITY_LOG_TIMEZONE)))->format('Y-m-d');

function logFilterDate($value)
{
    global $today;
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !strtotime($value)) {
        return '';
    }
    return $value > $today ? $today : $value;
}

$filters = [
    'from'     => logFilterDate($_GET['from'] ?? ''),
    'to'       => logFilterDate($_GET['to'] ?? ''),
    'user'     => trim($_GET['user'] ?? ''),
];
if ($filters['from'] !== '' && $filters['from'] >= $today) {
    $filters['from'] = date('Y-m-d', strtotime($today . ' -1 day'));
}
if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['to'] <= $filters['from']) {
    $filters['to'] = '';
}

$where  = [];
$types  = '';
$params = [];
if ($filters['from'] !== '') {
    $where[]  = 'created_at >= ?';
    $types   .= 's';
    $params[] = $filters['from'] . ' 00:00:00';
}
if ($filters['to'] !== '') {
    $where[]  = 'created_at <= ?';
    $types   .= 's';
    $params[] = $filters['to'] . ' 23:59:59';
}
if ($filters['user'] !== '') {
    $where[]  = 'user_name = ?';
    $types   .= 's';
    $params[] = $filters['user'];
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

function runLogQuery($conn, $sql, $types, array $params)
{
    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
}

if (($_GET['export'] ?? '') === 'csv') {
    $result = runLogQuery($conn, "SELECT * FROM activity_logs $whereSql ORDER BY created_at DESC, log_id DESC", $types, $params);
    $count  = $result ? mysqli_num_rows($result) : 0;

    logActivity('logs_exported', 'Exported ' . $count . ' activity log record(s) to CSV', [
        'details' => ['filters' => array_filter($filters, fn($v) => $v !== ''), 'records' => $count],
    ]);

    $stamp = (new DateTime('now', new DateTimeZone(ACTIVITY_LOG_TIMEZONE)))->format('Ymd_His');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activity-logs_' . $stamp . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Log ID', 'Date', 'Time', 'User', 'Role', 'Category', 'Action', 'Description',
                   'Student Number', 'Student Name', 'Target', 'Details', 'IP Address', 'Browser / Device']);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $ts = strtotime($row['created_at']);
        fputcsv($out, [
            $row['log_id'],
            date('Y-m-d', $ts),
            date('h:i:s A', $ts),
            $row['user_name'],
            $row['user_role'],
            activityCategoryLabel($row['category']),
            activityActionLabel($row['action']),
            $row['description'],
            $row['student_number'],
            $row['student_name'],
            trim(($row['target_type'] ?? '') . ' ' . ($row['target_id'] ?? '')),
            $row['details'],
            $row['ip_address'],
            $row['user_agent'],
        ]);
    }
    fclose($out);
    exit();
}

$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));

$countRes = runLogQuery($conn, "SELECT COUNT(*) AS n FROM activity_logs $whereSql", $types, $params);
$total    = (int) (mysqli_fetch_assoc($countRes)['n'] ?? 0);
$pages    = max(1, (int) ceil($total / $perPage));
$page     = min($page, $pages);
$offset   = ($page - 1) * $perPage;

$logs = [];
$res  = runLogQuery($conn, "SELECT * FROM activity_logs $whereSql ORDER BY created_at DESC, log_id DESC LIMIT $perPage OFFSET $offset", $types, $params);
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $logs[] = $row;
}

$users = [];
$userRes = mysqli_query($conn, "SELECT name FROM accounts WHERE role = 'Admin' AND status = 'Active' ORDER BY name");
while ($userRes && ($row = mysqli_fetch_row($userRes))) {
    $users[] = $row[0];
}


function logPageUrl(array $filters, array $extra = [])
{
    $query = array_filter(array_merge($filters, $extra), fn($v) => $v !== '' && $v !== null);
    return 'backup_logs.php' . ($query ? '?' . http_build_query($query) : '');
}

function renderLogDetails(?string $json)
{
    $data = $json ? json_decode($json, true) : null;
    if (!is_array($data) || !$data) {
        return '<span class="text-muted">No additional details.</span>';
    }

    $html = '';
    if (!empty($data['changes']) && is_array($data['changes'])) {
        $html .= '<table class="log-changes"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
        foreach ($data['changes'] as $field => $change) {
            $from = is_array($change) ? ($change['from'] ?? '') : '';
            $to   = is_array($change) ? ($change['to'] ?? '') : (string) $change;
            $html .= '<tr><td>' . htmlspecialchars((string) $field) . '</td>'
                . '<td class="log-old">' . ($from === '' ? '<em>empty</em>' : htmlspecialchars((string) $from)) . '</td>'
                . '<td class="log-new">' . ($to === '' ? '<em>empty</em>' : htmlspecialchars((string) $to)) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        unset($data['changes']);
    }

    if ($data) {
        $html .= '<dl class="log-kv">';
        foreach ($data as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', (string) $key));
            $text  = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value;
            $html .= '<dt>' . htmlspecialchars($label) . '</dt><dd>' . htmlspecialchars($text) . '</dd>';
        }
        $html .= '</dl>';
    }
    return $html;
}

include 'navbar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Backup &amp; Logs</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <link href="../css/font.css" rel="stylesheet">
    <link href="../css/activity-logs.css?v=<?= @filemtime(__DIR__ . '/../css/activity-logs.css') ?>" rel="stylesheet">
</head>
<body>
<div class="logs-container">

    <section class="backup-section" id="backups">

        <?php if ($backupFlash): ?>
            <div class="backup-flash backup-flash-<?= $backupFlash['type'] === 'success' ? 'success' : 'error' ?>" role="status">
                <i class="bi <?= $backupFlash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
                <span><?= htmlspecialchars($backupFlash['message']) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($backupAlerts): ?>
            <div class="backup-alert" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div class="backup-alert-body">
                    <strong><?= count($backupAlerts) === 1 ? 'A scheduled backup failed.' : count($backupAlerts) . ' scheduled backups failed.' ?></strong>
                    <?php foreach ($backupAlerts as $alert): ?>
                        <div><?= htmlspecialchars(date('M j, Y g:i A', strtotime($alert['started_at']))) ?>: <?= htmlspecialchars($alert['error_message'] ?: 'Unknown error') ?></div>
                    <?php endforeach; ?>
                    <div class="backup-alert-hint">Run a manual backup now to keep the data protected.</div>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="backup_action" value="dismiss_alerts">
                    <button type="submit" class="btn backup-dismiss-btn">Dismiss</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="backup-cards">
            <div class="backup-card">
                <div class="backup-card-top">
                    <span class="backup-card-icon backup-icon-blue"><i class="bi bi-cloud-check"></i></span>
                    <?php if ($lastSuccess && !$backupAlerts): ?>
                        <span class="backup-pill backup-pill-ok">Healthy</span>
                    <?php elseif ($backupAlerts): ?>
                        <span class="backup-pill backup-pill-bad">Needs attention</span>
                    <?php endif; ?>
                </div>
                <div class="backup-card-label">Last successful backup</div>
                <?php if ($lastSuccess): ?>
                    <div class="backup-card-value"><?= htmlspecialchars(date('M j, Y', strtotime($lastSuccess['started_at']))) ?></div>
                    <div class="backup-card-meta">
                        <?= htmlspecialchars(date('g:i A', strtotime($lastSuccess['started_at']))) ?> ·
                        <?= htmlspecialchars(ucfirst($lastSuccess['backup_type'])) ?> ·
                        <?= htmlspecialchars(formatBackupSize($lastSuccess['file_size'])) ?>
                    </div>
                <?php else: ?>
                    <div class="backup-card-value">None yet</div>
                    <div class="backup-card-meta">Click BACK UP NOW to create the first backup.</div>
                <?php endif; ?>
            </div>

            <div class="backup-card">
                <div class="backup-card-top">
                    <span class="backup-card-icon backup-icon-maroon"><i class="bi bi-clock-history"></i></span>
                </div>
                <div class="backup-card-label">Next scheduled backup</div>
                <?php if ($nextScheduled): ?>
                    <div class="backup-card-value"><?= htmlspecialchars($nextScheduled->format('M j, Y')) ?></div>
                    <div class="backup-card-meta"><?= htmlspecialchars($nextScheduled->format('g:i A')) ?> · <?= htmlspecialchars(backupScheduleLabel($backupSettings)) ?></div>
                <?php else: ?>
                    <div class="backup-card-value">Off</div>
                    <div class="backup-card-meta">Automatic backups are turned off.</div>
                <?php endif; ?>
            </div>

            <div class="backup-card backup-schedule">
                <div class="backup-card-top">
                    <span class="backup-card-icon backup-icon-gold"><i class="bi bi-calendar-event"></i></span>
                </div>
                <div class="backup-card-label">Backup schedule</div>
                <form method="POST" id="backupScheduleForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="backup_action" value="save_schedule">
                    <div class="backup-schedule-fields">
                        <select name="frequency" id="backupFrequency" class="form-select form-select-sm" aria-label="Frequency">
                            <?php foreach (BACKUP_FREQUENCIES as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($backupSettings['frequency'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="weekday" id="backupWeekday" class="form-select form-select-sm" aria-label="Day of the week">
                            <?php foreach (BACKUP_WEEKDAYS as $value => $label): ?>
                                <option value="<?= $value ?>" <?= (int) ($backupSettings['weekday'] ?? 1) === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="time" name="time" id="backupTime" class="form-control form-control-sm" aria-label="Time"
                               value="<?= htmlspecialchars($backupSettings['time'] ?? '02:00') ?>">
                        <button type="submit" class="btn btn-sm backup-save-btn">Save</button>
                    </div>
                </form>
                <form method="POST" class="backup-now-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="backup_action" value="backup_now">
                    <button type="submit" class="btn backup-now-btn" id="backupNowBtn">
                        <i class="bi bi-arrow-repeat"></i> BACK UP NOW
                    </button>
                </form>
            </div>
        </div>

        <p class="backup-note">
            <i class="bi bi-database-fill-lock"></i>
            Backups are copies of the whole database and all uploaded documents and photos, created on a schedule or on demand.
        </p>

    </section>

    <section class="logs-panel">
        <div class="logs-header">
            <div>
                <h2 class="logs-title">Activity log</h2>
                <p class="logs-subtitle">
                    <i class="bi bi-lock-fill"></i>
                    Read-only audit trail of sign-ins, sign-outs and every change to student records.
                </p>
            </div>
            <a class="btn logs-export-btn" href="<?= htmlspecialchars(logPageUrl($filters, ['export' => 'csv'])) ?>">
                <i class="bi bi-download"></i> Export CSV
            </a>
        </div>

        <form method="GET" class="logs-filters" autocomplete="off" data-today="<?= $today ?>">
            <div class="logs-filter">
                <label for="from">From</label>
                <input type="date" id="from" name="from" class="form-control" max="<?= date('Y-m-d', strtotime($today . ' -1 day')) ?>" value="<?= htmlspecialchars($filters['from']) ?>">
            </div>
            <div class="logs-filter">
                <label for="to">To</label>
                <input type="date" id="to" name="to" class="form-control" min="<?= $filters['from'] !== '' ? date('Y-m-d', strtotime($filters['from'] . ' +1 day')) : '' ?>" max="<?= $today ?>" value="<?= htmlspecialchars($filters['to']) ?>">
            </div>
            <div class="logs-filter">
                <label for="user">User</label>
                <select id="user" name="user" class="form-select">
                    <option value="">All users</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= htmlspecialchars($u) ?>" <?= $filters['user'] === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="logs-filter-actions">
                <button type="button" id="logsClearBtn" class="btn logs-clear-btn" <?= array_filter($filters, fn($v) => $v !== '') ? '' : 'hidden' ?>><i class="bi bi-x-circle"></i> Clear</button>
            </div>
        </form>

        <div class="logs-table-wrap">
            <table class="logs-table">
                <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>User</th>
                    <th>Category</th>
                    <th>Activity</th>
                    <th>Description</th>
                    <th>Student</th>
                    <th class="text-center">Details</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): $ts = strtotime($log['created_at']); ?>
                    <?php
                        $logNameParts = preg_split('/[\s,]+/', trim((string) $log['user_name']), -1, PREG_SPLIT_NO_EMPTY);
                        $logInitials = strtoupper(mb_substr($logNameParts[0] ?? '', 0, 1) . mb_substr($logNameParts[count($logNameParts) - 1] ?? '', 0, 1));
                    ?>
                    <tr>
                        <td class="log-time">
                            <div><?= date('M j, Y', $ts) ?></div>
                            <small><?= date('h:i:s A', $ts) ?></small>
                        </td>
                        <td>
                            <div class="log-user-cell">
                                <span class="log-avatar log-avatar-<?= abs(crc32((string) $log['user_name'])) % 4 ?>"><?= htmlspecialchars($logInitials) ?></span>
                                <div>
                                    <div class="log-user"><?= htmlspecialchars($log['user_name']) ?></div>
                                    <small class="log-role"><?= htmlspecialchars($log['user_role']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="log-cat log-cat-<?= htmlspecialchars($log['category']) ?>"><?= htmlspecialchars(activityCategoryLabel($log['category'])) ?></span></td>
                        <td class="log-action"><?= htmlspecialchars(activityActionLabel($log['action'])) ?></td>
                        <td class="log-desc"><?= htmlspecialchars($log['description']) ?></td>
                        <td>
                            <?php if ($log['student_number'] || $log['student_name']): ?>
                                <div><?= htmlspecialchars($log['student_name'] ?? '') ?></div>
                                <small class="log-role"><?= htmlspecialchars($log['student_number'] ?? '') ?></small>
                            <?php else: ?>
                                <span class="log-role">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm log-details-btn" data-target="log-<?= (int) $log['log_id'] ?>" aria-expanded="false">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </td>
                    </tr>
                    <tr class="log-details-row" id="log-<?= (int) $log['log_id'] ?>" hidden>
                        <td colspan="7">
                            <div class="log-details">
                                <div class="log-details-main">
                                    <?= renderLogDetails($log['details']) ?>
                                    <?php if ($log['action'] === 'backup_created' && isset($downloadableBackups[(int) $log['target_id']])): ?>
                                        <a class="btn btn-sm backup-download-btn" href="backup_logs.php?download_backup=<?= (int) $log['target_id'] ?>">
                                            <i class="bi bi-download"></i> Download backup file
                                        </a>
                                    <?php elseif ($log['action'] === 'backup_created'): ?>
                                        <div class="backup-file-removed">The backup file is no longer stored (only the latest <?= BACKUP_KEEP_FILES ?> are kept).</div>
                                    <?php endif; ?>
                                </div>
                                <div class="log-details-meta">
                                    <div><span>Log ID</span><?= (int) $log['log_id'] ?></div>
                                    <div><span>Recorded</span><?= htmlspecialchars($log['created_at']) ?></div>
                                    <div><span>IP address</span><?= htmlspecialchars($log['ip_address'] ?: '-') ?></div>
                                    <div><span>Browser / device</span><?= htmlspecialchars($log['user_agent'] ?: '-') ?></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr><td colspan="7" class="logs-empty"><i class="bi bi-journal-text"></i><br>No activity logs to show.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="logs-pagination" aria-label="Log pages">
                <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars(logPageUrl($filters, ['page' => max(1, $page - 1)])) ?>">
                    <i class="bi bi-chevron-left"></i> Previous
                </a>
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <a class="page-btn <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= htmlspecialchars(logPageUrl($filters, ['page' => min($pages, $page + 1)])) ?>">
                    Next <i class="bi bi-chevron-right"></i>
                </a>
            </nav>
        <?php endif; ?>
    </section>
</div>

<script src="../js/activity-logs.js?v=<?= @filemtime(__DIR__ . '/../js/activity-logs.js') ?>"></script>
</body>
</html>