# Untap-symbol combat support

The existing untap-cost path now recognizes two specific cached Eventide forms: Patrol Signaler creates a 1/1 white Kithkin Soldier, and Duergar Mine-Captain gives attacking creatures +1/+0 until end of turn. The latter applies to the creatures attacking when the ability resolves, including an opponent's attackers; creatures that attack later do not receive it. The shared cost path retains its stun replacement and summoning-sickness behavior.

At base `90f4b0db31ee2da001fe45f0358e00c6e2204c52`, six paid-cast and activation cases reached 24 assertions and all errored because these abilities were unread. The correction passes 16 tests and 150 assertions: both seats, both turn contexts for Mine-Captain, token characteristics, activation timing, stun, invalid costs, source removal and JSON restoration. The opposing creatures and response spells are fixtures, not additional card certificates.

The existing EVE pool SHA-256 is `7f13f2b3ca96c79758df3b6de99dfe8c0801fa28339fbcc3b7bd5ab9fc2b1e46`. Patrol Signaler UUID is `f622649f-9bdc-505a-9c29-6f7abf1dec8f`; Duergar Mine-Captain UUID is `d55259b7-19b1-5cde-ad49-9098a8d78779`. Normalized fixture SHA-256 is `c40901e5e5e98add7d9be47aaf210cc4db27de3fd5e577de2bb56e9e3a2e5ca9`. No corpus import or save schema change is involved; rollback restores the old unread parser behavior for future loads.

Only these forms and tested paths are covered. Other untap-symbol abilities, untap observers, source re-entry, general replacement ordering, newer printings and whole-card certification remain outside the evidence. Held PRs #444–#452 need later integration and revalidation against current main.

## Control changes during combat

Review found that an attacker changing controller while Mine-Captain's ability was on the stack remained in combat and could be boosted. At published head `bff434533c70086407c3360123b5cd8a4f1742a5`, eight cases reached 28 assertions and all failed for temporary or Aura control changes, including away-and-back. The correction removes a permanent from combat when its controller actually changes, including Aura return and temporary-control cleanup, while preserving the blocked status of surviving attackers and any remaining multiple-block relationship. Effects that do not change controller leave combat unchanged. This follows [rule 506.4](https://media.wizards.com/2026/downloads/MagicCompRules%2020260807.pdf).

Twenty-four focused cases and 136 assertions pass across both seats, control types, away-and-back, unchanged-controller effects, unaffected attackers, a blocker assigned to multiple attackers and save/restore. Other combat-removal causes, broader continuous-control layering, and the previously stated untap and whole-card limitations remain outside this correction. The earlier 16-test result and baseline remain historical.
