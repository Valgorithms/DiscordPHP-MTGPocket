# Controller damage and coverage checkpoint — 2026-10-23

This batch implements fixed damage to **you**, the spell or ability's controller. It also adds an offline JSON recognition inventory. Card coverage remains incomplete; recognizing text does not certify a whole card's gameplay.

## Current baseline and ownership

Remote `main` was verified as `710a47cde58525c24d59fb591ef81b6d63ee945d`. The existing Desktop primary checkout was clean at older commit `448c2f3e11565c5c4548a0280eb6cf3773045b62`; it was left untouched. Work used an independent clone based on current main. No other active MTGPocket task appeared in the available task inventory. No repository `.agents/skills` entries were present.

- [Fading #330](https://github.com/Valgorithms/DiscordPHP-MTGPocket/pull/330) remains open at `75e2ab6f6a99236460632b8f00c9e0efb96c6195`. Its exact-head Tests run [39400702814](https://github.com/Valgorithms/DiscordPHP-MTGPocket/actions/runs/39400702814) is successful. Its parser still adds nine recognized names on this corpus: 11,287 / 28,729. None overlap this batch's 20 new names. Its source and tests are preserved; this branch does not include it.
- [Equipped/enchanted #327](https://github.com/Valgorithms/DiscordPHP-MTGPocket/pull/327) remains open at `3378473689d14d705b5bbc6e82c3ebbb291d2dcb`, on `claude/card-coverage-gaps-sc84tj`. It owns attachment-dependent bonuses and keywords. Its external Claude ownership remains unconfirmed; no changes were made to that branch or family.

## Corpus and reproducibility

The existing local MTGJSON database is `5.3.0+20261022`, dated 2026-10-22, SHA-256 `5d03b173be65dc60e2d51f29c8cb2a9725449770e956f4db374435968f0f23de`. No corpus download occurred. The unchanged importer selected 183 sets, producing 181 nonempty pools and 858 rental decks in scratch storage. The imported pools, legality database, and name/printing selection are held constant across comparisons. These are imported booster-pool names, **not every card in MTGJSON**.

The [machine-readable checkpoint](card-coverage-audit-2026-10-23.json) records the selected-card fingerprints, database hash, code-file hashes, recognition deltas, new names, and residual patterns. The SHA-256 of the ordered selected-card records is `5583556fcc237fcaa5ebbf03c598644c4657773bd3bd415acb176ef779ed9fe1` for all imported names and `9ee46f974692dd3e5a60066eea49c926966ba6b551851a147aaeb28c480375f1` for Modern. Complete card data, including additional faces, contributes to these hashes; import timestamps do not.

Use a scratch checkout without `.env`, the pinned existing database, and the unchanged importer. Importing with the existing database and refresh disabled does not download data. Then run:

```powershell
php -d memory_limit=512M bin/card-coverage-audit.php var/audit-data > coverage-all.json
php -d memory_limit=512M bin/card-coverage-audit.php var/audit-data modern D:/path/to/AllPrintings.sqlite > coverage-modern.json
```

Unlike the older human-readable command, the new audit does not load dotenv, construct HTTP clients, or import cards. Format queries open the supplied SQLite database read-only and validate the requested legality column. Empty/missing pools, unknown formats, and malformed input fail rather than reporting successful zero coverage. For a baseline comparison, load an immutable baseline `TextParser.php` in a separate PHP process before the shared audit; verify its Git blob hash. Do not mix parser versions within one process or compare reports with different selected-card fingerprints.

## Four distinct evidence tiers

| Measurement | Main | This batch | Remaining gap |
| --- | --- | --- | --- |
| Names with all text recognized, imported pools | 11,278 / 28,729 | 11,298 / 28,729 | 17,431 names with unread text |
| Names with all text recognized, Modern | 9,520 / 22,399 | 9,533 / 22,399 | 12,866 names with unread text |
| Unread clauses, imported pools | 23,292 | 23,261 | 20,341 distinct normalized text patterns; these are not mechanic-family counts |
| Implemented rules | No comprehensive contract inventory | One added controller-damage contract documented below | Aggregate count unmeasured |
| Supported whole cards | No card-level certification register | No new blanket certifications | Count unmeasured; all 28,729 names still need classification against a declared mode/rules scope |
| Gameplay-tested whole cards | No exhaustive card-to-test mapping | Eight named cards exercised for this family, plus synthetic controls | Aggregate whole-card count unmeasured |

The audit outputs `null` and a reason for unmeasured tiers. Blank text, a parser match, or a simulation reaching an ending cannot promote a card to supported. Existing focused tests remain useful evidence even though a complete certification register has not yet been built.

The 20 newly recognized names are Ancient Tomb, Black Market Tycoon, Blade Juggler, Callous Inspector, City of Brass, Dark Bargain, Fledgling Djinn, Graf Reaver, Jackal Pup, Juzám Djinn, Lava Hounds, Mana Vault, Midnight Reaper, Nettletooth Djinn, Notion Rain, Ravenous Giant, Sell-Sword Brute, Serendib Efreet, Shinka Gatekeeper, and Smoldering Efreet. Their other clauses and all possible interactions are not certified by this list.

## Implemented rules and behavioral evidence

`TextParser::effect()` reads the exact fixed-integer clause `CARDNAME deals N damage to you` as `damage` with `you: true`, without a target. `Game::applyEffect()` routes that damage to the resolving stack item's recorded controller through the existing damage pipeline. It does not substitute life loss or choose the current controller of the permanent. This follows rules 109.5 and 113.8 in the [2026-09-25 Comprehensive Rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260925.txt). Prevention, infect, and lifelink continue through the ordinary damage rules (120.3).

Existing composition handles enter/upkeep/death/tap triggers, the damage amount from a dealt-damage event, per-Treasure multiplication, and an effect following a library choice. No new top-level persistence fields or schema migration are needed; ordinary stack, effect, and choice records preserve the recipient marker. Resuming requires code that understands the newly supported text/effect variant.

[ControllerDamageTest](../tests/Game/ControllerDamageTest.php) has 14 offline tests / 101 assertions. It exercises Serendib Efreet, Ravenous Giant, Smoldering Efreet, Lava Hounds, City of Brass, Jackal Pup, Dark Bargain, and Black Market Tycoon. Controls verify:

- Opponent upkeeps do not trigger your upkeep ability; a pending trigger damages its original controller after control changes and restart, while future triggers use the new controller.
- Removal of the source does not cancel its trigger. Death triggers retain the controller at death even when the card is owned by the other player.
- Player hexproof does not prevent untargeted damage. Prevention, infect, and lifelink distinguish damage from life loss.
- Mana payment, player ownership, extra targets, and invalid library choices reject without mutation. Countered spells and spells whose targets all become illegal deal no controller damage.
- A saved Dark Bargain selection resumes the remaining damage exactly once, and replaying the same saved spell produces identical state.
- Unsupported conditional payments, unimplemented intervening conditions, arbitrary variable amounts, and combined damage recipients stay visible.

The family reuses existing source/keyword and trigger-order handling. It does not repair general last-known-information or blink/copy interactions throughout the engine. Broader effects such as arbitrary "unless" payments, commander emblems, damage replacement/redirect effects, and source-relative controller damage need their own contracts.

## Verification and completion definition

- Latest affected suites: **71 tests / 577 assertions passed** (`ControllerDamageTest`, `CoverageAuditTest`, delayed draws, graveyard casts, and abilities).
- Broader bounded run, excluding the two long simulation methods: **706 tests / 81,727 assertions, no assertion failures, seven risky tests**. This run preceded the final two additional controller tests, which pass in the latest focused run. Six importer tests emit a deprecation from the copied `react/dns` Windows resolver configuration; one unchanged ManaPayer test emits PHP 8.5 null-offset deprecations. These are unresolved local validation limitations, not a green suite.
- Three seeded official-deck games, with save/reload on every move: **1 test / 486 assertions passed**. Completing a game does not verify every card it contains. The full 50-game run and long fixture-deck simulation were deliberately not repeated alongside the urgent server work.
- Full Pint passed after normalizing this isolated Windows checkout's PHP line endings to LF; unrelated Git content stayed unchanged. Whitespace checks passed.
- Dependencies were copied locally and autoloading regenerated with network disabled. No paid services, live bots, Discord sessions, credentials, token-bearing builds, deployment, merges, or other project installations were used.

Completion for the full imported corpus requires a pinned corpus/scope, a reviewed inventory of every relevant parser/engine contract, and a per-card evidence record covering every face and ability. Each record must distinguish rules implemented, deterministic positive behavior, negative/payment/timing controls, and pending-decision/stack save-resume evidence. Any mode exclusions stay counted and explained. The task is complete only when all names are classified and every card claimed supported satisfies that evidence contract; zero unread parser text alone is insufficient.

Remaining families include library selection/reordering, copy retargeting, monarch, initiative/dungeons, phasing, banding, regeneration restrictions, state-triggered sacrifice, and optional entry/type/color/devour choices. Commander/deck-construction and draft-only mechanics (Partner, Backgrounds, commander permission, hidden agenda) need explicit mode treatment. Fading and attachment conditions should continue through the existing PRs. Pattern counts overlap across cards and are triage hints, not additive counts of unsupported cards or implemented mechanics.

## Current main reconciliation (2026-10-26)

Published PR parent `3f309578ebc35f17ae9321f969a67fe4e14db4ab` is preserved as a merge parent with main `bd96559c492f6ae032a8a29898105e018f2020f1`; no published history is rewritten. Actual conflict: `docs/rules-engine-continuation.md`. Both documentation/evidence sets are retained. Source pins are refreshed for the automatic combined engine/test tree; all previous scoped claims remain unchanged at37names/zero whole-card certificates. Original recognition reports and validation are historical, not an aggregate update. Focused/full offline validation and exact-head hosted CI remain pending. No live bot, credential/security changes, deployment or independent merge.

Full-tested integrated source 153003f7eb27e2ff4e7dc6869167bc5e270c74ad: 2383 tests/62838 assertions,2expected missing-pool/rental skips,no risky tests;PHPUnit01:07.445/332.00 MB. Focused637tests/7996assertions,fullnonmutatingPint,all67normalizedPHPpinsPASS. PHP8.5.12/512MiB/Xdebugoff/isolateddata+temp/existingdeps. Final unpublished merge amend changes verification metadata/docs only;production/testbytes match. Hosted acceptance requires the final exact PR head.
