# GNED 14 & GNED 09 Late Placement - Investigation Summary

## What I've Done

I've created a comprehensive debug infrastructure to trace exactly why GNED 14 and GNED 09 are appearing in the final term (5th Yr - 1st Sem) for BSCpE student 220100021 instead of earlier.

### Changes Made to Codebase

#### 1. **Added Public Debug Log Access** (student/generate_study_plan.php, line ~3716)
```php
public function getDebugLogPath() {
    return $this->debug_log_path;
}
```
This allows diagnostic scripts to access the debug log file generated during study plan creation.

#### 2. **Enhanced Constraint Filtering Logs** (student/generate_study_plan.php)

**In `applyConstraintsForExactTerm()` method (line ~3230):**
- Now logs when GNED courses are filtered out by term mismatch
- Logs when GNED courses fail prerequisites
- Logs when GNED courses are accepted for a term
- Output examples:
  - `FILTER: GNED 14 term mismatch: curriculum=4th Yr|2nd Sem, target=2nd Yr|1st Sem`
  - `FILTER: GNED 14 prerequisites not satisfied: [ITEC 101, ITEC 102]`
  - `ACCEPTED: GNED 14 for term 2nd Yr|1st Sem`

**In `applyConstraintsForSimulation()` method (line ~3193):**
- Tracks GNED courses through the backlog filtering process
- Shows why GNED courses are deferred
- Output examples:
  - `BACKLOG_FILTER: GNED 14 semester mismatch: Mid Year != 1st Sem`
  - `BACKLOG_ACCEPTED: GNED 14 prerequisites satisfied`

### Diagnostic Tools Created

#### 1. **dev/quick_trace_gned.php** (Fastest)
Shows study plan summary + GNED debug entries
```bash
php dev/quick_trace_gned.php
```

#### 2. **dev/trace_gned_detailed.php** (Comprehensive)
Shows full study plan breakdown + filtered debug logs + analysis
```bash
php dev/trace_gned_detailed.php
```

#### 3. **dev/diagnose_gned_placement.php** (Database Analysis)
Shows curriculum mapping, student transcript, prerequisites
```bash
php dev/diagnose_gned_placement.php
```

### How the Study Plan Generator Works

The generator applies constraints in this sequence:

```
For each curriculum term (1st Yr 1st Sem → ... → 5th Yr 2nd Sem):
  ↓
  1. Apply Exact Term Constraints
     ├─ Filter courses belonging to THIS year/semester
     ├─ Filter by prerequisites
     └─ Result: "available" courses for this term
  ↓
  2. Apply Backlog Constraints  
     ├─ Include courses from earlier incomplete terms
     ├─ Check semester matching
     ├─ Check prerequisites
     └─ Result: additional available courses
  ↓
  3. Build Term Plan (Greedy Prioritization)
     ├─ Rank courses by priority (retakes > prerequisites > filler)
     ├─ Add courses up to unit limit
     └─ Result: scheduled courses for term
  ↓
  4. Move to next term with updated "completed" courses
```

### Why GNED Courses Appear Late - Expected Reasons

**Reason 1: Curriculum Placement**
- GNED 14 and GNED 09 may be defined in curriculum as **4th Yr courses**
- Cannot be scheduled before 4th year is reached
- Debug log will show: `FILTER: GNED XX term mismatch: curriculum=4th Yr|2nd Sem, target=2nd Yr|1st Sem`

**Reason 2: Prerequisite Dependencies**
- GNED courses may have prerequisites like "ITEC 101" or core courses
- These prerequisites must be completed first
- Debug log will show: `FILTER: GNED XX prerequisites not satisfied: [ITEC 101, ...]`

**Reason 3: Low Priority Score**
- GNED courses without prerequisites get low priority scores (~0-30 points)
- Core technical courses with prerequisites get high scores (~200+ points)
- Prioritizer defers GNED courses until technical courses are scheduled first
- Result: GNED courses fill remaining slots in final term

**Reason 4: Unit Capacity**
- Each term has unit limit (21 units, or 15 if on probation)
- If earlier terms are full with higher-priority courses
- GNED courses deferred to later terms with available capacity
- Debug log will show: `SKIP GNED XX: unit limit exceeded (would be X, max 21)`

### Expected Debug Output

When you run the diagnostic, you'll see log entries like:

```
--- buildTermPlanFromAvailable: Term=1st Yr|1st Sem, Max Units=21, Available=8
  SCHEDULE ITEC 105: units=3, total=3/21
  SCHEDULE ITEC 101: units=4, total=7/21
  ...

--- buildTermPlanFromAvailable: Term=2nd Yr|1st Sem, Max Units=21, Available=5
  FILTER: GNED 14 term mismatch: curriculum=4th Yr|2nd Sem, target=2nd Yr|1st Sem
  ...

--- buildTermPlanFromAvailable: Term=4th Yr|2nd Sem, Max Units=21, Available=3
  SCHEDULE GNED 14: units=3, total=7/21
  SCHEDULE GNED 09: units=3, total=10/21
```

### How to Read the Results

**If you see**: `FILTER: GNED XX term mismatch`
→ Course is defined for a later year; can't be scheduled before that year

**If you see**: `FILTER: GNED XX prerequisites not satisfied: [...]`
→ Course requires other courses to be completed first

**If you see**: `SKIP GNED XX: unit limit exceeded`
→ Earlier terms full; no capacity for this course until later

**If you see**: `SCHEDULE GNED XX: units=3, total=X/21`
→ Course is finally scheduled in this term

### Key Insight

The system is **working as designed** if:
- GNED 14 and GNED 09 are scheduled in the final term because they're **low-priority filler courses**
- All higher-priority technical courses are scheduled first
- Prerequisites are satisfied for the final term placement
- No capacity existed in earlier terms

The system has a **potential issue** if:
- GNED courses should appear earlier (check curriculum definition)
- Prerequisites are blocking them unnecessarily (check prerequisite data)
- Earlier terms have excess capacity that GNED should fill (check prioritization logic)

---

**Run the diagnostics to determine which applies to your case!**
