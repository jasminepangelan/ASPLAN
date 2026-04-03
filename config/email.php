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
