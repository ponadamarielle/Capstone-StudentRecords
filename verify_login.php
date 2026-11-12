<?php
include 'security_headers.php';
session_start();
require_once __DIR__ . '/two_factor.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

$conn = tfDb();
$row = tfPending($conn);
if (!$row) {
    tfCancel(isset($_SESSION['tf_pending']) ? 'Your sign-in session expired. Please sign in again.' : '');
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$hasApp = trim((string) ($row['totp_secret'] ?? '')) !== '';
$methodLabels = ['email' => 'email code', 'app' => 'Google Authenticator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        die('CSRF validation failed.');
    }
    $action = $_POST['action'] ?? '';
    $pending = &$_SESSION['tf_pending'];
    $pending['error'] = '';
    $pending['notice'] = '';

    if ($action === 'cancel') {
        tfCancel();
    } elseif ($action === 'use_app' && $hasApp) {
        $pending['method'] = 'app';
    } elseif ($action === 'use_email') {
        $pending['method'] = 'email';
        if (tfResendWait($conn, $row) === 0) {
            $pending['sent'] = false;
        }
    } elseif ($action === 'resend') {
        $pending['method'] = 'email';
        $wait = tfResendWait($conn, $row);
        if ($wait > 0) {
            $pending['error'] = 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before sending a new code.';
        } else {
            $pending['sent'] = false;
        }
    } elseif ($action === 'verify') {
        $code = (string) ($_POST['code'] ?? '');
        $method = $pending['method'] === 'app' && $hasApp ? 'app' : 'email';
        if (!preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code))) {
            $pending['error'] = 'Enter the 6-digit code.';
        } else {
            $ok = false;
            if ($method === 'app') {
                $ok = tfCheckAppCode($conn, $row, $code) !== false;
            } else {
                $result = tfCheckEmailCode($conn, $row, $code);
                $ok = $result === 'ok';
                if ($result === 'expired' || $result === 'missing') {
                    $pending['error'] = 'This code has expired. Click Resend code to get a new one.';
                }
            }

            if ($ok) {
                if (!empty($_POST['trust'])) {
                    tfTrustDevice($conn, $row);
                }
                if (!$hasApp) {
                    $_SESSION['tf_setup'] = [
                        'id'      => (int) $row['id'],
                        'started' => time(),
                        'secret'  => tfNewSecret(),
                        'method'  => $methodLabels[$method],
                    ];
                    unset($_SESSION['tf_pending']);
                    header('Location: setup_authenticator.php');
                    exit();
                }
                tfFinishLogin($row, $methodLabels[$method]);
            }

            $pending['fails'] = (int) ($pending['fails'] ?? 0) + 1;
            logActivity('login_2fa_failed', 'Wrong ' . $methodLabels[$method] . ' entered while signing in as ' . $row['email'], tfLogUser($row) + [
                'details' => ['email' => $row['email'], 'method' => $methodLabels[$method], 'attempt' => $pending['fails']],
            ]);
            if ($pending['fails'] >= TF_MAX_FAILS) {
                tfCancel('Too many wrong codes. Please sign in again.');
            }
            if ($pending['error'] === '') {
                $pending['error'] = 'Incorrect code. Please try again.';
            }
        }
    }
    unset($pending);
    header('Location: verify_login.php');
    exit();
}

