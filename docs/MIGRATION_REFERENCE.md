# Quick Migration Reference Guide

## For Future Files You Create or Edit

### ❌ OLD WAY (Don't do this anymore):
```php
<?php
// Database connection
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'e_checklist';
$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Your code here...

$conn->close();
?>
```

### ✅ NEW WAY (Always do this):
```php
<?php
require_once __DIR__ . '/config/config.php';

// Get database connection
$conn = getDBConnection();

// Your code here...

// Close connection
closeDBConnection($conn);
?>
```

---

## Common Scenarios:

### 1. Creating a New PHP File
```php
<?php
require_once __DIR__ . '/config/config.php';
$conn = getDBConnection();

// Your database queries here

closeDBConnection($conn);
?>
```

### 2. Sending Emails
```php
<?php
require_once __DIR__ . '/config/config.php';

// Get pre-configured mailer
$mail = getMailer();

// Just add recipient and content
$mail->addAddress('student@email.com');
$mail->Subject = 'Your Subject';
$mail->Body = 'Your message';

$mail->send();
?>
```

### 3. Using App Constants
```php
<?php
require_once __DIR__ . '/config/config.php';

// Available constants:
echo APP_NAME; // 'PEAS - Pre-Enrollment Assessment System'
echo MAX_FILE_SIZE; // 5242880 (5MB)
echo MIN_PASSWORD_LENGTH; // 8

$allowedTypes = ALLOWED_IMAGE_TYPES; // ['jpg', 'jpeg', 'png', 'gif']
?>
```

### 4. In Subdirectories (if you create them later)
```php
<?php
// If file is in admin/ folder
require_once __DIR__ . '/../config/config.php';

// If file is in admin/users/ folder
require_once __DIR__ . '/../../config/config.php';
?>
```

---

## Cheat Sheet:

| What You Need | What To Use |
|--------------|-------------|
| Database connection | `$conn = getDBConnection();` |
| Close connection | `closeDBConnection($conn);` |
| Send email | `$mail = getMailer();` |
| App name | `APP_NAME` |
| Upload directory | `UPLOAD_DIR` |
| Max file size | `MAX_FILE_SIZE` |
| Password min length | `MIN_PASSWORD_LENGTH` |

---

## Remember:

1. **Always include config first**
2. **Never hardcode credentials**
3. **Use the helper functions**
4. **Close connections when done**
5. **Check `config/` folder for available constants**

---

## Need Help?

- Check: `config/README.md`
- Run: `migration_helper.php` to see which files need updating
- Look at: `login_process.php` or `forgot_password.php` for examples
