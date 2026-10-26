# Den Protector combat power boundaries

This test-only milestone verifies the existing blocking restriction on the complete DTK Den Protector printing. No engine defect was reproduced and production code is unchanged.

The existing fixture `tests/Fixtures/den-protector-megamorph.json` pins UUID `e0c322a9-4486-5282-9d62-4a1bf6d49453` and normalized-LF SHA-256 `d35324e93fdf9a45b288f238e9db4ddf801857cd8870a6a6032ffaf8c90f81b7`. Its complete text parses without unread clauses; that recognition alone is not functional certification.

Twenty cases (468 assertions) cover both seats, paid face-up/morph/megamorph casting and synthetic copying, real turn progression, weaker/equal/greater blockers, current power after paid Giant Growth on either creature, turn-up before blocking and after a block is declared, invalid-declaration state preservation, damage/player-life outcomes and JSON replay before/after declarations. Face-down and blank-copy controls lack the printed restriction. An existing legal block remains after a later turn-up. Supporting blockers are simplified seeded units and copying uses the existing synthetic effect.

The focused suite passed in 0.280 seconds/32 MiB; adjacent combat/copy/parser/ledger checks passed 123 tests/5,324 assertions in 6.395 seconds/44 MiB (6.681 seconds wall). Each used a 30-second process-tree cap, 512 MiB, Xdebug off, empty data and task-local temp. Nonmutating single-file Pint and diff checking passed. The initial test harness incorrectly accessed a nonexistent player battlefield property; it was corrected to `Game::permanents()` before recording passing results.

A negative control temporarily disabled only the lesser-power blocking restriction. Six restricted-form cases failed among ten cases/202 assertions; four face-down/blank-copy controls still passed. Production bytes were restored exactly and all twenty focused cases passed again. This detects removal of the rule; it does not establish a preexisting runtime bug.

The prior unchanged runtime full run at `da028e54019dd800b4ac5aa095da43b96a4d7d29` passed 1,651 tests/50,739 assertions with two expected imported-rental-data skips in 65.660 seconds/222 MiB. It excludes these new twenty cases. Final exact-head CI must run the enlarged suite before merge.

Ledger validation passed eighteen tests/37 assertions; the 181-pool inventory completed in 0.822 seconds wall. The ledger retains twenty unique scoped names, three explicit-gap names, two overlaps and zero whole-card certificates. Overlap means scoped evidence plus an explicit gap, not multiple evidence entries for one name. Full blocking/continuous-layer/graveyard/general-copy behavior and whole-card certification remain unverified. No environment, schema, UI or practice behavior changes.

Dog Walker copied-ward behavior remains deferred: bounded local evidence lacks authoritative cached rules, and the task retains its no-network restriction. No imports, live bots, builds, restarts or deployments.
