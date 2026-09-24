<?php
/**
 * Sends candidate emails from the courses@ mailbox over SMTP (PHPMailer).
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

function mail_configured(): bool
{
    $c = pawpad_config();
    return !empty($c['smtp_host']) && !empty($c['smtp_user']) && !empty($c['smtp_password']);
}

/**
 * @return array{sent: bool, error: string}
 */
function send_candidate_email(string $toEmail, string $toName, string $subject, string $body): array
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'The application has no valid email address.'];
    }
    if (!mail_configured()) {
        return ['sent' => false, 'error' => 'Email is not set up yet (smtp_* settings in config.php).'];
    }

    $c = pawpad_config();
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $c['smtp_host'];
        $mail->Port = (int) $c['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = $c['smtp_user'];
        $mail->Password = $c['smtp_password'];
        $secure = $c['smtp_secure'] ?? 'ssl';
        if ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->Timeout = 20;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $from = $c['mail_from'] ?? $c['smtp_user'];
        $mail->setFrom($from, $c['mail_from_name'] ?? 'Pawpad Academy');
        $mail->addReplyTo($from, $c['mail_from_name'] ?? 'Pawpad Academy');
        $mail->addAddress($toEmail, $toName);
        if (!empty($c['mail_bcc']) && strcasecmp($c['mail_bcc'], $toEmail) !== 0) {
            $mail->addBCC($c['mail_bcc']);
        }

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
        return ['sent' => true, 'error' => ''];
    } catch (Throwable $e) {
        error_log('Pawpad API email failed: ' . $mail->ErrorInfo);
        return ['sent' => false, 'error' => 'The mail server refused the email. Check the smtp_* settings in config.php.'];
    }
}
