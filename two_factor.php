<?php
require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/account_email.php';

const TF_CODE_MINUTES     = 10;
const TF_RESEND_SECONDS   = 60;
const TF_MAX_FAILS        = 5;
const TF_PENDING_MINUTES  = 15;
const TF_TRUST_DAYS       = 30;
const TF_TRUST_COOKIE     = 'pup_trusted_device';
const TF_ISSUER           = 'PUP eRecords';

function tfEnv($key, $default = '')
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value !== false && $value !== null && $value !== '') {
        return $value;
    }
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = __DIR__ . '/.env';
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
    return $file[$key] ?? $default;
}

function tfDb()
{
    static $conn = null;
    if ($conn === null) {
        $conn = mysqli_connect('localhost', 'root', '', 'db_pup_eRecords');
        if (!$conn) {
            die('Connection failed: ' . mysqli_connect_error());
        }
        mysqli_set_charset($conn, 'utf8mb4');
        tfEnsureSchema($conn);
    }
    return $conn;
}

function tfEnsureSchema($conn)
{
    $columns = [];
    $result = mysqli_query($conn, "SHOW COLUMNS FROM accounts");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $columns[$row['Field']] = true;
    }
    if (!isset($columns['totp_secret'])) {
        mysqli_query($conn, "ALTER TABLE accounts ADD COLUMN totp_secret VARCHAR(64) NULL");
    }
    if (!isset($columns['totp_enabled_at'])) {
        mysqli_query($conn, "ALTER TABLE accounts ADD COLUMN totp_enabled_at DATETIME NULL");
    }
    if (!isset($columns['totp_last_step'])) {
        mysqli_query($conn, "ALTER TABLE accounts ADD COLUMN totp_last_step BIGINT NULL");
    }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS login_codes (
        account_id INT NOT NULL PRIMARY KEY,
        code_hash VARCHAR(255) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        sent_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS trusted_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        pass_hash CHAR(64) NOT NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        UNIQUE KEY account_token (account_id, token_hash)
    ) DEFAULT CHARSET=utf8mb4");
    $result = mysqli_query($conn, "SHOW INDEX FROM trusted_devices WHERE Key_name = 'token_hash'");
    if ($result && mysqli_num_rows($result) > 0) {
        mysqli_query($conn, "ALTER TABLE trusted_devices DROP INDEX token_hash");
    }
    $result = mysqli_query($conn, "SHOW INDEX FROM trusted_devices WHERE Key_name = 'account_token'");
    if ($result && mysqli_num_rows($result) === 0) {
        mysqli_query($conn, "ALTER TABLE trusted_devices ADD UNIQUE KEY account_token (account_id, token_hash)");
    }
}

function tfAccount($conn, $id)
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM accounts WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function tfLogUser(array $row)
{
    return [
        'user_id'   => $row['id'] ?? null,
        'user_name' => $row['name'] ?? ($row['email'] ?? ''),
        'user_role' => $row['role'] ?? 'Unknown account',
    ];
}

function tfMaskEmail($email)
{
    [$user, $domain] = array_pad(explode('@', (string) $email, 2), 2, '');
    $shown = mb_substr($user, 0, min(2, mb_strlen($user)));
    return $shown . str_repeat('*', max(3, mb_strlen($user) - 2)) . '@' . $domain;
}

function tfBase32Encode($bytes)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function tfBase32Decode($text)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $text));
    $bits = '';
    foreach (str_split($text) as $char) {
        $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }
    return $out;
}

function tfNewSecret()
{
    return tfBase32Encode(random_bytes(20));
}

