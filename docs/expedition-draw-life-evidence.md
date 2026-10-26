# Expedition paid draw and life-gain evidence

This evidence-only batch uses complete existing cached ZEN records for **Ior Ruin Expedition** (UUID `82c2fdfb-c6f6-591a-9c85-8913e559a5de`, fixture SHA-256 `303f638d4bd967a25b3604f9e162766272a16ad5d7c53d0d7e6b5866a6204e5c`) and **Sunspring Expedition** (UUID `46769dfe-05a9-55d4-b43e-7b3e7f1bc789`, fixture SHA-256 `c4853ecb3ba472b60331e76b54df44cb7d00953d3f19782e2e2efae74291a392`). Both preserve MTGJSON `5.3.0+20261022` metadata and the source-pool hash. Selection read the existing 229-card ZEN pool; no download or import occurred. Open PRs #330, #331 and #433 did not overlap at selection. The branch starts from merged Zektar `7204f5ba39c610fcaa762756c25a52ed6103adc0`.

Both genuine gameplay baselines already passed without an engine change: **Ior 12 tests /248 assertions**, **Sunspring 6 tests /112 assertions**. No runtime bug or failing baseline is claimed. Ior's prior isolated full suite passed2180/59332 with two expected missing-data skips; this is historical, not combined-tree acceptance. Baseline/source fingerprints and prior unpublished patches are retained in receipts rather than iterative public commits.

For each card and each seat, tests pay the printed cost (Ior1U;SunspringW), play three actual controlled lands and accept quest counters, then remove three counters and sacrifice the source before resolution. Opponent lands, declined counters, undercharge, wrong-seat and repeated activations have atomic negative controls. Queued JSON replay and synthetic source-return probes preserve the original ability controller and source incarnation.

Ior draws the expected two library object IDs in order. Zero/one/two-card short-library probes establish available draws and the failed-draw loss boundary. Sunspring gains eight life only at resolution. Its expanded16tests/292assertions include existing global/opponent gain prevention and controller/opponent plus-one modification, retaining paid costs even when gain is prevented. Those rule permanents and source/short-library relocations are synthetic unit setups, not supporting-card certificates.

Production, parser, schema and environment contracts are unchanged. General draw/life-gain replacement and observer interactions, APNAP/continuous rules, newest-printing review and live Discord remain outside. Complete cached records plus these scoped tests are not whole-card certificates.

The ledger records **35 scoped names, two Partner-gap names, one overlap and zero whole-card certificates**. Recognition remains the historical11,282/28,729 measurement. Merged Zektar, Quest and Soul Stair entries and validation provenance remain intact. The next exact cached candidate is Quest for Ancient Secrets; its graveyard-event/paid-shuffle gameplay remains unexecuted. Dog Walker's deferred record is preserved.

## Final combined local validation

Combined full-tested source 34d5ea2f2bd460cc47be312255f41097c01b1d94: 2196 tests / 59624 assertions, 2 expected missing-pool/rental skips, no risky tests; PHPUnit 01:01.374 / 308.00 MB. Focused 247 tests /4731 assertions and full nonmutating Pint PASS. All64 normalized PHP pins match. PHP8.5.12/512MiB/Xdebugoff/isolateddata+temp, existing dependencies. The final single coherent commit amends only metadata/docs from this tested source; production and test pins are identical. No hosted acceptance or publication.

Prior baseline and Ior-only full receipts remain intact. This batch is held unpublished for main review. General draw/life-gain rules, observer interactions, supporting-card certificates and live behavior remain outside the recorded scopes.
