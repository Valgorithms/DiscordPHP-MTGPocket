# Quest for Pure Flame: bounded damage ordering

This slice implements the reviewed damage-event contract on main `570771053d31f16d700178d9fbc742da37e9106d`. It adds scoped gameplay evidence, not a whole-card certificate or a new aggregate recognition measurement. The old unpublished baseline `edde2cc46a67b24ea908417547b2f1bac9ad1702` and its private review packet remain preserved separately.

## Reproduced baseline

The promoted cached-card probe was run again before integration on the current main: **16 tests / 156 assertions, four missing-trigger failures, four missing-activation errors, eight passing negative controls**. Both seats paid for the Quest and actual Bolt/combat damage. Activation-isolation cases seeded four counters after a paid cast; their subsequent doubling assertions were unreachable in the baseline. They were not counted as passing support evidence. New maintained tests separately earn four counters through four actual paid damage events before activating.

The original complete ZEN/M10 fixture remains byte-for-byte pinned at SHA-256 `67ab998793f431650a7a452f4e2a3b546fbc0c587320a8ed0ee51baf7743852b`. The interaction fixture pins nine selected complete records and their existing M10/M15/DTK/NPH/10E/GRN source-pool hashes at SHA-256 `a56d6ee3028d77a615f917a4e7aa6adc6ed1e9237fa6bca816a1099538a65e21`. No corpus import, download or aggregate parser walk was performed. Synthetic continuation contracts are explicitly separate from cached-card evidence.

## Runtime contract

The exact optional damage-to-opponent clause reuses the existing optional quest-counter choice. It observes positive final damage once per source/opponent event, with current source control or departed-source last-known control. Ownership is not substituted for control. The original observer incarnation is retained.

The exact four-counter/self-sacrifice activation pays its costs before queueing and installs a controller-bound turn effect only on resolution. Countering the activation installs nothing. Independent effect identities multiply: two doublings give four times the damage. Later sources and control changes are evaluated at the event; cleanup expires the effects. The effect is independent from the sacrificed Quest incarnation.

`DamageBatch` plans a finite batch without mutating damage or prevention resources. It records event source/incarnation/controller, pinned recipient, amount, combat status and applicable finite operations. Selected operation identities are replayed to derive results rather than trusting a serialized final amount. Each doubling applies at most once per event. Numbered shields share their resource budget across the batch. Identical doublings have one interchangeable ordering option while retaining separate application identities. A sole full-prevention family takes a deterministic path when ordering cannot alter damage or resource consumption.

For three damage, one doubling and a two-point shield, the affected player/permanent controller can choose **four damage** (double, then prevent) or **two damage** (prevent, then double). Unpreventable damage does not consume shields. Source prevention, protection and shield counters remain distinct operations when their resource ordering matters. The governing rules are CR 616.1, 614.5, 614.7a and 611.2c in the [September 25, 2026 Comprehensive Rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260925.txt).

`DamageResolution` persists the choice and resumes its typed completion. The engine commits the complete damage batch only after all ordering decisions. Spell resolution, fight directions, divided recipients and combat assignments cannot continue through a pending decision. Combat observers, commander damage, toxic and lifelink use positive final damage. No new priority window opens inside damage.

Mana payment is performed once. Cast/activation continuations then wait before their remaining costs and queueing; the payment is not replayed. `PaidActionContinuation` contains explicit existing utility-action tails, not dynamically callable method names. Resolution payments use the existing nested-step continuation, including optional token payment; ward payment pauses later trigger flushing. The practice policy and persisted Discord panel call the same engine action.

| Original synchronous caller | Continuation boundary |
| --- | --- |
| Painful mana | Paid cast/activation/utility action or remaining resolution steps |
| Ordinary damage effects | One batch for the instruction's recipients |
| Fight, first direction | Shared precomputed mutual-damage batch |
| Fight, return direction | Same batch, before state-based deaths |
| Divided damage | Adjacent allocated recipients captured before pausing |
| Creature self-damage | Affected creature is the damage source; its current controller chooses |
| Combat assignments | Entire assignment batch, followed by final-damage combat observers |

