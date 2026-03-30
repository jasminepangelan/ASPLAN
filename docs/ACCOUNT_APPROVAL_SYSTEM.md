# Account Approval System

## Overview
This system provides administrators with complete control over student account approvals, with the ability to toggle between automatic approval and manual approval modes.

## Features

### 1. **Auto-Approval Toggle**
- **ON**: New student accounts are automatically approved upon registration and can login immediately
- **OFF**: New student accounts require manual approval by administrators before they can login

### 2. **Manual Account Management**
- View all student accounts (pending, approved, rejected)
- Individual account actions (approve, reject, revert to pending)
- Bulk operations (approve/reject multiple accounts at once)
- Account statistics dashboard

### 3. **Status Tracking**
- **Pending**: Account waiting for approval
- **Approved**: Account can login and access the system
- **Rejected**: Account is denied access

## File Structure

### New Files Added:
- `account_approval_settings.php` - Main admin interface for account management
- `init_account_system.php` - Database initialization script

### Modified Files:
- `student_input_process.php` - Updated to check auto-approval setting
- `login_process.php` - Already had status checking (no changes needed)
- `settings.html` - Added link to account management

## Database Changes

### New Table:
```sql
system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    updated_by VARCHAR(255),
    updated_at TIMESTAMP,
    created_at TIMESTAMP
)
```

### Updated Students Table:
- Added `status` column (ENUM: 'pending', 'approved', 'rejected')
- Added `approved_by` column (VARCHAR: admin who approved/rejected)
- Added `approved_at` column (TIMESTAMP: when approved/rejected)
- Added `created_at` column (TIMESTAMP: when account was created)

## How to Use

### 1. **Access the System**
- Login as admin
- Go to Settings → Account Management
- Or directly access: `account_approval_settings.php`

### 2. **Configure Auto-Approval**
- Toggle the "Auto-Approval Mode" switch
- **ON**: Students can login immediately after registration
- **OFF**: Students must wait for manual approval

### 3. **Manage Individual Accounts**
- View all accounts in the table
- Use action buttons to:
  - ✓ Approve pending accounts
  - ✗ Reject accounts
  - ↺ Revert approved/rejected accounts to pending

### 4. **Bulk Operations**
- Select multiple accounts using checkboxes
- Use "Select All" to select all accounts
- Use "Approve Selected" or "Reject Selected" for bulk actions

## Account Status Flow

### With Auto-Approval ON:
```
Registration → Approved → Can Login
```

### With Auto-Approval OFF:
```
Registration → Pending → Manual Approval → Can Login
                      → Manual Rejection → Cannot Login
```

## Student Experience

### Auto-Approval ON:
1. Register account
2. Receive success message: "Account created and approved successfully. You can now login."
3. Can login immediately

### Auto-Approval OFF:
1. Register account
2. Receive message: "Account is pending approval. Please wait for admin approval."
3. Cannot login until approved
4. Login attempts show: "Your account is pending approval"

## Admin Benefits

### Statistics Dashboard:
- Total accounts count
- Pending accounts count
- Approved accounts count
- Rejected accounts count

### Audit Trail:
- Track who approved/rejected accounts
- Track when actions were taken
- Full history of account status changes

## Security Features

- Admin authentication required
- Session management for security
- SQL injection protection using prepared statements
- Input validation and sanitization
- Confirmation dialogs for destructive actions

## Installation Notes

1. The system automatically creates necessary database tables
2. Run `init_account_system.php` once to initialize (already done)
3. Auto-approval is disabled by default for security
4. Existing accounts are automatically marked as "approved"

## Best Practices

### For Development/Testing:
- Enable auto-approval for faster testing
- Use bulk operations to manage test accounts

### For Production:
- Keep auto-approval disabled for security
- Regularly review pending accounts
- Use bulk operations for efficiency
- Monitor account statistics

## Troubleshooting

### Common Issues:
1. **Students can't login**: Check if auto-approval is off and account needs manual approval
2. **Database errors**: Ensure init script ran successfully
3. **Missing interface**: Verify admin is logged in and has proper permissions

### Quick Fixes:
1. Re-run `init_account_system.php` if database issues occur
2. Check system_settings table for auto_approve_students setting
3. Verify student status column in students table

## Future Enhancements

Possible additions:
- Email notifications for account status changes
- Account approval workflow with multiple approval levels
- Automatic account expiration
- Role-based permissions for different admin levels
- Account activity logging

---

**Access URL**: `http://localhost/test_04/account_approval_settings.php`
**Required Role**: Admin login required
