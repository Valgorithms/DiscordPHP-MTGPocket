# Ich-Tekik artifact-burial counters

This milestone implements the exact selected CMR source/Golem counter clause, preserving the complete record and its still-unread Partner clause. It does not certify a whole card or commander/deck rules. The historical aggregate recognition report remains unchanged.

## Inputs and rules

The existing `tests/Fixtures/all-player-artifact-burial-candidates.json` is pinned after LF normalization to `5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b`. The selected UUID is `9767c0a3-2584-5383-8eeb-6873f4f20ba6`. Supporting complete Smelt, Damnation and Shatterstorm records remain existing offline fixtures; no import/download occurred.

The [official Commander Legends release notes](https://magic.wizards.com/en/news/feature/commander-legends-release-notes-2020-11-06) confirm that artifact tokens trigger burial and that surviving controlled Golems still receive counters after Ich-Tekik leaves. The [official Kindred explanation](https://magic.wizards.com/en/news/feature/lorwyn-eclipsed-mechanics) distinguishes creatures from noncreature Kindred permanents with creature types. These references support the scoped contract; they do not replace testing the pinned record.

## Behavior and evidence

Paid five-mana casting creates the printed 3/3 colorless Phyrexian Golem artifact token. Paid Smelt covers both active players, source controllers and artifact controllers, including stolen permanents. The trigger is untargeted: the original controller's current Golems receive counters, while the same source incarnation receives its own counter even after control changes. A departed/returned source receives no old self-counter. A synthetic Golem source receives both contributions; a returned Golem can still receive the independently applicable team counter.

Paid Damnation covers both source orders and original controllers with three callbacks, including the entry token's burial. Surviving controlled indestructible Golems receive each counter after source death; opposing Golems do not. Paid Shatterstorm produces separate callbacks for the token and two artifacts. JSON before spell resolution, with queued callbacks and between resolutions preserves outcomes. Counters remain through cleanup and appear in rendered permanent stats.

A negative Kindred test reproduced a shared subtype-counter defect (one failure / 23 assertions). The existing each-permanent counter path now includes matching noncreature Kindred/changeling permanents when the effect names a subtype, while an effect naming creatures still requires a creature. Four paid synthetic activated-effect controls cover Golem, other Golem, Goblin, creature, opponent and plain-artifact boundaries. These synthetic controls do not certify another complete card or generic continuous type-changing effects.

Movement tests distinguish artifact creature/token burial from discard, bounce, exile, nonartifact death and unearth replacement. Nearby wording remains unread. Partner stays explicit throughout; no stripping or whole-card admission claim occurs. State schema remains12: no new choices, UI routes, practice policy or configuration entries are required.

## Verification and remaining boundary

Baseline:8 regression failures /40assertions on the exact unread clause. Final focused:40tests /1067assertions in0.421sec. Adjacent:1054tests /18416assertions in21.913sec. Four-file Pint and diff check pass. The targeted181pool/eight-record scan still has six fully readable records and two unread; Ich-Tekik now retains only Partner. Fifteen names have scoped evidence, two explicit Partner gaps overlap one scoped name, and whole-card certificates remain zero.

Full offline and exact-head CI are pending. No new configuration contract means `env.example` is unchanged. No import, live Discord/Gaia, build, restart or deployment occurred. Next measured seed: Joven and Chandler's buried-artifact mana-value source counters, retaining its separate unread temporary-control activation. General continuous/replacement/conditions/APNAP models remain outside this evidence.
