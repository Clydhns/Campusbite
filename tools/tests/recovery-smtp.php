<?php
// Disposable local transport probe; never routed by api/index.php.
require dirname(__DIR__,2) . '/app/config/recovery-mail.php';
putenv('SMTP_USERNAME=sender@example.invalid');
putenv('SMTP_PASSWORD=abcdefghijklmnop');
putenv('CAMPUSBITE_LOCAL_TEST=');
putenv('CAMPUSBITE_TEST_MAILBOX=');
[$sender,$password]=cbRecoveryMailSettings();
require dirname(__DIR__,2) . '/app/config/recovery-smtp.php';
$mail=cbCreateRecoveryMailer($sender,$password);
$secure=$mail->Host==='smtp.gmail.com' && $mail->Port===465 && $mail->SMTPSecure==='ssl'
    && $mail->SMTPOptions['ssl']===['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]
    && $mail->SMTPDebug===0 && $mail->Timeout===4;
// Only this CLI fixture replaces the production destination and TLS setting.
$mail->Host='127.0.0.1';
$mail->Port=(int)$argv[1];
$mail->SMTPSecure='';
$mail->SMTPAutoTLS=false;
$mail->addAddress('recipient@example.invalid');
$mail->Subject='Fixture recovery email';
$mail->Body='Fixture message only. No real user or reset token.';
$accepted=false;
$status=0;
try { $accepted=$mail->send(); }
catch (Throwable $error) { $status=$mail->getSMTPInstance()->failureCode; }
finally { $mail->smtpClose(); }
echo json_encode(['secure_defaults'=>$secure,'accepted'=>$accepted,'smtp_status'=>$status],JSON_THROW_ON_ERROR);
