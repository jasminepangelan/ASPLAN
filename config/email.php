<?php
/**
 * Email Configuration
 * SMTP settings for sending emails (password reset, notifications, etc.)
 * 
 * SECURITY: All credentials loaded from .env file
 * Never hardcode credentials in this file!
 */

use PHPMailer\PHPMailer\PHPMailer;

// Load environment variables from .env file
require_once __DIR__ . '/../includes/env_loader.php';

if (!function_exists('normalizeMailEnvValue')) {
    function normalizeMailEnvValue($value, bool $collapseInternalWhitespace = false): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $value = trim($value);

        if ($collapseInternalWhitespace) {
            $value = preg_replace('/\s+/', '', $value) ?? $value;
        }

        return $value;
    }
}

// SMTP Configuration - loaded from environment variables
define('SMTP_HOST', normalizeMailEnvValue(getenv('SMTP_HOST')) ?: 'smtp.gmail.com');
define('SMTP_PORT', (int) (normalizeMailEnvValue(getenv('SMTP_PORT')) ?: 587));
define('SMTP_SECURE', normalizeMailEnvValue(getenv('SMTP_SECURE')) ?: 'tls');
define('SMTP_AUTH', filter_var(normalizeMailEnvValue(getenv('SMTP_AUTH')) ?: '1', FILTER_VALIDATE_BOOL));

// Email Credentials - loaded from environment variables
define('SMTP_USERNAME', normalizeMailEnvValue(getenv('SMTP_USERNAME')) ?: '');
define('SMTP_PASSWORD', normalizeMailEnvValue(getenv('SMTP_PASSWORD'), true) ?: '');

// Email Settings - loaded from environment variables
define('SMTP_FROM_EMAIL', normalizeMailEnvValue(getenv('SMTP_FROM_EMAIL')) ?: 'noreply@cvsu-carmona.edu.ph');
define('SMTP_FROM_NAME', normalizeMailEnvValue(getenv('SMTP_FROM_NAME')) ?: 'ASPLAN - Automated Study Plan Generator');
define('SMTP_REPLY_TO', normalizeMailEnvValue(getenv('SMTP_REPLY_TO')) ?: SMTP_FROM_EMAIL);
define('RESEND_API_KEY', normalizeMailEnvValue(getenv('RESEND_API_KEY')) ?: '');
define('RESEND_FROM_EMAIL', normalizeMailEnvValue(getenv('RESEND_FROM_EMAIL')) ?: '');
define('RESEND_FROM_NAME', normalizeMailEnvValue(getenv('RESEND_FROM_NAME')) ?: 'ASPLAN');
define('RESEND_REPLY_TO', normalizeMailEnvValue(getenv('RESEND_REPLY_TO')) ?: RESEND_FROM_EMAIL);

/**
 * Get configured PHPMailer instance
 * @return PHPMailer Configured PHPMailer object
 */
function getMailer() {
    require_once __DIR__ . '/../PHPMailerAutoload.php';
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = SMTP_AUTH;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        
        // Sender info
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addReplyTo(SMTP_REPLY_TO, SMTP_FROM_NAME);
        
        // Email format
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        
    } catch (Exception $e) {
        error_log("PHPMailer configuration error: " . $e->getMessage());
    }
    
    return $mail;
}

if (!function_exists('sendEmailThroughResend')) {
    function sendEmailThroughResend(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null, string $toName = ''): array {
        if (RESEND_API_KEY === '' || RESEND_FROM_EMAIL === '') {
            return ['success' => false, 'error' => 'Resend API is not configured.'];
        }

        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'cURL is required to send email through Resend.'];
        }

        $from = RESEND_FROM_NAME !== ''
            ? RESEND_FROM_NAME . ' <' . RESEND_FROM_EMAIL . '>'
            : RESEND_FROM_EMAIL;

        $payload = [
            'from' => $from,
            'to' => [$toName !== '' ? ($toName . ' <' . $toEmail . '>') : $toEmail],
            'subject' => $subject,
            'text' => $textBody,
        ];

        if ($htmlBody !== null && trim($htmlBody) !== '') {
            $payload['html'] = $htmlBody;
        }
        if (RESEND_REPLY_TO !== '') {
            $payload['reply_to'] = RESEND_REPLY_TO;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . RESEND_API_KEY,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $response === '') {
            return ['success' => false, 'error' => $curlError !== '' ? $curlError : 'No response from Resend API.'];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'error' => null, 'response' => $decoded];
        }

        $errorMessage = '';
        if (is_array($decoded)) {
            $errorMessage = trim((string) (($decoded['message'] ?? '') ?: ($decoded['error'] ?? '')));
        }
        if ($errorMessage === '') {
            $errorMessage = 'Resend API returned HTTP ' . $httpCode . '.';
        }

        return ['success' => false, 'error' => $errorMessage];
    }
}

if (!function_exists('sendConfiguredEmail')) {
    function sendConfiguredEmail(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null, string $toName = ''): array {
        if (RESEND_API_KEY !== '' && RESEND_FROM_EMAIL !== '') {
            $resendResult = sendEmailThroughResend($toEmail, $subject, $textBody, $htmlBody, $toName);
            if (!empty($resendResult['success'])) {
                return ['success' => true, 'error' => null];
            }
        }

        $mail = getMailer();
        try {
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML($htmlBody !== null && trim($htmlBody) !== '');
            $mail->Subject = $subject;
            $mail->Body = ($htmlBody !== null && trim($htmlBody) !== '') ? $htmlBody : $textBody;
            $mail->AltBody = $textBody;

            if (!$mail->send()) {
                return ['success' => false, 'error' => trim((string) $mail->ErrorInfo) ?: 'Unable to send email.'];
            }

            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => trim((string) $e->getMessage()) ?: 'Unable to send email.'];
        }
    }
}
