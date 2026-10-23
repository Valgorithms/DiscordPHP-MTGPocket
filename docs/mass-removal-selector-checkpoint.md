# Mass-removal selector checkpoint — 2026-10-23

This branch was based independently on main `710a47cde58525c24d59fb591ef81b6d63ee945d`. It now includes the separately reviewed floating-mana fix from PR #333, merged as `86d391b93760d6a103bcae644356d54bb632a545`. It does not include controller-damage PR #331, Fading #330, or equipped/enchanted #327.

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

The broad offline suite reached 708 tests / 31,508 assertions in 67.821 seconds but exited 1: one risky test from PHP 8.5.12's null-array-offset deprecation in unchanged `ManaPayer.php:141`. The two imported-pool/deck tests skipped as expected, using an empty task-local data directory. This initial run was not a broad-suite pass. The same diagnostic was reproduced against actual main, then fixed independently in PR #333 with mixed floating/produced/convoke regression coverage (ManaTest: 6 tests / 36 assertions; adjacent expend: 1 / 7). After integrating that fix, the broad offline suite at `fc77f4389269fa2dca56d600ae972ef747f8b0ec` passed: 709 tests / 31,514 assertions / two expected imported-data skips, exit 0, 63.088 seconds wall time under the 120-second process-tree cap. No imported-deck simulations ran.

## Recognition measurement

The all-pools report uses the preserved 181-pool MTGJSON `5.3.0+20261022` corpus. Selected-card SHA-256 `5583556fcc237fcaa5ebbf03c598644c4657773bd3bd415acb176ef779ed9fe1` matches the main baseline.

| Measurement | Main | Selector branch |
| --- | --- | --- |
| Names recognized whole | 11,278 / 28,729 | 11,282 / 28,729 |
| Names with unread text | 17,451 | 17,447 |
| Unread clauses | 23,292 | 23,284 |

The four newly recognized names are Consume the Meek, Culling Sun, Jokulhaups and Obliterate, with no recognition losses. These counts are independent of PR #331 and are not whole-card gameplay certification. The functional coverage denominator/count remains unmeasured.

A fresh full Modern audit did not finish: the default 128 MiB exhausted memory during corpus loading; a subsequent 1 GiB run hit its 180-second process-tree cap, and an alternative full legality-membership query hit its 30-second cap. All timed-out owned process trees stopped. No fresh full Modern report or pattern/clause delta is claimed.

A bounded incremental measurement instead reuses the matched full-all reports above and the preserved Modern baseline (9,520 / 22,399; selected-card SHA-256 `9ee46f974692dd3e5a60066eea49c926966ba6b551851a147aaeb28c480375f1`). It verifies identical all-pool corpus metadata and four added / zero lost recognized names, then queries only those four names through the existing SQLite name/UUID indexes, with `mode=ro` and `PRAGMA query_only=ON`:

```sql
SELECT DISTINCT c.name FROM cards c
JOIN cardLegalities l ON l.uuid = c.uuid
WHERE c.name IN (?, ?, ?, ?) AND l.modern = 'Legal';
```

Consume the Meek, Culling Sun and Obliterate are Modern-legal; Jokulhaups is not. The incremental Modern recognition count is therefore **9,523 / 22,399 (+3)**. The lookup completed in 417 ms (305 ms when rerun with explicit source/input provenance). It does not rerun the full Modern audit or establish gameplay support. Parser/engine sources are unchanged from measured selector `65134ebb`; the separately integrated mana diagnostic fix does not change parser recognition.

Preserved input report SHA-256 values, in baseline-all/current-all/baseline-Modern order:

- `7449427951d50f8d39126ad0b80d1697308207bf9b8a8964601f39593af8a69b`
- `a7b07773beb24532c66f282d65ac907968d6cd83f2dc8cb978457d76092e20ad`
- `48b26db8a3c7d1f55114400e798e16e1b54014df372c59657b54d8c76dfc9a60`


## Remaining work and limits

PR #332 publishes this bounded selector milestone. Its initial head `0d525959293886c658e0eca32db21e885407e688` passed Pint and PHPUnit in all PHP 8.3/8.4/8.5 CI lanes. The integrated head needs fresh CI before merging; previous CI is not evidence for the new head. General simultaneous death/leave handling is a separate functional gap: the existing mass-removal path moves objects sequentially, and death observers are drawn from remaining permanents. These tests do not certify simultaneous events, general last-known information or every interaction of the recognized cards.

No configuration contract changed, so `env.example` needs no new variables. No live bot, deployment, credentials, Discord session, Gaia operation or model/service setting was used. The original Desktop checkout remains untouched.
