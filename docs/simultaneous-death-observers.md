# Destroy-all death observers

Destroy-all effects previously moved permanents one at a time and only notified observers still on the battlefield. Zulaport Cutthroat or Grim Haruspex removed first missed later qualifying deaths; removing the same observer last produced a different result. The corrected baseline reproduces four failures in twelve paid-spell cases. A missing token flag in an earlier test fixture was corrected before that baseline was recorded.

The engine now retains a pre-event observer registry, records actual deaths of the selected object incarnations, and dispatches those death notifications after destruction finishes. Each event carries its former controller, creature characteristics, power and +1/+1-counter fact. The registry retains source state, including face-down status and once-per-turn marks. Its synchronous scope restores in `finally` and never becomes saved state. Existing single-target dispatch remains immediate.

This implements a bounded part of the pre-event lookback described by [Comprehensive Rules 603.10 and 603.10a](https://media.wizards.com/2026/downloads/MagicCompRules%2020260619.pdf). The existing prevention, replacement and movement internals remain sequential. This change does not establish general atomic simultaneous events, continuous-characteristic snapshots, intervening conditions, replacement choices or APNAP player decisions. Simultaneous state-based deaths and mass sacrifice are still explicit gaps.

## Exact printings and regression scope

The checked-in [fixture](../tests/Fixtures/simultaneous-deaths.json) contains complete selected card records from the ledger's pinned corpus `5583556fcc237fcaa5ebbf03c598644c4657773bd3bd415acb176ef779ed9fe1`:

| Card | Selected set | Scope |
| --- | --- | --- |
| Zulaport Cutthroat | A25 | Own and other controlled deaths, including tokens |
| Grim Haruspex | PLST | Other controlled nontoken deaths; paid face-down morph negative |
| Damnation | 2X2 | Paid destroy-all spell, regeneration prohibited |
| Evacuation | CMM | Paid return-to-hand non-death control |

Normalized-LF fixture SHA-256: `44e9b312a983d7cc1e477ba44d6049c961fcb2e7c6096352836552e2aa8c0cb6`. The PHP test pins these bytes, and the ledger pins the test source. No corpus/parser walk, download, database import or simulation is needed to execute the regressions.

[SimultaneousDeathObserverTest](../tests/Game/SimultaneousDeathObserverTest.php) checks both insertion orders, formerly controlled opposing-owned observers, self/other and token/nontoken restrictions, both players' observers with unequal qualifying counts, and JSON replay before the spell and with death abilities already on the stack. Supporting synthetic probes check surviving indestructible/regenerated/shielded observers, prevented or exile-replaced victims, no-regeneration, distinct per-death power/counter facts, once-per-turn marks and later separate deaths. Those synthetic probes do not certify additional corpus cards.

The ledger adds two scoped exemplar names (five total); its explicit gap count remains three and evidence/gap overlap becomes two. Recognition remains 11,282 of 28,729 names. Whole-card certificates remain zero: partial regressions do not establish either observer's every ability and interaction. No saved-state schema or environment configuration changed; `env.example` has no new entries.
