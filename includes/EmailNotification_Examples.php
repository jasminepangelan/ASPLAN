<?php
/**
 * Email Notification Integration Examples
 * Shows how to integrate email notifications in PEAS
 * 
 * IMPORTANT: This file is for reference only
 * Copy these examples to your actual handler files
 */

// Example 1: Send approval email in approve_account.php (Admin)
// ================================================================
/*
require_once __DIR__ . '/../includes/EmailNotification.php';

// After successfully approving the account in the database:
$email_notifier = new EmailNotification();
$success = $email_notifier->sendAccountApproval(
    $student_email,  // From database
    $student_name,   // From database
    $student_id      // From form/URL
);

if ($success) {
    // Email sent successfully
    $_SESSION['success_message'] = "Account approved and notification email sent!";
} else {
    // Email failed but account still approved
    $_SESSION['success_message'] = "Account approved! (Email notification could not be sent)";
}
*/

// Example 2: Send rejection email in reject_account.php (Admin)
// ===============================================================
/*
require_once __DIR__ . '/../includes/EmailNotification.php';

// After rejecting the account in the database:
$email_notifier = new EmailNotification();
$success = $email_notifier->sendAccountRejection(
    $student_email,
    $student_name,
    $student_id
);
*/

// Example 3: Send password change notification in auth/reset_password.php
// ========================================================================
/*
require_once __DIR__ . '/../includes/EmailNotification.php';

// After successfully changing the password:
$email_notifier = new EmailNotification();
$success = $email_notifier->sendPasswordChange(
    $user_email,
    $user_name
);
*/

// Example 4: Send pre-enrollment update in adviser/save_pre_enrollment.php
// =========================================================================
/*
require_once __DIR__ . '/../includes/EmailNotification.php';

// After saving pre-enrollment data:
$email_notifier = new EmailNotification();
$status_message = "Your pre-enrollment form has been saved successfully.";
$success = $email_notifier->sendPreEnrollmentUpdate(
    $student_email,
    $student_name,
    $status_message
);
*/

// ============================================================================
// SETUP INSTRUCTIONS
// ============================================================================
/*

1. Configure SMTP Settings
   -----------------------
   Edit config/email.php and add these constants:
   
   define('SMTP_HOST', 'smtp.gmail.com');
   define('SMTP_PORT', 587);
   define('SMTP_SECURE', 'tls');
   define('SMTP_USERNAME', 'your-email@cvsu-carmona.edu.ph');
   define('SMTP_PASSWORD', 'your-app-password');
   define('SMTP_FROM_EMAIL', 'noreply@cvsu-carmona.edu.ph');
   define('SMTP_FROM_NAME', 'PEAS - CvSU Carmona');

2. For Gmail/Google Workspace:
   ---------------------------
   - Enable 2-Factor Authentication
   - Generate an App Password: https://myaccount.google.com/apppasswords
   - Use the App Password in SMTP_PASSWORD

3. Test Email Sending:
   -------------------
   Create test_email.php:
   
   <?php
   require_once 'includes/EmailNotification.php';
   $email = new EmailNotification();
   $result = $email->sendAccountApproval(
       'test@cvsu-carmona.edu.ph',
       'Test Student',
       '2021-12345'
   );
   echo $result ? 'Email sent!' : 'Email failed!';
   ?>

4. Integration Checklist:
   ----------------------
   □ Update config/email.php with SMTP settings
   □ Add email notification to admin/approve_account.php
   □ Add email notification to admin/reject_account.php
   □ Add email notification to auth/reset_password.php (optional)
   □ Add email notification to adviser/save_pre_enrollment.php (optional)
   □ Test email sending with real accounts
   □ Check spam folders if emails don't arrive
   □ Update email templates with correct URLs/branding

5. Optional: Error Logging
   -----------------------
   Emails are logged to PHP error log if they fail.
   Check your XAMPP error logs if emails aren't sending.

*/
?>
