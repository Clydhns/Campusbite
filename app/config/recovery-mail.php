<?php
function cbRecoveryMailSettings(): ?array {
    if (getenv('CAMPUSBITE_LOCAL_TEST')==='1' && !getenv('VERCEL') && (getenv('CAMPUSBITE_TEST_MAILBOX') ?: '')!=='') return null;
    $sender = trim(getenv('SMTP_USERNAME') ?: '');
    $password = str_replace(' ', '', getenv('SMTP_PASSWORD') ?: '');
    if (!filter_var($sender,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$sender) || !preg_match('/^[a-z]{16}$/D',$password)) {
        error_log('CampusBite recovery: mail_configuration_invalid sender_valid=' . (int)(filter_var($sender,FILTER_VALIDATE_EMAIL)!==false)
            . ' app_password_valid=' . (int)(preg_match('/^[a-z]{16}$/D',$password)===1));
        throw new RuntimeException('Recovery mail is not configured.');
    }
    if (!extension_loaded('openssl') || !function_exists('stream_socket_client') || !in_array('ssl',stream_get_transports(),true)) {
        error_log('CampusBite recovery: smtp_socket_tls_unavailable');
        throw new RuntimeException('SMTP TLS is unavailable.');
    }
    if (!is_file(dirname(__DIR__,2) . '/vendor/autoload.php')) {
        error_log('CampusBite recovery: smtp_library_unavailable');
        throw new RuntimeException('SMTP library is unavailable.');
    }
    return [$sender,$password];
}

function cbRecoveryMail(string $recipient, string $subject, #[SensitiveParameter] string $text, #[SensitiveParameter] ?string $html=null): void {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipient)) {
        throw new RuntimeException('Invalid recipient.');
    }
    if (preg_match('/[\r\n]/',$subject)) throw new RuntimeException('Invalid email subject.');
    // Disposable test inbox only; never enable local-test settings on Vercel.
    $inbox = getenv('CAMPUSBITE_TEST_MAILBOX') ?: '';
    if (getenv('CAMPUSBITE_LOCAL_TEST') === '1' && !getenv('VERCEL') && $inbox !== '') {
        if (file_put_contents($inbox, json_encode(['to'=>$recipient,'subject'=>$subject,'text'=>$text,'html'=>$html], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write test email.');
        }
        return;
    }
    [$sender,$password] = cbRecoveryMailSettings();
    require_once __DIR__ . '/recovery-smtp.php';
    $mail = cbCreateRecoveryMailer($sender,$password);
    try {
        $mail->addAddress($recipient);
        $mail->Subject=$subject;
        $mail->isHTML($html!==null);
        $mail->Body=$html ?? $text;
        if ($html!==null) $mail->AltBody=$text;
        $mail->send();
        error_log('CampusBite recovery: smtp_accepted');
    } catch (Throwable $error) {
        // Never log ErrorInfo, SMTP replies, debug transcripts, recipients, or credentials.
        error_log('CampusBite recovery: smtp_failed smtp_status=' . $mail->getSMTPInstance()->failureCode);
        throw new RuntimeException('Recovery email could not be sent.');
    } finally { $mail->smtpClose(); }
}
