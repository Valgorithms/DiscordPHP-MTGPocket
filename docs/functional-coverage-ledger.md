# Functional coverage evidence ledger

This ledger makes the existing 100% functionality goal measurable against the pinned **28,729 newest-printing card names** in 181 imported pools, MTGJSON `5.3.0+20261022`. It does not redefine the goal as parser recognition or a smaller supported deck. A card name is the unit; all selected card data, including extra faces, contribute to the corpus fingerprint. It is not the complete MTGJSON database or a fresh Modern measurement.

## Baseline and acceptance

| Measure | Cards | Meaning |
| --- | ---: | --- |
| Pinned denominator | 28,729 | All selected names, including unread cards |
| Recognized whole | 11,282 | No unread parser clauses in the saved recognition audit |
| Has unread text | 17,447 | Complement of recognized names, not a gameplay verdict |
| Recognized without a whole-card certificate | 11,282 | Recognition does not clear the functional review |
| Named cards with scoped regression evidence | 7 | Three departure exemplars, two exact-printing death observers, explicit Barter in Blood player choices, and Mayhem Devil sacrifice callbacks |
| Named cards with an explicit unsupported gap | 1 | One unread Partner exemplar; the specific Barter player-choice gap is closed |
| Intersection of the previous two rows | 0 | Closing one specific observer gap does not certify its other interactions |
| Recorded whole-card certificates | 0 | No whole-card reviews have been issued |
| Remaining whole-card reviews | 28,729 | The initial certification backlog, not a count of broken cards |

The completion target is 28,729 reviewed whole-card certificates against this pinned inventory. **Zero recorded certificates does not mean zero cards function.** No functional percentage is inferred. Schema 1 intentionally cannot issue whole-card certificates from partial tests. A future certification record needs a reviewed rules/mode scope, every printed face and ability, targeting/payment/timing/control restrictions, relevant interactions and negative controls, save/resume, and exact source/test evidence. Scope exclusions must remain explicit; narrowing the goal requires a separate decision. Shared parser shape, unrelated passing tests, games finishing and engine bug fixes cannot substitute for those reviews.

The named evidence records are a deliberately non-exhaustive index of recent regressions, not a count of all tested cards in the repository. The original three departure exemplars use card-name wording or abbreviated reminders; the two destroy-all observers use exact selected printings. The ledger records those limits. Synthetic fixtures are not attributed to additional corpus cards. The remaining explicit gap is a reviewed seed, not an exhaustive unsupported-card list. Other known gaps include continuous subtype/ability characteristics, source conditions after movement, replacement ordering, APNAP decisions and heuristic type/color/devour choices.

## Reproduce without live effects

```powershell
# Use a verified PHP executable on Windows. Pools must already exist.
& 'C:/php/php.exe' -d xdebug.mode=off -d memory_limit=512M bin/functional-coverage.php var/audit-data/pools > var/functional-coverage.json
# Equivalent Composer command; optional second/third arguments select report/manifest files.
composer functional-coverage -- var/audit-data/pools
```

The CLI reads explicit pool paths, the versioned recognition index and evidence manifest. It loads no `.env`, database, bot, parser or game; it performs no import, download, simulation or network call. Pools are decoded one at a time, selecting the newest release date then highest set code per name. Every selected name appears in the JSON output with separate recognition, scoped evidence, explicit gap and null certificate fields. Wrong pool contents, changed recognition bytes and changed source/test pins fail before any ledger is printed. Source hashes normalize CRLF; the recognition index has enforced LF checkout bytes.

The versioned [recognition index](../config/coverage-recognition-2026-10-22.json) extracts corpus, counts and all 11,282 recognized names from the original saved report without reparsing. It deliberately omits the 20,353 grouped unread patterns: their three-example lists cannot reconstruct every unread card or certify feature coverage. The complement of the full selected inventory supplies all unread names instead. The [manifest](../config/coverage-evidence.json) records precise evidence scopes, limits, source pins and next-family acceptance.

Provenance:

- Original full report SHA-256: `a7b07773beb24532c66f282d65ac907968d6cd83f2dc8cb978457d76092e20ad`.
- Compact index SHA-256: `c7f8e9086eb85757aa4f70b121ed3659c9c30053776ea74a09cd90f2ce1a5d39`.
- Selected corpus SHA-256: `5583556fcc237fcaa5ebbf03c598644c4657773bd3bd415acb176ef779ed9fe1`.
- Recognition source: `65134ebb3a748e9ee23ae3c188b1411a52023440`. `TextParser.php` and `CardDefinition.php` Git blobs are unchanged through merged engine source `e40c470d74c7f9da5f798e9d3f6635d1b6f6bad4`.
- Historical behavior evidence: exact PR339 head `9f2f5670b533283130ab29f8a94492964eaf7dd1`, [CI 39970148365](https://github.com/Valgorithms/DiscordPHP-MTGPocket/actions/runs/39970148365), PHP 8.3/8.4/8.5. Local full suite: 777 tests, 32,292 assertions, two expected imported-data skips, exit 0. Ledger generation does not rerun these behaviors.

The source pins cover all fourteen current files in the Game namespace, including departure and death-batch snapshots, plus the cited regression tests and Discord/practice choice companions. The destroy-all, state-based and sacrifice death tests also pin their complete selected-printing JSON fixtures. Updating a pinned engine or evidence source intentionally invalidates its pin. Review affected claims, rerun meaningful checks and update provenance before accepting new pins; never refresh hashes solely to silence a failing test. Current evidence and its precise limits are recorded in the [destroy-all](simultaneous-death-observers.md), [state-based group](state-based-death-observers.md) and [sacrifice group](simultaneous-sacrifice-observers.md) milestone notes and manifest validation fields. This milestone introduces no environment variables or runtime configuration, so `env.example` has no new entries.

## Next measured family

The [resumable edict milestone](resumable-edict-choices.md) closes the exact Barter in Blood heuristic-choice gap with bounded engine, persistence, practice and Discord-panel tests. It adds Barter as the sixth scoped-evidence name and leaves one explicit gap (Akroma), zero overlap and zero whole-card certificates. Recognition counts and corpus fingerprint are unchanged.

**One seed: Mayhem Devil and sacrifice-specific observer lookback.** The exact WAR record says a player sacrificing a permanent triggers damage. That callback path still reads live watcher objects after movement; death notifications now use a separate pre-event snapshot. Investigate the sacrifice callback with real wording, paid sacrifices, both source orders, prior controller, target decisions, non-sacrifice controls and JSON replay. This is a source-backed investigation, not a reproduced failure or an exhaustive affected-card count. Sacrifice costs, replacement choices and general APNAP trigger ordering remain separate.

## Verification

`FunctionalCoverageLedgerTest` covers stable newest-printing selection and back-face hashing, report/source/corpus drift, duplicate/unknown recognition, overlapping evidence/gaps, absent test pins, missing limitations, attempted certificate inflation and explicit-path CLI success/failure with temporary storage. The pinned index and source hashes are also checked in ordinary offline CI. The real 181-pool ledger regenerated in 0.832 seconds under a 30-second process-tree cap and 512 MiB PHP limit; all counts and the selected fingerprint above matched. This is inventory validation, not a fresh parser audit or gameplay simulation.
