<?php
include 'security_headers.php';
session_start();
require_once __DIR__ . '/two_factor.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

$conn = tfDb();
$setup = $_SESSION['tf_setup'] ?? null;
if (!is_array($setup) || time() - (int) $setup['started'] > TF_PENDING_MINUTES * 60) {
    tfCancel(is_array($setup) ? 'The setup took too long. Please sign in again.' : '');
}
$row = tfAccount($conn, (int) $setup['id']);
if (!$row || $row['status'] !== 'Active') {
    tfCancel();
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        die('CSRF validation failed.');
    }
    if (($_POST['action'] ?? '') === 'cancel') {
        tfCancel();
    }
    $code = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));
    $step = preg_match('/^\d{6}$/', $code) ? tfCheckAppCode($conn, $row, $code, $setup['secret']) : false;
    if ($step === false) {
        $_SESSION['tf_setup']['fails'] = (int) ($setup['fails'] ?? 0) + 1;
        if ($_SESSION['tf_setup']['fails'] >= TF_MAX_FAILS) {
            tfCancel('Too many wrong codes. Please sign in again.');
        }
        $error = preg_match('/^\d{6}$/', $code)
            ? 'Incorrect code. Make sure you scanned the QR code on this page, then enter the newest code from the app.'
            : 'Enter the 6-digit code.';
    } else {
        $id = (int) $row['id'];
        $secret = $setup['secret'];
        $stmt = mysqli_prepare($conn, "UPDATE accounts SET totp_secret = ?, totp_enabled_at = NOW(), totp_last_step = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'sii', $secret, $step, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        logActivity('authenticator_enabled', 'Set up Google Authenticator for ' . $row['email'], tfLogUser($row) + [
            'category' => 'audit',
            'details'  => ['email' => $row['email']],
        ]);
        $row['totp_secret'] = $secret;
        tfFinishLogin($row, $setup['method'] ?? 'email code');
    }
}

$secret = $setup['secret'];
$uri = tfOtpauthUri($secret, $row['email']);
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set up Google Authenticator - PUP Student Records</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/font.css" rel="stylesheet">
    <link href="css/signin.css?v=<?= @filemtime(__DIR__ . '/css/signin.css') ?>" rel="stylesheet">
    <link href="css/two-factor.css?v=<?= @filemtime(__DIR__ . '/css/two-factor.css') ?>" rel="stylesheet">
</head>
<body class="auth-body">

    <main class="auth-page">
        <aside class="auth-side">
            <div class="auth-brand">
                <img src="images/logo.png" class="auth-brand-logo" alt="PUP Logo">
                <span class="auth-brand-name">PUP eRecords</span>
            </div>

            <div class="auth-side-content">
                <p class="auth-eyebrow">Admission and Registration Office</p>
                <h1 class="auth-headline">One more step to keep your account safe.</h1>
                <p class="auth-lead">Google Authenticator gives you a sign-in code even when there is no internet connection.</p>
            </div>

            <p class="auth-copyright">&copy; <?= date('Y') ?> Polytechnic University of the Philippines - Biñan Campus</p>
            <div class="auth-rings" aria-hidden="true"></div>
        </aside>

        <section class="auth-main">
            <div class="auth-form-wrap tf-setup-wrap">
                <div class="auth-mobile-brand">
                    <img src="images/logo.png" class="auth-brand-logo" alt="PUP Logo">
                    <span class="auth-brand-name">PUP eRecords</span>
                </div>

                <h2 class="auth-title">Set up Google Authenticator</h2>
                <p class="auth-subtitle">This is done only once. You will use the app when the sign-in code cannot be sent by email.</p>

                <ol class="tf-steps">
                    <li>Install <strong>Google Authenticator</strong> on your phone from the Play Store or App Store.</li>
                    <li>In the app, tap <strong>+</strong>, then <strong>Scan a QR code</strong>, and scan this code.</li>
                </ol>

                <div class="tf-qr-box">
                    <div class="tf-qr" data-otpauth="<?= htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') ?>" aria-label="QR code for Google Authenticator"></div>
                    <div class="tf-key-box">
                        <span class="tf-key-label">Can't scan? Tap <strong>Enter a setup key</strong> and type this key:</span>
                        <code class="tf-key" id="tfSecret"><?= htmlspecialchars(trim(chunk_split($secret, 4, ' '))) ?></code>
                        <button type="button" class="auth-link tf-copy" data-copy="tfSecret"><i class="bi bi-copy"></i> <span>Copy key</span></button>
                        <span class="tf-key-label">Account: <?= htmlspecialchars($row['email']) ?> &middot; Time based</span>
                    </div>
                </div>

                <ol class="tf-steps" start="3">
                    <li>Enter the 6-digit code that appears in the app.</li>
                </ol>

                <form method="POST" class="auth-form" id="tfCodeForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="verify">
                    <div class="auth-input">
                        <i class="bi bi-shield-lock auth-input-icon"></i>
                        <input
                            type="text"
                            class="form-control tf-code"
                            id="tfCode"
                            name="code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            pattern="\d{6}"
                            placeholder="000000"
                            title="Enter the 6-digit code"
                            required
                        >
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger auth-alert" role="alert">
                            <i class="bi bi-exclamation-circle"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="auth-submit">Finish setup</button>
                </form>

                <form method="POST" class="tf-back">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="auth-link"><i class="bi bi-arrow-left"></i> Back to sign in</button>
                </form>
            </div>
        </section>
    </main>

<script src="js/qrcode.js?v=<?= @filemtime(__DIR__ . '/js/qrcode.js') ?>"></script>
<script src="js/two-factor.js?v=<?= @filemtime(__DIR__ . '/js/two-factor.js') ?>"></script>
</body>
</html>
