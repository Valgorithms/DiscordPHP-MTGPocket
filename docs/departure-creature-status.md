# Current creature status for departure observers

A creature-leave observer must see the permanent as it was on the battlefield. Animated lands/artifacts lost animation during movement and were missed; bestowed Auras and impending permanents incorrectly acquired their printed creature status at the destination and triggered the observer.

`DepartureCharacteristics` holds exactly two immutable facts: the static definition and the existing engine's `isCreature()` result, captured before movement clears animation, counters or attachments. It replaces the private trigger context's definition-only value without adding more trigger arguments. Typed departure filters use the captured creature flag for creature/nontoken-creature/creature-token checks and retain the captured definition for other types and subtypes. Death dispatch reuses that same flag. Ordinary object-fit callers retain their previous behavior.

The snapshot is local to dispatch, not persisted; pending triggers already contain the chosen effects. No saved-state fields/version, parser, dependencies or environment contract changed. This is bounded look-back under [Comprehensive Rules 603.10 and 603.10a](https://media.wizards.com/2026/downloads/MagicCompRules%2020260619.pdf), not a complete last-known-state system. Continuous subtype changes, keyword/power/condition snapshots, gained/lost abilities and simultaneous departing observers remain separate. The engine's underlying `isCreature()` rules are reused, not comprehensively certified here.

## Verification

`DepartureCreatureStatusTest` adds 16 offline cases using paid activation/casting/removal, JSON saved pending removal and deterministic replay. Cases cover animated lands/artifacts into exile, graveyard and hand; ordinary noncreature and wrong-controller negatives; the previous controller; nontoken/creature-token filters; tokens ceasing to exist; and retaining land type. Actual Hopeful Eidolon bestow wording and a synthetic impending fixture verify that printed creatures currently not creatures do not notify creature-leave observers.

The final corrected fixtures failed 9 of 16 cases before the runtime change (155 assertions reached). After the fix, six related departure/death families pass 68 tests / 778 assertions. Changed-file Pint passes on all three PHP files with Xdebug disabled only in that process. Full offline suite and final exact-head CI are recorded in the PR. These are behavior-family tests, not whole-card certifications.

Recognition stays at the previous all-pool 11,282 / 28,729 and incrementally derived Modern 9,523 / 22,399; no fresh full Modern audit is claimed. Verified whole-card functional coverage remains unmeasured, and the 100% goal is incomplete. No import, simulation, live bot action, restart or deployment was performed.
