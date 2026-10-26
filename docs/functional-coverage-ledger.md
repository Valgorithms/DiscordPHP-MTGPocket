# Functional coverage evidence ledger

The [local Quest for the Gravelord slice](quest-gravelord-death-counters.md) fixes a reproduced forced optional-counter decision using a complete cached ZEN record. This branch is based on pre-#406 main: its scoped count is 31, including Quest and excluding Soul Stair. Current main's separate Soul Stair evidence must be reconciled before combining counts. Recognition remains historical and whole-card certificates remain zero; the next cached candidate and preserved Dog Walker deferral are recorded in the slice note.

The [Harrow land-cost milestone](harrow-land-cost-search.md) pays a controlled land before resolution and offers zero, one or two untapped basics followed by one shuffle. Land-cost payment follows the existing automatic policy. The ledger now has 29 scoped names, 2 Partner-gap names, 1 overlap and zero whole-card certificates; recognition and earlier counts remain historical.

The [Burnished Hart search milestone](burnished-hart-land-search.md) adds explicit selection of zero, one or two basics after a paid cast and paid self-sacrifice, with tapped entry and one final shuffle. The ledger now has 28 scoped names, 2 Partner-gap names, 1 overlap and zero whole-card certificates. Recognition and earlier milestone counts remain historical.

The [Diabolic Tutor reveal-policy milestone](diabolic-tutor-unrestricted-search.md) verifies paid unrestricted searches and keeps an unrevealed find off the public board while preserving explicit reveal controls. The ledger now has 27 scoped names, 2 Partner-gap names, 1 overlap and zero whole-card certificates. Recognition remains historical; pending legacy choices without the optional flag retain their old logging policy.

The [Cultivate split-search milestone](cultivate-split-search.md) adds paid selection of up to two basics, reveal, a destination choice for two, tapped battlefield/hand split and one final shuffle. Schema15 validates both split stages while ordinary13/14 look saves remain readable; optional legacy wording is unchanged. Current ledger: 26 scoped names, two explicit-gap names, one overlap and zero whole-card certificates. Recognition and earlier milestone counts remain historical.

The [Evolving Wilds search-choice milestone](evolving-wilds-search-choice.md) adds explicit selection or failure to find after actual land play and tap/self-sacrifice activation, including tapped entry, shuffle, save/load and persisted panels. Only mandatory put-it/put-that-card wording changes; optional legacy wording remains unchanged. Current ledger: 25 scoped names, two explicit-gap names, one overlap and zero whole-card certificates. Aggregate recognition and earlier milestone counts remain historical.

The [Farseek subtype-search milestone](farseek-subtype-search.md) records paid evidence for existing explicit normal/snow/nonbasic-dual subtype selection, tapped entry, failure to find and shuffle. It changes tests, not runtime. Current ledger: 24 scoped names, two explicit-gap names, one overlap and zero whole-card certificates. Aggregate recognition remains historical; earlier counts below are historical.

The [Rampant Growth mandatory-search milestone](rampant-growth-basic-search.md) adds paid explicit basic-land selection, tapped entry and shuffle, including failure to find, using the existing tutor. Current ledger: 23 scoped names, two explicit-gap names, one overlap and zero whole-card certificates. Aggregate recognition remains historical; earlier milestone counts below are historical.

The [Viridian Emissary death-search milestone](viridian-optional-death-search.md) adds an explicit original-controller search/decline decision after a paid cast and Shock, reusing schema 14 optional-search continuations. Current ledger: 22 scoped names, two explicit-gap names, one overlap and zero whole-card certificates. Aggregate recognition remains historical; earlier milestone counts below are historical.

The [Solemn Simulacrum entry-search milestone](solemn-optional-entry-search.md) adds an explicit search/decline decision, basic-land eligibility, tapped entry and original-controller continuation. Declining does not search or shuffle; accepting and failing to find shuffles. Version 14 preserves version 13 death choices. Current ledger: 21 scoped names, two explicit-gap names, one overlap and zero whole-card certificates. Aggregate recognition remains historical. Earlier milestone counts below are historical.

