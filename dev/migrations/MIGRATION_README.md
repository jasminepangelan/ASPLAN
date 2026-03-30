# Database Migration: e_checklist → osas_db

## Migration Date: March 4, 2026

This document describes the migration from `e_checklist` database to `osas_db` database.

## Migration Files

- **SQL Script**: `dev/migrations/migrate_e_checklist_to_osas_db.sql`
- **Runner Script**: `dev/migrations/run_migration.php`

## How to Run Migration

### Option 1: Using phpMyAdmin
1. Open phpMyAdmin
2. Import `dev/migrations/migrate_e_checklist_to_osas_db.sql`

### Option 2: Using PHP Script
```bash
cd c:\xampp\htdocs\ASPLAN\dev\migrations
php run_migration.php
```

### Option 3: Using MySQL Command Line
```bash
mysql -u root < dev/migrations/migrate_e_checklist_to_osas_db.sql
```

---

## Table Mapping Summary

| e_checklist Table | osas_db Table | Notes |
|-------------------|---------------|-------|
| `admins` | `admin` | Field names differ |
| `adviser` | `adviser` | Field structure changed |
| `adviser_batch` | `adviser_batch` | FK structure changed |
| `batches` | `batches` | Field: `batch` → `batches` |
| `checklist_bscs` | `curriculum_courses` | New table with multi-program support |
| `password_resets` | `password_resets` | **New table created** |
| `pre_enrollments` | `pre_enrollments` | **New table created**, student_id → student_number |
| `pre_enrollment_courses` | `pre_enrollment_courses` | **New table created** |
| `programs` | `programs` | **New table created** |
| `students` | `student_info` | Major structural changes |
| `student_checklists` | `student_checklists` | Added `final_grade_text` column |
| `system_settings` | `system_settings` | `setting_value` type: INT → TEXT |

---

## Required Code Changes

### 1. Student ID Field (CRITICAL)

**Old (`e_checklist`):**
```php
$student_id = $_SESSION['student_id'];  // VARCHAR
$sql = "SELECT * FROM students WHERE student_id = ?";
```

**New (`osas_db`):**
```php
$student_number = $_SESSION['student_number'];  // INT
$sql = "SELECT * FROM student_info WHERE student_number = ?";
```

**Files to update:**
- All files referencing `students` table
- Session variables using `student_id`

### 2. Admin Full Name

**Old (`e_checklist`):**
```php
$sql = "SELECT full_name FROM admins WHERE username = ?";
```

**New (`osas_db`):**
```php
$sql = "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM admin WHERE username = ?";
```

### 3. Adviser Full Name

**Old (`e_checklist`):**
```php
$sql = "SELECT full_name, pronoun FROM adviser WHERE id = ?";
```

**New (`osas_db`):**
```php
$sql = "SELECT CONCAT(first_name, ' ', last_name) AS full_name, prefix AS pronoun FROM adviser WHERE username = ?";
```

### 4. Batches Field Name

**Old (`e_checklist`):**
```php
$sql = "SELECT batch FROM batches";
```

**New (`osas_db`):**
```php
$sql = "SELECT batches FROM batches";
-- OR use alias:
$sql = "SELECT batches AS batch FROM batches";
```

### 5. Curriculum/Checklist Query

**Old (`e_checklist`):**
```php
$sql = "SELECT * FROM checklist_bscs WHERE year = ? AND semester = ?";
```

**New (`osas_db`):**
```php
$sql = "SELECT * FROM curriculum_courses WHERE program = ? AND year_level = ? AND semester = ?";
```

### 6. Student Checklist Grades

**Old (`e_checklist`):**
```php
$sql = "SELECT final_grade FROM student_checklists WHERE student_id = ?";
// final_grade is VARCHAR (e.g., '1.25', 'INC', 'DRP')
```

**New (`osas_db`):**
```php
$sql = "SELECT final_grade, final_grade_text FROM student_checklists WHERE student_number = ?";
// final_grade is DECIMAL (numeric only), final_grade_text has original value
```

---

## New Tables Created in osas_db

### 1. `password_resets`
Stores password reset tokens (was missing in osas_db).

### 2. `curriculum_courses`
Replaces `checklist_bscs` with support for multiple programs and curriculum years.

### 3. `pre_enrollments`
Pre-enrollment records with student_number (INT) instead of student_id (VARCHAR).

### 4. `pre_enrollment_courses`
Course selections for pre-enrollment.

### 5. `programs`
Academic programs list.

---

## Schema Modifications to Existing osas_db Tables

### `system_settings`
- `setting_value`: Changed from INT to TEXT (to support non-numeric values)
- Added: `description` column

### `adviser`
- Added: `id` (INT) for backward compatibility
- Added: `sex` (ENUM)
- Added: `pronoun` (ENUM)

### `student_checklists`
- Added: `final_grade_text` (VARCHAR) for non-numeric grades

---

## Data Type Conversions

| Field | e_checklist | osas_db | Conversion |
|-------|-------------|---------|------------|
| Student ID | VARCHAR(50) | INT(9) | Numeric extraction |
| Grades | VARCHAR(10) | DECIMAL(3,2) + VARCHAR | Dual storage |
| Setting values | TEXT | TEXT (was INT) | Schema altered |

---

## Backup Recommendation

The original `e_checklist` database has been preserved. Do not drop it until you have verified the migration is successful and all application features work correctly.

---

## Verification Steps

1. Run the migration script
2. Check record counts match (use `SELECT * FROM migration_summary;`)
3. Test login functionality (admin, adviser, student)
4. Test checklist viewing
5. Test pre-enrollment features
6. Test password reset functionality

---

## Rollback Procedure

If you need to rollback to e_checklist:

1. Edit `config/database.php`
2. Change `DB_NAME` from `'osas_db'` to `'e_checklist'`
3. Restart your application

```php
define('DB_NAME', 'e_checklist');  // Rollback
```
