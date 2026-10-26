# Quest for the Gravelord optional death counters

This local slice uses two complete cached ZEN records from MTGJSON `5.3.0+20261022`: Quest for the Gravelord (`3c15b10c-d09c-5c93-be42-98ff3515262a`) and enabling Disfigure (`15269cf5-9eb9-5b7d-8c79-42154e8ece24`). Fixture SHA-256: `c26e0bf5385b4bbf8ad5178f31263bfaf6f03f9391ddcbdc13c5cb3222924300`. The fixture also records the original cached pool fingerprint. No data was fetched or imported.

The complete cached ZEN Quest already parsed without unread text at this branch's base, but its generic `you may put` effect automatically added a quest counter. The older aggregate newest-name recognition index does not list Quest as recognized; that different printing/source measurement is left unchanged. Four executable regressions paid B to cast the enchantment and B to cast Disfigure, killing a seeded Ornithopter. Both actors and both dying-creature controllers failed the same assertion: a counter was added before acceptance. Baseline: **4 tests, 40 assertions, 4 failures**; local red commit `f0e6426` preserves the reproduction.

The narrow parser route recognizes only the exact optional creature-death quest-counter clause. It queues existing `creature_dies_other` and `creature_dies_any` events with the existing optional quest-counter effect. No engine decision, persistence schema, interaction route or environment contract is added. The existing ordinary activated ability still removes three quest counters and sacrifices the enchantment to create its token. No `env.example` change is needed.

Card-specific tests cover acceptance/decline, three counters earned through actual paid deaths, undercharged/repeated activation rejection, the 5/5 black Zombie Giant token, queued activation replay, pending/queued choice persistence, original trigger controller, wrong/stale/invalid/repeated answers, departing/returning incarnations and practice policy. Seeded victims/mana and explicit synthetic movement/transfer probes isolate contracts; they do not certify enabling cards, real transfer spells, arbitrary saves or general replacements. Animation/self-death, simultaneous mass deaths, broad APNAP/continuous behavior, newest printing and live Discord rendering remain outside this slice.

The branch is based on pinned main `d0431f1d834ba9365c159c9e341d9ac33905a903` and excludes PR #406, whose frozen final source was never modified. Main later merged #406 as `85d85418cee2244cf8e6eb9dc3dac70a9646bb6b`; this patch needs no target-clause functionality and can be reviewed independently before any later integration. No publication has been performed.

Coverage counts describe the checked-out branch: its base ledger had 30 scoped names; this entry makes 31. Current main's Soul Stair entry is separate, so combining both would require a fresh ledger reconciliation. Historical recognition remains 11,282/28,729 names; there are two Partner-gap names, one overlap and zero whole-card certificates. This does not establish a functional percentage or whole-card support.

## Refreshed bounded priorities

| Candidate | Evidence and disposition |
| --- | --- |
| Quest for the Gravelord | Selected: actual paid-gameplay optionality failure, narrow fix and card-specific tests. |
| Zektar Shrine Expedition | Next cached candidate: paid landfall, token characteristics and next-end-step exile need executable evidence; no runtime gap claimed yet. |
| Sunspring / Ior Ruin Expedition | Cached named alternatives using the established quest-counter path; their paid activation outcomes remain unverified here. |
| Dog Walker | Preserve the prior blocked record. Copiable disguise/ward rules need authoritative evidence before a correction. |
| Partner / commander rules | Explicit existing gaps; too broad for this slice. |

Open PRs were checked before selection: #433 draw/life-loss recognition, #330 fading and #331 controller damage remain independent. #406 was frozen, then explicitly reported merged by main. No duplicate work, corpus import or broad parser walk was started.

## Local verification for main review

Full offline source `58060b0d7d8eb86869a6ceed169ebf53f7bb72c5` passed 2,102 tests/57,778 assertions with2expected missing-pool/rental skips;PHPUnit01:04.004/294.00 MB,wall64.276s/120scap. Adjacent ledger/Khalni/Moriok/Quest124tests2323assertionsPASS;Quest27tests372assertionsPASS;fullnonmutatingPintPASS. All59normalizedPHPpins match. PHP8.5.12/512MiB/Xdebugoff/isolated data+temp;ignoredtask-localautoloadbridge reusesexistingvendorread-onlyandallowsCLItests. No dependencyorenvcontractchange;nohostedCI/publication.

The initial adjacent run exposed only a dependency setup issue in its CLI subprocess. The ignored local autoload bridge corrected that setup; no application workaround was introduced. The historical recognition file, original vendor, frozen PR406 branch and primary checkout remain unchanged. The independently based ledger has59pins/31scopednames;currentmainintegrationstillrequirespreservingSoulStairandmergingitsledgerentrybeforepublication. No hosted acceptance or whole-card certification is claimed.