The [Solemn Simulacrum death-draw milestone](solemn-optional-death-draw.md) adds explicit accept/decline, original-controller continuation, version13 save validation, persisted panels and practice empty-library policy. Its entry basic-land search remains unread. The ledger has21scoped names,3explicit-gap names,2overlaps and zero whole-card certificates; aggregate recognition remains historical.

The [Clay-Fired Bricks entry milestone](clay-fired-bricks-search-entry.md) removes its explicit unread search gap and adds paid front search/reveal/shuffle/gain, negative choices, JSON and front-to-craft evidence. The ledger remains20unique scoped names, with2explicit-gap names,1overlap and zero whole-card certificates. Historical aggregate recognition is unchanged; the selected complete LCI record now parses without unread clauses.

The [Den Protector combat milestone](den-protector-combat-power.md) adds test-only evidence for paid current-power blocking boundaries, response timing, damage and JSON replay. Existing production behavior passed; no engine defect or runtime change is claimed. The ledger remains20scoped names,3explicit-gap names,2overlaps and zero whole-card certificates. Historical recognition is unchanged.

The [megamorph copy milestone](megamorph-token-copy.md) adds the paid Den Protector face-down copy and original turn-up/counter/targeted-return path. The ledger has20scoped names,3explicit-gap names,2overlaps and zero whole-card certificates. Historical recognition remains unchanged; disguise/ward and broader copying remain separate.

The [plain-morph copy milestone](plain-morph-token-copy.md) selects a nameless, colorless2/2 with no hidden turn-up action for the paid Woolly Loxodon morph/copy path. The ledger has19scoped names,3explicit-gap names,2overlaps and zero whole-card certificates. Historical recognition remains unchanged; megamorph/disguise and broader copy layers remain separate.

The [transforming-token copy milestone](transforming-token-copy-face.md) selects the active face before entry for unmerged transforming copies. Converter Beast remains scoped evidence: 18 names, 3 explicit-gap names, 2 overlaps and zero whole-card certificates. A separately selected Woolly Loxodon face-down-copy lead is unreproduced.

The [Incubator token-identity milestone](incubator-token-identity.md) preserves token identity across transformation on the paid Converter Beast path. Current evidence covers 18 scoped names, 3 explicit-gap names, 2 overlaps and zero whole-card certificates. Recognition remains historical; active-face token copying is the next separate, unreproduced lead.

The [transformed-artifact burial milestone](transformed-artifact-burial-mana-value.md) freezes the selected crafted back face's front mana value before departure. The ledger now has17scoped names,3explicit-gap names,2overlaps and zero whole-card certificates; Clay's search ETB and broader copy/transforming-token lifecycle remain explicit. Historical recognition is unchanged.

The [Joven temporary artifact-control milestone](joven-temporary-artifact-control.md) adds scoped paid activation, identity, cleanup/restoration, JSON, persisted panel and existing practice-resolution evidence. It removes Joven's explicit unread activation gap: the ledger now has 16 unique scoped names, 2 Partner-gap names, 1 overlap and zero whole-card certificates. Historical aggregate recognition remains unchanged; a bounded eight-record scan is measured separately. Next is a regression-first transformed-artifact burial mana-value investigation, retaining broader model and Partner/commander limits.

The [Disciple milestone](disciple-optional-artifact-burial.md) supersedes the earlier Disciple unread-gap assertion: complete pinned 2XM wording now has scoped optional/all-player behavior evidence. The current manifest records thirteen names with scoped evidence, one explicit gap and zero whole-card certificates, including the later Fangren, Molder Beast, Moriok Rigger and Summoning Station milestones. Its overall recognition totals remain the historical pinned report, not current parser totals. The [Fabricate milestone](fabricate-resolution-choices.md) retains its scoped entry decisions on the complete KLD Marionette fixture.

This ledger makes the existing 100% functionality goal measurable against the pinned **28,729 newest-printing card names** in 181 imported pools, MTGJSON `5.3.0+20261022`. It does not redefine the goal as parser recognition or a smaller supported deck. A card name is the unit; all selected card data, including extra faces, contribute to the corpus fingerprint. It is not the complete MTGJSON database or a fresh Modern measurement.

