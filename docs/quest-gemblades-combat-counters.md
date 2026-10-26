# Quest for the Gemblades: optional combat counters and sacrifice activation

The isolated slice starts from merged main `2e77a1eed73485fd4238c975a18b0969e63ac9ea` (#437). Open PRs #330/#331/#433 were checked: no Gemblades or matching observer/cost implementation overlaps. No published history is rewritten.

Complete cached ZEN UUID `72d2c199-508a-566e-a058-bb604d7817ac` costs 1G. Fixture SHA-256 `4ff5e1ed95dbf1af435c6dff68f579186948f8381afa31b13aa54890d836948e` retains the full Quest record, four M10 combat/target/damage enablers and RTR Fencing Ace, plus all source-pool metadata/hashes. Exact-name cached selections only; no import or new corpus scan.

## Reproduced baseline and narrow correction

Both seats paid 1G for Quest and R for Raging Goblin, attacked into a seeded complete Centaur Courser, and dealt one real combat damage. The Goblin died normally, but no Quest trigger queued. Four separate activation-isolation cases paid the source cast, explicitly seeded one quest counter and a target creature, then got "has no such ability." The baseline was **6 tests /52 assertions: two failures and four errors**. A later rerun of the maintained six cases loaded immutable base Game/TextParser bytes with a class map and reproduced the same results without reverting edited production.

Recognition and runtime were separated. Adding the exact optional trigger and single-counter sacrifice cost made all four activation cases pass, but both combat cases still failed (**6/76, two failures**). Passing `combat: true` only from combatDamage and observing successful creature-to-creature damage after all existing prevention fixes the remaining runtime gap (**6/82 PASS**). Other damage call sites retain the default false. Existing optional quest-counter choices, cost payment, target validation, stack and schema16 own the rest; no persistent state or interaction action is added. Existing three/five-counter Quest costs retain their wording and behavior.

Historical intermediate harness mistakes are retained: a power/toughness assertion originally passed an integer instead of GameObject, ownership setup tried to mutate a readonly field, and empty blocking had already been automatic when no legal blockers remained. Corrected fixtures create foreign-owned objects and change only controller, and respect automatic empty declarations. These are excluded from runtime-defect claims.

## Scoped gameplay evidence

The maintained **61 tests /798 assertions** cover both seats, actual paid counter earning followed by sacrifice activation, either player's legal creature, four +1/+1 counters, queued JSON replay and costs before resolution. Zero/wrong-kind counters, wrong seat, missing/noncreature/extra targets and hexproof reject atomically. Later hexproof, departure or return invalidates the old target without refunding the sacrifice. Control changes keep otherwise legal creature targets; the ability resolves after its source returns.

Actual combat declarations exercise current source/observer controller versus owner, a blocking creature, no trigger for paid Lightning Bolt or player combat damage, zero power and source/numeric/protection/shield-counter prevention, partial and unpreventable damage. Separate attackers, one attacker damaging two blockers, and a paid Fencing Ace over two combat damage steps produce distinct optional choices. Decline, original queued controller, wrong/stale/repeated answers, save/reload and departed/returned source incarnations reuse the existing counter-choice rules.

The selected complete Quest changes from two unread clauses to none. This bounded six-record observation does not update aggregate recognition, which remains historical **11,282/28,729**. The ledger adds one scoped name: **37 scoped names, two Partner-gap names, one overlap and zero whole-card certificates**. Existing Quest, merged-identity, Expedition, Soul Stair and older provenance remains intact. Supporting cards and seeded control/prevention/relocation states receive no certificates.

General replacement ordering, copy/mutation/continuous/APNAP behavior, an animated Quest leaving during simultaneous combat damage, arbitrary forged-save authentication and newest printing remain outside. Pure Flame remains an unexecuted next candidate; Dog Walker stays deferred. Final focused/full offline validation and main review precede publication. No live bot, build, deploy or merge.

## Final local validation for main review

Full-tested source ca412b8e9da14a0328080abc7eda6de5440565c3: 2359 tests /62440 assertions, 2 expected missing-pool/rental skips, no risky tests; PHPUnit 01:01.007 /330.00 MB,61.287s wall/120s cap. Focused835tests/12124assertions, full nonmutating Pint, existing-pool ledger inventory and all67 normalized PHP pins PASS. New card61tests/798assertionsPASS. PHP8.5.12/512MiB/Xdebugoff/isolateddata+temp/existingdeps. Final single coherent commit amends metadata/docs only; production/test pins match. No publication or hosted acceptance.

One unpublished commit remains above the approved merged base. Historical failed receipts and exact source/test/fixture identities are retained; main review precedes any draft PR publication.
