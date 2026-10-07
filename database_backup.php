<?php
require_once __DIR__ . '/activity_log.php';

const BACKUP_DIR        = __DIR__ . '/backups/';
const BACKUP_UPLOADS_DIR = __DIR__ . '/uploads/';
const BACKUP_KEEP_FILES = 30;
const BACKUP_DB_NAME    = 'db_pup_eRecords';
const BACKUP_WEEKDAYS   = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const BACKUP_FREQUENCIES = ['off' => 'Off', 'daily' => 'Daily', 'weekly' => 'Weekly'];

function backupNow()
{
    return new DateTime('now', new DateTimeZone(ACTIVITY_LOG_TIMEZONE));
}

function backupConnection()
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }
    try {
        $conn = mysqli_connect('localhost', 'root', '', BACKUP_DB_NAME);
        if ($conn) {
            mysqli_set_charset($conn, 'utf8mb4');
            ensureBackupTables($conn);
        }
    } catch (Throwable $e) {
        error_log('Backup: cannot connect: ' . $e->getMessage());
        $conn = null;
    }
    return $conn ?: null;
}

function ensureBackupTables($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS database_backups (
        backup_id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        started_at      DATETIME     NOT NULL,
        finished_at     DATETIME     NULL,
        backup_type     VARCHAR(20)  NOT NULL,
        status          VARCHAR(20)  NOT NULL,
        file_name       VARCHAR(255) NULL,
        file_size       BIGINT       NULL,
        table_count     INT          NULL,
        row_count       BIGINT       NULL,
        checksum        CHAR(64)     NULL,
        error_message   VARCHAR(1000) NULL,
        triggered_by    VARCHAR(150) NOT NULL,
        file_deleted    TINYINT(1)   NOT NULL DEFAULT 0,
        alert_dismissed TINYINT(1)   NOT NULL DEFAULT 0,
        KEY idx_started_at (started_at),
        KEY idx_type_status (backup_type, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS backup_settings (
        setting_key   VARCHAR(50)  NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $now = backupNow()->format('Y-m-d H:i:s');
    foreach (['frequency' => 'daily', 'time' => '02:00', 'weekday' => '1', 'updated_at' => $now] as $key => $value) {
        $stmt = mysqli_prepare($conn, 'INSERT IGNORE INTO backup_settings (setting_key, setting_value) VALUES (?, ?)');
        mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
        mysqli_stmt_execute($stmt);
    }

    $columns = [];
    $result = mysqli_query($conn, 'SHOW COLUMNS FROM database_backups');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $columns[] = $row['Field'];
    }
    if (!in_array('upload_files', $columns, true)) {
        mysqli_query($conn, 'ALTER TABLE database_backups ADD COLUMN upload_files INT NULL AFTER row_count');
    }

    $stale = backupNow()->modify('-1 hour')->format('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "UPDATE database_backups
        SET status = 'failed', finished_at = started_at,
            error_message = 'The backup stopped before it finished (the server may have been stopped or timed out).'
        WHERE status = 'running' AND started_at < ?");
    mysqli_stmt_bind_param($stmt, 's', $stale);
    mysqli_stmt_execute($stmt);
}

function backupSettings($conn)
{
    $settings = ['frequency' => 'daily', 'time' => '02:00', 'weekday' => '1', 'updated_at' => backupNow()->format('Y-m-d H:i:s')];
    $result = mysqli_query($conn, 'SELECT setting_key, setting_value FROM backup_settings');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function saveBackupSettings($conn, $frequency, $time, $weekday)
{
    if (!array_key_exists($frequency, BACKUP_FREQUENCIES)) {
        return 'Choose a valid schedule.';
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        return 'Choose a valid time.';
    }
    $weekday = (int) $weekday;
    if (!isset(BACKUP_WEEKDAYS[$weekday])) {
        return 'Choose a valid day.';
    }

    $before = backupSettings($conn);
    $values = [
        'frequency'  => $frequency,
        'time'       => $time,
        'weekday'    => (string) $weekday,
        'updated_at' => backupNow()->format('Y-m-d H:i:s'),
    ];
    foreach ($values as $key => $value) {
        $stmt = mysqli_prepare($conn, 'REPLACE INTO backup_settings (setting_key, setting_value) VALUES (?, ?)');
        mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
        mysqli_stmt_execute($stmt);
    }

    logActivity('backup_schedule_changed', 'Changed the backup schedule to ' . backupScheduleLabel($values), [
        'details' => ['changes' => ['Schedule' => ['from' => backupScheduleLabel($before), 'to' => backupScheduleLabel($values)]]],
    ]);
    return null;
}

function backupScheduleLabel(array $settings)
{
    $frequency = $settings['frequency'] ?? 'off';
    if ($frequency === 'off') {
        return 'Off';
    }
    $time = DateTime::createFromFormat('H:i', $settings['time'] ?? '02:00');
    $timeLabel = $time ? $time->format('g:i A') : ($settings['time'] ?? '');
    if ($frequency === 'weekly') {
        return 'Every ' . (BACKUP_WEEKDAYS[(int) ($settings['weekday'] ?? 1)] ?? 'Monday') . ' at ' . $timeLabel;
    }
    return 'Daily at ' . $timeLabel;
}

function backupLastSlot(array $settings, ?DateTime $now = null)
{
    $frequency = $settings['frequency'] ?? 'off';
    if ($frequency === 'off') {
        return null;
    }
    $now = $now ?: backupNow();
    [$hour, $minute] = array_map('intval', explode(':', $settings['time'] ?? '02:00'));

    $slot = (clone $now)->setTime($hour, $minute, 0);
    if ($frequency === 'weekly') {
        $weekday = (int) ($settings['weekday'] ?? 1);
        $diff = ((int) $now->format('N') - $weekday + 7) % 7;
        $slot->modify("-{$diff} days");
        if ($slot > $now) {
            $slot->modify('-7 days');
        }
    } elseif ($slot > $now) {
        $slot->modify('-1 day');
    }
    return $slot;
}

function backupNextSlot(array $settings, ?DateTime $now = null)
{
    $last = backupLastSlot($settings, $now);
    if ($last === null) {
        return null;
    }
    return (clone $last)->modify(($settings['frequency'] ?? '') === 'weekly' ? '+7 days' : '+1 day');
}

function scheduledBackupIsDue($conn)
{
    $settings = backupSettings($conn);
    $slot = backupLastSlot($settings);
    if ($slot === null) {
        return false;
    }

    $updatedAt = DateTime::createFromFormat('Y-m-d H:i:s', $settings['updated_at'] ?? '', new DateTimeZone(ACTIVITY_LOG_TIMEZONE));
    if ($updatedAt && $slot < $updatedAt) {
        return false;
    }

    $slotText = $slot->format('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM database_backups WHERE backup_type = 'scheduled' AND started_at >= ?");
    mysqli_stmt_bind_param($stmt, 's', $slotText);
    mysqli_stmt_execute($stmt);
    return (int) (mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0] ?? 0) === 0;
}

function runScheduledBackupIfDue()
{
    try {
        $conn = backupConnection();
        if (!$conn || !scheduledBackupIsDue($conn)) {
            return null;
        }
        return runDatabaseBackup('scheduled');
    } catch (Throwable $e) {
        error_log('Scheduled backup check failed: ' . $e->getMessage());
        return null;
    }
}

function prepareBackupDir()
{
    if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0755, true)) {
        throw new Exception('Could not create the backups folder.');
    }
    if (!is_writable(BACKUP_DIR)) {
        throw new Exception('The backups folder is not writable.');
    }
    if (!file_exists(BACKUP_DIR . '.htaccess')) {
        @file_put_contents(BACKUP_DIR . '.htaccess', "Require all denied\n");
    }
    if (!file_exists(BACKUP_DIR . 'index.html')) {
        @file_put_contents(BACKUP_DIR . 'index.html', '');
    }
}

function runDatabaseBackup($type, $triggeredBy = null)
{
    $conn = backupConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Could not connect to the database.'];
    }

    $lock = null;
    try {
        prepareBackupDir();
        $lock = fopen(BACKUP_DIR . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['success' => false, 'message' => 'Another backup is already running. Please wait a moment.'];
        }
    } catch (Throwable $e) {
        $lock = null;
    }

    $isScheduled = $type === 'scheduled';
    $triggeredBy = $isScheduled ? 'System' : ($triggeredBy ?? ($_SESSION['name'] ?? 'Unknown user'));
    $start = backupNow();
    $startText = $start->format('Y-m-d H:i:s');

    $stmt = mysqli_prepare($conn, "INSERT INTO database_backups (started_at, backup_type, status, triggered_by) VALUES (?, ?, 'running', ?)");
    mysqli_stmt_bind_param($stmt, 'sss', $startText, $type, $triggeredBy);
    mysqli_stmt_execute($stmt);
    $backupId = mysqli_insert_id($conn);

    $baseName = 'pup_erecords_' . $start->format('Y-m-d_His') . '_' . $type . '_' . bin2hex(random_bytes(4));
    $canZip = function_exists('gzdeflate');
    $fileName = $baseName . ($canZip ? '.zip' : '.sql');
    $sqlPath = BACKUP_DIR . $baseName . '.sql.part';
    $tempPath = BACKUP_DIR . $fileName . '.part';
    $finalPath = BACKUP_DIR . $fileName;
    $uploads = ['files' => 0, 'bytes' => 0];

    try {
        @set_time_limit(1800);
        prepareBackupDir();
        $stats = writeDatabaseDump($conn, $sqlPath, $start);
        if ($canZip) {
            $uploads = writeBackupZip($sqlPath, $tempPath, $start);
            @unlink($sqlPath);
        } elseif (!@rename($sqlPath, $tempPath)) {
            throw new Exception('Could not save the backup file.');
        }
        if (!@rename($tempPath, $finalPath)) {
            throw new Exception('Could not save the backup file.');
        }
        $size = filesize($finalPath);
        if ($size === false || $size === 0) {
            throw new Exception('The backup file is empty.');
        }
        $checksum = hash_file('sha256', $finalPath);
        $finished = backupNow()->format('Y-m-d H:i:s');

        $stmt = mysqli_prepare($conn, "UPDATE database_backups
            SET status = 'success', finished_at = ?, file_name = ?, file_size = ?, table_count = ?, row_count = ?, upload_files = ?, checksum = ?
            WHERE backup_id = ?");
        $uploadFiles = $canZip ? $uploads['files'] : null;
        mysqli_stmt_bind_param($stmt, 'ssiiiisi', $finished, $fileName, $size, $stats['tables'], $stats['rows'], $uploadFiles, $checksum, $backupId);
        mysqli_stmt_execute($stmt);

        logActivity('backup_created',
            ($isScheduled ? 'Scheduled' : 'Manual') . ' backup created: database'
                . ($canZip ? ' + ' . number_format($uploads['files']) . ' uploaded file(s)' : ' only')
                . ' (' . formatBackupSize($size) . ')',
            [
                'system'      => $isScheduled,
                'target_type' => 'backup',
                'target_id'   => $backupId,
                'details'     => [
                    'file_name' => $fileName,
                    'file_size' => formatBackupSize($size),
                    'tables'    => $stats['tables'],
                    'rows'      => $stats['rows'],
                    'uploaded_files' => $canZip ? $uploads['files'] : 'Not included (the PHP zlib extension is turned off)',
                    'uploads_size'   => $canZip ? formatBackupSize($uploads['bytes']) : null,
                    'checksum'  => $checksum,
                ],
            ]);

        pruneOldBackups($conn);

        return [
            'success'   => true,
            'backup_id' => $backupId,
            'file_name' => $fileName,
            'file_size' => $size,
            'tables'    => $stats['tables'],
            'rows'      => $stats['rows'],
            'message'   => 'Backup created successfully: ' . $fileName . ' (' . formatBackupSize($size) . ', '
                . $stats['tables'] . ' tables, ' . number_format($stats['rows']) . ' rows'
                . ($canZip
                    ? ', ' . number_format($uploads['files']) . ' uploaded files).'
                    : '). Uploaded files were NOT included because the PHP zlib extension is turned off.'),
        ];
    } catch (Throwable $e) {
        @unlink($tempPath);
        @unlink($sqlPath);
        $error = substr($e->getMessage(), 0, 1000);
        $finished = backupNow()->format('Y-m-d H:i:s');
        $stmt = mysqli_prepare($conn, "UPDATE database_backups SET status = 'failed', finished_at = ?, error_message = ? WHERE backup_id = ?");
        mysqli_stmt_bind_param($stmt, 'ssi', $finished, $error, $backupId);
        mysqli_stmt_execute($stmt);

        logActivity('backup_failed', ($isScheduled ? 'Scheduled' : 'Manual') . ' database backup failed: ' . $error, [
            'system'      => $isScheduled,
            'target_type' => 'backup',
            'target_id'   => $backupId,
            'details'     => ['error' => $error],
        ]);

        if ($isScheduled) {
            sendBackupFailureEmail($conn, $startText, $error);
        }

        return ['success' => false, 'backup_id' => $backupId, 'message' => 'Backup failed: ' . $error];
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function backupSqlValue($conn, $value, $field)
{
    if ($value === null) {
        return 'NULL';
    }
    if ((int) $field->charsetnr === 63 && in_array($field->type, [MYSQLI_TYPE_BLOB, MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB,
            MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING], true)) {
        return $value === '' ? "''" : '0x' . bin2hex($value);
    }
    return "'" . mysqli_real_escape_string($conn, (string) $value) . "'";
}

function writeDatabaseDump($conn, $path, DateTime $start)
{
    $out = fopen($path, 'wb');
    if (!$out) {
        throw new Exception('Could not create the backup file.');
    }
    $write = function ($text) use ($out) {
        if (fwrite($out, $text) === false) {
            throw new Exception('Could not write to the backup file. The disk may be full.');
        }
    };

    $write("-- PUP eRecords database backup\n");
    $write('-- Database: ' . BACKUP_DB_NAME . "\n");
    $write('-- Created: ' . $start->format('Y-m-d H:i:s T') . "\n\n");
    $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

    $tables = [];
    $views = [];
    $result = mysqli_query($conn, 'SHOW FULL TABLES');
    if (!$result) {
        throw new Exception('Could not read the list of tables: ' . mysqli_error($conn));
    }
    while ($row = mysqli_fetch_row($result)) {
        if (strtoupper($row[1]) === 'VIEW') {
            $views[] = $row[0];
        } else {
            $tables[] = $row[0];
        }
    }

    $rowTotal = 0;
    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $create = mysqli_query($conn, "SHOW CREATE TABLE $quoted");
        if (!$create) {
            throw new Exception("Could not read the structure of table $table: " . mysqli_error($conn));
        }
        $createSql = mysqli_fetch_row($create)[1];

        $write("-- Table: $table\n");
        $write("DROP TABLE IF EXISTS $quoted;\n");
        $write($createSql . ";\n\n");

        $data = mysqli_query($conn, "SELECT * FROM $quoted", MYSQLI_USE_RESULT);
        if (!$data) {
            throw new Exception("Could not read the data of table $table: " . mysqli_error($conn));
        }
        $fields = mysqli_fetch_fields($data);
        $columns = implode(', ', array_map(fn($f) => '`' . str_replace('`', '``', $f->name) . '`', $fields));

        $batch = [];
        while ($row = mysqli_fetch_row($data)) {
            $values = [];
            foreach ($row as $i => $value) {
                $values[] = backupSqlValue($conn, $value, $fields[$i]);
            }
            $batch[] = '(' . implode(', ', $values) . ')';
            $rowTotal++;
            if (count($batch) >= 100) {
                $write("INSERT INTO $quoted ($columns) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        mysqli_free_result($data);
        if ($batch) {
            $write("INSERT INTO $quoted ($columns) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $write("\n");
    }

    foreach ($views as $view) {
        $quoted = '`' . str_replace('`', '``', $view) . '`';
        $create = mysqli_query($conn, "SHOW CREATE VIEW $quoted");
        if ($create) {
            $write("-- View: $view\nDROP VIEW IF EXISTS $quoted;\n" . mysqli_fetch_row($create)[1] . ";\n\n");
        }
    }

    $triggers = mysqli_query($conn, 'SHOW TRIGGERS');
    while ($triggers && ($trigger = mysqli_fetch_assoc($triggers))) {
        $name = '`' . str_replace('`', '``', $trigger['Trigger']) . '`';
        $table = '`' . str_replace('`', '``', $trigger['Table']) . '`';
        $write("-- Trigger: {$trigger['Trigger']}\nDROP TRIGGER IF EXISTS $name;\nDELIMITER ;;\n");
        $write("CREATE TRIGGER $name {$trigger['Timing']} {$trigger['Event']} ON $table FOR EACH ROW {$trigger['Statement']};;\n");
        $write("DELIMITER ;\n\n");
    }

    $write("SET FOREIGN_KEY_CHECKS = 1;\n");
    $write('-- Backup completed: ' . backupNow()->format('Y-m-d H:i:s T') . "\n");

    if (!fclose($out)) {
        throw new Exception('Could not finish writing the backup file.');
    }
    return ['tables' => count($tables), 'rows' => $rowTotal];
}

class BackupZipWriter
{
    private $handle;
    private $entries = [];
    private $offset = 0;
    private $dosTime;
    private $dosDate;

    public function __construct($path, DateTime $time)
    {
        $this->handle = fopen($path, 'wb');
        if (!$this->handle) {
            throw new Exception('Could not create the backup zip file.');
        }
        $this->dosTime = ($time->format('G') << 11) | ((int) $time->format('i') << 5) | intdiv((int) $time->format('s'), 2);
        $this->dosDate = (((int) $time->format('Y') - 1980) << 9) | ((int) $time->format('n') << 5) | (int) $time->format('j');
    }

    private function write($data)
    {
        if (fwrite($this->handle, $data) === false) {
            throw new Exception('Could not write the backup zip file. The disk may be full.');
        }
        $this->offset += strlen($data);
    }

    private function addEntry($name, $data, $isDir, $store)
    {
        $size = strlen($data);
        if ($this->offset + $size > 4000000000) {
            throw new Exception('The backup is larger than 4 GB, which this backup format cannot hold.');
        }
        $crc = $isDir ? 0 : crc32($data);
        $method = 0;
        $payload = $data;
        if (!$isDir && !$store && $size > 0) {
            $deflated = gzdeflate($data, 6);
            if ($deflated !== false && strlen($deflated) < $size) {
                $method = 8;
                $payload = $deflated;
            }
        }
        $compressed = strlen($payload);
        $headerOffset = $this->offset;

        $this->write(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $this->dosTime, $this->dosDate,
            $crc, $compressed, $size, strlen($name), 0) . $name);
        $this->write($payload);

        $this->entries[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $this->dosTime, $this->dosDate,
            $crc, $compressed, $size, strlen($name), 0, 0, 0, 0, $isDir ? 0x10 : 0x20, $headerOffset) . $name;
    }

    public function addFromString($name, $data, $store = false)
    {
        $this->addEntry($name, (string) $data, false, $store);
    }

    public function addFile($path, $name, $store = false)
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new Exception('Could not read "' . $name . '" for the backup.');
        }
        $this->addEntry($name, $data, false, $store);
    }

    public function addEmptyDir($name)
    {
        $this->addEntry(rtrim($name, '/') . '/', '', true, true);
    }

    public function close()
    {
        $centralOffset = $this->offset;
        $central = implode('', $this->entries);
        $this->write($central);
        $count = count($this->entries);
        $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $centralOffset, 0));
        return fclose($this->handle);
    }
}

function writeBackupZip($sqlPath, $zipPath, DateTime $start)
{
    $zip = new BackupZipWriter($zipPath, $start);

    $zip->addFile($sqlPath, 'database.sql');
    $zip->addFromString('README.txt',
        "PUP eRecords backup\r\n"
        . 'Created: ' . $start->format('Y-m-d H:i:s T') . "\r\n\r\n"
        . "database.sql  - the whole database (import it in phpMyAdmin)\r\n"
        . "uploads/      - copy this folder into the project folder (C:\\xampp\\htdocs\\CAPSTONE)\r\n");

    $files = 0;
    $bytes = 0;
    $base = realpath(BACKUP_UPLOADS_DIR);
    if ($base !== false && is_dir($base)) {
        $zip->addEmptyDir('uploads');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = 'uploads/' . str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            if (!$item->isReadable()) {
                continue;
            }
            $zip->addFile($item->getPathname(), $relative, (bool) preg_match('/\.(jpe?g|png|gif|webp|zip)$/i', $relative));
            $files++;
            $bytes += $item->getSize();
        }
    }

    if (!$zip->close()) {
        throw new Exception('Could not finish the backup zip file. The disk may be full.');
    }
    return ['files' => $files, 'bytes' => $bytes];
}

function pruneOldBackups($conn)
{
    $keep = BACKUP_KEEP_FILES;
    $result = mysqli_query($conn, "SELECT backup_id, file_name FROM database_backups
        WHERE status = 'success' AND file_deleted = 0
        ORDER BY started_at DESC, backup_id DESC LIMIT 100000 OFFSET $keep");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $path = BACKUP_DIR . basename((string) $row['file_name']);
        if (is_file($path)) {
            @unlink($path);
        }
        $id = (int) $row['backup_id'];
        mysqli_query($conn, "UPDATE database_backups SET file_deleted = 1 WHERE backup_id = $id");
    }
}

function backupFilePath(array $backup)
{
    if (($backup['status'] ?? '') !== 'success' || !empty($backup['file_deleted']) || empty($backup['file_name'])) {
        return null;
    }
    $path = BACKUP_DIR . basename($backup['file_name']);
    return is_file($path) ? $path : null;
}

function formatBackupSize($bytes)
{
    $bytes = (float) $bytes;
    foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
        if ($bytes < 1024 || $unit === 'GB') {
            return ($unit === 'B' ? (int) $bytes : number_format($bytes, 1)) . ' ' . $unit;
        }
        $bytes /= 1024;
    }
}

function backupEnv($key)
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $file = __DIR__ . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v);
            }
        }
    }
    return $env[$key] ?? (getenv($key) ?: null);
}

