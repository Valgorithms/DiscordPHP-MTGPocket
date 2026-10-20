# Delayed upkeep draws

This family reads exactly “Draw a card at the beginning of the next turn's upkeep.” The same mandatory, fixed-controller effect can appear in an otherwise understood spell, triggered ability, or activated ability. It does not recognize optional draws, other recipients, multiple cards, “your next upkeep,” recurring draws, or broader delayed effects.

## Engine contract

- The parser emits `draw_next_upkeep`, not an immediate `draw`.
- Resolving that effect adds one `Game::$delayedDraws` record: `createdTurn`, `source`, `incarnation`, `controller`, and `label`. A countered spell or an ability with no remaining legal targets never schedules its draw. A resolving copy schedules its own draw.
- At the first upkeep in a later turn, `triggerDelayedDraws()` removes each due schedule and adds a normal one-card draw to `pendingTriggers`. It does not require that turn's active player to be the recorded controller. Extra turns therefore work without guessing who takes the next turn.
- The ordinary trigger queue handles APNAP placement, priority, responses, and resolution. Drawing does not happen during upkeep entry, and this effect does not replace or depend on the normal draw step. Once promoted to a trigger, a schedule is consumed even if the trigger does not resolve.
- The recorded controller remains the recipient after the source leaves, returns, or changes control. No continuing source-zone or current-controller condition is added. Existing object records remain available for normal ability-stack source references.

These choices follow rules 603.7a–e and 503.1a in the [Comprehensive Rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260925.txt). The separate Pact implementation is not a model for resolution: it immediately pays a cost in the caster's upkeep.

The source incarnation is captured when scheduling, and is not used to select a permanent or gate this fixed-recipient draw. Delayed effects referring to a particular permanent would need originating-object snapshots and their own zone-change semantics; this record must not be generalized to those effects unchanged.

## Persistence

State version increases from 2 to 3. New states always include `delayedDraws`; version-3 loads reject a missing, non-list, or malformed queue. Records require valid source/controller references, nonnegative source incarnations, an existing creation turn, and a nonempty label. Legacy version-1/version-2 states load with an empty queue. A nonempty queue falsely marked as a legacy version is rejected.

Older version-2 builds reject version-3 saves rather than silently dropping delayed draws, including draws not yet scheduled because a spell is still resolving. After promotion, the existing pending-trigger and ability-stack formats persist the draw. Keep the upgraded engine when resuming version-3 matches.

## Scope of evidence

Recognition is not whole-card gameplay certification. The 2026-10-19 local MTGJSON database contains 40 cards with the standalone sentence and additional composed/variant forms. This narrow change removes the final unread text from 16 standalone-spell definitions and five enter-trigger definitions in the full database; an imported-pool report must measure its own eligible subset.

The engine still has its existing same-controller trigger-order policy and no general skipped/additional-upkeep scheduler. This change does not claim to implement those rules, unrestricted delayed abilities, or unrelated card text.

## Imported-pool checkpoint — 2026-10-20

Measured against the same hashed `5.3.0+20261019` corpus and importer recorded in [the prior checkpoint](card-coverage-progress.md), relative to merged PR #328 (`e280cce21ce39972a59f9f43fd78755b7bedeb38`):

| Scope | Before this batch | After this batch |
| --- | --- | --- |
| All imported pools, recognized whole | 11,257 / 28,729 (39.2%) | 11,278 / 28,729 (39.3%) |
| Modern, recognized whole | 9,517 / 22,399 (42.5%) | 9,520 / 22,399 (42.5%) |

These are 21 additional wholly recognized imported entries, three Modern-legal. The focused [DelayedUpkeepDrawTest](../tests/Game/DelayedUpkeepDrawTest.php) exercises Flare, Heal, Ray of Erasure, Pyknite and Carrier Pigeons, plus synthetic negative and lifecycle controls. It does not certify every newly recognized card's other abilities. Coverage remains incomplete.
