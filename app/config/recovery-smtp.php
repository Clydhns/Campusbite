<?php
require_once dirname(__DIR__,2) . '/vendor/autoload.php';

// Bound the whole exchange, not just each SMTP command. PHPMailer handles the protocol.
final class CampusBiteRecoverySMTP extends \PHPMailer\PHPMailer\SMTP {
    private float $deadline;
    public int $failureCode=0;
    public function __construct() { $this->deadline=microtime(true)+4; }
    protected function setError($message,$detail='',$smtp_code='',$smtp_code_ex='') {
        if ((int)$smtp_code>=400) $this->failureCode=(int)$smtp_code;
        parent::setError($message,$detail,$smtp_code,$smtp_code_ex);
    }
    protected function get_lines() {
        $remaining=$this->deadline-microtime(true);
        if ($remaining<=0) { $this->close(); return ''; }
        $this->Timeout=$this->Timelimit=max(1,(int)ceil($remaining));
        return parent::get_lines();
    }
    public function client_send($data,$command='') {
        if (microtime(true)>=$this->deadline) { $this->close(); return false; }
        return parent::client_send($data,$command);
    }
}

function cbCreateRecoveryMailer(string $sender, #[SensitiveParameter] string $password): \PHPMailer\PHPMailer\PHPMailer {
    $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host='smtp.gmail.com';
    $mail->Port=465;
    $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->SMTPAuth=true;
    $mail->AuthType='LOGIN';
    $mail->Username=$sender;
    $mail->Password=$password;
    $mail->Timeout=4;
    $mail->SMTPDebug=0;
    $mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
    $mail->setSMTPInstance(new CampusBiteRecoverySMTP());
    $mail->CharSet='UTF-8';
    $mail->Encoding='base64';
    $mail->setFrom($sender,'CampusBite');
    return $mail;
}

function cbSetRecoveryMailBody(\PHPMailer\PHPMailer\PHPMailer $mail, string $text, ?string $html): void {
    $mail->isHTML($html !== null);
    $mail->Body = $html ?? $text;
    if ($html !== null) {
        $mail->AltBody = $text;
        if (str_contains($html, 'cid:campusbite-logo')) {
            $mail->addEmbeddedImage(__DIR__ . '/../assets/campusbite-email.png', 'campusbite-logo', 'campusbite.png', 'base64', 'image/png');
        }
    }
}
