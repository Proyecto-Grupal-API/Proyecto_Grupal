# Test execution and profiling

All integration runs use the forced `campus_virtual_testing` target in
`phpunit.xml` and the destructive-operation guard in `Tests\TestCase`.
Do not run two integration suites simultaneously against that database.

- FAST: `composer test:fast` (the existing Unit suite, no integration substitute).
- FOCUSED: `composer test:focused -- tests/Feature/InitialPasswordLifecycleTest.php`
  (choose the existing files relevant to the change).
- FULL: `php artisan test`, or `composer test:full`. FULL remains required before delivery.

Use an existing temporary output directory for reproducible serial profiling:

```sh
INT1BP1_COMMAND_REPORT=/tmp/suite-commands.json /usr/bin/time -p php artisan test --bootstrap=tests/Profiling/bootstrap.php --log-junit=/tmp/suite.xml --log-events-verbose-text=/tmp/suite.events --display-all-issues
python3 tests/Profiling/report.py /tmp/suite.xml
```

The optional bootstrap records command names, collection names, counts and driver
durations, never payloads, results, errors or credentials. JUnit reports supply
per-test/class timings. The event log distinguishes preparation, execution and
teardown; driver durations overlap those phases and must not be added to them.
Review issue events as well as the exit code for skipped/incomplete/risky tests.
Artifacts are not committed. No additional dependencies are needed.

Only the seven explicitly listed integration classes in `tests/Pest.php` opt in
to `reusable-mongo-schema`. They reuse real migrated indexes within a class,
not application instances, seed data, users or sessions. Before each test the
helper compares collection options, every index definition and the migration
ledger, deletes all non-migration documents, and drops test-created collections.
A class boundary or schema/ledger drift rebuilds the database through the real
migrations. Existing per-test migration assertions still run.

Migration/index-creation tests, foundation/backfill tests and all other classes
keep the original physical database reset. Do not opt a class into reuse if its
purpose is to prove schema creation or alteration from scratch. Keep the schema
isolation and database-guard tests when changing this infrastructure. Validate
critical files in reverse/random order in addition to the full serial suite.

ParaTest is installed but not adopted: parallel execution requires a separate,
guarded MongoDB database per worker; the present guard intentionally permits
only the single testing database.
