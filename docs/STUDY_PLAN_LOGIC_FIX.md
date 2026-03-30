# Study Plan Logic Issues - Analysis & Fixes

## Issues Identified

### 1. **Incomplete Grade Validation** (Critical)
**Problem**: The system counts courses as "completed" based only on:
- Having a numeric grade (not empty, INC, DRP)
- Grade being between 1.0-3.0

**Missing Validation**:
- No check if grade was actually submitted by student vs pre-populated
- No timestamp validation (`grade_submitted_at`, `updated_at`)
- No check for evaluator remarks/approval

**Impact**: 
- Pre-filled template courses may be counted as completed
- Statistics may show inflated completion rates
- Study plan may skip courses that aren't actually done

**Fix Applied**:
- Added timestamp checks (`grade_submitted_at`, `updated_at`, `evaluator_remarks`)
- Implemented fallback validation if timestamp columns don't exist
- Added stricter filtering for placeholder grades (N/A, 0, 0.0)

### 2. **Curriculum Data Validation** (Medium)
**Problem**: Loads all courses from `checklist_bscs` without validation

**Issues**:
- May include empty/placeholder records
- No validation that courses have actual credit units
- Could count template rows as real courses

**Fix Applied**:
- Added WHERE clause to exclude empty/null course codes
- Validate that courses have at least some credit units
- Filter out invalid course titles

### 3. **Prerequisite Matching** (Low)
**Problem**: Simple string matching without normalization

**Issues**:
- Case sensitivity issues (CS101 vs cs101)
- Whitespace problems
- No handling of partial matches

**Fix Applied**:
- Added case-insensitive comparison
- Trim all course codes
- Dual-check with both normalized and original values

### 4. **Statistics Calculation** (Low)
**Problem**: No validation of final calculated statistics

**Issues**:
- Could return negative values
- Could show >100% completion
- No bounds checking

**Fix Applied**:
- Added min/max bounds (0-100% for completion)
- Ensure completed never exceeds total
- Prevent negative remaining counts

## Testing Recommendations

### 1. Run Diagnostic Tool
```bash
# From command line:
cd c:\xampp\htdocs\GradMap\student
php diagnose_study_plan.php [STUDENT_ID]

# Or visit in browser (when logged in as student):
http://localhost/GradMap/student/diagnose_study_plan.php
```

This will show:
- Total curriculum courses
- Student's grade distribution
- Which courses are counted as completed
- Pre-populated records (if any)
- Expected vs actual statistics
- Database schema verification

### 2. Check Database Schema
Verify if these columns exist in `student_checklists`:
- `grade_submitted_at` (DATETIME)
- `updated_at` (TIMESTAMP)
- `evaluator_remarks` (VARCHAR)

If missing, the system uses fallback validation (less accurate).

### 3. Manual Verification
For the student in screenshot (220100031):
1. Check their actual completed courses in database
2. Verify which courses have real grades vs pre-filled
3. Compare diagnostic output with displayed statistics
4. Check study plan shows correct remaining courses

## Database Schema Recommendations

### Add Tracking Columns (if not present):
```sql
ALTER TABLE student_checklists
ADD COLUMN grade_submitted_at DATETIME NULL,
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
ADD COLUMN submitted_by VARCHAR(20) NULL COMMENT 'student, adviser, or admin',
ADD INDEX idx_grade_submission (student_id, grade_submitted_at);
```

### Update Existing Records:
```sql
-- Mark records with grades as "submitted" (retroactive)
UPDATE student_checklists
SET grade_submitted_at = NOW(),
    submitted_by = 'student'
WHERE final_grade IS NOT NULL 
AND final_grade != '' 
AND final_grade REGEXP '^[0-9]+(\\.[0-9]+)?$'
AND CAST(final_grade AS DECIMAL(3,1)) BETWEEN 1.0 AND 3.0
AND grade_submitted_at IS NULL;
```

## Files Modified

1. **generate_study_plan.php** - Core algorithm fixes
   - Enhanced `loadStudentData()` method
   - Improved `loadCurriculumData()` method  
   - Better `prerequisitesSatisfied()` validation
   - Robust `getCompletionStats()` calculation

2. **study_plan.php** - Added debug logging
   - Error log output for troubleshooting
   - Helps verify statistics in production

3. **diagnose_study_plan.php** - New diagnostic tool
   - Comprehensive analysis of student data
   - Identifies pre-populated records
   - Validates database schema

## Expected Behavior After Fix

### Scenario 1: Timestamp Columns Exist
- System uses `grade_submitted_at`, `updated_at`, or `evaluator_remarks`
- Only counts grades with at least one timestamp/remark
- Most accurate validation

### Scenario 2: No Timestamp Columns
- System uses fallback validation
- Stricter filtering (excludes N/A, 0, 0.0)
- Less accurate but still functional

### For All Cases:
- Completion % properly bounded (0-100%)
- Completed count never exceeds total
- Remaining count never negative
- Prerequisites matched case-insensitively
- Invalid/placeholder courses excluded

## Next Steps

1. **Run diagnostic for student 220100031**:
   ```
   php c:\xampp\htdocs\GradMap\student\diagnose_study_plan.php 220100031
   ```

2. **Review diagnostic output** to see exact issue

3. **If pre-populated records found**:
   - Decide: keep as completed or clear them?
   - If keeping: add timestamps retroactively
   - If clearing: NULL out pre-filled grades

4. **Test study plan page** with fixed code:
   - Refresh page (Ctrl+Shift+R)
   - Check if statistics match diagnostic
   - Verify remaining courses are correct

5. **Consider adding timestamp columns** if not present

## Verification Checklist

- [ ] Diagnostic shows correct completed course count
- [ ] No pre-populated records flagged (or handled appropriately)
- [ ] Study plan statistics match diagnostic output
- [ ] Remaining courses list is accurate
- [ ] Page refresh shows updated statistics
- [ ] Cache bypass working (check browser console)

---

**Note**: The core issue is likely that grades are being counted without verifying they were actually submitted by users. The fix prioritizes records with timestamps/remarks, ensuring only real completions are counted.
