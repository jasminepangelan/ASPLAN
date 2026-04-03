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

// SMTP Configuration - loaded from environment variables
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
define('SMTP_AUTH', getenv('SMTP_AUTH') ?: true);

// Email Credentials - loaded from environment variables
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');

// Email Settings - loaded from environment variables
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'noreply@cvsu-carmona.edu.ph');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'ASPLAN - Automated Study Plan Generator');
define('SMTP_REPLY_TO', getenv('SMTP_REPLY_TO') ?: 'noreply@cvsu-carmona.edu.ph');

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
