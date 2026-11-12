# Rules-engine architecture and continuation

This is the short, current guide. Milestone-by-milestone history lives in [rules-engine-history.md](rules-engine-history.md); read it only when a past decision matters. Keep this file small: record a milestone in the history and the ledger, not here.

This is a continuation plan, not a claim of complete Magic rules support or authorization to deploy.

## Current state

- Counts of scoped names, explicit gaps, overlaps and whole-card certificates live only in [the functional coverage ledger](functional-coverage-ledger.md) and `config/coverage-evidence.json`. Do not copy them elsewhere.
- Recognition (a parser match) is separate from verified gameplay support. No whole-card certificates have been issued.
- Saved games carry `schemaVersion` (`Game::STATE_VERSION`). Older versions load through deliberate migration; newer or invalid versions are rejected.
- `config/coverage-evidence.json` pins the SHA-256 of evidence sources and tests. After changing a pinned file, refresh its pin; `FunctionalCoverageLedgerTest` fails otherwise.

## Foundation to preserve

Keep the current engine rather than starting a broad rewrite. [CardDefinition](../src/MTGPocket/Game/CardDefinition.php) turns imported card data into engine definitions; [TextParser](../src/MTGPocket/Game/TextParser.php) recognizes supported text; [Game](../src/MTGPocket/Game/Game.php) owns authoritative state, priority, stack resolution and target revalidation. Mana helpers and Discord presentation remain separate. Deterministic seeds, injected test data and save/load support are useful foundations.

Continue card functionality through coherent ability families. Small wording fixes are appropriate when they reuse verified mechanics.

## Rules for engine work

- Player decisions go through `pendingChoice`, `decision()` and the resolution resume path. The engine validates answers (responsible player, legal options, choice identity) and persists them; Discord panels and the practice bot use the same API. Automated selection belongs in bot policy, not in the engine.
- Prefer a general mechanism over a card-specific one: a new wording should reuse an existing effect, choice or selector before adding a new string discriminator.
- Effects are associative arrays with string discriminators. Validate new kinds at the parser/engine boundary and extract cohesive families gradually behind existing behavior tests.
- For each family, use actual card wording plus engine tests for successful resolution and negative controls: targeting, controller restrictions, payment, timing, target changes before resolution, and save/resume where relevant.
- Unsupported text is shown to players by [MatchMessageBuilder](../src/MTGPocket/Builders/MatchMessageBuilder.php). An empty unsupported list measures recognition, not correctness.
- Never use live services in tests. Preserve the CLI/ReactPHP runtime and unrelated Composer wiring.

## Known engine heuristics

These substitute engine policy for a player decision and are candidates for explicit choices:

- `Game::choiceFor()` picks a creature type or color from owned cards when a permanent enters.
- The devour entry path sacrifices the controller's other creature tokens automatically.

## Open milestones

1. **Explicit resumable choices** for the remaining heuristics above, including choosing none where permitted.
2. **Validated effect contracts** at the parser/engine boundary.
3. **Versioned persisted state**: test save/resume during choices, stack resolution and triggers.
4. **Honest support tiers**: unrecognized, recognized, behavior-tested, and supported within a documented mode or deck.

## Ownership and authorization

Use descriptive branches and open PRs, preserve unrelated local files, and check open PRs for overlapping families before starting one. This guide authorizes neither live bot startup, token-bearing builds, releases nor deployment. Follow the current human task authorization and repository workflow.
