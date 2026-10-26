# Card coverage checkpoint — 2026-10-20

The [Soul Stair Expedition milestone](soul-stair-expedition-returns.md) verifies paid quest-counter graveyard returns and fixes repeated selections within one target clause before costs are spent. Separate clauses may still select the same object. Current ledger: 31 scoped names, two Partner-gap names, one overlap and zero whole-card certificates; recognition remains historical.

This checkpoint distinguishes **recognized text** from **behavior tested in the engine**. It is not a claim that every recognized card is fully playable or that the card-coverage task is complete. See [the continuation plan](rules-engine-continuation.md) for architectural limits and ownership boundaries.

## Reproducible corpus

- Baseline code: `9781fa91765702206f1ebbd681a1221ff6a72bb2` (main).
- Public source: <https://mtgjson.com/api/v5/AllPrintings.sqlite.zip>, downloaded 2026-10-20.
- Database metadata: date `2026-10-19`, version `5.3.0+20261019`.
- Download archive SHA-256: `57a22f9820af958e9bf9974f375ddb6554796769b7a123d8a6773b49c21976c8`.
- Extracted SQLite SHA-256: `0b1eb20e43a709a92cfe23a946e75d9b44b05d480878cc6623e96c69743b946f`.
- The unchanged importer selected 183 sets and wrote 181 nonempty pools plus 858 rental-deck files. Booster eligibility, printing deduplication, front/back handling, and Modern legality use the existing importer/report rules. “All” below means all imported pools, **not every card in the full database**.
- Tests and coverage reports used the downloaded local corpus. Automated tests did not contact Discord, MTGJSON, or Scryfall. No live bot, deployment, release, or token-bearing build was run.

Use `MTGJSON_DATABASE` to point to the extracted file and `MTGPOCKET_DATA` to a scratch directory. Run `php bin/import-cards.php`, followed by `php bin/card-coverage.php 60 all` and `php bin/card-coverage.php 60 modern`. Pin the database hashes when reproducing these numbers; the public URL is mutable. The baseline was measured in a separate immutable checkout against the same imported pools.

## Recognition delta

| Imported scope | Baseline read whole | With this batch |
| --- | --- | --- |
| Modern | 9,514 / 22,399 (42.5%) | 9,517 / 22,399 (42.5%) |
| All imported pools | 11,254 / 28,729 (39.2%) | 11,257 / 28,729 (39.2%) |

The three newly wholly recognized pool entries are **Secrets of the Dead**, **Burning Vengeance**, and **Devoted Grafkeeper // Departed Soulkeeper**. The attacking-counter warning fix adds no recognition coverage. Historical percentages in the continuation plan used older code/data and are not this baseline.

## Implemented behavior

### Graveyard-cast triggers

The exact clause “Whenever you cast a spell from your graveyard, …” uses the existing effect/trigger system. Casting captures the actual origin before the spell moves to the stack, rather than inferring it from flashback payment or from not having been cast from hand. Only the caster's permanents see this event. Ordinary copies, graveyard activations, hand casts, and exile casts do not create it.

[GraveyardCastTriggerTest](../tests/Game/GraveyardCastTriggerTest.php) checks real wording, flashback, retrace, controller restrictions, payment/target rejection without mutation, independent trigger resolution after countering, target choice and save/resume, target incarnation changes, and spell-copy distinctions. Triggered effects continue to use existing engine decisions and targeting rules. This does not add new casting permissions, new persistence fields, or a new rules system.

Primary wording and rules notes: [Wizards' Innistrad Remastered release notes, page 18](https://media.wizards.com/2024/downloads/INR_Release_Notes_hzvmSd7KJG/EN_MTGINR_ReleaseNotes_20240903.pdf) explain Burning Vengeance's ordering and why unearth/Reassembling Skeleton activations do not trigger it.

### Attacking-counter parsing

“Put a +1/+1 counter on each attacking creature” omitted the optional “other” regex capture. Reading actual corpus cards previously raised an undefined-array-key warning, which failed the imported-card test. The parser now defaults that absent qualifier to false. [AttackingCounterTextTest](../tests/Game/AttackingCounterTextTest.php) uses Fangren Firstborn's wording and verifies that both the source and another attacker receive counters after save/resume, while nonattackers do not. The pre-existing “each other attacking creature” regression remains covered separately.

## Remaining work

Most imported entries still have unrecognized text: 17,472 of 28,729 across all pools and 12,882 of 22,399 Modern-legal entries. Even the recognized subset needs behavior evidence, rather than an automatic “supported” label.

This batch deliberately does not implement combined enter/cast clauses such as Ominous Roost, arbitrary alternate-source casts, player-selected type/color/devour decisions, multiplayer mechanics, or comprehensive rules correctness. The top residual report includes delayed upkeep draws, monarch/initiative, fading/phasing, library selection, copy retargeting, and unsupported combat/token restrictions; these need coherent engine work and negative/save-resume tests, not blanket regex acceptance. Commander/deck-construction-only wording must be distinguished from in-game effects before choosing another family.

PR #124 is merged. At this checkpoint, open [PR #327](https://github.com/Valgorithms/DiscordPHP-MTGPocket/pull/327), head `3378473689d14d705b5bbc6e82c3ebbb291d2dcb`, owns enchanted/equipped conditional bonuses and keywords. This batch does not change that family. Refresh upstream and ownership again before continuing it.
