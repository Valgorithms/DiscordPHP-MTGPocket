# Soul Stair Expedition graveyard returns

PR #406 uses one complete existing ZEN record, UUID `22b41c42-6a08-5efd-9959-b125f48535cf`, from cached MTGJSON `5.3.0+20261022`. The normalized fixture SHA-256 is `61a718050f3e11e158c08640c538218d38dda28135337bedb0d44d11959e231f`. Nothing was fetched or imported.

Both actors pay the actual black casting cost, play three lands and accept three quest counters. Removing those counters and sacrificing the enchantment pays for returning zero, one or two different creature cards from their own graveyard. The ordinary two-card path already passed: 2 tests, 32 assertions at `00262fe`. The initial unavailable Memnite test-catalog entry was a harness error and is excluded from runtime findings.

The paid negative control at `b6287c5` failed in both seats: the same graveyard creature could be selected twice and the game spent the quest counters and sacrifice cost. That is the runtime defect addressed here. Repeated target slots from one occurrence of “target” now retain their clause span. Casts, activated abilities and targeted triggers reject duplicate identities within that span before any cost or pending-trigger mutation. Raw, incarnation-pinned and zero-padded references cannot select the same object twice. Separate clauses and selected modes can legally reuse an object. Support and tap/untap expansions use the same mechanism. Practice target selection respects the same spans, including its forced fallback, and still permits reuse across separate clauses.

Focused source `636dec8` passed 28 tests and 338 assertions in 0.222 seconds/38 MiB. Earlier adjacent source `a55fc5d` passed 492 tests and 9,439 assertions in 28.986 seconds/92 MiB, covering the new family, Khalni and existing modern/common/card-text behavior. Runs were serial with PHP 512 MiB, Xdebug off, isolated data and task-local temporary storage under a 30-second process-tree cap. Full offline PHPUnit and exact final hosted full Pint/PHPUnit on PHP 8.3, 8.4 and 8.5 remain pending at creation of these notes.

Coverage includes atomic rejection of duplicate/wrong-seat/wrong-type/wrong-zone/wrong-count/undercharged actions, zero/one/two choices, repeated activation, partial and all-invalid targets, original-incarnation targeting and deterministic queued JSON replay. Synthetic clause controls cover paid casts and activations, pending-trigger save/load, modal offsets, support and practice policy. Zone relocation and seeded grave creatures isolate engine contracts; they are not proof of enabling spells or whole supporting cards.

Existing queued abilities serialized before clause metadata retain their historical semantics. New announcements use freshly parsed clause groups; no new save schema or environment contract is introduced, so `env.example` needs no new entries. The metadata is not authentication of arbitrary forged saves. Broader mixed-verb target grammar, unrestricted any-number counts (historically approximated as up to three), general copy/replacement/APNAP/continuous rules and Discord panel rendering remain outside this slice.

The ledger now contains 31 scoped names, two Partner-gap names, one overlap and zero whole-card certificates. Recognition counts remain historical; this is not 100% functional coverage. No corpus scan, live service, bot, Gaia, build, restart or deployment was performed.

## Resumed verification, 2026-10-26

Full offline PHPUnit at `86dd45fd6069a1382381095bd160c57b15acfdcc` passed: 2,079 tests, 57,860 assertions, two expected missing-imported-pool/rental skips; 63.716s PHPUnit/280MiB, 64.29s wall under a 120s process-tree cap. Focused checks passed 48 tests/391 assertions in 0.576s/36MiB. Five-file nonmutating Pint passed. PHP8.5.12, 512MiB, Xdebug off, isolated data and task-local temp. All 60 normalized PHP pins verified. No new parser recognition scan or whole-card certificates. Exact final hosted matrix remains pending.

The additional synthetic cleave control changes which text supplies target groups and verifies duplicate rejection before payment plus distinct-target resolution in both seats. It passed without a production change. The six paused local commits are preserved; the existing draft PR remains the review vehicle. Main advanced independently and was not merged into this slice.

## Pinned conflict integration, 2026-10-26

Main review explicitly authorized resolving the single coverage-evidence conflict. Merge `51bf5132f5313959d3deead373f094f902739417` has parents `d258380aec4a80d6a9e29e4ccbd1453266fd59d3` and `d0431f1d834ba9365c159c9e341d9ac33905a903`; no published history was rewritten. The resolution retains all PR scoped evidence and older validation, records main's three validation-provenance changes and five source-pin changes with before/after values, and recalculates all60current pins from the combined tree. Game/TextParser auto-merged; the conflict resolution changes metadata only.

Integrated offline full suite at `51bf5132f5313959d3deead373f094f902739417` passed 2,105 tests/57,760 assertions with 2 expected missing-data skips; PHPUnit 00:54.239, 296.00 MB, 54.488s wall/120s cap. Focused ledger/Soul Stair/clause/Khalni schema checks passed 66 tests/1,041 assertions; full nonmutating Pint passed. PHP8.5.12,512MiB,Xdebugoff,isolated data/temp. All60normalizedPHPpins verified. Exact final hosted PHP8.3/8.4/8.5 matrix remains pending.

Recognition remains historical11,282/28,729, scoped gameplay31names, Partner gaps2/overlap1, whole-card certificates0. No mechanics family was broadened. Original pre-integration results above remain historical.
