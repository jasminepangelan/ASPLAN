# Study Plan QA Report (Railway Production DB)

Generated: 2026-04-20

## Environment Verification

QA commands were executed against the Railway-hosted database context.

Validated runtime DB target:

1. DB_HOST = `hopper.proxy.rlwy.net`
2. DB_NAME = `railway`

## QA Artifacts

1. Live Railway scenario audit:
   `dev/test/reports/railway/study_plan_scenario_audit_railway.txt`
2. Seeded live-cycle Railway scenario audit:
   `dev/test/reports/railway/study_plan_scenario_audit_seeded_railway.txt`
3. Railway synthetic branch suite:
   `dev/test/reports/railway/study_plan_synthetic_suite_railway.txt`

## Results Summary

## Live Railway Data Audit

1. Students processed: 2
2. Errors: 0
3. Covered in live Railway records:
   - Plan generation
   - Irregular optimization path
   - Historical partial-term handling
   - Retake tag path
   - Cross-registration and source labeling
   - Retention Warning history and current Warning
   - Policy gate applies + eligible
   - Extended term beyond 4th year
4. Not covered in current Railway live data:
   - Fully completed curriculum
   - Retention Probation and Disqualification states
   - Skipped term from disqualification
   - Triple-fail stop
   - Policy gate paused (ineligible)
   - Academic hold active
   - Remaining 1 to 3 courses edge case
   - Near-graduation forced-add path

## Seeded Live-Data QA Cycle (Temporary IDs)

Temporary QA IDs `91010001` to `91010007` were inserted, audited, and then removed.

Coverage result from seeded live audit:

1. Students processed: 9
2. Newly covered with seeded records:
   - Regular exact-curriculum path candidate
   - No completed history candidate
   - Fully completed curriculum
   - Current Probation and Disqualification statuses
   - Retention history with Probation and Disqualification
   - Skipped term from Disqualification
   - Policy gate paused (ineligible)
   - Academic hold active
   - No future plan but completed terms exist
   - Remaining 1 to 3 courses edge case
3. Still not covered even after seeded live data:
   - Near-graduation forced-add used
   - Plan stopped due to 3+ failed attempts
4. Cleanup verification:
   - `qa_seed_students=0` after cleanup

## Synthetic Suite on Railway Context

1. Passed: `26/26` scenarios
2. Confirms major generator and constraint branches execute as expected, including:
   - Prerequisite enforcement with no same-term chaining
   - Case-insensitive prerequisite matching
   - Prerequisite parser expansion for compact and compound inputs
   - Standing constraints, including incoming-midyear rule handling
   - No-overload enforcement
   - Non-credit exclusion from unit cap
   - Cross-registration by direct and equivalency fallback paths
   - Retention escalations from Warning to Probation and Probation to Disqualification
   - Disqualification skip-term handling
   - Triple-fail stop logic
   - Policy gate stop and flexible-fill behavior
   - Near-graduation forced-add behavior
   - Extended-year generation beyond 4th year
   - Current-term anchor progression for retake-only incomplete terms
   - Greedy preference for lower-year retakes
   - Greedy preference for the current semester during flexible irregular fill

## What the Latest QA Confirms

The current generator is not only functionally working. It now also reflects the intended optimization logic more accurately:

1. Lower-year retakes are prioritized ahead of less urgent future choices.
2. Flexible irregular fill still respects the semester currently being planned.
3. The exact-curriculum shortcut does not incorrectly absorb policy-gated, retention-affected, or thrice-failed cases.
4. Extra projected terms inherit realistic unit limits from the matching semester reference term.
5. Retake-only incomplete last terms no longer trap the planner in a historical term anchor.

## Remaining Improvement Backlog

### P1 - Expand organic live-data coverage

Current organic live Railway data still represents only a small number of naturally occurring student states.

1. Seed at least one student each for:
   - Fully completed curriculum
   - Current Probation
   - Current Disqualification
   - Triple-fail stop
   - Policy gate paused (ineligible transferee or shiftee)
   - Academic hold active
   - Remaining 1 to 3 courses edge case
2. Re-run the live audit and target at least 90 percent live-scenario coverage.

### P1 - Add CI regression gating

1. Add a CI job that runs:
   - `dev/test/study_plan_synthetic_suite.php`
   - `dev/test/study_plan_scenario_audit.php` against a seeded QA database
2. Fail CI on any synthetic or audit regression.

### P2 - Add endpoint-level tests

Current synthetic tests are generator-focused. Add endpoint tests for:

1. `program_coordinator/save_study_plan_override.php`
   - auth required
   - invalid term rejection
   - move persistence
2. `adviser/study_plan_view.php`
   - scope enforcement by adviser program and batch
3. `student/study_plan.php`
   - policy pause message path
   - hold banner path

### P2 - Performance hardening

1. Cache static curriculum metadata per request or test run to reduce repeated generator bootstrap cost.
2. Track execution duration in synthetic and live audit scripts and alert when runtime regresses.

### P3 - Observability

1. Emit structured logs for plan stop reasons:
   - policy gate pause
   - triple-fail stop
   - disqualification count stop
2. Add a lightweight admin diagnostic page summarizing scenario distribution across students.

## Conclusion

Railway-backed QA currently passes with full synthetic coverage for the intended planner branches.

Status: Pass, with current synthetic suite result at `26/26`.
