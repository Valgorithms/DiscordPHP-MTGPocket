# Mass-removal selector checkpoint — 2026-10-23

This branch is based independently on main `710a47cde58525c24d59fb591ef81b6d63ee945d`. It does not include controller-damage PR #331, Fading #330, or equipped/enchanted #327.

## Runtime change

The parser maps exact `destroy/exile each creature` wording to the existing `all creatures` contract while retaining supported qualifiers. It also recognizes `all artifacts, creatures, and lands`; the engine selects that union once per permanent. The existing trailing `They can't be regenerated` handler supplies the prohibition. Unknown qualifiers are not discarded.

All new saves use schema 4 so actual older readers reject the new selector instead of falling back to nonland permanents. Existing version-1/2 defaults and version-3 delayed-state requirements remain. See [the saved-state contract](selector-state-compatibility.md), including pinned reader hashes and the unsupported downgrade boundary.

## Actual local verification

At `65134ebb`, serial offline checks passed:

- `SelectorStateCompatibilityTest`: 5 tests / 22 assertions.
- `MassRemovalSelectorTest`: 9 / 87, including actual Culling Sun wording, both players, mana-value bounds, creature-only selection, regeneration and saved-stack resolution; union deduplication, indestructibility, countering, target/payment rejection, exile and deterministic replay.
- `DelayedUpkeepDrawTest` and `EntryLifeChoiceTest`: 38 / 333.
- Seven separate PHP processes using the actual pinned main/PR #331 writers and readers: new-save rejection by both old readers, plus old-state preservation and exactly-once damage/draw by the new reader.
- Pint on all eight changed PHP files: passed in 4.928 seconds with process-local `-d xdebug.mode=off`. The same Game.php check exceeded its 30-second cap with local Xdebug develop mode enabled; it passed in 4.517 seconds with that mode disabled. No machine PHP configuration changed.

The broad offline suite reached 708 tests / 31,508 assertions in 67.821 seconds but exited 1: one risky test from PHP 8.5.12's null-array-offset deprecation in unchanged `ManaPayer.php:141`. The two imported-pool/deck tests skipped as expected, using an empty task-local data directory. This is not a broad-suite pass; the mana diagnostic is tracked separately.

## Recognition measurement

The all-pools report uses the preserved 181-pool MTGJSON `5.3.0+20261022` corpus. Selected-card SHA-256 `5583556fcc237fcaa5ebbf03c598644c4657773bd3bd415acb176ef779ed9fe1` matches the main baseline.

| Measurement | Main | Selector branch |
| --- | --- | --- |
| Names recognized whole | 11,278 / 28,729 | 11,282 / 28,729 |
| Names with unread text | 17,451 | 17,447 |
| Unread clauses | 23,292 | 23,284 |

The four newly recognized names are Consume the Meek, Culling Sun, Jokulhaups and Obliterate, with no recognition losses. These counts are independent of PR #331 and are not whole-card gameplay certification. The functional coverage denominator/count remains unmeasured.

Modern measurement remains incomplete. The original interrupted scan produced no report. On recovery, the default 128 MiB failed during corpus loading; a subsequent explicitly bounded 1 GiB run hit its 180-second process-tree timeout without a report. Both stopped. Query-plan inspection identified a name-index scan of cards plus indexed legality lookups; a membership query is being evaluated without altering the database or importing cards. No Modern delta is claimed.

## Remaining work and limits

Publish this bounded selector milestone as a draft, finish the Modern measurement and resolve the inherited full-suite diagnostic before readiness. Record exact-head CI before merging. General simultaneous death/leave handling is a separate functional gap: the existing mass-removal path moves objects sequentially, and death observers are drawn from remaining permanents. These tests do not certify simultaneous events, general last-known information or every interaction of the recognized cards.

No configuration contract changed, so `env.example` needs no new variables. No live bot, deployment, credentials, Discord session, Gaia operation or model/service setting was used. The original Desktop checkout remains untouched.
