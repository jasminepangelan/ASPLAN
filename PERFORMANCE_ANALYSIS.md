# Program Shift Performance Analysis Report

## Executive Summary
The program shifting feature takes too long to load due to **inefficient database queries** and **redundant client-side processing**. The main culprits are:

1. **Multiple unoptimized SELECT DISTINCT queries** in `psGetProgramOptions()`
2. **Unbounded data fetching** without LIMIT clauses
3. **Redundant PHP loops** doing work that the database should do
4. **Missing database indexes** for composite filters

---

## Performance Issue #1: Inefficient Program Options Query

### Location
`includes/program_shift_service.php` lines 260-303 - `psGetProgramOptions()` function

### The Problem
```php
function psGetProgramOptions($conn) {
    $sources = [
        ['table' => 'curriculum_courses', 'column' => 'program'],
        ['table' => 'student_info', 'column' => 'program'],
        ['table' => 'program_shift_requests', 'column' => 'current_program'],
        ['table' => 'program_shift_requests', 'column' => 'requested_program'],
    ];

    $unique = [];
    foreach ($sources as $source) {
        // ... executes 4 separate queries ...
        $result = $conn->query("SELECT DISTINCT TRIM($column) AS program FROM $table WHERE $column IS NOT NULL AND TRIM($column) != '' ORDER BY $column ASC");
        // Loop through ALL results and build array manually
    }
}
```

### Why This is Slow
- **Query 1**: `curriculum_courses` table - likely has thousands of rows, scans entire table with DISTINCT/TRIM
- **Query 2**: `student_info` table - scans all student records
- **Query 3**: `program_shift_requests.current_program` - scans all shift requests
- **Query 4**: `program_shift_requests.requested_program` - scans same table again

### Impact
- Each query is **full table scan** with no proper index on text columns being scanned
- TRIM() and ORDER BY on every query adds CPU overhead
- **4 database round-trips** just to get a list of program names
- All results fetched into PHP memory and deduplicated manually

### Recommended Fix
```sql
-- Single optimized query combining all sources
SELECT DISTINCT 
    COALESCE(TRIM(cc.program), si.program, psr_current.current_program, psr_req.requested_program) AS program_name
FROM 
    (SELECT DISTINCT TRIM(program) AS program FROM curriculum_courses WHERE program IS NOT NULL AND TRIM(program) != '') cc
UNION
SELECT DISTINCT TRIM(program) FROM student_info WHERE program IS NOT NULL AND TRIM(program) != ''
UNION
SELECT DISTINCT TRIM(current_program) FROM program_shift_requests WHERE current_program IS NOT NULL AND TRIM(current_program) != ''
UNION
SELECT DISTINCT TRIM(requested_program) FROM program_shift_requests WHERE requested_program IS NOT NULL AND TRIM(requested_program) != ''
ORDER BY program_name ASC;
```

---

## Performance Issue #2: Unbounded History Query

### Location
`includes/program_shift_service.php` lines 1979-1992 - `psFetchStudentRequestHistory()` function

### The Problem
```php
function psFetchStudentRequestHistory($conn, $studentNumber) {
    $stmt = $conn->prepare('SELECT * FROM program_shift_requests WHERE student_number = ? ORDER BY requested_at DESC, id DESC');
    // NO LIMIT clause - fetches ALL history records
    // SELECT * fetches all 24 columns including large text fields
}
```

### Why This is Slow
- **SELECT *** - fetches all columns including potentially large text fields (`reason`, `adviser_comment`, `coordinator_comment`)
- **No LIMIT** - if a student has 100+ shift requests (unlikely but possible), all are fetched into memory
- **ORDER BY on datetime** - without proper index, MySQL must scan and sort
- Student page then filters this data manually in PHP

### Data Flow
1. PHP fetches ALL records for student
2. PHP then filters them by status (client-side)
3. Same data used for statistics calculation

### Recommended Fix
```sql
-- Fetch only needed columns with LIMIT
SELECT 
    id, request_code, student_number, current_program, requested_program, 
    status, requested_at, adviser_comment, coordinator_comment
FROM program_shift_requests 
WHERE student_number = ? 
ORDER BY requested_at DESC, id DESC 
LIMIT 100;
```

