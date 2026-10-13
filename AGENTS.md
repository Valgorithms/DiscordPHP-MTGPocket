# DiscordPHP-MTGPocket agent guide

A Discord-hosted MTG collectible game (packs, collection, decks, rules-engine matches, matchmaking, drafts, trades, shop) built on `DiscordPHP-MTG` (`discord-php-extended/discordphp-mtg`, `dev-master`) and DiscordPHP. It runs as one long-lived ReactPHP process (`bot.php`, packaged with PHPacker).

## Working rules

- Work from this repo root; check `git status` first and keep unrelated edits. `main` is the development branch; ship via PR, then annotated tag + GitHub Release from the changelog.
- Preserve the Composer wiring (`discord-php/http: dev-master as 10.9.8`) and any path repositories.
- Must work on Windows. Do not add blocking I/O to the runtime path.
- Tests use temporary storage (see `tests/StorageTestCase.php`, `tests/PocketTestCase.php`) and injected adapters; never hit Discord, MTGJSON or Scryfall from tests. Live runs need explicit authorization.
- Never read or print `.env`; use `env.example` for shape.

## Layout

- `src/MTGPocket/` game code (the rules engine lives under the Game namespace, tests under `tests/Game/`)
- `config/` tunable data: `drafts.php`, `economy.php`, `modes.php`, `quests.php`. Change balance here, not in code.
- `bin/import-cards.php`, `bin/card-coverage.php` card pool import and rules-text coverage
- `var/` runtime data (JSON stores, SQLite card pool); do not commit

## Verify (smallest first)

```
composer unit                      # phpunit; narrow with: vendor/bin/phpunit --filter=Class::method
composer cs                        # php-cs-fixer (CI config); `composer pint` for pint
composer card-coverage             # after changing rules-text parsing
```

CI is `.github/workflows/tests.yml` and `docs.yml`; check the exact target commit's runs before calling anything ready. Docs build with phpDocumentor (`phpdoc.dist.xml`); keep PHPDoc accurate and use narrow return types on overrides.

## Deploy

After shipping, rebuild with `composer phpacker` and restart the running exe (bin/build is gitignored; it embeds the token). Verify from outcomes, not just process start.

## Rules-engine continuation

Before extending card functionality, read [the architecture review and continuation priorities](docs/rules-engine-continuation.md). Recognition coverage is separate from verified gameplay support; preserve the ownership boundaries recorded there.
