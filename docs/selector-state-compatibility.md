# Proposed selector saved-state contract

This is a local, unpublished source proposal for parent review. No tests, builds, corpus scans, or historical-reader probes were run for this compatibility delta while source-only work was requested. The previous 8-test / 70-assertion result applies to selector preparation commit `3109819dd69afbe175890fdc42af29f9f88fe761`, before this version change.

## Narrow runtime delta

Change only `Game::STATE_VERSION` from 3 to 4, with a comment explaining the semantic compatibility boundary. `toArray()` already writes that constant. `fromArray()` already rejects a non-integer, out-of-range, or future version before constructing a game or hydrating its objects, stack, and pending decisions. Its version-1/version-2 defaults and version-3 delayed-draw requirements stay unchanged.

The new `all artifacts, creatures, and lands` selector can persist in an ability's effect array. An older version-3 `allMatching()` falls back to nonland permanents for that unknown selector. It could therefore destroy enchantments and planeswalkers while sparing lands. Keeping the version at 3 would permit that misinterpretation.

All saves from this engine write version 4, even before a new selector is used. This also protects cards that may produce the new contract later, without attempting to infer capability requirements from only the current stack. There are no new top-level fields, capability lists, conditional version labels, or serialized-data rewrites.

## Forward and backward compatibility

| Writer / saved record | Reader | Contract |
| --- | --- | --- |
| Unversioned or version 1–2 | New version-4 engine | Existing defaults/migrations remain; next save writes 4. |
| Valid version 3 | New version-4 engine | Preserve stack, choices, and delayed draws; next save writes 4. Missing or malformed delayed-draw state remains rejected. |
| Version 4 | Historical version-1/2/3 engine, including current main and PR #331 | Existing upper-version gate rejects before hydration; it must not resolve the unknown selector. Execution against actual historical readers is still pending. |
| Version 4 | New version-4 engine | Preserve the new selector, its pending stack/ability state, and deterministic resolution. |
| Version 5 or later | Version-4 engine | Existing future-version rejection remains. |

Do not manually lower a schema label. There is no supported downgrade or migration of a version-4 game back to an older engine. A process resuming version-4 matches must keep a compatible reader. This proposal does not validate every possible tampered effect array or repair the older reader's fallback.

PR #331 stays independent at `3f309578ebc35f17ae9321f969a67fe4e14db4ab`, with its existing version-3 behavior. This proposal does not edit or publish that branch. Its +20 recognition result and this branch's independently measured +4 must remain separate until integration is tested.

## Focused verification plan before publication

1. Run `SelectorStateCompatibilityTest` once execution is authorized. Its five source cases cover unconditional version-4 output; a persisted union ability that destroys lands/creatures while preserving enchantments/planeswalkers; migration of a version-3 pending ordinary ability plus a delayed draw; missing delayed state in versions 3 and 4; and future-version rejection using a version-only record, before any game payload can be read.
2. Run `DelayedUpkeepDrawTest` and `EntryLifeChoiceTest` for legacy defaults, strict delayed-state validation, resumable decisions, and future-version rejection. Two delayed-draw assertions now expect `Game::STATE_VERSION` instead of a fixed current value of 3. Explicit legacy version 3 remains exercised in the new compatibility test.
3. Run `MassRemovalSelectorTest`, including its saved-stack and deterministic replay cases, against the new version gate. Add/verify Culling Sun's actual card fixture before making an individual gameplay claim about it.
4. In separate offline PHP processes, exercise the actual immutable reader sources from main `710a47cde58525c24d59fb591ef81b6d63ee945d` and PR #331 `3f309578ebc35f17ae9321f969a67fe4e14db4ab`. Extract byte-exact `Game.php` and `TextParser.php` snapshots using Git and record their blob hashes. Each historical process must load a newly generated version-4 pending-union fixture and throw `GameException` with the schema-version rejection before interpreting effects. Use the repository's existing offline dependencies; do not substitute a copied guard or a mock reader. Never load two `Game` class definitions into the same process.
5. Record commands, exact source commit and reader blob hashes, pass/fail/skip counts, and rejection output. Run Pint/whitespace checks. Resume Modern corpus measurement and adjacent removal/regeneration regressions only after the parent's startup resource hold is lifted.

No selector PR should be published while these compatibility checks are unverified. General simultaneous death/leave handling remains an inherited limitation requiring separate review; this version gate only prevents older builds from silently interpreting new saved semantics.
