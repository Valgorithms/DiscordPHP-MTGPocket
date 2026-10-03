# DiscordPHP-MTGPocket

A Magic: The Gathering collectible card game played entirely through Discord, in the spirit of Pokémon TCG Pocket. Built on [DiscordPHP-MTG](https://github.com/Valgorithms/DiscordPHP-MTG), with card data from [MTGJSON](https://mtgjson.com/) and card images from [Scryfall](https://scryfall.com/).

Planned features:

1. **Foundation** (this release): the project, the card pool import, and JSON storage for players, inventories, decks and side decks.
2. **Packs and collection**: one free pack a day. A pack is one set plus one color, and every pack holds at least one rare or mythic rare. Commands to view your collection and build decks.
3. **Rules engine and matches**: full Magic rules (turns, the stack, priority, combat, state-based actions), played through Discord buttons and menus.
4. **Matchmaking and game modes**: queues and challenges, with a separate card pool and deck rules for each format.

## How the data is kept

Card data comes from DiscordPHP-MTG's local copy of MTGJSON's AllPrintings SQLite build. The game copies what it needs out of it into its own JSON files under `var/data/` (or `MTGPOCKET_DATA`):

| File | What it holds |
| --- | --- |
| `pools/{SET}.json` | The cards packs of a set are drawn from, sorted by color (`W` `U` `B` `R` `G`, `M` multicolor, `C` colorless) and rarity (`common` to `mythic`). Each card keeps its MTGJSON `uuid` and its `scryfallId` for images. |
| `players/{userId}.json` | A player's profile and when they last opened their daily pack. |
| `inventories/{userId}.json` | The cards a player owns: printing uuid → copies. |
| `decks/{userId}.json` | A player's decks, each with a format, a main deck and a side deck. |

Writes are atomic (a temporary file renamed into place) and every read-modify-write holds a lock on its file, so nothing is lost when two changes land at once.

A set's pool is the cards its real boosters contain: front faces only, no promos or basic lands, and one printing per card name. Sets of type core, expansion, draft innovation and masters that are already released are imported by default.

## Requirements

- PHP 8.3 or higher, with the `pdo_sqlite` and `zlib` extensions
- Composer
- About 1.4 GB of free disk space for the MTGJSON build

## Setup

```sh
git clone https://github.com/Valgorithms/DiscordPHP-MTGPocket.git
cd DiscordPHP-MTGPocket
composer install
cp env.example .env       # then set TOKEN
composer import-cards     # downloads the MTGJSON build on first run, then writes var/data/pools/
php bot.php
```

`composer import-cards -- KTK DMU` imports just those sets. Re-run the import after MTGJSON adds a set.

## Tests

```sh
composer unit
composer pint
```

## License

MIT. See [LICENSE.md](LICENSE.md). Magic: The Gathering is © Wizards of the Coast; this project is not affiliated with or endorsed by Wizards of the Coast.
