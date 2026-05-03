# Study Plan Verification

Use this checklist to talk about study-plan quality with evidence instead of guesses.

## What "optimized" should mean here

A study plan is only defensibly "optimized" if all of these are true:

- It generates without errors for real students.
- It handles regular, irregular, retake, cross-registration, and policy-gated cases.
- Its response time stays within a reasonable target on your real database.
- Its runtime is stable across repeated runs.
- It does not regress after code changes.

Code structure alone is not enough.

## Safe commands

Run the benchmark:

```bash
php dev/test/study_plan_benchmark.php --limit=10 --repeat=3
```

Run the scenario audit:

```bash
php dev/test/study_plan_scenario_audit.php --limit=25
```

Run the synthetic suite:

```bash
php dev/test/study_plan_synthetic_suite.php
```

Benchmark one student only:

```bash
php dev/test/study_plan_benchmark.php --student=220100031 --repeat=5 --target-ms=1500
```

## How to interpret the benchmark

The benchmark reports:

- average generation time
- median generation time
- max generation time
- average memory delta
- per-student status against a target threshold

Good signs:

- `Errors: 0`
- average and median times are comfortably below your target
- max time is not wildly above average
- repeated runs stay consistent

Warning signs:

- any benchmark error
- large spikes in max time
- repeated runs drifting upward
- only one student case being tested

## How to interpret the scenario audit

The scenario audit checks whether real student data covers important planning paths such as:

- exact curriculum path
- irregular path
- retakes
- cross-registration
- retention states
- policy gate pause
- academic hold
- extended plan beyond 4th year

If key scenarios are not covered, you do not yet have strong evidence for optimization correctness on live data.

## Suggested review-ready evidence pack

For an IT Expert review, keep these:

- latest benchmark report from `dev/test/reports/`
- latest scenario audit report from `dev/test/reports/`
- a short note describing the student sample size and target threshold used
- any before/after comparison if you changed the generator

## Honest wording for the review

Use wording like this:

"The study-plan engine was verified through repeatable benchmark and scenario-audit scripts. We validated syntax, multi-scenario behavior, and measured runtime on real student records instead of relying only on manual checks."

That is stronger than simply saying "it is optimized."
