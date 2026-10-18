<?php

function accountSignInUrl()
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $base . '/signin.php';
}

function buildTemporaryPasswordEmail($name, $email, $password, $refNumber)
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $url = accountSignInUrl();
    $date = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('F j, Y \a\t g:i A');
    $first = trim(explode(' ', trim((string) $name))[0] ?? '') ?: 'there';

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your PUP eRecords Admin Account</title></head>'
        . '<body style="margin:0;padding:0;background:#eceff3;">'
        . '<div style="display:none;max-height:0;overflow:hidden;">Your Admin account is ready. Sign in with your temporary password and set a new one.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eceff3;"><tr><td align="center" style="padding:32px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6dcd7;">'

        . '<tr><td style="background:#6e0000;background-image:linear-gradient(135deg,#7d0000 0%,#560000 100%);padding:26px 32px 22px 32px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle;">'
        . '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;line-height:24px;color:#ffffff;font-weight:bold;">PUP <span style="color:#f5c542;">eRecords</span></div>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;letter-spacing:2px;color:#e8c9c9;text-transform:uppercase;margin-top:4px;">Polytechnic University of the Philippines &middot; Bi&ntilde;an Campus</div>'
        . '</td>'
        . '<td align="right" style="vertical-align:middle;"><span style="display:inline-block;width:38px;height:38px;line-height:38px;border-radius:50%;background:#f5c542;color:#6e0000;text-align:center;font-family:Georgia,serif;font-size:18px;font-weight:bold;">&#9733;</span></td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="height:4px;background:#f5c542;font-size:0;line-height:0;">&nbsp;</td></tr>'

        . '<tr><td style="padding:30px 32px 6px 32px;">'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:#6e0000;">Admin Account Created</div>'
        . '<h1 style="margin:8px 0 0 0;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;line-height:31px;color:#3a0b0b;font-weight:normal;">Welcome to PUP eRecords</h1>'
        . '</td></tr>'

        . '<tr><td style="padding:16px 32px 0 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3b3b3b;">'
        . '<p style="margin:0 0 12px 0;">Hi ' . $e($first) . ',</p>'
        . '<p style="margin:0;">An <strong>Admin</strong> account has been created for you in PUP eRecords, the student records system of PUP Bi&ntilde;an Campus. Use the temporary credentials below to sign in for the first time.</p>'
        . '</td></tr>'

        . '<tr><td style="padding:22px 32px 0 32px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbf7f4;border:1px solid #eadfd9;border-radius:10px;">'
        . '<tr><td style="padding:18px 20px 6px 20px;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#8a7b74;">Your sign-in details</td></tr>'
        . '<tr><td style="padding:4px 20px 0 20px;">'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#8a7b74;">Email</div>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#2b2b2b;font-weight:bold;margin-top:2px;">' . $e($email) . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:14px 20px 18px 20px;">'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#8a7b74;">Temporary password</div>'
        . '<div style="margin-top:6px;"><span style="display:inline-block;padding:9px 16px;background:#ffffff;border:1px dashed #6e0000;border-radius:8px;font-family:\'Courier New\',Courier,monospace;font-size:20px;letter-spacing:3px;color:#6e0000;font-weight:bold;">' . $e($password) . '</span></div>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr>'

        . '<tr><td align="center" style="padding:22px 32px 0 32px;">'
        . '<a href="' . $e($url) . '" style="display:inline-block;padding:12px 30px;background:#6e0000;border-radius:999px;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;letter-spacing:.5px;">Sign in to PUP eRecords</a>'
        . '</td></tr>'

        . '<tr><td style="padding:24px 32px 0 32px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#fff8e6;border-left:4px solid #e0a100;border-radius:6px;padding:14px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#5c5240;">'
        . '<strong style="color:#8a6100;">Keep this private.</strong> Do not share your password with anyone. If you did not expect this email, please contact the Super Admin.'
        . '</td></tr></table>'
        . '</td></tr>'

        . '<tr><td style="padding:24px 32px 30px 32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;color:#3b3b3b;">'
        . '<p style="margin:0;">Welcome aboard,<br><span style="font-family:Georgia,serif;font-size:16px;color:#6e0000;font-weight:bold;">PUP eRecords Super Admin</span><br>'
        . '<span style="font-size:13px;color:#8a7b74;">PUP Bi&ntilde;an Campus</span></p>'
        . '</td></tr>'

        . '<tr><td style="background:#f6f7f9;border-top:1px solid #e3e6eb;padding:18px 32px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:17px;color:#7a808a;">'
        . 'This is an automated message from PUP eRecords sent on ' . $e($date) . ' &middot; Ref ' . $e($refNumber) . '. Please do not reply to this email.<br>'
        . 'PUP Bi&ntilde;an Campus, Brgy. Zapote, Bi&ntilde;an City, Laguna &middot; &copy; ' . date('Y') . ' Polytechnic University of the Philippines'
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';

    $text = "Hi {$first},\n\n"
        . "An Admin account has been created for you in PUP eRecords.\n\n"
        . "Email: {$email}\n"
        . "Temporary password: {$password}\n\n"
        . "Sign in: {$url}\n"
        . "You will be asked to change your password on your first sign-in.\n\n"
        . "Do not share your password with anyone.\n\n"
        . "PUP eRecords Super Admin\nPUP Biñan Campus\nRef {$refNumber}";

    return [$html, $text];
}