# Private Ward targeting snapshot model

Base: frozen counter correction `015ce3ecdf42e8f92c7125c9733cadf19b744fd5` (#447). This is an additive standalone model, with no caller in Game, parser, panel, practice bot or schema. It does not activate Ward timing, replace a targeting tax, enqueue triggers, select order, pay costs or certify a card. All four public PRs remain frozen.

## Data and refusals

`WardInstance` is readonly: local instance key, Ward-bearing permanent object/incarnation, its event-time controller, typed fixed cost and opposing event-time payer. A grant key identifies its granting source/incarnation/index, but the instance's object/controller still describe the Ward-bearing permanent, not the grant producer. Distinct keys with identical costs remain distinct. The same printed index on different permanents also remains distinct. Repeated observations with the same full object/incarnation/key identity are refused, not silently merged.

Costs are a closed union: `{kind: mana, symbols: string}` or `{kind: life, amount: int}`. Supported mana is bounded fixed generic/colored/colorless symbols. X/Y/Z, hybrid, Phyrexian, snow, discard, sacrifice, compound or unknown costs are explicit held cases. Tax-shaped input is refused rather than normalized to Ward. The representation bounds (IDs through 2^31-1, life through 1,000,000, mana strings through 256 bytes, keys through 128 bytes) are model safeguards, not Magic rules limits; larger values require a reviewed extension. Zero fixed mana/life costs are representable.

`WardTargetingEvent::publish(eventId, committedEntryOrNull, preCostPinnedTargets, instances)` is a pure factory, not a Game event bus. Null means the action failed or never committed and yields no event. A successful engine caller must supply the actual committed entry; this model does not authenticate a caller-supplied receipt or implement rollback. It captures the offending stack ID, underlying object/incarnation, controller and existing engine kind. Ability-shaped spell copies retain their own stack identity. It collapses repeated object target slots, validates instance source incarnations against those pinned targets, checks the payer against the offending controller and refuses a mismatch between pre-cost and committed object-target sets. Player/stack targets and omitted optional slots are not Ward-bearing permanent observations.

Readonly values retain no live Game reference. Source departure, blink, control change or mutable input-array edits cannot change a captured record. JSON version 1 restores typed values without coercion; unknown fields, unsupported costs, invalid identities, malformed/bounded lists, empty events and noncanonical saved duplicate targets reject. This is structural validation, not forged-save authentication.

Events always serialize `ordering: unselected`. Observation order is preserved for provenance only; reversed input remains unselected. No method treats that list as chosen stack order.

## Exact next wiring seam (not implemented)

1. Collect intrinsic/face-down and distinct granted Ward instances at the validated targeting event, before payment can remove their source. Do not derive grants from the deduplicated `keywords()` result, and do not reinterpret `tax: true` or Frost Titan's different payer wording as this true-Ward model.
2. After successful cast/activation/trigger/copy commitment, bind those snapshots to the exact new entry and publish a model record. Failed actions supply null and must not increment an event ID or queue anything. Retain the original committed spell entry directly: `end(stack)` after casualty/conspire/storm copies can name a different offending entry. Activation target pins must be frozen before costs, not resolved again after a source departs. Actual initial/changed-target observation and event-ID allocation still belong to the future engine caller.
3. Before stacking Ward, implement controller-chosen ordering for the affected simultaneous pending-trigger group, including other triggers that player controls. Deduplicate repeated target slots, never independent abilities. The current deterministic insertion order is not a substitute; general multiplayer/APNAP redesign remains outside this model.
4. Only afterward connect a non-targeting Ward effect referencing the offending stack ID to a validated optional payment/completion choice. The reviewed counter helper may remove that exact entry after decline. Payment must compose with #444/#445's painful-mana and Recover continuation seam, with already-paid costs and completion applied once. None of these frozen branches is modified here.

Reuse the existing cached Dog Walker record and September 25, 2026 rules references from the counter-foundation doc. The real paid-action tests intentionally retain the current wrong upfront Ward payment. They prove model commitment/failure boundaries on both seats, not correct Ward timing. No corpus import or historical recognition change is needed.

## Private verification

Verified source `525a2b7b2f7bf311c860c95676f31498e416211f`: model 53 tests / 115 assertions; adjacent model/counter/Ward/genuine-tax controls 80 / 287; full nonmutating Pint; ledger 18 / 37; all 73 normalized pins pass, preserving all prior 70. No new full gameplay suite was run: existing engine and preexisting tests are unchanged from base447, whose full gameplay evidence remains historical. Subsequent metadata/docs completion leaves the verified PHP/test bytes identical. No publication, hosted CI, new card certificate or recognition rescan.
