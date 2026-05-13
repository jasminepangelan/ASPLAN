## GNED Course Late Placement Investigation

### Problem Statement
Student 220100021 (BSCpE program) has GNED 14 and GNED 09 scheduled in **5th Yr - 1st Sem** (final term/last page), but these should logically appear earlier in the curriculum.

### Investigation Approach

We've enabled detailed debug logging to trace exactly which constraint is preventing these GNED courses from being scheduled earlier. The study plan generator applies multiple constraints in sequence:

#### 1. **Curriculum Structure Check** (`applyConstraintsForExactTerm`)
   - Does the course belong in this term's year/semester?
   - Debug output: `FILTER: GNED XX term mismatch` or `ACCEPTED: GNED XX`
   - **Possible Issue**: GNED courses may be defined for later curriculum years

#### 2. **Prerequisite Validation** (`prerequisitesSatisfiedForCompletedSet`)
   - Are all prerequisites of GNED courses completed?
   - Debug output: `FILTER: GNED XX prerequisites not satisfied: [prereq1, prereq2...]`
   - **Possible Issue**: Uncompleted prerequisites blocking earlier placement

#### 3. **Backlog Clearing** (`applyConstraintsForSimulation`)
   - Can courses from earlier incomplete terms be included?
   - Debug output: `BACKLOG_FILTER` or `BACKLOG_ACCEPTED` for GNED
   - **Possible Issue**: GNED courses deferred until prerequisites resolve

#### 4. **Unit Limit Enforcement** (`buildTermPlanFromAvailable`)
   - Do all available courses fit within term's unit limit?
   - Debug output: `SKIP GNED XX: unit limit exceeded` or `SCHEDULE GNED XX`
   - **Possible Issue**: Earlier terms full; GNED deferred to final term

#### 5. **Prioritization** (`prioritizeCourses`)
   - Are GNED courses low priority vs. core technical courses?
   - GNED courses (no prerequisites, no retakes needed) get low priority scores
   - **Likely Reason**: GNED courses deferred while prerequisite-dependent courses prioritized

### Running the Investigation

#### Quick Check
```bash
cd /path/to/ASPLAN
php dev/quick_trace_gned.php
```
Shows: Study plan summary + GNED debug entries

#### Comprehensive Analysis
```bash
php dev/trace_gned_detailed.php
```
Shows: Full study plan breakdown + filtered debug log + analysis

#### Database Diagnostic
```bash
php dev/diagnose_gned_placement.php
```
Shows: Curriculum mapping + student transcript + prerequisite requirements

### Key Files Modified
- `student/generate_study_plan.php`:
  - Added `getDebugLogPath()` method (line ~3716)
  - Enhanced `applyConstraintsForExactTerm()` with GNED logging (line ~3230)
  - Enhanced `applyConstraintsForSimulation()` with GNED logging (line ~3193)

### Expected Debug Log Output Pattern
```
--- buildTermPlanFromAvailable: Term=1st Yr|1st Sem, Max Units=21, Available=X
  [Course scheduling decisions...]
--- buildTermPlanFromAvailable: Term=...
  FILTER: GNED 14 term mismatch: curriculum=4th Yr|2nd Sem, target=2nd Yr|1st Sem
  [More decisions...]
--- buildTermPlanFromAvailable: Term=5th Yr|1st Sem, Max Units=21, Available=Y
  SCHEDULE GNED 14: units=3, total=7/21
  SCHEDULE GNED 09: units=3, total=10/21
```

### Hypothesis
Based on the constraint architecture, GNED 14 and GNED 09 are likely:
1. Defined for later curriculum years (4th Yr or beyond)
2. Have prerequisites that aren't satisfied until later in the plan
3. Are treated as low-priority "filler" courses deferred until prerequisite-dependent courses resolve
4. End up in the final term because they finally become available after all prerequisites are met

### Next Steps
1. Enable debug logging (`?debug=1` in URLs or `$_GET['debug'] = '1'` in code)
2. Run study plan generation for student 220100021
3. Check generated log file in `/tmp/` for GNED 14 and GNED 09 entries
4. Identify which constraint filters them out in earlier terms
5. Determine if this is expected behavior or a bug
