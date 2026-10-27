# Private PR handoff: frozen targets and paid continuations

## Required upgrade prerequisite

**Do not roll out schema 19 until affected schema-17/18 already-paid bare-object-target actions have drained or finished under the old version, or an explicit recovery policy has been reviewed for any remaining records.**

A pending action storing `o:55` has lost the original selected incarnation; the new loader refuses it instead of guessing. This can leave an existing match unloadable after upgrade. This patch does not migrate, refund, cancel or replay the already-paid action, and it does not rewrite the original save. Current state must not be used to invent the old incarnation. Repository fixtures do not establish live readiness; live private match storage was not inspected or modified.

Canonical pinned older pending saves remain eligible under the other existing source/action checks. Empty schema-17/18 pending state migrates. The compatibility boundary and old cast/Bloodrush versus activation/legacy-trigger behavior are detailed in the [upgrade notes](ward-paid-target-integration.md#upgrade-prerequisite-finish-older-already-paid-bare-target-actions-first).

## Change and evidence for review

This private integration combines reviewed frozen-target head `ec1e9311683a6789586ac0f48970fe762b5ffbf8` with frozen #445 `e49214864e72e1b1c69964d57b06a67a912040fc`, which includes #444. It carries original chosen pins through paid suspension, strict save/load and completion without selecting targets or paying costs again. A post-cost notification cannot bind the selected raw ID to a returned incarnation. Original committed entry/copy identity remains intact.

Full-tested runtime/test source: `9da3bce5cc813cb1fea493e9426a93075c685525`. Combined focused: 275 tests / 2198 assertions; full: 2649 / 65315, two expected missing-data skips, no failures/errors/warnings/risky tests. Full Pint, 88 source pins and ledger 18 / 37 passed for that checkpoint. Subsequent PHPDoc corrections change comments only; the historical results are not a claim of another full run.

Preserved baselines: one returned-incarnation notification failure at `ec1e931` after seven reached assertions; five already-paid continuation errors at frozen #445 after 14 reached assertions. Both complete parent validation histories, the initial schema-assertion failure and the older unexplained native PHP crash remain retained.

## Remaining limits and workflow state

General pre-cost capture of an original target's triggers, copy/trigger target-event completeness and full Ward timing/order/payment remain outside this patch. The optional Ward observer stays default-off and ephemeral; collection order is unselected. No new card or whole-card certificate is claimed, and historical recognition is unchanged.

This handoff records private preparation; current draft status and exact-head checks must be read from GitHub. Draft publication is not a ready transition, merge decision or rollout approval. Public #444–#447 remain separate and frozen. Main retains code review, merge decisions and any separately authorized rollout handling. No live bot action, deployment or private-storage migration was performed.

The comparison base for the combined draft is frozen #445, branch `implement/recover-resolution-continuations`, at `e49214864e72e1b1c69964d57b06a67a912040fc`. The diff includes the reviewed counter-identity correction from still-unmerged #447, head `015ce3ecdf42e8f92c7125c9733cadf19b744fd5`, as an explicit dependency. Those counter changes are not newly independent work and this draft does not replace main's separate #447 review/merge decision. No existing public branch is rewritten or synchronized by this preparation.
