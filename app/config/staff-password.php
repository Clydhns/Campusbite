<?php
function cbStaffCredentialStamp(string $hash): string {
    $secret = getenv('APP_SESSION_SECRET') ?: '';
    if (strlen($secret) < 64) throw new RuntimeException('Session secret is not configured.');
    return hash_hmac('sha256', $hash, $secret);
}

function cbChangeStaffPassword(PDO $pdo, int $userId, #[SensitiveParameter] array $input): string {
    $current = $input['current_password'] ?? null;
    $password = $input['new_password'] ?? null;
    $confirm = $input['confirm_password'] ?? null;
    if (!is_string($current) || $current === '' || strlen($current) > 4096 || str_contains($current, "\0")) {
        throw new RuntimeException('Enter your current password.');
    }
    if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")
        || !preg_match('/^.{8,}$/us', $password)) {
        throw new RuntimeException('Use at least 8 characters and no more than 72 bytes for the new password.');
    }
    if (!is_string($confirm) || !hash_equals($password, $confirm)) throw new RuntimeException('The new passwords do not match.');
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT password_hash FROM users WHERE user_id=? AND role_id=2 AND is_active=1 FOR UPDATE');
        $query->execute([$userId]);
        $previous = $query->fetchColumn();
        if (!is_string($previous) || !password_verify($current, $previous)) throw new RuntimeException('Your current password is incorrect.');
        if (password_verify($password, $previous)) throw new RuntimeException('Choose a new password different from your current password.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=? AND role_id=2 AND is_active=1 AND password_hash=?');
        $update->execute([$hash, $userId, $previous]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Your account changed while saving. Please sign in again.');
        $pdo->commit();
        return $hash;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
