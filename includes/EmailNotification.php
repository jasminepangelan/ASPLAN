<?php
/**
 * Email Notification Helper
 * Handles sending email notifications for various events in PEAS
 * 
 * Uses PHPMailer for sending emails
 * Requires: config/email.php for SMTP settings
 */

require_once __DIR__ . '/../PHPMailerAutoload.php';
require_once __DIR__ . '/../config/email.php';

class EmailNotification {
    
    private $mailer;
    private $from_email;
    private $from_name;
    
    public function __construct() {
        $this->mailer = new \PHPMailer\PHPMailer\PHPMailer();
        $this->setupMailer();
        $this->from_email = SMTP_FROM_EMAIL ?? 'noreply@cvsu-carmona.edu.ph';
        $this->from_name = SMTP_FROM_NAME ?? 'PEAS - CvSU Carmona';
    }
    
    /**
     * Setup PHPMailer with SMTP configuration
     */
    private function setupMailer() {
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST ?? 'smtp.gmail.com';
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USERNAME ?? '';
            $this->mailer->Password = SMTP_PASSWORD ?? '';
            $this->mailer->SMTPSecure = SMTP_SECURE ?? 'tls';
            $this->mailer->Port = SMTP_PORT ?? 587;
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->isHTML(true);
        } catch (Exception $e) {
            error_log("Email setup error: " . $e->getMessage());
        }
    }
    
    /**
     * Send account approval notification
     * @param string $email Student email
     * @param string $student_name Student full name
     * @param string $student_id Student ID
     * @return bool Success status
     */
    public function sendAccountApproval($email, $student_name, $student_id) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->setFrom($this->from_email, $this->from_name);
            $this->mailer->addAddress($email, $student_name);
            
            $this->mailer->Subject = '✅ Account Approved - PEAS CvSU Carmona';
            
            $body = $this->getAccountApprovalTemplate($student_name, $student_id);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body);
            
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send account rejection notification
     * @param string $email Student email
     * @param string $student_name Student full name
     * @param string $student_id Student ID
     * @return bool Success status
     */
    public function sendAccountRejection($email, $student_name, $student_id) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->setFrom($this->from_email, $this->from_name);
            $this->mailer->addAddress($email, $student_name);
            
            $this->mailer->Subject = 'Account Application Status - PEAS CvSU Carmona';
            
            $body = $this->getAccountRejectionTemplate($student_name, $student_id);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body);
            
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send password change notification
     * @param string $email User email
     * @param string $user_name User full name
     * @return bool Success status
     */
    public function sendPasswordChange($email, $user_name) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->setFrom($this->from_email, $this->from_name);
            $this->mailer->addAddress($email, $user_name);
            
            $this->mailer->Subject = '🔒 Password Changed - PEAS CvSU Carmona';
            
            $body = $this->getPasswordChangeTemplate($user_name);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body);
            
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send pre-enrollment update notification
     * @param string $email Student email
     * @param string $student_name Student full name
     * @param string $status Status message
     * @return bool Success status
     */
    public function sendPreEnrollmentUpdate($email, $student_name, $status) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->setFrom($this->from_email, $this->from_name);
            $this->mailer->addAddress($email, $student_name);
            
            $this->mailer->Subject = '📋 Pre-Enrollment Update - PEAS CvSU Carmona';
            
            $body = $this->getPreEnrollmentTemplate($student_name, $status);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body);
            
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify that the configured SMTP server can be reached and authenticated.
     * This does not send a message.
     */
    public function testSmtpConnection() {
        try {
            $connected = $this->mailer->smtpConnect();
            if ($connected) {
                $this->mailer->smtpClose();
            }

            return (bool) $connected;
        } catch (Exception $e) {
            error_log("SMTP connection test error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send program shift submission confirmation
     */
    public function sendProgramShiftSubmitted($email, $student_name, $request_code, $current_program, $requested_program) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->setFrom($this->from_email, $this->from_name);
            $this->mailer->addAddress($email, $student_name);

            $this->mailer->Subject = 'Program Shift Request Submitted - PEAS CvSU Carmona';
            $body = $this->getProgramShiftSubmittedTemplate($student_name, $request_code, $current_program, $requested_program);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body);

            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send new program shift request notification to an adviser
     * @param string $email Adviser email
     * @param string $adviser_name Adviser name
     * @param string $student_name Student full name
     * @param string $request_code Request code
     * @param string $current_program Current program
     * @param string $requested_program Requested program
     * @param string $reason Student reason
     * @return bool Success status
     */
    public function sendProgramShiftAdviserNotification($email, $adviser_name, $student_name, $request_code, $current_program, $requested_program, $reason = '') {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->setFrom($this->from_email, $this->from_name);
            $this->mailer->addAddress($email, $adviser_name);

            $this->mailer->Subject = 'New Program Shift Request - PEAS CvSU Carmona';

            $body = $this->getProgramShiftAdviserTemplate($adviser_name, $student_name, $request_code, $current_program, $requested_program, $reason);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body);

            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send program shift status update email
     */
    public function sendProgramShiftStatusUpdate($email, $student_name, $request_code, $status_label, $current_program, $requested_program, $details = '') {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->setFrom($this->from_email, $this->from_name);
            $this->mailer->addAddress($email, $student_name);

            $this->mailer->Subject = 'Program Shift Update - PEAS CvSU Carmona';
            $body = $this->getProgramShiftStatusUpdateTemplate($student_name, $request_code, $status_label, $current_program, $requested_program, $details);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body);

            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Account approval email template
     */
    private function getAccountApprovalTemplate($name, $student_id) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #206018 0%, #4CAF50 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; padding: 12px 30px; background: #206018; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                .success-icon { font-size: 48px; margin-bottom: 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='success-icon'>✅</div>
                    <h1 style='margin: 0; font-size: 28px;'>Account Approved!</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$name}</strong>,</p>
                    
                    <p>Great news! Your PEAS account has been successfully approved.</p>
                    
                    <p><strong>Student ID:</strong> {$student_id}</p>
                    
                    <p>You can now log in to the Pre-Enrollment Assessment System and access all features:</p>
                    
                    <ul>
                        <li>✅ View and manage your checklist</li>
                        <li>✅ Submit pre-enrollment forms</li>
                        <li>✅ Track your enrollment status</li>
                        <li>✅ Access your student records</li>
                    </ul>
                    
                    <div style='text-align: center;'>
                        <a href='https://cvsu-carmona.edu.ph/peas/' class='button'>Login to PEAS</a>
                    </div>
                    
                    <p style='margin-top: 30px; padding: 15px; background: #e8f5e9; border-left: 4px solid #4CAF50; border-radius: 5px;'>
                        <strong>📌 Next Steps:</strong><br>
                        1. Log in using your student ID and password<br>
                        2. Complete your profile information<br>
                        3. Review your enrollment checklist
                    </p>
                    
                    <p>If you have any questions, please contact your adviser or the admin office.</p>
                    
                    <p>Welcome to PEAS!</p>
                </div>
                <div class='footer'>
                    <p><strong>Pre-Enrollment Assessment System</strong><br>
                    Cavite State University - Carmona Campus<br>
                    This is an automated email. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Account rejection email template
     */
    private function getAccountRejectionTemplate($name, $student_id) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #d32f2f 0%, #f44336 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; padding: 12px 30px; background: #206018; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0; font-size: 28px;'>Account Application Update</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$name}</strong>,</p>
                    
                    <p>Thank you for applying to the Pre-Enrollment Assessment System.</p>
                    
                    <p>We regret to inform you that your account application (Student ID: <strong>{$student_id}</strong>) could not be approved at this time.</p>
                    
                    <p style='padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px;'>
                        <strong>⚠️ Possible Reasons:</strong><br>
                        • Incomplete or incorrect information<br>
                        • Student ID verification issues<br>
                        • Documentation requirements not met
                    </p>
                    
                    <p>Please contact the admin office or your program coordinator for more information and assistance with your application.</p>
                    
                    <p><strong>Contact Information:</strong><br>
                    Email: registrar@cvsu-carmona.edu.ph<br>
                    Office Hours: Monday - Friday, 8:00 AM - 5:00 PM</p>
                    
                    <p>We're here to help you resolve any issues!</p>
                </div>
                <div class='footer'>
                    <p><strong>Pre-Enrollment Assessment System</strong><br>
                    Cavite State University - Carmona Campus<br>
                    This is an automated email. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Password change email template
     */
    private function getPasswordChangeTemplate($name) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #206018 0%, #4CAF50 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                .security-icon { font-size: 48px; margin-bottom: 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='security-icon'>🔒</div>
                    <h1 style='margin: 0; font-size: 28px;'>Password Changed Successfully</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$name}</strong>,</p>
                    
                    <p>This email confirms that your PEAS account password was successfully changed.</p>
                    
                    <p><strong>Change Details:</strong><br>
                    Date: " . date('F d, Y') . "<br>
                    Time: " . date('h:i A') . "</p>
                    
                    <p style='padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px;'>
                        <strong>⚠️ Didn't make this change?</strong><br>
                        If you did not request this password change, please contact the admin office immediately to secure your account.
                    </p>
                    
                    <p style='padding: 15px; background: #e8f5e9; border-left: 4px solid #4CAF50; border-radius: 5px;'>
                        <strong>🛡️ Security Tips:</strong><br>
                        • Never share your password with anyone<br>
                        • Use a strong, unique password<br>
                        • Change your password regularly<br>
                        • Log out when using shared computers
                    </p>
                    
                    <p>Thank you for helping keep your account secure!</p>
                </div>
                <div class='footer'>
                    <p><strong>Pre-Enrollment Assessment System</strong><br>
                    Cavite State University - Carmona Campus<br>
                    This is an automated email. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Pre-enrollment update email template
     */
    private function getPreEnrollmentTemplate($name, $status) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #206018 0%, #4CAF50 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; padding: 12px 30px; background: #206018; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div style='font-size: 48px; margin-bottom: 10px;'>📋</div>
                    <h1 style='margin: 0; font-size: 28px;'>Pre-Enrollment Update</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$name}</strong>,</p>
                    
                    <p>There's an update regarding your pre-enrollment status:</p>
                    
                    <p style='padding: 20px; background: white; border-radius: 8px; border: 2px solid #206018; font-size: 16px; text-align: center;'>
                        <strong>{$status}</strong>
                    </p>
                    
                    <p>Please log in to your PEAS account to view complete details and take any necessary actions.</p>
                    
                    <div style='text-align: center;'>
                        <a href='https://cvsu-carmona.edu.ph/peas/' class='button'>View My Checklist</a>
                    </div>
                    
                    <p style='margin-top: 30px; padding: 15px; background: #e8f5e9; border-left: 4px solid #4CAF50; border-radius: 5px;'>
                        <strong>📌 Important:</strong><br>
                        Make sure to complete all required items in your checklist before the deadline to ensure smooth enrollment processing.
                    </p>
                    
                    <p>If you have any questions, please contact your adviser.</p>
                </div>
                <div class='footer'>
                    <p><strong>Pre-Enrollment Assessment System</strong><br>
                    Cavite State University - Carmona Campus<br>
                    This is an automated email. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Program shift submission email template
     */
    private function getProgramShiftSubmittedTemplate($name, $request_code, $current_program, $requested_program) {
        $request_code = htmlspecialchars((string)$request_code, ENT_QUOTES, 'UTF-8');
        $current_program = htmlspecialchars((string)$current_program, ENT_QUOTES, 'UTF-8');
        $requested_program = htmlspecialchars((string)$requested_program, ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #206018 0%, #4CAF50 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .panel { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin: 15px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0; font-size: 28px;'>Program Shift Request Submitted</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$name}</strong>,</p>
                    <p>Your program shift request has been submitted successfully and is now waiting for review.</p>
                    <div class='panel'>
                        <p><strong>Request Code:</strong> {$request_code}</p>
                        <p><strong>Current Program:</strong> {$current_program}</p>
                        <p><strong>Requested Program:</strong> {$requested_program}</p>
                    </div>
                    <p>You will receive another update when the request is reviewed or finalized.</p>
                    <p>If you did not create this request, please contact your adviser or the registrar office immediately.</p>
                </div>
                <div class='footer'>
                    <p><strong>Pre-Enrollment Assessment System</strong><br>
                    Cavite State University - Carmona Campus<br>
                    This is an automated email. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Program shift adviser notification template
     */
    private function getProgramShiftAdviserTemplate($adviser_name, $student_name, $request_code, $current_program, $requested_program, $reason = '') {
        $adviser_name = htmlspecialchars((string)$adviser_name, ENT_QUOTES, 'UTF-8');
        $student_name = htmlspecialchars((string)$student_name, ENT_QUOTES, 'UTF-8');
        $request_code = htmlspecialchars((string)$request_code, ENT_QUOTES, 'UTF-8');
        $current_program = htmlspecialchars((string)$current_program, ENT_QUOTES, 'UTF-8');
        $requested_program = htmlspecialchars((string)$requested_program, ENT_QUOTES, 'UTF-8');
        $reason = trim((string)$reason) !== '' ? '<p><strong>Reason:</strong> ' . htmlspecialchars((string)$reason, ENT_QUOTES, 'UTF-8') . '</p>' : '';

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #206018 0%, #4CAF50 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .panel { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin: 15px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0; font-size: 28px;'>New Program Shift Request</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$adviser_name}</strong>,</p>
                    <p>A student has submitted a new program shift request for your review.</p>
                    <div class='panel'>
                        <p><strong>Request Code:</strong> {$request_code}</p>
                        <p><strong>Student:</strong> {$student_name}</p>
                        <p><strong>Current Program:</strong> {$current_program}</p>
                        <p><strong>Requested Program:</strong> {$requested_program}</p>
                        {$reason}
                    </div>
                    <p>Please log in to ASPLAN to review the request in the adviser queue.</p>
                </div>
                <div class='footer'>
                    <p><strong>ASPLAN</strong><br>
                    Cavite State University - Carmona Campus<br>
                    This is an automated email. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Program shift status update email template
     */
    private function getProgramShiftStatusUpdateTemplate($name, $request_code, $status_label, $current_program, $requested_program, $details = '') {
        $request_code = htmlspecialchars((string)$request_code, ENT_QUOTES, 'UTF-8');
        $status_label = htmlspecialchars((string)$status_label, ENT_QUOTES, 'UTF-8');
        $current_program = htmlspecialchars((string)$current_program, ENT_QUOTES, 'UTF-8');
        $requested_program = htmlspecialchars((string)$requested_program, ENT_QUOTES, 'UTF-8');
        $details = trim((string)$details) !== '' ? '<p><strong>Details:</strong> ' . htmlspecialchars((string)$details, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        $name = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #206018 0%, #4CAF50 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .panel { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin: 15px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0; font-size: 28px;'>Program Shift Update</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$name}</strong>,</p>
                    <p>Your program shift request has been updated.</p>
                    <div class='panel'>
                        <p><strong>Request Code:</strong> {$request_code}</p>
                        <p><strong>Status:</strong> {$status_label}</p>
                        <p><strong>Current Program:</strong> {$current_program}</p>
                        <p><strong>Requested Program:</strong> {$requested_program}</p>
                        {$details}
                    </div>
                    <p>Thank you for using the student portal.</p>
                </div>
                <div class='footer'>
                    <p><strong>Pre-Enrollment Assessment System</strong><br>
                    Cavite State University - Carmona Campus<br>
                    This is an automated email. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
?>
