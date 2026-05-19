# Quarantined tests

Tests in this directory are intentionally **outside the PHPUnit test suite**
because the code they exercise (e.g. `app/Libraries/AbiSageIntacct/`) does
not currently exist in the repository.

They are preserved here as documentation of intended behaviour so that when
the corresponding library lands they can be moved back into `tests/Unit/`
without having to be re-derived.

To re-enable a quarantined suite:

1. Restore the corresponding source code under `app/Libraries/`.
2. `git mv tests/_skipped/AbiSageIntacct tests/Unit/Libraries/AbiSageIntacct`
3. `composer test` to verify the suite passes.

`phpunit.xml.dist` deliberately does **not** scan `tests/_skipped/`.
