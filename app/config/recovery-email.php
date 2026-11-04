<?php
function cbRecoveryEmailHtml(#[SensitiveParameter] string $url, bool $changed=false): string {
    $link=htmlspecialchars($url,ENT_QUOTES,'UTF-8');
    $title=$changed ? 'Your password is updated.' : 'A fresh start for your account.';
    $intro=$changed ? 'Your CampusBite password was changed. All existing sessions have been signed out.'
        : 'We received a request to reset your CampusBite password. Use the button below to choose a new one.';
    $action=$changed ? 'Sign in to CampusBite' : 'Reset password';
    $notice=$changed ? 'If this was not you, secure your email account and use Forgot password on CampusBite to recover access.'
        : 'Your password has not changed yet. If you did not request this email, you can safely ignore it. Never share this link.';
    $expiry=$changed ? 'ACCOUNT SECURITY' : 'VALID FOR 15 MINUTES &nbsp;&middot;&nbsp; ONE USE ONLY';
    return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>CampusBite account security</title></head>
<body style="margin:0;padding:0;background:#fff8f0;color:#3b2a20;font-family:'Trebuchet MS',Arial,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">$title $action securely with CampusBite.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fff8f0;"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;background:#ffffff;border:1px solid #f1d5bf;border-radius:20px;">
<tr><td style="padding:30px 28px 24px;border-bottom:1px solid #f1d5bf;">
<table role="presentation" cellspacing="0" cellpadding="0"><tr>
<td style="width:64px;height:64px;text-align:center;vertical-align:middle;"><img src="cid:campusbite-logo" width="64" height="64" alt="CampusBite logo" style="display:block;border:0;width:64px;height:64px;"></td>
<td style="padding-left:12px;font-size:26px;font-weight:bold;letter-spacing:-1px;color:#e63946;">CampusBite</td>
</tr></table></td></tr>
<tr><td style="padding:28px;">
<p style="margin:0 0 16px;color:#8a4c26;font-size:10px;font-weight:bold;letter-spacing:1.5px;">YOUR ACCOUNT. A FRESH START.</p>
<h1 style="margin:0 0 16px;font-size:28px;line-height:1.25;letter-spacing:-0.6px;color:#3b2a20;">$title</h1>
<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#685344;">$intro</p>
<table role="presentation" cellspacing="0" cellpadding="0"><tr><td bgcolor="#bd2433" style="border-radius:12px;background:#bd2433;">
<a href="$link" style="display:inline-block;padding:16px 26px;border:1px solid #bd2433;border-radius:12px;color:#ffffff;text-decoration:none;font-size:16px;font-weight:bold;">$action</a>
</td></tr></table>
<p style="margin:18px 0 24px;font-size:10px;line-height:1.7;letter-spacing:0.8px;color:#8a4c26;font-weight:bold;">$expiry</p>
<p style="margin:0;padding:16px;background:#fff8f0;border:1px solid #f1d5bf;border-radius:12px;font-size:13px;line-height:1.7;color:#685344;">$notice</p>
<p style="margin:20px 0 0;font-size:12px;line-height:1.7;color:#685344;">Button not working? <a href="$link" style="color:#bd2433;text-decoration:underline;">Open the secure page</a>.</p>
</td></tr>
<tr><td style="padding:20px 28px;border-top:1px solid #f1d5bf;font-size:11px;line-height:1.7;color:#796554;">CampusBite is an independent student project, not your school or email provider. We will never ask you to email us your password.</td></tr>
</table></td></tr></table>
</body></html>
HTML;
}