function sendBackupFailureEmail($conn, $startedAt, $error)
{
    try {
        $mailerDir = __DIR__ . '/PHPMailer-7.0.1/src/';
        $user = backupEnv('GMAIL_USER');
        $pass = backupEnv('GMAIL_APP_PASSWORD');
        if (!$user || !$pass || !is_file($mailerDir . 'PHPMailer.php')) {
            return false;
        }
        require_once $mailerDir . 'PHPMailer.php';
        require_once $mailerDir . 'SMTP.php';
        require_once $mailerDir . 'Exception.php';

        $recipients = [];
        $result = mysqli_query($conn, "SELECT name, email FROM accounts WHERE role = 'Super Admin' AND status = 'Active'");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $row;
            }
        }
        if (!$recipients) {
            return false;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom($user, 'PUP eRecords - Backup Monitor');
        foreach ($recipients as $r) {
            $mail->addAddress($r['email'], $r['name']);
        }
        $when = date('F j, Y g:i A', strtotime($startedAt));
        $mail->isHTML(true);
        $mail->Subject = 'PUP eRecords: Scheduled database backup failed';
        $mail->Body = '<h3>The scheduled database backup failed.</h3>'
            . '<p><strong>Date and time:</strong> ' . htmlspecialchars($when) . '</p>'
            . '<p><strong>Reason:</strong> ' . htmlspecialchars($error) . '</p>'
            . '<p>Please sign in to PUP eRecords, open <strong>Backup &amp; Logs</strong> and run a manual backup.</p>';
        $mail->AltBody = "The scheduled database backup failed on $when.\nReason: $error\nPlease sign in and run a manual backup.";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Backup failure email could not be sent: ' . $e->getMessage());
        return false;
    }
}

function backupFailureAlerts($conn)
{
    $alerts = [];
    $result = mysqli_query($conn, "SELECT * FROM database_backups
        WHERE backup_type = 'scheduled' AND status = 'failed' AND alert_dismissed = 0
        ORDER BY started_at DESC LIMIT 5");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $alerts[] = $row;
    }
    return $alerts;
}

function dismissBackupAlerts($conn)
{
    mysqli_query($conn, "UPDATE database_backups SET alert_dismissed = 1
        WHERE backup_type = 'scheduled' AND status = 'failed' AND alert_dismissed = 0");
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit();
    }

    $force = in_array('--force', $argv ?? [], true);
    $conn = backupConnection();
    if (!$conn) {
        fwrite(STDERR, "Could not connect to the database.\n");
        exit(1);
    }

    if (!$force && !scheduledBackupIsDue($conn)) {
        echo 'No scheduled backup is due. Schedule: ' . backupScheduleLabel(backupSettings($conn)) . "\n";
        exit(0);
    }

    $result = runDatabaseBackup('scheduled');
    echo $result['message'] . "\n";
    exit(!empty($result['success']) ? 0 : 1);
}