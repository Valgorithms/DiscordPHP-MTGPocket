# Saved-selector compatibility probes

These offline developer checks exercise actual immutable engine readers and writers,
not copies of their version guards. They do not bootstrap the bot, load `.env`,
import cards, contact services, or rebuild binaries. Run each PHP command separately;
historical and current `Game` classes cannot coexist in one process.

Run from the repository root with existing offline dependencies. Use verified PHP
and Python executable paths on Windows. Resource holds still apply; preparing the
scripts does not authorize execution. Use a process-tree timeout for each check.

1. Run `bin/selector-compatibility/prepare-selector-readers.py` with Python. It
   extracts `Game.php` and `TextParser.php` byte for byte from pinned main
   `710a47cde58525c24d59fb591ef81b6d63ee945d` and PR #331
   `3f309578ebc35f17ae9321f969a67fe4e14db4ab`. It records Git blobs and SHA-256
   hashes in ignored `var/selector-compatibility/selector-readers/manifest.json`.
   Missing Git objects fail preparation; no fetch or download is performed.
2. Run `php bin/selector-compatibility/make-selector-fixture.php current` to
   save a current schema-4 game with the union selector pending on the stack.
3. Run `php bin/selector-compatibility/probe-selector-reader.php main`, then
   the same command with `pr331`. Each checks the extracted source hashes and
   requires the actual old engine to reject the new record with the expected
   schema error. Accepting or misinterpreting the record fails the check.
4. Run `php bin/selector-compatibility/make-selector-fixture.php main`, then
   the same command with `pr331`. Each actual historical writer saves a
   schema-3 game containing pending damage and a scheduled upkeep draw.
5. Run `php bin/selector-compatibility/probe-selector-upgrade.php main`, then
   the same command with `pr331`. The new engine must preserve each old saved
   record except for its schema label, deal damage once, and draw once at the
   next upkeep. The transition loop is capped at 100 passes.

Outputs and fixtures stay under ignored `var/selector-compatibility/`. Preserve
their hashes, command results, source head and applicable resource clearance in
the task's evidence. Original task-local probes are retained for comparison.
These checks establish only the tested persistence/selector behavior. They do
not certify whole-card functionality, simultaneous death triggers, live Discord
integration, or compatibility with arbitrary historical engine versions.