## Baseline and acceptance

| Measure | Cards | Meaning |
| --- | ---: | --- |
| Pinned denominator | 28,729 | All selected names, including unread cards |
| Recognized whole | 11,282 | No unread parser clauses in the saved recognition audit |
| Has unread text | 17,447 | Complement of recognized names, not a gameplay verdict |
| Recognized without a whole-card certificate | 11,282 | Recognition does not clear the functional review |
| Named cards with scoped regression evidence | 18 | Current manifest names, including Converter Beast; each has a bounded evidence scope |
| Named cards with an explicit unsupported gap | 3 | Akroma and Ich-Tekik Partner, and Clay-Fired Bricks search ETB; this is not an exhaustive gap list |
| Intersection of the previous two rows | 2 | Scoped behavior evidence does not certify other interactions |
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

The [departed-source statistics milestone](departed-source-statistics.md) adds scoped counter/temporary-power and per-incarnation evidence for the existing Marionette Master exemplar; it adds no new corpus names or certificates. Next is the separately unread complete Disciple optional/all-player ability, retaining real targeting and resolution choices. Recognition remains unchanged until measured parser changes.

The [Fangren optional-life-gain milestone](fangren-optional-artifact-burial.md) separately adds its untargeted controller choice. At that milestone scoped evidence covered ten names, one explicit gap and zero whole-card certificates; the earlier Disciple nine-name status is historical. The same eight-name candidate set now has two fully readable records and six with unread abilities. Overall recognition remains the original historical report.

The [Molder Beast milestone](molder-beast-artifact-burial.md) adds the exact temporary source boost through existing incarnation and cleanup rules. Current scoped evidence covers eleven names, one explicit gap and zero whole-card certificates. Three of the targeted eight records are now fully readable; five remain unread. Moriok Rigger's optional source counter is the next separately measured seed. Aggregate recognition remains historical.

## Moriok Rigger optional source counters (2026-10-23)

The [Moriok milestone](moriok-rigger-optional-counter.md) adds explicit accept/decline, permanent counters on the original source incarnation, saved schema 10 and Discord/practice controls. Twelve names have scoped evidence, one explicit gap remains and whole-card certificates stay zero. The targeted eight-name scan now reads Disciple, Fangren, Molder and Moriok; four remain unread. Aggregate recognition remains historical. Summoning Station is the next measured optional source-untap seed.

## Summoning Station optional source untap (2026-10-23)

The [Station milestone](summoning-station-optional-untap.md) adds explicit untap/decline, paid tap-token activation and repeated activation, original-incarnation isolation, schema11 and Discord/practice controls. It also reproduces and fixes stun replacement in the shared single-permanent untap effect. Thirteen scoped names/one gap/zero whole-card certificates remain separate from the historical aggregate recognition. The eight-name targeted scan has five readable records and three unread; Krenko's optional red-payment token is the next measured seed.

## Krenko optional red payment (2026-10-23)

The [Krenko milestone](krenko-optional-red-goblin.md) adds original-controller pay/decline, ordinary mana legality, one red Goblin with temporary haste, schema 12 and Discord/practice controls. Paid cast, printed activation, Smelt and simultaneous Damnation tests remain scoped evidence. Fourteen scoped names, one explicit gap and zero whole-card certificates remain separate from historical aggregate recognition. Six of the targeted eight records are readable; Ich-Tekik and Joven and Chandler retain unread clauses. Ich-Tekik source/Golem counters are the next bounded seed; its Partner clause stays separate.

## Ich-Tekik source/Golem counters (2026-10-23)

The [Ich-Tekik milestone](ich-tekik-artifact-burial-counters.md) adds paid entry/token and source/Golem counter evidence, source/controller/departure/save/cleanup controls and a reproduced shared Kindred-counter fix. Partner remains unread in the complete fixture. Fifteen scoped names, two explicit Partner gaps and one overlap still confer zero whole-card certificates; aggregate recognition remains historical. Joven and Chandler mana-value counters are the next bounded seed, keeping its temporary-control activation separate.
