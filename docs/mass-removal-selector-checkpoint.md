# Mass-removal selector preparation — 2026-10-23

This unpublished batch is based independently on main `710a47cde58525c24d59fb591ef81b6d63ee945d`. It does not include controller-damage PR #331, Fading #330, or equipped/enchanted #327.

## Implemented locally

The parser maps exact `destroy/exile each creature` wording to the existing `all creatures` selection contract, retaining supported qualifiers. It also recognizes `all artifacts, creatures, and lands`; the engine selects the union once per permanent. The existing trailing `They can't be regenerated` handler supplies the regeneration prohibition. Unknown qualifiers are not discarded.

At preparation commit `3109819dd69afbe175890fdc42af29f9f88fe761`, eight focused tests / 70 assertions passed in 0.095 seconds. They cover Consume the Meek, Jokulhaups, Obliterate, mana-value boundaries (including zero, three, and four), both controllers, artifact/creature overlap, regeneration with and without prohibition, indestructibility, ordinary countering versus Obliterate, payment and extra-target rejection without mutation, exile versus destruction, deterministic replay, and saved-stack resume. Earlier changed-file Pint and syntax checks passed. Those results precede the proposed compatibility version gate; its tests remain unrun under the source-only restriction. Broad regressions have not run for this batch.

## Completed recognition measurement

The all-pools report uses the preserved 181-pool MTGJSON `5.3.0+20261022` corpus, selected-card SHA-256 `5583556fcc237fcaa5ebbf03c598644c4657773bd3bd415acb176ef779ed9fe1`, identical to the main baseline.

| Measurement | Main | Selector batch |
| --- | --- | --- |
| Names recognized whole | 11,278 / 28,729 | 11,282 / 28,729 |
| Names with unread text | 17,451 | 17,447 |
| Unread clauses | 23,292 | 23,284 |

The four newly recognized names are Consume the Meek, Culling Sun, Jokulhaups, and Obliterate. No previously recognized name was lost. Recognition is not a whole-card gameplay certification. A Culling Sun source test now covers actual wording, both players, the mana-value boundary, creature-only selection, regeneration, and saved-stack resolution. It remains unrun under the source-only hold; the earlier 8-test result does not cover it.

The Modern scan was interrupted at the parent's resource-priority update before producing a report. No Modern delta is claimed. The all-pools result and its input fingerprint are preserved in task-local `coverage-current-all.json`.

## Publication gates still pending

- Resume Modern measurement and focused adjacent mass-removal/regeneration regressions once the priority runtime cycle has a confirmed healthy startup. Do not rerun broad workloads during that cycle.
- Verify the [proposed schema-4 rejection contract](selector-state-compatibility.md) before publication. The source now bumps `STATE_VERSION` to 4 and retains the existing loader gate/migrations. Actual historical-reader rejection, legacy migration, and new same-build round trips still need execution; no compatibility pass is claimed yet.
- Review simultaneous death/leave triggers separately. Existing mass removal moves objects sequentially through `moveTo()`; its death observers are drawn from remaining permanents. This selector change does not certify simultaneous-event handling, general last-known information, or every interaction of the newly recognized cards.
- Add final exact-head evidence and publish as a draft after the above checks. No draft exists for this branch yet. Parent retains merge decisions.

No live bots, deployments, credentials, Discord sessions, Gaia resources, paid services, or model/service settings were used. The shared Desktop checkout remains untouched.
