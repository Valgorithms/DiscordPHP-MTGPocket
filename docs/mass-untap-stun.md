# Mass untap and stun counters

Vitalize and To Arms! previously bypassed stun replacement when their mass untap effect processed creatures. The shared effect now removes one stun counter instead of untapping a tapped creature. The existing single-permanent effect uses the same helper; tapping does not consume stun. An untap-step restriction does not prohibit an explicit spell effect.

The paid gameplay baseline at `62df1ca5bd185b3b8d1d3db69abe5dbca6a9989f` had four tests, 26 reached assertions and four failures: both spells for both seats untapped the first stunned creature. After correction, ten cases and 92 assertions pass, including restored spell stacks, tapped and untapped states, opposing creatures, To Arms! drawing, mass tap and countered-spell controls. Creature and counter states are seeded; they do not certify those creatures.

The complete cached 6ED Vitalize record has UUID `9086302a-37e4-59f8-9cc0-cf6fc52171ee` (pool SHA-256 `e507e47e7251ad80caf5a6750065010c22e04287e83a3aa6e272b4ae2b337123`). The complete cached GPT To Arms! record has UUID `5a64edee-23a7-5c0d-8170-0cca6b8270ff` (pool SHA-256 `7378364f96741f7ddc6951366e8fc6ceea944e529a2c4fbe73e00c92a2a38f03`). The normalized fixture SHA-256 is `4d193f862baec509f25e254d1bd5c8139e30fdc92508b45b19aff5b106f19ec6`.

This verifies these selected paths, not general mass untap, replacement ordering, other printings or whole-card behavior. No save schema changes or new corpus import are involved. Review-held branches #444–#452 must integrate and revalidate later changes before any rollout.
