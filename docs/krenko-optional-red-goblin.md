# Krenko optional red payment and temporary-haste Goblin

Eight baseline cases reproduced the complete selected MKM Krenko, Baron of Tin Street record's unread artifact-burial optional payment clause. That exact wording now creates an untargeted persisted decision: the original trigger controller may pay {R} for one 1/1 red Goblin creature token, or decline. That controller still pays and receives the token if Krenko leaves, returns as another incarnation or changes controller.

## Pinned inputs

All hashes below normalize CRLF to LF; no import or download occurred.

| Complete selected record | Fixture | UUID | SHA-256 |
| --- | --- | --- | --- |
| MKM Krenko | `tests/Fixtures/all-player-artifact-burial-candidates.json` | `026a8fcb-c5db-50c9-9c9c-13db04701f21` | `5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b` |
| Supporting M13 Smelt | `tests/Fixtures/artifact-burial-smelt.json` | `bf9f48d0-70bb-59e6-9bb7-116a924204a4` | `6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15` |
| Supporting Damnation | `tests/Fixtures/simultaneous-deaths.json` | `d1daaab5-3a27-5b2d-82dc-a0e24a82cc15` | `44e9b312a983d7cc1e477ba44d6049c961fcb2e7c6096352836552e2aa8c0cb6` |

These records and supporting casts do not certify newest printings or whole cards.

## Tested behavior

`KrenkoRedGoblinChoiceTest` pays three mana to cast Krenko and uses its printed haste for the tap/sacrifice activation. Controlled Goblins, including the newly created token, receive counters; an opposing Goblin and a controlled non-Goblin do not. Paid complete Smelt covers both active players, source controllers and artifact controllers, including stolen permanents. Paid complete Damnation covers both source orders and controllers: each artifact callback survives simultaneous Krenko death. This matches the [official MKM release notes](https://media.wizards.com/2024/downloads/MKM_Release_Notes_82nnDTWVBdD/EN_MTGMKM_ReleaseNotes_20240125.pdf), page 34.

Payment uses the existing automatic mana planner. Floating red mana and untapped controlled Mountains pay normally; green mana, Forests, tapped Mountains, opposing sources and mana restricted to casting creature spells cannot pay. Declining spends nothing. An unaffordable acceptance is rejected before resources or the decision change. Treasure payment queues its own independent artifact-burial trigger without automatically purchasing another token. This does not add generic optional payments or player-selected mana sources.

The token is a 1/1 red nonartifact Goblin owned and controlled by the payer. Its granted haste is temporary: it can attack while summoning-sick, loses haste at cleanup, and remains on the battlefield. Later continuation effects resume exactly once after either answer, even after the source leaves.

Schema 12 validates choice identity, original controller, source incarnation, untargeted options and continuation. Version-11 pending untap decisions still resume and reject the new payment API. JSON before a paid spell resolves, with queued triggers, during the decision and after resolution preserves tested outcomes. Wrong-player, stale, repeated, cross-effect and malformed answers are rejected. Movement and nearby-wording controls retain the narrow admission boundary. Synthetic controls test engine contracts rather than additional complete cards.

Temporary persisted Discord tests verify pay/decline controls, the temporary-haste prompt, original-player access, disabled payment when unaffordable, rejected interactions, and saved resources/token. Practice explicitly buys only affordable Goblins through the engine API. No live Discord behavior is claimed.

## Verification and limits

Focused verification passed 82 tests / 1,800 assertions in 1.170 seconds. Adjacent verification passed 638 tests / 11,165 assertions in 9.572 seconds before the final supporting paid-Damnation enhancement; focused verification includes that enhancement. Eight-file Pint, changed-test Pint and whitespace checks passed. The targeted 181-pool scan reparsed only eight matching records in 1.026 seconds: six readable, two unread. Fourteen names now have scoped evidence, one explicit gap remains and whole-card certificates stay zero. Aggregate recognition remains the historical 11,282/28,729 report.

Full offline verification at `bde33f25825b2825602d18cab6551de2d9063845` passed 1,405 tests / 44,403 assertions with two expected imported-rental-data skips in 78.828 seconds (184 MiB). The run used a 120-second process-tree cap, 512 MiB PHP limit, Xdebug off, empty data and task-local temporary storage after a fresh 8.57 GiB capacity check. Tested-head CI `40037242332` passed full Pint/PHPUnit on PHP 8.3, 8.4 and 8.5. Final metadata-head CI is checked separately before merge; all 37 runtime/test pins match the full-tested commit. No artifacts are expected; the nonrelease docs workflow is skipped. Next measured seed is Ich-Tekik's source/Golem counters; its separate Partner clause remains unread. No configuration contract changed, so `env.example` needs no entries. No import, live bot/Gaia run, token-bearing build, restart or deployment occurred. General continuous, replacement, condition and APNAP rules remain outside this scope.
