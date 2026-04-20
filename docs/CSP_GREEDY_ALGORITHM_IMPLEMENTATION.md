# CSP and Greedy Algorithm Implementation
## Intelligent Study Plan Generator

### Overview
The study plan generator uses a two-layer decision model:

1. Constraint Satisfaction Problem (CSP) for non-negotiable academic rules
2. Greedy ranking for choosing the best eligible subjects for each term

This design is intentionally conservative. The greedy layer does not break university rules. It only ranks courses after the CSP layer has already filtered the course pool to subjects that are valid for the student.

---

## Core Design Principle

### CSP handles correctness
The CSP layer answers this question:

"Which courses is the student allowed to take right now under the university rules?"

Only courses that satisfy the hard constraints are allowed to move forward into planning.

### Greedy handles optimization
The greedy layer answers this question:

"Among the allowed courses, which ones should be chosen first so the student can progress faster with less delay risk?"

Because the greedy step works only on already valid options, the system stays compliant while still making practical scheduling decisions.

---

## Hard Constraints Kept in the System

The current implementation keeps the applied university constraints in place. The generator does not relax these rules.

### 1. Prerequisite completion
- A course is only eligible if all required prerequisites are already completed.
- Same-term shortcutting is not allowed in normal planning.
- Case-insensitive prerequisite matching is supported so historical encoding differences do not invalidate a correct prerequisite chain.

### 2. Semester offering
- Courses are matched to their proper semester offering: `1st Sem`, `2nd Sem`, or `Mid Year`.
- A course that is not offered in the current planning semester is normally excluded unless a valid cross-registration path exists.

### 3. Already completed subjects
- Passed courses are filtered out from the remaining pool.
- Active failed, incomplete, and dropped subjects are treated separately so the student is not incorrectly considered fully clear for that term.

### 4. Standing and progression rules
- Standing constraints are checked before a course can be considered available.
- The system still respects regular term ordering, backlog handling, and progression structure from the checklist.

### 5. Retention policy rules
- Warning, Probation, and Disqualification states still affect planning.
- Probation and Disqualification reduce allowable load to the retention limit.
- Disqualification skip-term handling remains enforced.

### 6. Triple-fail and stop conditions
- Generation stops when the stop-rule conditions are reached.
- The optimizer does not bypass this safeguard.

### 7. Policy gate for transferees and shiftees
- The policy gate remains active.
- Flexible irregular fill is only allowed when the policy gate both applies and is eligible.
- If not eligible, the generator does not force an irregular fill.

### 8. Unit cap enforcement
- The selected subjects must fit inside the maximum load for the term.
- Non-credit overloading is not used to inflate the term.
- Extra terms inherit the correct reference unit cap for the same semester label.

---

## Algorithm Architecture

## 1. CSP Phase

### Purpose
Filter the remaining curriculum into a valid pool of eligible courses for the target term.

### Practical effect
If a course violates even one hard rule, it is not handed to the greedy selector.

### Simplified flow
```php
private function applyConstraintsForSimulation($target_semester, $simulated_completed, $simulated_all_courses) {
    foreach ($simulated_all_courses as $code => $course) {
        if ($course['completed']) {
            continue;
        }

        if ($target_semester !== null && $course['semester'] !== $target_semester) {
            continue;
        }

        if (!$this->prerequisitesSatisfiedForCompletedSet($code, $simulated_completed)) {
            continue;
        }

        $available[$code] = $course;
    }

    return $available;
}
```

---

## 2. Greedy Phase

### Purpose
Rank eligible courses so the term is filled with the most strategically useful subjects first.

### Why greedy is appropriate here
This is a term-building problem with repeated local decisions under a strict unit limit. Once the legal course set is known, the system must choose a subset that improves near-term progress and reduces future blockage. A weighted greedy ranking is a practical fit because it is:

- explainable to advisers and panel members
- fast enough for live generation
- flexible enough to reflect academic priorities
- safe because it operates only after CSP filtering

---

## Current Greedy Scoring Logic

The current implementation in `student/generate_study_plan.php` does not use a single simple weight anymore. It blends several signals.

### 1. Retake and backlog priority
- Retake courses receive the strongest bonus.
- Lower-year retakes are ranked above higher-year retakes.
- This reduces cascading delay because unresolved early subjects often block many later subjects.

### 2. Earlier curriculum terms first
- Courses from earlier curriculum terms get a higher score.
- This helps clear backlog in chronological order instead of jumping too far ahead.

### 3. Current target-term awareness
- If the generator is currently planning a specific term, subjects that belong exactly to that year and semester get a meaningful boost.
- Earlier backlog is still allowed to outrank future-term subjects.
- Future-term subjects receive a penalty so flexible fill does not become too aggressive.

### 4. Prerequisite-gated course bonus
- Courses with prerequisites get an additional boost.
- Courses with more prerequisite burden receive a slightly larger bonus.
- This helps the planner prioritize structurally important courses that are harder to schedule later.

### 5. Dependent-chain priority
- The system counts downstream subjects that depend on a course.
- Courses on a longer dependency chain get ranked higher.
- This is the main "critical path" logic because unlocking one course may unlock many later courses.

### 6. Same-year alignment
- Courses matching the currently planned year level get an extra score.
- This keeps the recommendation aligned with the student's expected curriculum position when possible.

### 7. Same-semester preference under flexible fill
- When flexible irregular fill is active, the generator still prefers the semester currently being planned.
- This prevents unnecessary forward-reaching when same-semester valid options already exist.

### 8. Unit contribution
- Higher-unit courses get a small bonus.
- This helps the plan make efficient progress without letting unit size dominate the decision.

### 9. Lower-year progression preference
- Lower-year courses still receive a progression bonus.
- This keeps the student from carrying foundational backlog for too long.

