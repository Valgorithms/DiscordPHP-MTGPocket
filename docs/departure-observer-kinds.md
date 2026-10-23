# Departure observer type look-back

Artifact, enchantment, land and subtype departure observers now check the departing static battlefield definition instead of its reset destination face. Previously an artifact front face transformed into an enchantment incorrectly notified artifact observers and missed enchantment observers. The reverse transformation could miss the artifact event entirely because the event gate read the new front face.

`Game::moveTo()` passes its existing captured definition to the three typed departure observer families. The artifact-event gate uses that same definition. A departing observer included in its own event also selects abilities from that definition; surviving observers retain their own current definitions. `trigger()` passes the existing context definition through the optional static-definition argument of `objectFits()`. Other object-fit callers retain their current behavior, including recursive compound filters.

This milestone adds bounded static type/subtype look-back under [Comprehensive Rules 603.10 and 603.10a](https://media.wizards.com/2026/downloads/MagicCompRules%2020260619.pdf). The subsequent [creature-status snapshot](departure-creature-status.md) also captures the engine's current creature flag. General last-known state, continuous subtype changes, gained/lost abilities, reset-state trigger conditions and simultaneous departing observers still need separate handling. No additional trigger arguments, persisted fields, parser matches or configuration are introduced.

## Evidence

`DepartureObserverKindTest` adds 17 synthetic behavior cases with paid transform/morph/removal actions. Positive and negative controls cover both artifact/enchantment face directions, a transformed land, Human subtypes, face-down subtype absence and creature status, a wrong controller, exile versus graveyard, and departing observers that include themselves. Pending removal is JSON saved/reloaded and replayed to identical final state. Synthetic faces isolate the event contracts and do not represent new whole-card certifications.

With corrected fixtures, the unchanged runtime failed 10 of 17 cases (149 assertions reached). After the fix, five related departure/death families pass 52 tests / 605 assertions. Changed-file Pint passes after formatting the new test's multiline method arguments. The full offline suite at runtime commit `bd663c205a236e0eaa8a05a1af3768937366de4f` passed 761 tests / 32,119 assertions with two expected imported-data skips. A companion PHPDoc correction changes no executable code; final exact-head CI is recorded in the PR.

Recognition remains the previously measured all-pool 11,282 / 28,729 and incrementally derived Modern 9,523 / 22,399; no fresh full Modern audit or new recognition count is claimed. Verified whole-card functional coverage remains unmeasured, and 100% functionality is incomplete. No import, simulation, live bot action, restart or deployment was performed.