$pending = &$_SESSION['tf_pending'];
$blocked = '';
if ($pending['method'] === 'email' && empty($pending['sent'])) {
    $status = tfSendLoginCode($conn, $row);
    $pending['sent'] = true;
    if ($status === 'sent') {
        $pending['notice'] = '';
    } elseif ($hasApp) {
        $pending['method'] = 'app';
        $pending['notice'] = $status === 'offline'
            ? 'There is no internet connection, so the code could not be sent by email. Enter the code from Google Authenticator instead.'
            : 'The code could not be sent by email. Enter the code from Google Authenticator instead.';
    } else {
        $pending['sent'] = false;
        $blocked = $status === 'offline'
            ? 'The sign-in code could not be sent because there is no internet connection. Google Authenticator is not set up on this account yet, so please connect to the internet and try again.'
            : 'The sign-in code could not be sent by email. Please try again in a moment.';
    }
}
$method = $pending['method'] === 'app' && $hasApp ? 'app' : 'email';
$error = $pending['error'] ?? '';
$notice = $pending['notice'] ?? '';
$pending['error'] = '';
unset($pending);
$wait = $method === 'email' ? tfResendWait($conn, $row) : 0;
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify sign-in - PUP Student Records</title>
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
                <h1 class="auth-headline">Every student record, safe and easy to find.</h1>
                <p class="auth-lead">Digitize, manage and back up student files in one secure place.</p>
            </div>

            <p class="auth-copyright">&copy; <?= date('Y') ?> Polytechnic University of the Philippines - Biñan Campus</p>
            <div class="auth-rings" aria-hidden="true"></div>
        </aside>

        <section class="auth-main">
            <div class="auth-form-wrap">
                <div class="auth-mobile-brand">
                    <img src="images/logo.png" class="auth-brand-logo" alt="PUP Logo">
                    <span class="auth-brand-name">PUP eRecords</span>
                </div>

                <span class="tf-badge"><i class="bi <?= $method === 'app' ? 'bi-phone' : 'bi-envelope-check' ?>"></i></span>
                <h2 class="auth-title">Verify it's you</h2>
                <p class="auth-subtitle">
                    <?php if ($blocked !== ''): ?>
                        A sign-in code is needed to continue.
                    <?php elseif ($method === 'app'): ?>
                        Open <strong>Google Authenticator</strong> on your phone and enter the 6-digit code for PUP eRecords.
                    <?php else: ?>
                        Enter the 6-digit code sent to <strong><?= htmlspecialchars(tfMaskEmail($row['email'])) ?></strong>.
                    <?php endif; ?>
                </p>

                <?php if ($notice !== '' && $blocked === ''): ?>
                    <div class="tf-info" role="status">
                        <i class="bi bi-info-circle"></i>
                        <span><?= htmlspecialchars($notice) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($blocked !== ''): ?>
                    <div class="alert alert-danger auth-alert" role="alert">
                        <i class="bi bi-wifi-off"></i>
                        <span><?= htmlspecialchars($blocked) ?></span>
                    </div>
                    <form method="POST" class="auth-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="resend">
                        <button type="submit" class="auth-submit">Try again</button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="auth-form" id="tfCodeForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="verify">

                        <label class="auth-label" for="tfCode"><?= $method === 'app' ? 'Authenticator code' : 'Sign-in code' ?></label>
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
                                autofocus
                                required
                            >
                        </div>

                        <label class="auth-check tf-trust">
                            <input type="checkbox" name="trust" value="1" checked>
                            <span>Trust this computer for <?= TF_TRUST_DAYS ?> days</span>
                        </label>

                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger auth-alert" role="alert">
                                <i class="bi bi-exclamation-circle"></i>
                                <span><?= htmlspecialchars($error) ?></span>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="auth-submit">Verify</button>
                    </form>

                    <div class="tf-options">
                        <?php if ($method === 'email'): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="resend">
                                <button type="submit" class="auth-link" data-wait="<?= (int) $wait ?>">Resend code</button>
                            </form>
                            <?php if ($hasApp): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="use_app">
                                    <button type="submit" class="auth-link">Use Google Authenticator instead</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="use_email">
                                <button type="submit" class="auth-link">Send a code to my email instead</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="tf-back">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="auth-link"><i class="bi bi-arrow-left"></i> Back to sign in</button>
                </form>
            </div>
        </section>
    </main>

<script src="js/two-factor.js?v=<?= @filemtime(__DIR__ . '/js/two-factor.js') ?>"></script>
</body>
</html>