Departed source snapshots preserve the old incarnation's controller, active definition and damage-relevant infect/wither/deathtouch/lifelink keywords. They are separate from current battlefield identity. Matching live sources retain their actual once-per-turn trigger-use state; departed battlefield abilities stop observing later damage. These zone and lifelink distinctions were cross-checked against CR 113.6 and 702.15c–d in the publisher's [April 17, 2026 rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260417.pdf), alongside the retained September ordering review. Countering an ability does not move a returned source as though it were a spell.

## Saved state and bounds

Schema **17** adds `damageEffects`, `nextDamageEffectId` and `pendingDamage`. Older saves migrate to an empty effect list and no pending damage; new-format missing/invalid fields and unknown versions are rejected. New pending choices validate the responsible seat, monotonic choice identity, legal operation list, source snapshots, recipient incarnation, prevention resources and typed continuation before mutating the game. Stale, wrong-seat, unknown and replayed answers are atomic rejections.

The implementation supports at most 32 active/queued doublings, 256 events, 64 operations per event, 256 remaining resolution steps, 256 KiB of batch data and 1 MiB each for the pending continuation and choice. Damage must remain within the PHP integer range. Snapshot history is not save authentication; externally forged saves are outside this scoped evidence. Existing general save/source-history limits are not redesigned here.

## Tests and remaining limits

Maintained tests cover paid triggers and earned counters, accept/decline, activation cost/target rejection, both seats, source and recipient control/ownership, observer incarnation, queued/countered activation, repeated/later sources, current versus last-known source control, stacked effects, expiry, full/partial/unpreventable damage, both ordering outcomes, save/replay, simultaneous fight, divided recipient interruptions, combat lifelink, painful-mana cast/activation completion and persisted panels/practice behavior. Synthetic contracts exercise existing utility and resolution payment continuations; they do not certify enabling cards. The countered-activation test uses the existing counter primitive and does not claim Stifle parsing.

**Recover follow-up:** this stacked candidate corrects the historical automatic-payment boundary with optional mana-cost Recover triggers, original graveyard-incarnation identity and bounded already-paid damage continuations. See [Recover integration evidence](recover-damage-ordering-private.md). General resumable zone movement, life-cost Recover and multi-owner merged departures remain outside scope. Pact payment is unchanged: its existing turn-start processing precedes new turn effects. Broader automatic payment policies, arbitrary replacement/redirection families, multiplayer/APNAP, prevention side-effect feedback into applicability, continuous/copy layers, unrestricted divided-damage allocation, newest-printing validation and live Discord acceptance remain outside this slice. None is implied by recognizing both Quest clauses.

Final local and hosted results are recorded in the evidence manifest after validation; historical failed runs remain historical. No deployment, live bot, credential change or PR merge is part of this work.

## Historical local verification

Immutable full-tested feature source adf35df5b1ba727a1903e6762807ae4e9cfcc20e: 2,481 tests / 64,174 assertions, two expected missing-pool/rental skips and no risky tests; 01:06.266 / 352 MiB. Focused: 97 tests / 1,062 assertions. Full nonmutating Pint and all 73 normalized PHP pins pass. PHP 8.5.12, 512 MiB limit, Xdebug off, isolated data/temp and existing dependencies. Read-only native Windows DNS configuration access was required by fixture-backed importer constructors; no live requests, dependency edits or deprecation suppression. At that historical pre-publication checkpoint, the final amend changed verification metadata/docs only; production/test bytes remained identical. That pending-publication status is historical; read [PR #444 checks](https://github.com/Valgorithms/DiscordPHP-MTGPocket/pull/444/checks) for the current head.

The first restricted full run on this same source had six risky importer tests from the Windows WMIC/vendor deprecation; its complete receipts remain preserved. A focused native-access probe passed 6 tests / 41 assertions before the final full retry. These local outcomes do not establish hosted or live-service acceptance.

Verified historical hosted evidence after the Fog correction: [Tests 40456232370](https://github.com/Valgorithms/DiscordPHP-MTGPocket/actions/runs/40456232370) passed Pint and PHPUnit on PHP 8.3/8.4/8.5 at exact head `f15f18288ac8db2b31ef1aa5f59c7602681811da`. This does not validate any later commit; its checks must be read from GitHub. Root retains readiness and merge decisions.
