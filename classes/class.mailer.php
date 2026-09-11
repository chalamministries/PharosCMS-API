<?php
/**
 * Mailer Class - Handles SMTP email sending using PHPMailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    private $pdo;
    private $smtpSettings;

    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->loadSettings();
    }

    /**
     * Load SMTP settings from communications_settings table
     */
    private function loadSettings() {
        $this->smtpSettings = $this->pdo->selectFirst("communications_settings", ["id" => 1]);
    }

    /**
     * Send an email using SMTP
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param array $attachments Optional attachments
     * @return bool|string True on success, error message on failure
     */
    public function send($to, $subject, $body, $attachments = []) {
        if (!$this->smtpSettings || empty($this->smtpSettings['smtp_host'])) {
            return "SMTP settings not configured.";
        }

        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->smtpSettings['smtp_host'];
            $mail->SMTPAuth   = !empty($this->smtpSettings['smtp_user']);
            $mail->Username   = $this->smtpSettings['smtp_user'];
            $mail->Password   = $this->smtpSettings['smtp_pass'];
            $mail->SMTPSecure = $this->smtpSettings['smtp_secure'] === 'none' ? false : $this->smtpSettings['smtp_secure'];
            $mail->Port       = $this->smtpSettings['smtp_port'];

            // Recipients
            $fromEmail = !empty($this->smtpSettings['smtp_from_email']) ? $this->smtpSettings['smtp_from_email'] : 'no-reply@pharoscms.com';
            $fromName = !empty($this->smtpSettings['smtp_from_name']) ? $this->smtpSettings['smtp_from_name'] : 'PharosCMS';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);

            // Attachments
            foreach ($attachments as $attachment) {
                if (isset($attachment['path'])) {
                    $name = isset($attachment['name']) ? $attachment['name'] : '';
                    $mail->addAttachment($attachment['path'], $name);
                }
            }

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mailer Error: {$mail->ErrorInfo}");
            return "Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }
}
