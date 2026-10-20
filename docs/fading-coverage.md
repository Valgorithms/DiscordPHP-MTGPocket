# Fading coverage checkpoint — 2026-10-20

This narrow continuation adds **Fading N**, not a general promise that every card containing that keyword is fully supported. Recognition and behavior evidence remain separate; see the [architecture boundaries](rules-engine-continuation.md) and [shared corpus checkpoint](card-coverage-progress.md).

## Rules and implementation

The current [Wizards Comprehensive Rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260925.txt), effective September 25, 2026, distinguish:

- **702.32a, fading:** enter with fade counters; each controller upkeep creates an ability that removes one counter if possible, otherwise sacrifices the permanent. Removing the final counter does not itself sacrifice the permanent or create another trigger.
- **701.21a, sacrifice:** the resolving ability's controller cannot sacrifice a permanent now controlled by somebody else. A control change does not prevent the ability from removing a counter.
- **702.63, vanishing:** a separate time-counter mechanic. This batch does not change or comprehensively validate the existing vanishing implementation.

Only an exact numeric fading keyword on a permanent is accepted. Entry uses the existing generic counter contract; upkeep uses the existing trigger stack, original-controller record and source-incarnation validation. Sacrifice uses the existing sacrifice helper so its watchers fire. No persistence fields, external services, runtime I/O or new decision policies are introduced.

## Recognition measurement

Pre-rebase baseline: main `e280cce21ce39972a59f9f43fd78755b7bedeb38` (#328). These numbers isolate fading's **+9 all-pool / zero Modern** delta before integration with the delayed-draw batch. Both reports use the unchanged imported pools and hashed MTGJSON database described in the [shared corpus checkpoint](card-coverage-progress.md). No download or reimport occurred.

| Imported scope | Baseline read whole | With fading |
| --- | --- | --- |
| Modern | 9,517 / 22,399 (42.5%) | 9,517 / 22,399 (42.5%) |
| All imported pools | 11,257 / 28,729 (39.2%) | 11,266 / 28,729 (39.2%) |

The nine newly wholly recognized cards are **Blastoderm**, **Cloudskate**, **Defender en-Vec**, **Jolting Merfolk**, **Phyrexian Prowler**, **Rejuvenation Chamber**, **Skyshroud Behemoth**, **Skyshroud Ridgeback**, and **Woodripper**.

Other cards lose only their unsupported fading line. Ancient Hydra, Parallax Dementia, Parallax Nexus, Parallax Tide, Parallax Wave, Rusting Golem, Saproling Burst and Tangle Wire still report their unresolved extra clauses. Parallax Inhibitor and Old Fogey remain unsupported. None receives a blanket whole-card support claim.

## Behavior evidence

[FadingTest](../tests/Game/FadingTest.php) has 19 offline tests / 311 assertions. It checks:

- Actual corpus wording, exact parsing and negative controls for unsupported clauses.
- Casting, entry counters, tapped entry and the complete upkeep lifecycle of Blastoderm, Cloudskate, Skyshroud Ridgeback, Skyshroud Behemoth and Rejuvenation Chamber.
- Existing counter-cost effects on Phyrexian Prowler, Jolting Merfolk, Defender en-Vec and Woodripper, including rejection when no counter can be paid.
- Fading zero, indestructible and sacrifice watchers; spending the last counter outside upkeep and in response; adding a counter in response to a zero-counter trigger.
- Controller rather than owner upkeep, control changes in response, source removal, blink and fresh object incarnations, and multiple fading instances.
- Save/resume with casting and upkeep abilities on the stack, zero counters, control changes and returned permanents.
- A distinct existing vanishing lifecycle regression; this is not a claim of complete vanishing rules support.

The default full suite passes with its two normal absent-corpus skips: 692 tests / 31,507 assertions. Both corpus-dependent tests then pass separately against the existing local data: 2 tests / 71,268 assertions, including 50 official-deck simulations. Pint passes for all three changed PHP files. No live bot, Discord acceptance, publication, release, deployment or token-bearing build was run.
