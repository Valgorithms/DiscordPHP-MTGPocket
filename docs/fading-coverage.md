# Fading coverage checkpoint — 2026-10-20

This narrow continuation adds **Fading N**, not a general promise that every card containing that keyword is fully supported. Recognition and behavior evidence remain separate; see the [architecture boundaries](rules-engine-continuation.md) and [shared corpus checkpoint](card-coverage-progress.md).

## Rules and implementation

The current [Wizards Comprehensive Rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260925.txt), effective September 25, 2026, distinguish:

- **702.32a, fading:** enter with fade counters; each controller upkeep creates an ability that removes one counter if possible, otherwise sacrifices the permanent. Removing the final counter does not itself sacrifice the permanent or create another trigger.
- **701.21a, sacrifice:** the resolving ability's controller cannot sacrifice a permanent now controlled by somebody else. A control change does not prevent the ability from removing a counter.
- **702.63, vanishing:** a separate time-counter mechanic. This batch does not change or comprehensively validate the existing vanishing implementation.

Only an exact numeric fading keyword on a permanent is accepted. Entry uses the existing generic counter contract; upkeep uses the existing trigger stack, original-controller record and source-incarnation validation. Sacrifice uses the existing sacrifice helper so its watchers fire. No persistence fields, external services, runtime I/O or new decision policies are introduced.

## Recognition measurement

Integrated baseline: main `710a47cde58525c24d59fb591ef81b6d63ee945d` (#329). These numbers measure fading's **+9 all-pool / zero Modern** delta after integration with the delayed-draw batch. Both reports use the unchanged imported pools and hashed MTGJSON database described in the [shared corpus checkpoint](card-coverage-progress.md). No download or reimport occurred.

| Imported scope | Baseline read whole | With fading |
| --- | --- | --- |
| Modern | 9,520 / 22,399 (42.5%) | 9,520 / 22,399 (42.5%) |
| All imported pools | 11,278 / 28,729 (39.3%) | 11,287 / 28,729 (39.3%) |

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

The final integrated full suite passes against the existing local corpus: **713 tests / 102,978 assertions, zero skips**, including all imported-card parsing and 50 official-deck simulations. Pint and whitespace checks pass. No live bot, Discord acceptance, release, deployment or token-bearing build was run.

## Current main reconciliation (2026-10-26)

Published PR parent `75e2ab6f6a99236460632b8f00c9e0efb96c6195` is preserved as a merge parent with main `bd96559c492f6ae032a8a29898105e018f2020f1`; no published history is rewritten. Actual conflict: `docs/rules-engine-continuation.md`. Both documentation/evidence sets are retained. Source pins are refreshed for the automatic combined engine/test tree; all previous scoped claims remain unchanged at37names/zero whole-card certificates. Original recognition reports and validation are historical, not an aggregate update. Focused/full offline validation and exact-head hosted CI remain pending. No live bot, credential/security changes, deployment or independent merge.

Full-tested integrated source 2e0dcb18036d69b081c431a1e03710903b565361: 2384 tests/63030 assertions,2expected missing-pool/rental skips,no risky tests;PHPUnit01:05.773/334.00 MB. Focused638tests/8188assertions,fullnonmutatingPint,all67normalizedPHPpinsPASS. PHP8.5.12/512MiB/Xdebugoff/isolateddata+temp/existingdeps. Final unpublished merge amend changes verification metadata/docs only;production/testbytes match. Hosted acceptance requires the final exact PR head.
