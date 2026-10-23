# Selector saved-state contract

Recovery verification completed on 2026-10-23 in the existing DiscordPHP coordinator. The Projects coordinator released the stale source-only deferral and authorized serial offline checks with process-tree timeouts. No live bot or deployment was used.

At `65134ebb`, `SelectorStateCompatibilityTest` passes 5 tests / 22 assertions; `MassRemovalSelectorTest` passes 9 / 87; adjacent delayed-draw and entry-choice tests pass 38 / 333. Seven separate historical writer/reader and upgrade processes pass. The initial assertion at `5e69c4b` compared pre-JSON `manaValue: 0.0` with decoded `0`; the corrected test compares the actual JSON-decoded persisted record while retaining strict equality. Production serialization did not change.

## Narrow runtime delta

The compatibility change sets `Game::STATE_VERSION` from 3 to 4, with a comment explaining the semantic compatibility boundary. `toArray()` already writes that constant. `fromArray()` already rejects a non-integer, out-of-range, or future version before constructing a game or hydrating its objects, stack, and pending decisions. Its version-1/version-2 defaults and version-3 delayed-draw requirements stay unchanged.

The new `all artifacts, creatures, and lands` selector can persist in an ability's effect array. An older version-3 `allMatching()` falls back to nonland permanents for that unknown selector. It could therefore destroy enchantments and planeswalkers while sparing lands. Keeping the version at 3 would permit that misinterpretation.

All saves from this engine write version 4, even before a new selector is used. This also protects cards that may produce the new contract later, without attempting to infer capability requirements from only the current stack. There are no new top-level fields, capability lists, conditional version labels, or serialized-data rewrites.

## Forward and backward compatibility

| Writer / saved record | Reader | Contract |
| --- | --- | --- |
| Unversioned or version 1–2 | New version-4 engine | Existing defaults/migrations remain; next save writes 4. |
| Valid version 3 | New version-4 engine | Preserve stack, choices, and delayed draws; next save writes 4. Missing or malformed delayed-draw state remains rejected. |
| Version 4 | Historical version-1/2/3 engine, including current main and PR #331 | Existing upper-version gate rejects before hydration; it must not resolve the unknown selector. Actual pinned main and PR #331 readers both rejected the schema-4 fixture. |
| Version 4 | New version-4 engine | Preserve the new selector, its pending stack/ability state, and deterministic resolution. |
| Version 5 or later | Version-4 engine | Existing future-version rejection remains. |

Do not manually lower a schema label. There is no supported downgrade or migration of a version-4 game back to an older engine. A process resuming version-4 matches must keep a compatible reader. This proposal does not validate every possible tampered effect array or repair the older reader's fallback.

PR #331 stays independent at `3f309578ebc35f17ae9321f969a67fe4e14db4ab`, with its existing version-3 behavior. This milestone does not edit that branch. Its +20 recognition result and this branch's independently measured +4 must remain separate until integration is tested.

## Focused verification

1. `SelectorStateCompatibilityTest` passed. Its five source cases cover unconditional version-4 output; a persisted union ability that destroys lands/creatures while preserving enchantments/planeswalkers; migration of a version-3 pending ordinary ability plus a delayed draw; missing delayed state in versions 3 and 4; and future-version rejection using a version-only record, before any game payload can be read.
2. Passed `DelayedUpkeepDrawTest` and `EntryLifeChoiceTest` for legacy defaults, strict delayed-state validation, resumable decisions, and future-version rejection. Two delayed-draw assertions now expect `Game::STATE_VERSION` instead of a fixed current value of 3. Explicit legacy version 3 remains exercised in the new compatibility test.
3. `MassRemovalSelectorTest` passed, including saved-stack replay, actual Culling Sun wording and regeneration, union deduplication, targeting/payment rejection, and countering controls.
4. Separate offline PHP processes exercised the actual immutable reader sources from main `710a47cde58525c24d59fb591ef81b6d63ee945d` and PR #331 `3f309578ebc35f17ae9321f969a67fe4e14db4ab`. Extract byte-exact `Game.php` and `TextParser.php` snapshots using Git and record their blob hashes. Each historical process loaded a newly generated version-4 pending-union fixture and threw `GameException` with the schema-version rejection before interpreting effects. The processes used existing offline dependencies and actual readers, with no copied guard or mock reader. No process loaded two `Game` class definitions.
5. Commands, exact source commit, reader hashes and rejection outputs are retained in ignored recovery evidence. Pint on all eight changed PHP files passed in 4.928 seconds with process-local `-d xdebug.mode=off`; local Xdebug develop mode caused the earlier 30-second formatter timeouts. No machine PHP configuration was changed. Modern recognition measurement and the offline suite are tracked in the selector checkpoint.

## Historical source preparation

Byte-exact reader snapshots have been extracted from the existing local Git objects, without a network fetch or PHP execution. Source identity and both compatibility directions are verified for the fixtures below.

| Reader | File | Git blob | SHA-256 |
| --- | --- | --- | --- |
| Main `710a47c` | `Game.php` | `a90195117e65f781ae84ee37e731e5d7803ec3c1` | `b4cd302f8754c99145f75f411d523b59d96aef0749ffcb808625ca559ccc95a1` |
| Main `710a47c` | `TextParser.php` | `47a86286564668728df93ebac257ed8209b8f7bb` | `90b5a88c1c4efd6d3e4f0721a3833cef9daf56132f0ed00400871860b5edf28e` |
| PR #331 `3f30957` | `Game.php` | `aaae2f3795c30235fc28805cf2e858c842c57140` | `276a4b5358b5be9bacb8571f397371c5ee4c89603626980d445c772a962c6cd6` |
| PR #331 `3f30957` | `TextParser.php` | `4af5ddf1737f300c63d6c00accfc3ac179ea4e1a` | `c97fb9cad705da177e419d1a3c1292d0221ab8f229d4662f2f457dcd5eb47ba7` |

The versioned offline probes generated a schema-4 pending union-ability fixture; both actual old readers rejected it before hydration. Each historical writer generated a schema-3 fixture with pending damage and a scheduled draw. The new reader preserved each record except for its schema label, dealt damage once, and drew once at the next upkeep. Current fixture SHA-256: `fab7f90d5056236b773a76ec60ad58391ce85c1528b189723b064be60ab39140`. Both historical fixtures: `71f7f3becf886c70a34c03ac46ce7ef617b5d5dd792c04653eef4a4b18067620`.

General simultaneous death/leave handling remains an inherited limitation requiring separate work; this version gate prevents older builds from silently interpreting new saved semantics. These probes establish the tested state transitions, not whole-card functionality.

## Reusable checks

The prepared probes are now versioned under [bin/selector-compatibility](../bin/selector-compatibility/README.md). They use ignored `var/selector-compatibility` for extracted sources and fixtures, so continuation does not depend on a particular chat directory. Historical writers and readers check the extracted source hashes. All seven PHP processes passed with 30-second process-tree caps; the original task-local scripts and evidence are preserved unchanged.
