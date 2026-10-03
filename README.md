# DiscordPHP-MTGPocket

A Magic: The Gathering collectible card game played entirely through Discord, in the spirit of Pokémon TCG Pocket. Built on [DiscordPHP-MTG](https://github.com/Valgorithms/DiscordPHP-MTG), with card data from [MTGJSON](https://mtgjson.com/) and card images from [Scryfall](https://scryfall.com/).

Planned features:

1. **Foundation** (done): the project, the card pool import, and JSON storage for players, inventories, decks and side decks.
2. **Packs and collection** (this release): one free pack a day. A pack is one set plus one color, and every pack holds at least one rare or mythic rare. Commands to view your collection and build decks.
3. **Rules engine and matches**: full Magic rules (turns, the stack, priority, combat, state-based actions), played through Discord buttons and menus.
4. **Matchmaking and game modes**: queues and challenges, with a separate card pool and deck rules for each format.

## Playing

| Command | What it does |
| --- | --- |
| `/pack open [set] [color]` | Opens your free pack for today and adds it to your collection. Leave the set or color empty for a surprise. |
| `/pack list` | The sets and colors you can open, and when your next free pack is ready. |
| `/collection [set] [color] [rarity] [name] [player]` | The cards you (or another player) own, in pages. Pick a card to see it in full. |
| `/decks create <name> [format]` | Starts an empty deck. Your first deck becomes your active one. |
| `/decks add <deck> <card> [count] [side]` | Puts cards you own, or basic lands, in the main deck or the side deck. |
| `/decks remove <deck> <card> [count] [side]` | Takes cards out. |
| `/decks show [deck]` · `/decks list` | A deck with its cards grouped by type and **Export decklist** for MTG Arena or Moxfield; or all your decks. |
| `/decks rename` · `/decks format` · `/decks delete` · `/decks use` | Rename a deck, change its format, delete it, or make it the one you play with. |

**Packs.** Everyone gets one free pack a day; the day turns over at midnight UTC. A pack is 15 cards of one color (white, blue, black, red, green, multicolor or colorless) of one set: 10 commons, 3 uncommons, a wildcard slot that is usually a common or uncommon but sometimes rarer, and a rare slot that is always a rare or mythic rare. A mythic shows up as often as on a real print sheet (each rare printed twice, each mythic once). A pack never repeats a card unless its color is too small to fill it; a color with no rare or mythic rare has no packs.

**Decks.** A deck may use each card you own as many times as you own it, main and side deck together, and the same cards can go in any number of decks. Basic lands are free and unlimited. Deck edits answer only you. Formats (Standard, Commander, Limited, Casual) are recorded now; their deck rules are checked once matches arrive (steps 3 and 4).

## How the data is kept

Card data comes from DiscordPHP-MTG's local copy of MTGJSON's AllPrintings SQLite build. The game copies what it needs out of it into its own JSON files under `var/data/` (or `MTGPOCKET_DATA`):

| File | What it holds |
| --- | --- |
| `pools/{SET}.json` | The cards packs of a set are drawn from, sorted by color (`W` `U` `B` `R` `G`, `M` multicolor, `C` colorless) and rarity (`common` to `mythic`). Each card keeps its MTGJSON `uuid` and its `scryfallId` for images. |
| `players/{userId}.json` | A player's profile and when they last opened their daily pack. |
| `inventories/{userId}.json` | The cards a player owns: printing uuid → copies. |
| `decks/{userId}.json` | A player's decks, each with a format, a main deck and a side deck: printing uuid → copies, with basic lands as `basic:Plains` and so on. |

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
