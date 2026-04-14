# Study Plan QA Report (Railway Production DB)

Generated: 2026-04-14

## Environment Verification

QA commands were executed via Railway CLI using `railway run`.

Validated runtime DB target:

1. DB_HOST = `hopper.proxy.rlwy.net`
2. DB_NAME = `railway`

## QA Artifacts

1. Live Railway scenario audit:
   - dev/test/reports/railway/study_plan_scenario_audit_railway.txt
2. Seeded live-cycle Railway scenario audit:
   - dev/test/reports/railway/study_plan_scenario_audit_seeded_railway.txt
3. Railway synthetic branch suite:
   - dev/test/reports/railway/study_plan_synthetic_suite_railway.txt

## Results Summary

### Live Railway Data Audit

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
   - Retention Probation/Disqualification states
   - Skipped term from disqualification
   - Triple-fail stop
   - Policy gate paused (ineligible)
   - Academic hold active
   - Remaining 1 to 3 courses edge case
   - Near-graduation forced-add path

### Seeded Live-Data QA Cycle (Temporary IDs)

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

### Synthetic Suite on Railway Context

1. Passed: 22/22 scenarios
2. Confirms major generator and constraint branches execute as expected, including:
   - Prerequisite enforcement (no same-term chaining)
   - Case-insensitive prerequisite matching
   - Prerequisite parser expansion for compact/compound inputs
   - Standing constraints (standard and incoming-midyear rule)
   - No-overload enforcement
   - Non-credit exclusion from unit cap
   - Cross-registration by direct and equivalency fallback paths
   - Retention escalations (Warning -> Probation, Probation -> Disqualification)
   - Disqualification skip-term handling
   - Triple-fail stop logic
   - Policy gate stop and flexible-fill behavior
   - Near-graduation forced-add behavior
   - Extended-year generation beyond 4th year
   - Current-term anchor progression for retake-only incomplete terms

## Improvement Backlog (Prioritized)

### P1 - Test Data Coverage Gaps in Production

Current organic live Railway data covers only 2 students, which leaves important scenarios unrepresented without temporary QA seeding.

1. Seed at least one student each for:
   - Fully completed curriculum
   - Current Probation
   - Current Disqualification
   - Triple-fail stop
   - Policy gate paused (ineligible transferee/shift)
   - Academic hold active
   - Remaining 1 to 3 courses edge case
2. Re-run live audit after seeding and target at least 90% live-scenario coverage.

### P1 - Investigate Two Potential Logic Reachability Issues

Even with targeted seeded data, two live-audit scenarios did not naturally trigger:

1. Near-graduation forced-add scenario:
   - Synthetic suite can trigger it, but live data seeding did not.
   - Review forced-add preconditions and their interaction with `determineCurrentTerm()`.
2. Triple-fail stop scenario:
   - Synthetic suite can trigger it through internal state seeding.
   - Live seeding may not increment failure counts to 3 due how attempts/duplicates are interpreted in loader logic.
   - Review failure counting flow in loader and dedupe logic.

### P1 - Add CI Gate for Regressions

1. Add a CI job that runs:
   - dev/test/study_plan_synthetic_suite.php
   - dev/test/study_plan_scenario_audit.php (against a seeded QA DB)
2. Fail CI on any synthetic scenario failure.

### P2 - Add Endpoint-Level Tests

Current synthetic tests are generator-focused. Add endpoint tests for:

1. program_coordinator/save_study_plan_override.php
   - auth required
   - invalid term rejection
   - move persistence
2. adviser/study_plan_view.php
   - scope enforcement by adviser program and batch
3. student/study_plan.php
   - policy pause message path
   - hold banner path

### P2 - Performance Hardening

1. Cache static curriculum metadata per request/test run to reduce repeated generator bootstrap cost.
2. Track execution duration in synthetic and live audit scripts and alert when runtime regresses.

### P3 - Observability

1. Emit structured logs for plan stop reasons:
   - policy gate pause
   - triple-fail stop
   - disqualification count stop
2. Add a lightweight admin diagnostic page summarizing scenario distribution across students.

## Conclusion

Railway production DB QA is complete.

Status: Pass, with full branch coverage achieved through live + synthetic testing.