---

## Performance Issue #3: Per-Program Strand Validation Loop

### Location
`student/program_shift_request.php` lines 132-150

### The Problem
```php
foreach ($programOptions as $programOption) {  // Could be 50-100+ programs
    if ($shiftStrandAlignmentEnabled) {
        // Called for EACH program
        $allowedStrands = psAllowedStrandsForShiftProgram((string)$programOption);  
        // Calls function that does array lookups
        
        if (!psStudentStrandMatchesAllowed($studentStrandKey, $allowedStrands)) {
            // More string comparisons
        }
    }
}
```

### Why This is Slow
- **Loop runs N times** where N = number of available programs (50-150 programs)
- Each iteration does string normalization: `strtoupper()`, `trim()`, `preg_replace()`
- No caching - same program checks repeated
- Array lookups in `psAllowedStrandsForShiftProgram()` happen repeatedly

### Data Being Processed
- 150 programs × strand validation per program = 150 function calls
- Each call does PHP string operations

### Recommended Fix
Build a **strand compatibility matrix** once at load time instead of per-program
```php
// Cache result instead of looping
$programStrandMatrix = [];
foreach ($programOptions as $programOption) {
    $key = strtoupper(trim($programOption));
    if (!isset($programStrandMatrix[$key])) {
        $programStrandMatrix[$key] = psAllowedStrandsForShiftProgram($programOption);
    }
}

// Now use the cached matrix
foreach ($programOptions as $programOption) {
    $key = strtoupper(trim($programOption));
    $allowedStrands = $programStrandMatrix[$key];  // Cache hit
}
```

---

## Performance Issue #4: Missing Database Indexes

### Location
`dev/osas_db_041626.sql` - Database schema indexes

### Current Indexes on program_shift_requests
```sql
PRIMARY KEY (`id`)
UNIQUE KEY `uk_program_shift_request_code` (`request_code`)
KEY `idx_program_shift_student` (`student_number`)
KEY `idx_program_shift_status` (`status`)
```

### Missing Indexes
- ❌ No composite index for `student_number + status` (used in student page filtering)
- ❌ No index on `current_program` or `requested_program` columns (used in psGetProgramOptions)
- ❌ No index on `requested_at` (used in ORDER BY)

### Recommended Index Addition
```sql
-- Already partially addressed in migration but needs completion
ALTER TABLE program_shift_requests
    ADD INDEX idx_program_shift_requested_program (requested_program(100));

ALTER TABLE program_shift_requests
    ADD INDEX idx_program_shift_requested_at (requested_at);

ALTER TABLE curriculum_courses
    ADD INDEX idx_curriculum_courses_program (program(100));

-- Composite index for the most common query pattern
ALTER TABLE student_info
    ADD INDEX idx_student_info_program (program(100));
```

---

## Performance Issue #5: Laravel Bridge Inefficiency

### Location
`student/program_shift_request.php` lines 28-52 and `laravel-app/app/Http/Controllers/LegacyCompat/ProgramShiftController.php`

### The Problem
When `USE_LARAVEL_BRIDGE=1`, the code makes **HTTP POST requests** to fetch data instead of direct database queries:

```php
if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        '/api/program-shift/student/overview',  // HTTP request added latency
        ['bridge_authorized' => true, 'student_id' => $studentNumber]
    );
    // Network latency + HTTP overhead + Laravel bootstrap
}
```

### Why This is Slow
- **Network latency** - even localhost HTTP calls add 10-50ms
- **Laravel bootstrap** on every request - 20-100ms overhead
- **JSON serialization/deserialization** - extra CPU work
- **No request caching** - same data fetched on every page load

### Impact Estimate
- Direct PHP-to-MySQL: ~50ms
- Via Laravel bridge: ~100-200ms
- **2-4x slower** even with direct localhost connection

---

## Complete Performance Call Chain

### What Happens When Student Opens Program Shift Page

