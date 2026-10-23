# Battlefield-face leave abilities

Own `leaves` and `to_graveyard` abilities now use the departing permanent's static battlefield definition. Previously, moving the object cleared its transform/face-down state before these events chose abilities, so a back face used the front face's ability and a face-down permanent incorrectly acquired a printed ability as it left.

`Game::moveTo()` captures one immutable departing definition before movement and reuses the existing source-definition argument to `trigger()`. The earlier own-death and surviving subtype-death fixes use the same captured definition. The actual destination object and its new incarnation remain intact; the controller of the departing permanent still controls the triggered ability. `to_graveyard_anywhere` keeps reading the new graveyard object rather than the battlefield face.

This follows the look-back distinction in [Comprehensive Rules 603.10 and 603.10a](https://media.wizards.com/2026/downloads/MagicCompRules%2020260619.pdf). It does not implement general last-known information: continuous gained/lost abilities, conditions evaluated from reset object state, observer object-kind filters and simultaneous departing observers remain separate work.

## Verification

`DepartingPermanentAbilityTest` adds 15 offline cases. Synthetic front/back faces distinguish one-life from two-life abilities through paid transform/morph/removal actions. Cases cover graveyard, exile and hand destinations, face-down absence of abilities, the previous controller of a stolen permanent, ability labels, JSON reload with pending removal and with a stacked trigger, and deterministic replay. Exile and bounce do not trigger the battlefield-to-graveyard ability.

Actual Thragtusk wording creates a 3/3 Beast after a paid Unsummon. Actual Hatching Plans wording draws three cards after a paid Naturalize and does not draw when milled from the library. These are focused behavior fixtures, not whole-card certifications.

Before the runtime change, the new class failed 8 of 15 cases (102 assertions reached); the unaffected front-face, destination and actual-card controls passed. After the fix, the new class plus the three preceding death families passed 35 tests / 426 assertions with Xdebug disabled only in that PHP process. Final full-suite and exact-head CI results are recorded in the PR.

No parser, saved-state fields/version, dependencies or environment configuration changed. Recognition remains the previously measured all-card 11,282 / 28,729 and incrementally derived Modern 9,523 / 22,399; these are not functional coverage counts or a fresh full Modern audit. A verified whole-card functional denominator/count remains unmeasured, and the 100% functional coverage goal is incomplete. No import, simulation, bot restart or deployment was performed.