### 10. Small first-semester preference
- `1st Sem` gets a minor tie-break preference.
- This preserves chronological order when scores are otherwise close.

### 11. Cross-registration support, not dominance
- Valid cross-registration can help a course move up.
- Retake cross-registration gets a stronger bonus than ordinary cross-registration.
- The bonus is intentionally smaller than retake, backlog, and dependent-chain signals so cross-registration helps but does not override core academic logic.

### Simplified scoring excerpt
```php
private function prioritizeCourses($courses, $target_year = null, $simulated_completed = [], $target_semester = null) {
    foreach ($courses as $course_code => $course) {
        $score = 0;

        if (!empty($course['needs_retake'])) {
            $score += 260;
        }

        $score += max(0, 135 - ($term_order * 8));
        $score += $dependent_count * 15;
        $score += $course['units'] * 2;

        if (!empty($course['cross_registered'])) {
            $score += !empty($course['needs_retake']) ? 28 : 6;
        }

        $priority_scores[$course_code] = $score;
    }
}
```

The exact formula contains additional year and semester adjustments, but the key point is this: the ranking favors courses that remove academic blockage earlier, especially retakes and critical-path subjects.

---

## What Was Improved in the Current Version

The latest tuning kept the existing university constraints but improved the greedy behavior.

### Improvement 1. Better current-term targeting
- The planner now prefers the exact semester currently being generated.
- This avoids overusing future-semester flexible fill when a same-semester valid option exists.

### Improvement 2. Stronger lower-year retake handling
- Lower-year retakes are now intentionally favored.
- This reflects the academic reality that unresolved early failures usually create more downstream blockage.

### Improvement 3. Smarter critical-path ranking
- Dependent-chain scoring is cached per ranking pass.
- This preserves the critical-path idea while reducing repeated recursion cost.

### Improvement 4. Softer cross-registration effect
- Cross-registration still helps when valid.
- It no longer dominates the ranking over more important factors such as retakes, backlog order, and critical prerequisites.

### Improvement 5. Better current-term anchoring
- If the latest recorded term is the last regular curriculum term and it is incomplete only because of retakes, the planner can now project into the next valid future term.
- This prevents the plan from appearing stuck in a historical term that has already passed.

### Improvement 6. More reliable exact-plan vs optimized-plan routing
- Students with no validated academic history can still receive the clean exact curriculum path.
- Students with policy-gate, retention, or thrice-failed conditions are kept in the optimization flow instead of accidentally falling into the regular exact-plan shortcut.

### Improvement 7. Better extra-term unit handling
- Extra terms now inherit the correct reference cap for the same semester label.
- Retake-only terms no longer distort the reference cap selection.

---

## Term Building Strategy

After ranking, the planner greedily adds courses until the unit cap is reached.

### Selection rule
- Start from the highest-ranked eligible course
- Add it if it still fits in the remaining allowed units
- Continue until no more ranked courses can be added without violating the cap

### Why this is still safe
- The cap is checked before each addition
- CSP has already removed invalid courses
- Standing and policy checks remain active

---

## Special Planning Paths

### Exact curriculum path
Used for brand-new or clean regular students with no active irregular issues. In this mode, the plan mirrors the checklist exactly.

### Flexible irregular fill
Used only when the policy gate applies and the student is eligible. In this mode, the generator may use other truly eligible remaining courses to fill an irregular term up to the valid cap.

### Near-graduation forced add
If only 1 to 3 late-stage courses remain, the planner can force-add them into the `4th Yr / 2nd Sem` output so the student does not get extended by one extra displayed term for a very small remainder.

### Extra-term projection
If standard curriculum terms are exhausted but valid remaining subjects still exist, the planner continues generating `5th Yr` and beyond using the real semester cycle and the inherited reference unit cap.

---

## Why the System Can Be Defended as "Optimized"

The generator is optimized in a controlled way, not in a rule-breaking way.

### It is optimized because:
- it prioritizes courses that unblock more future courses
- it clears earlier backlog before unnecessary future expansion
- it gives strong importance to retakes that delay graduation
- it fills each term up to the valid cap instead of underloading by default
- it supports valid cross-registration when that shortens completion time

### It is not "free-form optimization" because:
- it cannot schedule a course with unmet prerequisites
- it cannot exceed the valid term cap
- it cannot ignore retention, policy, or stop conditions
- it cannot replace the official curriculum structure with arbitrary ordering

This balance is the main reason the combined CSP + Greedy approach is appropriate for the university setting.

---

## Complexity

### Time complexity
- CSP filtering is linear across the remaining course set for a pass
- Greedy ranking is dominated by sorting, so it is approximately `O(n log n)` per planning term
- The overall plan generation remains efficient for real curriculum sizes

### Space complexity
- `O(n)` for course storage and planning state
- `O(n)` for prerequisite and dependency tracking

---

## Validation Status

The current generator behavior has been revalidated using the synthetic study plan suite.

### Current synthetic result
- Passed: `26/26` scenarios

### Included recent greedy-focused checks
- `greedy_prioritizes_lower_year_retake`
- `greedy_prefers_current_semester_under_flexible_fill`

These tests confirm that the latest greedy tuning behaves as intended while still preserving the existing constraint rules.

---

## Short Defense Summary

If you need a concise explanation for system testing, capstone defense, or panel questioning, this is the safest summary:

"The study plan generator uses CSP to enforce the university's hard academic constraints, then applies a greedy ranking only to the courses that are already valid. The greedy step is optimized to prioritize retakes, earlier backlog, and critical-path courses that unlock more downstream subjects, while still respecting prerequisite rules, semester offerings, retention policy, standing requirements, and term unit limits."

---

**Implementation Status:** Active  
**Latest QA-Aligned Revision:** 2026-04-20
