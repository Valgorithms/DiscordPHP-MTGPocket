# Garza's Assassin: exact half-life Recover

Base: `3d035c8939d40a90676fc1a8fd18ee35f7eca925` (frozen #449). Source/tests: `43058091c0875800302086404b5e27219250c5c3`. This draft depends on the unmerged #444/#445/#447/#448/#449 stack; it neither replaces their review nor includes separate #446 disguise-copy work. The existing #444–449 branches remain unchanged. Draft publication does not establish independent code review or merge readiness; consult GitHub for current exact-head checks.

## Causal baseline and provenance

The complete cached CSP Garza's Assassin (UUID `a49f3a52-aa88-598a-818b-23b314cfc33c`, pool SHA256 `b5c1a9c3cf20afeda999baaa7e11c75ee6088a52f2641b898c720255956d847e`, MTGJSON5.3.0+20261022) includes sacrifice-self destroy-nonblack and Recover—Pay half your life, rounded up. The three-record fixture also retains complete cached M10 Raging Goblin and Lightning Bolt with source-pool hashes; fixture SHA256 `2e7a9a0a4a53cdbb774c75a4e01ad583de243fdd731579e01e2ea09e65bda04a`. No import or new corpus scan occurred.

On the frozen base, both seats actually paid R for Goblin and BBB for Assassin. Sacrificing Assassin paid the activation cost; its independent ability killed the owned Goblin. No Recover entry followed: **2tests/16reached assertions/2failures**. Later payment/replay assertions were unreachable. A separate parser probe found only the exact half-life Recover clause unread, while sacrifice/destroy already parsed. The final selected complete record has no unread clauses; that is local recognition, not a certificate or updated aggregate percentage.

## Narrow correction

One exact symbolic string identifies the cost; it is not a dynamic-cost language. Both payment preview and acceptance call the existing planner with `ceil(current life/2)` as a life cost. The current life is read at resolution, so a paid Bolt response changes the price. Existing optional Recover identity/return/exile and mana-cost behavior remain in use. No damage or prevention path is entered. The engine permits spending the last life, completes resolution, then performs state-based actions; practice policy chooses to decline at1 life.

[Official September25,2026 Comprehensive Rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260925.pdf),702.59a and118.3/118.3b, supply the graveyard-trigger and life-payment rules. General life-payment restrictions/observers are not implemented by this slice.

## Persistence and rollout prerequisite

New state schema21 owns the half-life cost. Older ordinary mana-Recover and schema20 fixed-Ward pending choices remain readable. New half-life entries/choices mislabelled older are refused; no such old executable could produce them. Half-life payment cannot legitimately create a painful-mana damage continuation, so that forged combination is refused. Source incarnation, wrong-player/choice/option and replay validation stay intact.

**Existing schema17/18 already-paid bare-target actions remain an upgrade hold. Finish/drain affected actions under the old version before upgrade, or obtain an explicitly reviewed recovery policy if such records exist. Do not invent original incarnations, refund, cancel or silently migrate.** No live match storage was read or altered. New schema21 saves require this executable or a reviewed compatible successor.

## Actual verification

- Dedicated Garza plus persisted panels: **29tests/409assertions**.
- Combined focused Recover/Fog/Ward/ledger/panel regression selection: **219tests/1705assertions**.
- Exact source full suite: **2745tests/66303assertions**,2unchanged missing-pool/rental skips, no errors/failures/warnings/risky;01:06.583,378MiB (runner66.912s),PHP8.5.12,512MiB,Xdebugoff,isolated temporary data.
- Scoped Pint7files passed;98normalized source pins verified. Publication-status metadata/docs follow without source/test changes. These local results do not establish hosted acceptance of the publication head; read its GitHub checks.

The earlier unexplained native PHP0xC0000005 crash and all Ward/Fog/Recover baseline histories remain historical evidence; this pass does not explain them. Garza's first setup attempt requested absent M10 Shock; using cached Lightning Bolt corrected that setup error. A later parser probe omitted its root argument and failed before loading code; its corrected invocation passed. Neither is represented as a gameplay failure or erased.

## Coverage limits

Ledger now records39scoped names,3explicit-gap names,2overlaps and **zero whole-card certificates**. Historical recognition stays11282/28729. Cached older printing only: odd/even/low/last-life, both seats, actual response, original source identity, decline/counter/ownership, invalid/repeated decisions, JSON/panel persistence and practice policy. Seeded life/prevention/doubling and reflection source-return/counter/compatibility probes are unit boundaries, not additional supporting-card certificates. Other Recover life formulas, general payment restrictions/observers, Ward discard/dynamic costs, broad zone/merged/APNAP/continuous/copy/replacement behavior and newest-printing/whole-card review remain outside. Ward is still default off.

## Separate future Ward discard design seam

Cached MID Graveyard Trespasser // Graveyard Glutton (`00b8c874-ae08-5023-aef0-65d2e3e29d2a`, pool SHA256 `5d0fd5bffb824a0eac7d1855faa68f58c19cd80e5922e0ac499bc676b1d507b8`) supplies a future actual-card lead, not a new fixture or reproduced runtime claim. `discard(seat,ids)` is an effect/cleanup API without cost-choice IDs or hand incarnation validation; `discardCheapest()` is an automatic heuristic. A reviewed versioned cost/selection contract must bind a fresh optional choice to pinned hand identity and the original offending stack entry, then reuse physical `discardCards()`/Madness routing exactly once. Fixed Ward models currently refuse that cost. No runtime wiring or enabling is included here.
