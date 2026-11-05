<?php
require_once __DIR__ . '/password-recovery.php';

function cbRequestRegistration(PDO $pdo, array $details, string $address): void {
    $email = strtolower($details['email']);
    cbRecoveryMailSettings();
    if (!cbRecoveryLimit($pdo, 'signup-ip', $address, 20, 3600)
        || !cbRecoveryLimit($pdo, 'signup-cooldown', $email, 1, 60)
        || !cbRecoveryLimit($pdo, 'signup-email', $email, 5, 3600)
        || !cbRecoveryLimit($pdo, 'signup-global', 'all', 100, 86400)) {
        throw new RuntimeException('Please wait before requesting another verification email. Check your inbox and spam folder.');
    }
    $pdo->exec('DELETE FROM campusbite_private.pending_registrations WHERE expires_at<=clock_timestamp()');
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $details['email'] = $email;
    $query = $pdo->prepare("INSERT INTO campusbite_private.pending_registrations(token_hash,email,details,expires_at)
        VALUES (?,?,?::jsonb,clock_timestamp()+INTERVAL '15 minutes')
        ON CONFLICT (email) DO UPDATE SET token_hash=EXCLUDED.token_hash,details=EXCLUDED.details,expires_at=EXCLUDED.expires_at");
    $query->execute([$hash, $email, json_encode($details, JSON_THROW_ON_ERROR)]);
    $link = cbRecoveryOrigin() . '/verify_email.php?token=' . $token;
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html lang="en"><body style="margin:0;padding:24px;background:#fff8f0;color:#3b2a20;font-family:Arial,sans-serif">'
        . '<table role="presentation" style="max-width:560px;margin:auto;background:white;border:1px solid #f1d5bf;border-radius:20px"><tr><td style="padding:28px">'
        . '<h1 style="color:#e63946;font-size:26px">Verify your CampusBite email</h1>'
        . '<p style="line-height:1.7">Finish the registration you started on CampusBite. Your account has not been created yet.</p>'
        . '<p style="margin:28px 0"><a style="display:inline-block;background:#bd2433;color:white;padding:16px 24px;border-radius:12px;text-decoration:none" href="'
        . $safeLink . '">Verify email and continue</a></p>'
        . '<p style="line-height:1.7">This single-use link expires in 15 minutes. Confirm the CampusBite password you chose before your account is created.</p>'
        . '<p>If you did not start this registration, ignore this email. Do not share this link.</p>'
        . '<p style="font-size:13px;line-height:1.7">CampusBite is an independent student project, not your school or email provider. Never enter your email-account password here.</p>'
        . '</td></tr></table></body></html>';
    try {
        cbRecoveryMail($email, 'Verify your CampusBite email',
            "Finish your CampusBite registration:\n\n$link\n\nYour account has not been created yet. This single-use link expires in 15 minutes. "
            . 'Confirm the CampusBite password you chose on the verification page. If you did not start this registration, ignore this email. '
            . 'CampusBite is an independent student project, not your school or email provider.', $html);
    } catch (Throwable $error) {
        $pdo->prepare('DELETE FROM campusbite_private.pending_registrations WHERE token_hash=?')->execute([$hash]);
        error_log('CampusBite registration: verification_delivery_failed');
        throw new RuntimeException('We could not send the verification email. Your account was not created. Please try again later.');
    }
}

function cbPendingRegistration(PDO $pdo, #[SensitiveParameter] string $token, bool $lock = false): array|false {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $query = $pdo->prepare('SELECT details FROM campusbite_private.pending_registrations
        WHERE token_hash=? AND expires_at>clock_timestamp()' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([hash('sha256', $token)]);
    $json = $query->fetchColumn();
    return $json === false ? false : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function cbCompleteRegistration(PDO $pdo, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): array {
    $pdo->beginTransaction();
    try {
        $details = cbPendingRegistration($pdo, $token, true);
        if (!$details) throw new RuntimeException('This verification link is invalid or expired. Start registration again.');
        // Forwarded links and email scanners must not create an account with a stranger's chosen password.
        if (strlen($password)>72 || str_contains($password, "\0") || !password_verify($password, $details['password_hash'])) {
            throw new RuntimeException('Enter the CampusBite password you chose during registration.');
        }
        $query = $pdo->prepare('INSERT INTO users(role_id,id_number,first_name,last_name,email,password_hash,contact_number,grade_section,is_active)
            VALUES (3,:id_number,:first_name,:last_name,:email,:password_hash,:contact_number,:grade_section,1)
            RETURNING user_id,role_id,first_name,last_name,email');
        $query->execute($details);
        $account = $query->fetch();
        $account['password_hash'] = $details['password_hash'];
        $pdo->prepare('DELETE FROM campusbite_private.pending_registrations WHERE token_hash=?')->execute([hash('sha256', $token)]);
        $pdo->commit();
        return $account;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && $error->getCode()==='23505') {
            throw new RuntimeException('That ID number or email is already registered. Sign in or start again with your own details.');
        }
        throw $error;
    }
}
