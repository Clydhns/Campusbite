<?php
function cbRecoveryMail(string $recipient, string $subject, #[SensitiveParameter] string $text): void {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipient)) {
        throw new RuntimeException('Invalid recipient.');
    }
    if (preg_match('/[\r\n]/',$subject)) throw new RuntimeException('Invalid email subject.');
    // Disposable test inbox only; never enable local-test settings on Vercel.
    $inbox = getenv('CAMPUSBITE_TEST_MAILBOX') ?: '';
    if (getenv('CAMPUSBITE_LOCAL_TEST') === '1' && !getenv('VERCEL') && $inbox !== '') {
        if (file_put_contents($inbox, json_encode(['to'=>$recipient,'subject'=>$subject,'text'=>$text], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write test email.');
        }
        return;
    }
    $sender = trim(getenv('SMTP_USERNAME') ?: '');
    $password = str_replace(' ', '', getenv('SMTP_PASSWORD') ?: '');
    if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $sender) || !preg_match('/^[a-z]{16}$/D', $password)) {
        error_log('CampusBite recovery: mail_configuration_invalid sender_valid=' . (int)(filter_var($sender,FILTER_VALIDATE_EMAIL)!==false)
            . ' app_password_valid=' . (int)(preg_match('/^[a-z]{16}$/D',$password)===1));
        throw new RuntimeException('Recovery mail is not configured.');
    }
    if (!function_exists('curl_version') || !in_array('smtps', curl_version()['protocols'], true)) {
        error_log('CampusBite recovery: smtps_unavailable');
        throw new RuntimeException('SMTP TLS is unavailable.');
    }
    $message = 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
        . "From: CampusBite <$sender>\r\nTo: <$recipient>\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(16)) . "@campusbite.local>\r\n"
        . "Subject: $subject\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n");
    $offset = 0;
    $curl = curl_init('smtps://smtp.gmail.com:465');
    try {
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS=>CURLPROTO_SMTPS, CURLOPT_USERNAME=>$sender, CURLOPT_PASSWORD=>$password,
            CURLOPT_MAIL_FROM=>"<$sender>", CURLOPT_MAIL_RCPT=>["<$recipient>"], CURLOPT_UPLOAD=>true,
            CURLOPT_READFUNCTION=>static function ($handle, $stream, $length) use (&$offset, $message): string {
                $chunk = substr($message, $offset, $length); $offset += strlen($chunk); return $chunk;
            },
            CURLOPT_INFILESIZE=>strlen($message), CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>4,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_RETURNTRANSFER=>true,
        ]);
        if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) >= 400) {
            // Do not log SMTP debug output, credentials, recipient, or reset links.
            error_log('CampusBite recovery: smtp_failed curl_code=' . curl_errno($curl)
                . ' smtp_status=' . (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE));
            throw new RuntimeException('Recovery email could not be sent.');
        }
        error_log('CampusBite recovery: smtp_accepted');
    } finally { curl_close($curl); }
}
