# Private frozen chosen-target identity correction

Historical synchronous checkpoint: the later [private paid-continuation integration](ward-paid-target-integration.md) composes this correction with #444/#445. The separate evidence below does not establish that combined tree.

This private step follows reviewed observer checkpoint `a7ad4964a6f107fb21b42f15feadb10c1405898d`. It corrects chosen target identity across synchronous costs and spell copying. It adds no Ward trigger, payment choice, selected ordering, card certificate or corpus scan. Public PRs #444–#447 remain frozen and unchanged.

## Reproduced behavior and narrow contract

Before the correction, `pushAbility()` called `pinTarget()` after costs and even rewrote an already pinned incarnation. The original four-case baseline reached 22 assertions and failed four times: both bare and explicitly pinned sacrifice-cost targets changed from incarnation 1 to 2, and an actual paid Giant Growth / paid blink / paid copy sequence pumped the returned creature from 2/2 to 5/5 for either seat. Later assertions after each failure were not executed. The expanded maintained test on that same reviewed parent reached 72 assertions, with seven failures in 11 cases, additionally exposing legacy targeted-trigger payment and Bloodrush late pins. Both failure receipts and exact test/source hashes remain preserved privately.

Chosen targets are frozen once after validation and before costs. A bare object target gains its current incarnation; a supplied pinned identity remains verbatim. Non-object tokens and all slots retain their order and multiplicity. Activation, targeted-trigger payment and Bloodrush pass that gameplay-owned list explicitly to `pushAbility()`. Copies pass the original stack entry's stored targets verbatim. Optional diagnostic observation never supplies gameplay truth. The optional frozen input is trusted private caller data, not a new unvalidated public endpoint.

`pinTarget()` and event-context callers retain their existing semantics. Target validation, resolution legality and `targetObject()` remain unchanged: a stale incarnation cannot become legal by leaving and returning or by being reanimated. A still-legal second target resolves normally. Source sacrifice/discard does not remove an independent ability. Save/load retains the stale pin, and failed target validation or activation payment does not partially mutate gameplay or publish an observation. The existing observer mismatch regression now verifies matching original pins and an observed, still-unselected event; its prior held-mismatch result remains in the parent checkpoint and historical receipts.

The fixtures reuse existing Giant Growth data and ModernCardTextTest blink, copier, mana-sacrifice and Bloodrush wording. New synthetic sacrifice, two-target entry and instant reanimation enablers are explicitly unit fixtures. They do not establish printed enabling-card support or whole-card certification. Legacy upfront Ward/tax policy and automatic cost selection are deliberately retained.

## Required later integration with #444 / #445

This source is on the synchronous main-derived observer branch, not the frozen damage/Recover continuation branches. Composition with #444/#445 must carry the same pre-cost frozen chosen-target list through their already-paid activation/trigger/cast continuation, using the reviewed targets payload and corresponding validators. Resume must neither select/pin the targets again nor demand that paid targets remain live. Stale targets stay stored and are checked at resolution. The original committed stack identity must still be returned before later copy appends. That composition requires its own combined source, interruption/save/replay and exactly-once payment tests; the present run is not evidence for an unbuilt combination.

Controller-chosen simultaneous-trigger order, genuine-tax separation, optional Ward payment, persisted event consumption, unsupported Ward costs and broad APNAP remain subsequent work. Observation is default-off and ephemeral. Historical recognition, scoped names, explicit card gaps and zero whole-card certificates are unchanged.

## Exact local verification

Immutable runtime/test source `48de6f52229a79a6f5e847db3bd33a28474c0921`: focused 28 tests / 218 assertions; adjacent 617 / 6981; final full offline 2505 / 63620 with two unchanged missing-pool/rental skips and no failures, errors, warnings or risky tests, 00:58.238 / 342 MiB. Full nonmutating Pint, all 76 normalized source pins and ledger 18 / 37 pass. The earlier full run (2505 / 63615, one stale Game.php evidence-pin error and the same two skips) remains preserved separately. Runtime and test bytes did not change between those full runs; only evidence metadata was corrected.

PHP 8.5.12, 512 MiB, Xdebug off, original dependencies, isolated data and temporary directories; native read-only Windows DNS for existing mocked HTTP constructors. Later metadata/docs completion keeps the immutable source's PHP/test bytes. No hosted CI, publication or built paid-continuation combination is claimed.
