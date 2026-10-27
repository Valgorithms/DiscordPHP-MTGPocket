# Private Recover continuation candidate (depends on frozen PR #444)

Base: b0941ed43b4a95c08ba83c1f1f82793c9c212f01. Do not widen or amend that PR.

## Reproduced baseline

`RecoverDamageOrderingTest`: 2 tests, 13 assertions, one failure and one error on the unmodified base. Ordinary paid creature/removal returned Grim Harvest to hand during death processing. With activated Pure Flame (seeded counters), Underground River and a one-point prevention shield, death processing threw the preserved unsupported painful-payment ordering exception. Baseline receipts and file hashes live outside this checkout under task evidence/recover-baseline*; no failed evidence is overwritten.

## Required rules behavior

[Official Comprehensive Rules, April 17, 2026](https://media.wizards.com/2026/downloads/MagicCompRules%2020260417.pdf), 702.59a and 603.3/603.10: Recover is an optional-payment triggered ability, functioning from the graveyard. The death event completes before the ability goes on the stack. Payment occurs during resolution, not inside zone movement. A card newly arriving in the graveyard does not observe its own or the same selected group's departures. Existing observers can trigger separately for each actual creature death. Ownership of the destination graveyard matters rather than who controlled the dying creature. Countering the trigger entails no payment or exile. A departed-and-returned Recover card is a new object (400.7); old triggers cannot move it.

## Proposed bounded contract

1. Snapshot Recover cards (id, graveyard incarnation, owner, printed cost) before an individual death or selected death group. Queue one ordinary untargeted ability per qualifying owner/death. Retain existing two-seat trigger ordering policy, not a new APNAP interface.
2. Resolving the trigger opens a persisted optional choice pinned to that original graveyard incarnation. Decline exiles only that incarnation. Invalid actor/id/option, unpayable acceptance and replay leave state unchanged. A later trigger can still be paid after the original card left; neither payment nor decline moves its new incarnation.
3. Acceptance preflights then pays once using `pay(..., kind=resolution)`. If painful damage needs replacement/prevention ordering, the paid mana stays paid, damage waits atomically, and the original card stays in the graveyard until payment damage completes. An already-paid return instruction precedes the existing remaining resolution steps; it never calls payment again.
4. Save/load preserves optional and nested damage choices, payment, original source identity and the remainder exactly once. Bound optional choice and continuation sizes and validate the source/cost/controller/resume identity. Older schema 17 saves without this choice remain readable; schema 18 owns the new choice.
5. No arbitrary zone-movement continuation is introduced: moving the dead creature never opens this choice. Existing end-of-resolution/SBA/trigger boundaries do that work. Keep unsupported replacement families, broader mana-source choice policy, merged multi-owner creatures and general trigger-order choices explicit; no whole-card certification or ledger-count change.

## Review status

Private source/test preparation only. #444 remains frozen, and no publication or merge is requested from this artifact.

## Concrete implementation and verification

Runtime/test source commit: `554dff631e0431c6d517b0832c789fcdf313b6d8` (parent `b0941ed43b4a95c08ba83c1f1f82793c9c212f01`). `RecoverResolution` owns observer descriptors, trigger queueing, optional payment, identity-pinned return/exile and strict optional/paid resume checks. `DeathObserverBatch` snapshots graveyard observers before selected group movement. `Game` creates and validates schema-18 choices; the damage validator accepts exactly one already-paid Recover return. Existing panels and practice policy handle the choice. Two previous synthetic regressions now answer Recover explicitly. TextParser recognition behavior is unchanged (only its implementation comment changes).

Final offline full suite: **2508 tests / 64524 assertions / 2 unchanged missing-data skips**, no failures, errors, warnings or risky tests; **59.339 seconds / 356 MiB**, PHP 8.5.12, 512 MiB, Xdebug off. Dedicated Recover: **24 tests / 281 assertions**; persisted panels: **3 / 65**; updated animated death case: **1 / 11**. All 76 current source pins are validated separately. Full style check passed. Runtime and test bytes remain identical to the source commit during metadata/doc completion.

The first full attempt crashed with Windows access violation `0xC0000005`; cause is unestablished. Its early stale-pin error and automatic-payment expectation were isolated and corrected. All raw receipts remain available; the later successful traced full run is not substituted for failed historical evidence. No deprecations or assertions were suppressed.

### Deliberate limits and review decision

Mana-only Recover, represented by cached CSP Grim Harvest and 10E Underground River, is this tested family. Grim Harvest starts in the graveyard; the positive path uses paid creature cast/opponent removal and paid Quest activation with explicitly seeded counters. It does not certify Grim Harvest's separate cast-return spell path or whole card. Synthetic creature/token/control/movement/counter and persistence boundary probes are explicitly labelled. Garza's Assassin's life-cost Recover stays outside the parser contract. General mana-source selection, multi-owner merged creatures, broader replacement families, APNAP/trigger-order interface and live Discord are outside scope.

Current ledger retains **38 scoped names / 3 historical explicit-gap names / 2 overlaps / 0 whole-card certificates** and historical recognition unchanged. The old Pure Flame nested-Recover gap entry remains verbatim until root reviews this private correction; it is not a claim that this candidate has no new scoped runtime evidence. Previous validation and previous source pins are preserved in full. Root should review the required trigger-timing correction and the exact one-return continuation before deciding publication/evidence promotion. No publication or merge is authorized for this follow-up yet.

Final metadata-only check: `FunctionalCoverageLedgerTest` **18 tests / 37 assertions**, full nonmutating Pint confirmed, and historical/current provenance audit all pass. No full suite was repeated after this metadata/doc completion.
