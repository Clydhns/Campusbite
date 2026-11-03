<?php
require_once __DIR__ . '/staff-password.php';
require_once __DIR__ . '/recovery-mail.php';

function cbRecoveryOrigin(): string {
    // Never construct recovery URLs from Host or forwarded headers.
    $origin = rtrim(getenv('APP_BASE_URL') ?: 'https://campusbite-orpin.vercel.app', '/');
    if (!preg_match('~^https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::443)?$~iD', $origin)) {
        throw new RuntimeException('Invalid recovery origin.');
    }
    return $origin;
}

function cbRecoveryLimit(PDO $pdo, string $scope, string $identity, int $maximum, int $seconds): bool {
    $key = cbStaffCredentialStamp('recovery:' . $scope . ':' . $identity);
    $query = $pdo->prepare("INSERT INTO campusbite_private.recovery_limits(limit_key,attempts,expires_at)
        VALUES (?,1,clock_timestamp() + (? * INTERVAL '1 second'))
        ON CONFLICT (limit_key) DO UPDATE SET
        attempts=CASE WHEN recovery_limits.expires_at<=clock_timestamp() THEN 1 ELSE recovery_limits.attempts+1 END,
        expires_at=CASE WHEN recovery_limits.expires_at<=clock_timestamp() THEN EXCLUDED.expires_at ELSE recovery_limits.expires_at END
        RETURNING attempts");
    $query->execute([$key, $seconds]);
    return (int) $query->fetchColumn() <= $maximum;
}

function cbRequestPasswordRecovery(PDO $pdo, string $email, string $address): void {
    $email = strtolower(trim($email));
    if (strlen($email)>100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $pdo->exec("DELETE FROM campusbite_private.password_resets WHERE expires_at<clock_timestamp()-INTERVAL '1 day';
        DELETE FROM campusbite_private.recovery_limits WHERE expires_at<clock_timestamp()-INTERVAL '1 day'");
    if (!cbRecoveryLimit($pdo,'request-ip',$address,20,3600)
        || !cbRecoveryLimit($pdo,'request-email',$email,3,3600)
        || !cbRecoveryLimit($pdo,'request-cooldown',$email,1,60)
        || !cbRecoveryLimit($pdo,'request-global','all',200,86400)) {
        error_log('CampusBite recovery: request_limited');
        return;
    }
    $query = $pdo->prepare('SELECT user_id,email,password_hash FROM users WHERE lower(email)=? AND is_active=1 AND role_id IN (1,2,3)');
    $query->execute([$email]);
    $account = $query->fetch();
    if (!$account) {
        error_log('CampusBite recovery: no_eligible_account');
        return;
    }
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $query = $pdo->prepare("INSERT INTO campusbite_private.password_resets(token_hash,user_id,email,credential_stamp,expires_at)
        VALUES (?,?,?,?,clock_timestamp()+INTERVAL '30 minutes')");
    $query->execute([$hash,$account['user_id'],$account['email'],cbStaffCredentialStamp($account['password_hash'])]);
    try {
        $link = cbRecoveryOrigin() . '/reset_password.php?token=' . $token;
        cbRecoveryMail($account['email'], 'Reset your CampusBite password',
            "Someone requested a password reset for your CampusBite account.\n\n$link\n\n"
            . "This single-use link expires in 30 minutes. Your password has not changed.\n"
            . "If you did not request this, ignore this email. Never share this link.\n\n"
            . "CampusBite is an independent student project, not your school or email provider.");
    } catch (Throwable $error) {
        $pdo->prepare('DELETE FROM campusbite_private.password_resets WHERE token_hash=?')->execute([$hash]);
        throw $error;
    }
}

function cbRecoveryAccount(PDO $pdo, #[SensitiveParameter] string $token): array|false {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $query = $pdo->prepare('SELECT r.*,u.password_hash,u.is_active,u.role_id,u.email AS current_email
        FROM campusbite_private.password_resets r JOIN users u ON u.user_id=r.user_id
        WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>clock_timestamp()');
    $query->execute([hash('sha256',$token)]);
    $account = $query->fetch();
    if (!$account || (int)$account['is_active']!==1 || !in_array((int)$account['role_id'],[1,2,3],true)
        || $account['email']!==$account['current_email']
        || !hash_equals($account['credential_stamp'],cbStaffCredentialStamp($account['password_hash']))) return false;
    return $account;
}

function cbResetPassword(PDO $pdo, #[SensitiveParameter] string $token, #[SensitiveParameter] array $input): string {
    $password = cbValidateNewPassword($input);
    $account = cbRecoveryAccount($pdo,$token);
    if (!$account) throw new RuntimeException('This reset link is invalid or expired. Request a new one.');
    $pdo->beginTransaction();
    try {
        // Lock the account first so concurrent resets serialize, then recheck token expiry and credentials.
        $lock = $pdo->prepare('SELECT user_id FROM users WHERE user_id=? FOR UPDATE');
        $lock->execute([$account['user_id']]);
        $account = cbRecoveryAccount($pdo,$token);
        if (!$account) throw new RuntimeException('This reset link is invalid or expired. Request a new one.');
        if (password_verify($password,$account['password_hash'])) throw new RuntimeException('Choose a password different from your current password.');
        $hash = password_hash($password,PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([$hash,$account['user_id']]);
        $pdo->prepare('UPDATE campusbite_private.password_resets SET used_at=clock_timestamp() WHERE user_id=? AND used_at IS NULL')->execute([$account['user_id']]);
        $pdo->commit();
        return $account['email'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
