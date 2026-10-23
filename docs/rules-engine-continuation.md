# Rules-engine architecture and continuation

Reviewed 2026-10-13 against merge `1b81558adaabae16a54cf865cd87c37019aa1461` (PR #125). This is a source review and continuation plan, not a claim of complete Magic rules support or authorization to deploy.

## Foundation to preserve

Keep the current engine rather than starting a broad rewrite. [CardDefinition](../src/MTGPocket/Game/CardDefinition.php) turns imported card data into engine definitions; [TextParser](../src/MTGPocket/Game/TextParser.php) recognizes supported text; [Game](../src/MTGPocket/Game/Game.php) owns authoritative state, priority, stack resolution and target revalidation. Mana helpers and Discord presentation remain separate. Deterministic seeds, injected test data and save/load support are useful foundations.

Continue the original card-functionality scope through coherent ability families. Small wording fixes are appropriate when they reuse verified mechanics. A parser match alone does not establish functional support.

## Observed limits

- At the reviewed revision, Game.php is about 8,900 lines and TextParser.php about 3,600. Effects use associative arrays and string discriminators, including `Game::applyEffect()` (line 3907). Expanding these contracts across parsing, targeting, costs and resolution increases coupling.
- `Game::choiceFor()` (line 7524), called when a permanent enters (line 7442), selects a creature type or color from owned cards rather than asking its player.
- The devour entry path (around line 7449) automatically sacrifices the controller's other creature tokens. It is a heuristic, not an explicit player selection.
- `Game::entersUntapped()` (line 7560) automatically pays the optional life cost on the controller's turn when life exceeds 10. This substitutes engine policy for a player decision.
- `pendingChoice`, `decision()`, and the resolution resume path already exist. Use them as the starting point for explicit decisions rather than creating a separate UI-owned rules system.
- `Game::toArray()` (line 8746) and `fromArray()` (line 8797) persist game state, but the returned top-level record has no explicit schema version. [The existing round-trip test](../tests/Game/TurnsAndSpellsTest.php) checks a saved spell on the stack; it does not prove every new pending decision survives restart.
- Unsupported text is exposed to players by [MatchMessageBuilder](../src/MTGPocket/Builders/MatchMessageBuilder.php). An empty unsupported list measures recognition, not comprehensive correctness.

Line numbers are revision-specific; locate the named methods again before editing.

## Proposed next milestones

These are recommendations, not completed implementation:

1. **Explicit resumable choices:** represent legal options, responsible player, selection bounds and continuation state. Cover type/color choices, optional life payment and devour selection, including choosing none where permitted. Validate choices in the engine; persist and resume them. Put automated selection in bot policy, using the same legal-choice API available to humans.
2. **Validated effect contracts:** define and validate effect kinds, required fields and target/cost semantics at the parser/engine boundary. Extract cohesive families gradually behind existing behavior tests; avoid one large rewrite or broad framework replacement.
3. **Versioned persisted state:** introduce a schema version and deliberate migration/rejection behavior. Test save/resume during choices, stack resolution and triggers, including changed object incarnations and invalid selections.
4. **Honest support tiers:** distinguish unrecognized, recognized, behavior-tested, and supported within a documented mode/deck. Use curated supported decks for reliable play. Avoid marking a whole card playable merely because one phrase became readable.

For each family, use actual card wording plus engine tests for successful resolution and negative controls: targeting, controller restrictions, payment, timing, target changes before resolution, and save/resume where relevant. Reuse repository CI and temporary/injected test fixtures; never use live services in tests. Preserve the CLI/ReactPHP runtime and unrelated Composer wiring.

## Implemented first choice milestone

Optional land-entry life payments now use an explicit engine decision before the land enters. The decision records the responsible player, legal options, selection bounds and a monotonic choice identity; the saved entry queue and remaining resolution steps resume after restart. Invalid, wrong-player, repeated and stale answers are rejected without a saved mutation. Forced-tapped entries remain tapped even if the player pays; payment is offered on either player's turn whenever the controller has enough life.

Discord panels route these answers through the engine and persisted match transaction. The practice bot makes its own conservative selection using the same API, rather than embedding policy in the rules engine. Entry triggers wait for the answer, and later spell effects wait for the entry.

Saved games now carry `schemaVersion: 2`. Unversioned/version-1 games load with defaults for the new entry queue and choice counter; newer or invalid versions are rejected. Older bot builds cannot safely resume version-2 pending entry choices: keep the upgraded code when resuming them.

[EntryLifeChoiceTest](../tests/Game/EntryLifeChoiceTest.php) covers acceptance/decline, low/insufficient/equal life, other-turn resolution, save/resume, forced tapping, batched selected entries, stale/repeated/wrong-player actions, trigger ordering, nested paid continuations, reanimation/unearth entry instructions, practice-bot decisions and legacy/future state versions. [MatchesTest](../tests/MatchesTest.php) exercises serialized controls and persisted interaction routing using temporary storage. This milestone does not change recognition coverage, type/color/devour policies or deck eligibility; those remain separate follow-up families. No configuration contract changed, so `env.example` needs no new variables.

## Coverage evidence

Measurements used the same existing imported pools and shared local MTGJSON SQLite at `D:/GitHub/DiscordPHP-MTG/var/mtgjson/AllPrintings.sqlite`; no download or reimport occurred. Baseline parser: `3c5840e`. Final parser: `604e12662560d14c9002477f1181b8fe08a8f7ea`, merged by PR #125. The baseline parser was loaded separately without reverting the checkout.

| Scope | Baseline read whole | Final read whole |
| --- | --- | --- |
| Modern | 7,764 / 22,399 (34.7%) | 7,764 / 22,399 (34.7%) |
| All | 9,204 / 28,658 (32.1%) | 9,204 / 28,658 (32.1%) |

PR #125 adds **zero whole-card coverage**. It accepts the uncontracted two-sentence chosen-creature fight wording through existing mechanics. [ChooseFightTest](../tests/Game/ChooseFightTest.php) verifies mutual damage without a bonus, controller restrictions, payment, and removal of the opposing target in response. Four focused tests/16 assertions passed; the existing pumped-fight regression also passed. Exact-head CI run `38458441620` passed full Pint and PHPUnit on PHP 8.3, 8.4 and 8.5. This is offline/CI evidence, not live Discord acceptance. No measured model-efficiency or cost savings are claimed.

## Ownership and continuation boundaries

[PR #124](https://github.com/Valgorithms/DiscordPHP-MTGPocket/pull/124), branch `claude/card-coverage-gaps-sc84tj`, head `6ba030f8a6844f4a11b52b677d142c34399a4853`, covers chosen creature types and typed team pumps. Claude ownership was unresolved at review time: preserve its branch and treat it as potentially owned. Refresh ownership and upstream before touching that family; do not assume Claude was stopped or its parent session migrated.

Use descriptive branches and open PRs, preserve unrelated local files, and distinguish recognized text from functional gameplay. This continuation plan authorizes neither runtime refactoring by itself nor live bot startup, token-bearing builds, releases or deployment. Follow the current human task authorization and repository workflow.

## Current coverage continuation

The [resumable creature-sacrifice milestone](resumable-edict-choices.md) adds validated, persisted each-player choices and Discord/practice companions. New saves now use schema version 7 after the [Fabricate choice milestone](fabricate-resolution-choices.md); earlier version notes retain their historical migration context.

The [functional coverage ledger](functional-coverage-ledger.md) pins the October 22 corpus at 28,729 names and separates recognition, explicit gaps, scoped behavior evidence and whole-card review backlog. It supplies the measurable continuation baseline; historical recognition tables below retain their original dates and scopes.

The [2026-10-20 coverage checkpoint](card-coverage-progress.md) records a fresh, hashed public corpus, the current recognition baseline, tested graveyard-cast behavior, and remaining scope. It also refreshes the older ownership status above: #124 is merged, while #327 covers enchanted/equipped conditional bonuses and keywords. Recognition percentages remain separate from gameplay support.

The next [delayed-upkeep-draw milestone](delayed-upkeep-draw.md) adds an exact one-card delayed-draw family through the ordinary trigger queue. It advances saved-state format to version 3, with legacy migration and rejection of missing delayed state. See that note for the tested behavior, compatibility boundary, recognition delta, and remaining limits.

## Sacrifice-specific observer follow-up (2026-10-23)

The [Mayhem Devil milestone](sacrifice-observer-lookback.md) now preserves selected-group sacrifice callbacks and ordinary single-sacrifice source context, distinct from actual creature deaths. Its pinned WAR printing has scoped evidence only: recognition remains 11,282/28,729 and whole-card certificates remain zero. The next source-backed seed is Disciple of the Vault: determine all-player artifact-graveyard recognition and observer visibility before claiming runtime coverage. Broader replacement/continuous-condition/choice/APNAP limits remain explicit.

The [artifact-graveyard observer milestone](artifact-graveyard-observer-lookback.md) extends selected-group lookback to same-controller artifact/permanent burial callbacks, with exact Marionette Master tests at printed power. The complete Disciple ability remains unread and is tracked separately. Next, explicitly test modified source power after departure and reincarnation; the printed-power fallback is not a last-known-power certificate. Fabricate decisions, all-player optional callbacks and whole-card certification remain outside this slice.

The [departed-source statistics milestone](departed-source-statistics.md) now reproduces and fixes modified Marionette power, per-incarnation last-known statistics and surviving-source current power. Legacy saves cannot reconstruct missing departed statistics; this remains explicit. Next, implement the separately unread Disciple optional/all-player artifact ability with real resolution choices and negative controls, without changing recognition claims before measurement.

The [Fabricate choice milestone](fabricate-resolution-choices.md) follows the same exact Marionette through paid entry, counters/Servos, departed-source fallback, persisted resolution and Discord/practice decisions. Admission is unchanged; earlier automatic-counter saves retain their encoded effects. Disciple remains a separate unread candidate; scoped Marionette evidence does not certify its newest printing or every interaction.

The [Disciple optional-burial milestone](disciple-optional-artifact-burial.md) adds all-player artifact burial and explicit target-opponent accept/decline at resolution, persisted schema 8, Discord/practice routes and a targeted eight-name candidate superset. The original overall recognition report remains historical; Fangren Marauder is the next separately measured optional controller-life-gain candidate.

The [Fangren optional-life-gain milestone](fangren-optional-artifact-burial.md) follows the same all-player burial event with a separate untargeted controller decision, persisted schema 9 and Discord/practice controls. It verifies ordinary gain prevention, modifiers and life-gain observers without changing Disciple targets. Ten names now have scoped evidence; whole-card certificates remain zero and aggregate recognition remains historical. The next measured unread seed is Molder Beast's temporary source buff, requiring incarnation and turn-expiry proof before support claims.

The [Molder Beast milestone](molder-beast-artifact-burial.md) proves the exact all-player burial +2/+0 boost through existing source-incarnation, persistence and cleanup behavior. It changes no engine state schema or optional decision. Eleven names now have scoped evidence; aggregate recognition stays historical and whole-card certificates stay zero. Next is the separately unread complete 5DN Moriok Rigger optional +1/+1-counter ability, requiring explicit resolution choices, persistence, source identity and Discord/practice controls.
