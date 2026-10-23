# Mass-removal selector preparation — 2026-10-23

This unpublished batch is based independently on main `710a47cde58525c24d59fb591ef81b6d63ee945d`. It does not include controller-damage PR #331, Fading #330, or equipped/enchanted #327.

## Implemented locally

The parser maps exact `destroy/exile each creature` wording to the existing `all creatures` selection contract, retaining supported qualifiers. It also recognizes `all artifacts, creatures, and lands`; the engine selects the union once per permanent. The existing trailing `They can't be regenerated` handler supplies the regeneration prohibition. Unknown qualifiers are not discarded.

Eight focused tests / 70 assertions pass in 0.095 seconds. They cover Consume the Meek, Jokulhaups, Obliterate, mana-value boundaries (including zero, three, and four), both controllers, artifact/creature overlap, regeneration with and without prohibition, indestructibility, ordinary countering versus Obliterate, payment and extra-target rejection without mutation, exile versus destruction, deterministic replay, and saved-stack resume. Earlier changed-file Pint and syntax checks passed; whitespace checks pass. Broad regressions have not run for this batch.

## Completed recognition measurement

The all-pools report uses the preserved 181-pool MTGJSON `5.3.0+20261022` corpus, selected-card SHA-256 `5583556fcc237fcaa5ebbf03c598644c4657773bd3bd415acb176ef779ed9fe1`, identical to the main baseline.

| Measurement | Main | Selector batch |
| --- | --- | --- |
| Names recognized whole | 11,278 / 28,729 | 11,282 / 28,729 |
| Names with unread text | 17,451 | 17,447 |
| Unread clauses | 23,292 | 23,284 |

The four newly recognized names are Consume the Meek, Culling Sun, Jokulhaups, and Obliterate. No previously recognized name was lost. Recognition is not a whole-card gameplay certification. Culling Sun's individual gameplay has not yet been exercised with its actual card fixture.

The Modern scan was interrupted at the parent's resource-priority update before producing a report. No Modern delta is claimed. The all-pools result and its input fingerprint are preserved in task-local `coverage-current-all.json`.

## Publication gates still pending

- Resume Modern measurement and focused adjacent mass-removal/regeneration regressions once the priority runtime cycle has a confirmed healthy startup. Do not rerun broad workloads during that cycle.
- Resolve backward saved-state compatibility before publication: new saves currently retain schema version 3, while an older version-3 engine does not know the new union selector. Its `allMatching()` fallback treats an unknown selector as nonland permanents, so it can misinterpret a saved pending union effect. A version gate or a deliberately compatible representation needs implementation and legacy/future-version tests. Same-build save/resume already passes; that does not establish older-build compatibility.
- Review simultaneous death/leave triggers separately. Existing mass removal moves objects sequentially through `moveTo()`; its death observers are drawn from remaining permanents. This selector change does not certify simultaneous-event handling, general last-known information, or every interaction of the newly recognized cards.
- Add final exact-head evidence and publish as a draft after the above checks. No draft exists for this branch yet. Parent retains merge decisions.

No live bots, deployments, credentials, Discord sessions, Gaia resources, paid services, or model/service settings were used. The shared Desktop checkout remains untouched.
