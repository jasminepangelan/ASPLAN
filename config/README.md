# PEAS Configuration Files

⚠️ **SECURITY WARNING**: These files contain sensitive credentials!

## Files in this directory:

- `config.php` - Master configuration (include this in your PHP files)
- `database.php` - Database connection settings
- `email.php` - SMTP/Email configuration
- `app.php` - Application settings

## Usage:

Instead of duplicating database connections in every file, simply include:

```php
<?php
require_once __DIR__ . '/config/config.php';

// Get database connection
$conn = getDBConnection();

// Your code here...

// Close connection when done
closeDBConnection($conn);
?>
```

## Security Checklist:

- [ ] Add `config/email.php` to `.gitignore`
- [ ] Move credentials to environment variables (`.env` file)
- [ ] Never commit sensitive credentials to version control
- [ ] Set `DEBUG_MODE` to `false` in production

## Next Steps:

1. Update all PHP files to use these config files
2. Remove hardcoded database credentials from individual files
3. Test thoroughly after migration
