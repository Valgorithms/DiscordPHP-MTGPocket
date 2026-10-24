# Burnished Hart land search

This milestone starts from the complete cached THS Burnished Hart record, UUID `62e2bb8a-2c0a-505c-8c47-7f0c2d08f49d`, MTGJSON `5.3.0+20261022`. Its normalized fixture SHA-256 is `46b9c6b35fc83b400df52913d4aeeddaed85750df15f19f1a398abd1c283ec46`.

Reproduce an actual three-mana creature cast followed by the three-mana self-sacrifice activation. Verify player selection of up to two basic lands, including zero or two of the same name, tapped battlefield entry and one final shuffle. Cover both seats, costs/control, invalid actions, empty/single-card libraries, JSON/replay, continuation, existing practice and persisted panels before accepting a fix. The current source appears to select automatically; no paid regression is established yet.

Use bounded offline checks and exact CI. No source fetch, corpus scan/import, live service, bot build/restart or deployment. Keep recognition, scoped functionality and whole-card certification separate. No environment contract change is planned.