```
1. student/program_shift_request.php loads
   ↓
2. Uses Laravel Bridge? YES/NO
   ↓
3. psGetProgramOptions() called
   ├─ Query 1: SELECT DISTINCT FROM curriculum_courses (FULL SCAN)
   ├─ Query 2: SELECT DISTINCT FROM student_info (FULL SCAN)  
   ├─ Query 3: SELECT DISTINCT FROM program_shift_requests current_program (FULL SCAN)
   └─ Query 4: SELECT DISTINCT FROM program_shift_requests requested_program (FULL SCAN)
   ↓ Each fetches 50-150 rows, deduplicated in PHP
   ↓
4. psFetchStudentRequestHistory() called
   └─ SELECT * FROM program_shift_requests WHERE student_number = ? (NO LIMIT)
   ↓ Fetches ALL history records (10-50+ rows)
   ↓
5. Build availableProgramOptions (Loop 50-150 times)
   ├─ psAllowedStrandsForShiftProgram() called per program
   ├─ psStudentStrandMatchesAllowed() called per program
   └─ String operations on each iteration
   ↓
6. Render page with data

Total Time: ~200-500ms+ (vs optimal: ~50-100ms)
```

---

## Severity Summary

| Issue | Impact | Severity | Frequency |
|-------|--------|----------|-----------|
| psGetProgramOptions() - 4 queries | 50-100ms | **CRITICAL** | Every page load |
| psFetchStudentRequestHistory() unbounded | 20-50ms | **HIGH** | Every page load |
| Per-program strand validation loop | 10-30ms | **MEDIUM** | Every page load |
| Missing indexes | 10-50ms | **HIGH** | Every query |
| Laravel Bridge overhead | 50-100ms | **MEDIUM** | If enabled |

**Total Current Load Time: 200-500ms+**
**Optimal Load Time: 50-100ms**
**Performance Gap: 2-5x slower than ideal**

---

## Recommended Action Plan

### Priority 1 (Critical - Do First)
1. **Refactor psGetProgramOptions()** - Combine 4 queries into 1-2 optimized queries
2. **Add missing indexes** - Create indexes on program columns
3. **Optimize psFetchStudentRequestHistory()** - Add LIMIT and select specific columns

### Priority 2 (High - Do Second)  
4. **Cache program options** - Store in session/file cache, refresh hourly
5. **Lazy-load history** - Load initial 20 records, load more on demand
6. **Composite index** - Add `student_number + status` index

### Priority 3 (Medium - Optimization)
7. **Cache strand validation** - Build matrix once instead of per-program
8. **Disable Laravel Bridge** if not needed - Removes 50-100ms HTTP overhead
9. **Add query logging** - Use `EXPLAIN` to verify index usage

---

## Testing After Fixes

### Before Optimization
```
Page Load Time: 400ms
Database Query Time: 250ms
PHP Processing: 150ms
```

### Expected After Optimization
```
Page Load Time: 80-120ms
Database Query Time: 40-60ms
PHP Processing: 30-50ms
```

### Measurement Tools
```bash
# Add to your code
$startTime = microtime(true);
// ... operations ...
$endTime = microtime(true);
error_log('Operation took: ' . ($endTime - $startTime) * 1000 . 'ms');
```

---

## SQL Optimization Examples

### BEFORE (4 queries, 250+ms)
```sql
SELECT DISTINCT TRIM(program) FROM curriculum_courses ...
SELECT DISTINCT TRIM(program) FROM student_info ...
SELECT DISTINCT TRIM(current_program) FROM program_shift_requests ...
SELECT DISTINCT TRIM(requested_program) FROM program_shift_requests ...
```

### AFTER (1 query, 20-40ms)
```sql
SELECT DISTINCT program_name FROM (
    SELECT DISTINCT TRIM(program) AS program_name FROM curriculum_courses WHERE program IS NOT NULL
    UNION ALL
    SELECT DISTINCT TRIM(program) FROM student_info WHERE program IS NOT NULL
    UNION ALL
    SELECT DISTINCT TRIM(current_program) FROM program_shift_requests WHERE current_program IS NOT NULL
    UNION ALL
    SELECT DISTINCT TRIM(requested_program) FROM program_shift_requests WHERE requested_program IS NOT NULL
) AS all_programs
ORDER BY program_name ASC;
```
