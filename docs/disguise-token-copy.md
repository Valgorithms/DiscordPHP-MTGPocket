# Dog Walker disguise token-copy characteristics

This is an independent slice on main `570771053d31f16d700178d9fbc742da37e9106d`, without #444/#445 integration. It reuses the existing prepared Dog Walker selection and paid synthetic copier fixture. Source commit: `3d30a9c576e196e919064ee60f380c163f400913`.

## Baseline and correction

The complete cached MKM record UUID `7037c6a6-2329-5e62-a365-ddd83f37d0aa` parses without unread clauses. The paid baseline on both seats paid {3} to disguise and {U} for the existing copier: **2 tests / 30 reached assertions, two failures**, actual printed 3/1 rather than copied 2/2. Ward and later assertions after that failure were unreachable, not passes. An initial missing provider and a later invalid test-property assertion are preserved separately as harness errors.

`copy_token` previously selected face-down characteristics only for morph/megamorph. It now includes disguise, removes our synthetic turn-up action and hidden printed abilities, but retains `Ward {2}`. Morph/megamorph remain blank copies; face-up and transforming controls are unchanged. No parser, save-schema, workflow, dependency or environment changes.

The [official September 25, 2026 rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260925.pdf), 702.168b, 707.2 and 708.2, establish the copied face-down characteristics. CR 702.21a defines actual Ward as triggered, which is outside this correction. The existing upfront tax is retained as an engine compatibility contract only.

## Provenance and validation

Fixture normalized-LF SHA256 `27a09d399cdd6cbe3e819319bd47c28c0b08295bc635020daa55f2f4fcca973c`; complete MKM pool SHA256 `59e2c0f688f2e8d69f8f7fa179cd3e7ea2e3b9372d3554f58bffbd42d0a801b2`, version `5.3.0+20261022`. Prepared packet SHA256 `3f2858d325f7a92456750dd3b787577ab51d2d53cf6999ed2e824fd24699116f` matches the cached record field-for-field. No import or corpus walk.

Focused **16 tests / 660 assertions**; adjacent morph/megamorph/transforming/Incubator/Den Protector/controller-damage controls **142 / 4313**, pass. Full offline **2418 tests / 63809 assertions**, two unchanged missing-pool/rental skips and no failures/errors/warnings/risky tests; **59.733 seconds / 338 MiB**. Full nonmutating Pint and all **69 pins** pass. Full-tested source and final-head hosted checks must be distinguished; read PR checks for any current head.

## Limits

The token is face up, nameless, colorless 2/2 with ward, no hidden turn-up action/vigilance/Dog trigger. The original can pay its printed hybrid cost to turn up and create its two tapped Dogs independently. Tests cover both seats, save/replay, copy of copy after departure, response timing, invalid costs/targets and noncopy modifiers. Synthetic copying/targeting/movement setups do not certify enabling cards. Actual Ward trigger sequencing, cloak/manifest, merged/modal/general copy factories, newest printing, forged-save authentication and whole-card support remain outside.

The ledger adds Dog Walker as one scoped name, keeping whole-card certificates at zero. Its Ward-timing gap is explicit. Historical recognition stays 11282/28729; aggregate coverage was not rescanned. Main retains readiness/merge review; only separate draft publication is authorized. No live bot, build, deployment or ready transition.