function tfTotpAt($secret, $step)
{
    $key = tfBase32Decode($secret);
    $hash = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function tfTotpMatch($secret, $code, $lastStep = null)
{
    $code = preg_replace('/\D/', '', (string) $code);
    if (strlen($code) !== 6 || $secret === '') {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $drift) {
        $step = $now + $drift;
        if ($lastStep !== null && $step <= (int) $lastStep) {
            continue;
        }
        if (hash_equals(tfTotpAt($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function tfOtpauthUri($secret, $email)
{
    $label = rawurlencode(TF_ISSUER) . ':' . rawurlencode($email);
    return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode(TF_ISSUER) . '&algorithm=SHA1&digits=6&period=30';
}

function tfDeviceToken()
{
    $token = $_COOKIE[TF_TRUST_COOKIE] ?? '';
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
}

function tfIsTrustedDevice($conn, array $row)
{
    $token = tfDeviceToken();
    if ($token === '') {
        return false;
    }
    mysqli_query($conn, "DELETE FROM trusted_devices WHERE expires_at <= NOW()");
    $hash = hash('sha256', $token);
    $stmt = mysqli_prepare($conn, "SELECT pass_hash FROM trusted_devices WHERE token_hash = ? AND account_id = ? AND expires_at > NOW() LIMIT 1");
    $id = (int) $row['id'];
    mysqli_stmt_bind_param($stmt, 'si', $hash, $id);
    mysqli_stmt_execute($stmt);
    $found = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $found && hash_equals($found[0], hash('sha256', (string) $row['pass']));
}

function tfTrustDevice($conn, array $row)
{
    $token = tfDeviceToken() ?: bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $passHash = hash('sha256', (string) $row['pass']);
    $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $days = TF_TRUST_DAYS;
    $id = (int) $row['id'];
    $stmt = mysqli_prepare($conn, "INSERT INTO trusted_devices (account_id, token_hash, pass_hash, user_agent, expires_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL $days DAY)
        ON DUPLICATE KEY UPDATE pass_hash = VALUES(pass_hash), user_agent = VALUES(user_agent), created_at = NOW(), expires_at = VALUES(expires_at)");
    mysqli_stmt_bind_param($stmt, 'isss', $id, $hash, $passHash, $agent);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(TF_TRUST_COOKIE, $token, [
        'expires'  => time() + TF_TRUST_DAYS * 86400,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function tfBegin($conn, array $row)
{
    tfEnsureSchema($conn);
    if (tfIsTrustedDevice($conn, $row) && trim((string) ($row['totp_secret'] ?? '')) !== '') {
        tfFinishLogin($row, 'trusted device');
    }
    unset($_SESSION['tf_setup']);
    $_SESSION['tf_pending'] = [
        'id'      => (int) $row['id'],
        'started' => time(),
        'method'  => 'email',
        'fails'   => 0,
        'sent'    => false,
        'notice'  => '',
    ];
    header('Location: verify_login.php');
    exit();
}

function tfPending($conn)
{
    $pending = $_SESSION['tf_pending'] ?? null;
    if (!is_array($pending) || time() - (int) $pending['started'] > TF_PENDING_MINUTES * 60) {
        return null;
    }
    $row = tfAccount($conn, (int) $pending['id']);
    if (!$row || $row['status'] !== 'Active') {
        return null;
    }
    return $row;
}

function tfCancel($message = '')
{
    unset($_SESSION['tf_pending'], $_SESSION['tf_setup']);
    if ($message !== '') {
        $_SESSION['signin_notice'] = $message;
    }
    header('Location: signin.php');
    exit();
}

function tfInternetReachable()
{
    $socket = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 4);
    if ($socket) {
        fclose($socket);
        return true;
    }
    return false;
}

function tfSendLoginCode($conn, array $row)
{
    if (!tfInternetReachable()) {
        return 'offline';
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    require_once __DIR__ . '/PHPMailer-7.0.1/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer-7.0.1/src/SMTP.php';
    require_once __DIR__ . '/PHPMailer-7.0.1/src/Exception.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = tfEnv('GMAIL_USER');
        $mail->Password = tfEnv('GMAIL_APP_PASSWORD');
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->Timeout = 15;
        $mail->setFrom('pupbc.superadmin@gmail.com', 'PUP eRecords');
        $mail->addAddress($row['email'], $row['name']);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = 'Your PUP eRecords sign-in code: ' . $code;
        [$mail->Body, $mail->AltBody] = buildLoginCodeEmail($row['name'], $code, TF_CODE_MINUTES);
        $mail->send();
    } catch (Throwable $e) {
        error_log('Sign-in code email failed: ' . ($mail->ErrorInfo ?: $e->getMessage()));
        logActivity('email_failed', 'Could not send the sign-in code to ' . $row['email'], tfLogUser($row) + [
            'category' => 'audit',
            'details'  => ['recipient' => $row['email'], 'error' => $mail->ErrorInfo ?: $e->getMessage()],
        ]);
        return tfInternetReachable() ? 'failed' : 'offline';
    }

    $hash = password_hash($code, PASSWORD_DEFAULT);
    $minutes = TF_CODE_MINUTES;
    $id = (int) $row['id'];
    $stmt = mysqli_prepare($conn, "REPLACE INTO login_codes (account_id, code_hash, attempts, sent_at, expires_at) VALUES (?, ?, 0, NOW(), NOW() + INTERVAL $minutes MINUTE)");
    mysqli_stmt_bind_param($stmt, 'is', $id, $hash);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    logActivity('login_code_sent', 'Sent a sign-in code to ' . $row['email'], tfLogUser($row) + [
        'category' => 'audit',
        'details'  => ['recipient' => $row['email']],
    ]);
    return 'sent';
}

function tfResendWait($conn, array $row)
{
    $id = (int) $row['id'];
    $stmt = mysqli_prepare($conn, "SELECT TIMESTAMPDIFF(SECOND, sent_at, NOW()) FROM login_codes WHERE account_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $found = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $found ? max(0, TF_RESEND_SECONDS - (int) $found[0]) : 0;
}

function tfCheckEmailCode($conn, array $row, $code)
{
    $id = (int) $row['id'];
    $stmt = mysqli_prepare($conn, "SELECT code_hash, attempts, expires_at > NOW() FROM login_codes WHERE account_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $found = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$found) {
        return 'missing';
    }
    if (!(int) $found[2] || (int) $found[1] >= TF_MAX_FAILS) {
        return 'expired';
    }
    if (password_verify(preg_replace('/\D/', '', (string) $code), $found[0])) {
        $stmt = mysqli_prepare($conn, "DELETE FROM login_codes WHERE account_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return 'ok';
    }
    $stmt = mysqli_prepare($conn, "UPDATE login_codes SET attempts = attempts + 1 WHERE account_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return 'wrong';
}

function tfCheckAppCode($conn, array $row, $code, $secret = null)
{
    $saved = $secret === null;
    $secret = $secret ?? trim((string) ($row['totp_secret'] ?? ''));
    $step = tfTotpMatch($secret, $code, $saved ? ($row['totp_last_step'] ?? null) : null);
    if ($step === null) {
        return false;
    }
    if ($saved) {
        $id = (int) $row['id'];
        $stmt = mysqli_prepare($conn, "UPDATE accounts SET totp_last_step = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $step, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    return $step;
}

function tfFinishLogin(array $row, $method)
{
    unset($_SESSION['tf_pending'], $_SESSION['tf_setup']);
    session_regenerate_id(true);
    $_SESSION['email'] = $row['email'];
    $_SESSION['role'] = $row['role'];
    $_SESSION['name'] = $row['name'];
    $_SESSION['is_first_login'] = $row['is_first_login'];

    logActivity('login', 'Signed in as ' . $row['role'] . ' (' . $method . ')', tfLogUser($row) + [
        'details' => ['email' => $row['email'], 'verification' => $method],
    ]);

    if ($row['is_first_login'] == 1 && in_array($row['role'], ['Admin', 'OJT Intern'], true)) {
        header('Location: change_password.php');
        exit();
    }
    if ($row['role'] === 'Super Admin') {
        header('Location: superadmin/dashboard.php');
        exit();
    }
    header('Location: admin/student_directory.php');
    exit();
}