# Private default-off Ward observer and committed identity seam

Historical checkpoint: the later [frozen chosen-target correction](frozen-chosen-targets.md) supersedes the retained late-pin limit described below. This document preserves the original observer-stage contract and evidence.

Base: reviewed private models `1a4b33e4e0d21943e16bce91acae56333e425c15`, on frozen #447 `015ce3e`. This step adds return receipts and opt-in diagnostics. It does not implement Ward timing, change Ward/tax/payment policy, enqueue a Ward ability, select a trigger order or add a card certificate. Public #444-#447 and the reviewed model checkpoint are unchanged.

## Exact receipts and baseline

`cast()` returns its original committed entry, retained at stack creation before copy appends. `activate()` returns its committed ability entry after costs, or null for a face-up special action. `chooseTriggerTargets()` and private `pushTriggered()` return the new entry or null when the retained legacy Ward payment prevents it reaching the stack. `pushAbility()` returns its actual entry; `copySpell()` returns its own entry, or null for an inapplicable copy. Copies include their counter characteristic when constructed, so no later end-of-stack lookup is needed for the receipt. Existing callers may ignore these additive return values.

On unchanged reviewed base, paid storm-cast and source-sacrificing activation probes reached 8 assertions and failed twice because both APIs returned null. This is a missing identity-seam baseline, not a claim those actions previously failed to execute. Exact baseline test bytes/source hashes and receipts are retained. The first expanded observer run had a test-only ASCII-hyphen Ward fixture; replacing it with the existing em-dash syntax fixed the harness without expanding recognition.

## Read-only capture, successful commit and limits

`WardObservation` is disabled by default and has no UI/practice enablement. Capture runs only after target validation and before costs; already-pinned target incarnations are preserved in the snapshot. It mutates no observer event, ID or log. Failed casts, failed activations and legacy unstacked triggers publish nothing. The commit receives the exact actual entry, never `end(stack)` after copies. Activation observation commits after self-sacrifice/bounce/exile tails. These diagnostics are not executable pending triggers.

An additive `CardDefinition::wardInstances` list records each true Ward keyword occurrence without changing the old single `ward` payment field. Static targeting taxes are excluded; Frost Titan-style clauses with different payer wording are held. Granted Ward is collected before keyword deduplication, with attachment/anthem source-incarnation keys and local level/speed/station/turn keys. Temporary effects have only current occurrence-slot identities in the existing engine; those keys explicitly retain slots and do not invent producer provenance. Identical separate instances are preserved. The optional anthem origin decoration is used only by observation; ordinary anthem output remains unchanged.

Fixed bounded mana/life costs can form model events. Unsupported costs, oversized model input and binding mismatches are held diagnostics, not gameplay exceptions or partial events. All records retain `ordering: unselected`. The side channel keeps the latest 64 committed records. Observation configuration, IDs and records are deliberately ephemeral, excluded from `Game::toArray()` and reset to default-off on load. The standalone event still round-trips through its own validated JSON model. Persisted gameplay/event ordering is subsequent work.

**Precise retained limit:** `pushAbility()` currently pins targets again after costs, even when the input already names an incarnation. An actual two-target sacrifice-cost test removes one chosen creature before the entry is pushed. The observer keeps its original pre-cost pin, detects that the committed target differs, records a held mismatch and emits no bound event. It never fabricates an event for the newer incarnation. The underlying late-pin gameplay behavior is retained, including while observation is disabled. Correcting that existing target identity behavior requires a separately reviewed change to pass frozen pins into committed ability construction; this step does not silently make it.

## Remaining ordering/payment seam

After the late-pin correction is reviewed, bind the pre-cost instance list to each exact entry returned here. Runtime consumers must still implement controller-chosen ordering for the whole affected simultaneous-trigger group, then a non-targeting Ward reference to the offending stack ID and an explicit optional payment/completion action. They must split genuine static targeting taxes from true Ward, preserve Frost Titan payer semantics, and compose with frozen #444/#445's already-paid painful-mana/Recover continuations. Observation list order is never selected stack order. No source sync or broader replacement/APNAP architecture is included.

Paid unit regressions use synthetic enabling cards and the existing cached Dog Walker record. Seeded attachments, temporary grants and internal movement/copy probes do not certify printed cards. Historical recognition, existing scoped evidence, explicit gaps and zero whole-card certificates remain separate and unchanged. No corpus import, live bot, publication, ready transition, merge or deployment.

## Private verification

Full-tested source `d9fc1baefc809a18beb4a49a5ff6e06665a43a74`: focused 17 tests / 132 assertions; adjacent 650 / 13760; full offline 2494 / 63534, two unchanged missing-pool/rental skips and no failures/errors/warnings/risky tests, 00:58.303 / 340 MiB. Full nonmutating Pint and all 75 pins pass. Later metadata/docs completion preserves PHP/test bytes. Historical recognition and all scoped/explicit-gap entries remain unchanged, with zero whole-card certificates. No hosted CI or publication.
