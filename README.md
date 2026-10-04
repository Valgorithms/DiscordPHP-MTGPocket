# DiscordPHP-MTGPocket

A Magic: The Gathering collectible card game played entirely through Discord, in the spirit of Pokémon TCG Pocket. Built on [DiscordPHP-MTG](https://github.com/Valgorithms/DiscordPHP-MTG), with card data from [MTGJSON](https://mtgjson.com/) and card images from [Scryfall](https://scryfall.com/).

Planned features:

1. **Foundation** (done): the project, the card pool import, and JSON storage for players, inventories, decks and side decks.
2. **Packs and collection** (done): one free pack a day. A pack is one set plus one color, and every pack holds at least one rare or mythic rare. Commands to view your collection and build decks.
3. **Rules engine and matches** (in progress): full Magic rules, played through Discord buttons and menus. The core is in: opening hands, turns, priority and the stack, mana, casting, combat and state-based actions. Card abilities are added in the next releases.
4. **Matchmaking and game modes**: queues and challenges, with a separate card pool and deck rules for each format.

Also in: **trading and the points shop**. Players trade cards and points with each other, sell cards they don't need for points, and spend points on single cards or on packs of one color of one set.

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
| `/match challenge <opponent> [deck]` | Challenges another player. They get **Accept** and **Decline** buttons. |
| `/match board` | The board of the game you are in. |
| `/match leave` | Calls off your challenge, or concedes your game. |
| `/shop balance` | Your points, what cards cost by rarity and set age, and what each set's packs cost. |
| `/shop price <card>` | What a card costs to buy and pays to sell. |
| `/shop sell <card> [count]` | Sells cards you own for points. Copies your decks use are kept. |
| `/shop sell-extras [keep] [rarity] [set]` | Sells every copy beyond `keep` (default 4) of each card and beyond what your decks use. It lists what would sell and waits for **Sell** first. |
| `/shop buy-card <card> [count]` | Buys any card from the game's sets with points. |
| `/shop buy-pack [set] [color]` | Buys a pack with points. It is built exactly like a free pack and does not use up your free one. |
| `/trade offer <player> [give] [give_count] [want] [want_count] [give_points] [want_points]` | Offers another player a trade. They get **Accept** and **Decline** buttons; you get **Cancel offer**. |
| `/trade add <side> [card] [count] [points] [offer]` | Adds a card or points to an offer you made (your newest, unless you pick one). |
| `/trade list` · `/trade cancel [offer]` | Your open offers, made and received; or calls one off. |

**Packs.** Everyone gets one free pack a day; the day turns over at midnight UTC. A pack is 15 cards of one color (white, blue, black, red, green, multicolor or colorless) of one set: 10 commons, 3 uncommons, a wildcard slot that is usually a common or uncommon but sometimes rarer, and a rare slot that is always a rare or mythic rare. A mythic shows up as often as on a real print sheet (each rare printed twice, each mythic once). A pack never repeats a card unless its color is too small to fill it; a color with no rare or mythic rare has no packs.

**Matches.** A match is one game between two players, each playing the main deck of their active deck (or the deck named in the challenge). A deck needs at least 40 cards for now; each format's deck rules come with the game modes. The board is public: life, cards in hand, library and graveyard, everything on the battlefield, the stack and what just happened. **Your hand & actions** opens your own panel, which only you see: your hand and the choice in front of you (keep or mulligan, play a land or cast a spell, choose X and targets, attack, block, discard). Each action posts a fresh board that mentions whoever the game waits on.

What the rules engine does today:

- **Game flow**: a coin flip for who plays first (they skip their first draw), seven-card hands with the London mulligan, every step of the turn, the maximum hand size, and losing at 0 life, 10 poison counters or when drawing from an empty library. Conceding is `/match leave`.
- **Priority and the stack**: spells are cast at instant or sorcery speed (flash counts), resolve last in, first out, and do nothing if all their targets are gone. Like MTG Arena, a player who has nothing they could do passes automatically, except in their own first main phase; **Auto-pass** in your panel turns that off.
- **Mana**: costs are paid for you from floating mana and your untapped lands and mana creatures, saving dual lands for the colors only they make. Hybrid, Phyrexian (2 life when you have no mana for it), colorless and X costs work, and lands that say they enter tapped do so.
- **Combat**: attacking (summoning sickness, haste, defender, vigilance), blocking (flying and reach, menace), first strike and double strike, trample, deathtouch and lifelink. An attacker assigns lethal damage to its blockers in the order they were declared and the rest to the last one, or with trample to the player.
- **State-based actions**: lethal damage and deathtouch, 0 toughness, planeswalkers with no loyalty, the legend rule (the newest stays), Auras attached to nothing, and +1/+1 against -1/-1 counters.
- **Card text** it reads: keyword lines (flying, reach, first strike, double strike, deathtouch, lifelink, trample, vigilance, haste, defender, menace, indestructible, hexproof, shroud, flash), `{T}: Add …` mana abilities, Auras that give +N/+N and keywords, and the most common instant and sorcery effects (damage, draw, gain and lose life, destroy, exile, return to hand, counter a spell, +N/+N until end of turn). Text it cannot read yet is listed under the card in your panel, and the card still works without it. Triggered and other activated abilities, tokens, equipment and planeswalker abilities come next.

**Points and the shop.** Selling a card earns points; points buy single cards or packs. Prices are set in [`config/economy.php`](config/economy.php): a card costs 20, 60, 300 or 900 points by rarity (common to mythic), times 2 for a set from the last year, 1.5 for the last three years, 1 for the last ten and 0.75 for older ones. A pack costs 500 points with the same multiplier. Selling pays 20% of the buy price (at least 1 point), so selling a card and buying it back always loses points. Cards your decks use are never sold: a deck that needs 2 copies keeps 2.

**Trades.** An offer can hold cards and points on both sides, up to 20 different cards each. Sending it is your agreement and **Accept** is theirs. Nothing is set aside while the offer is open: when it is accepted, both sides are checked again and the whole trade happens at once or not at all. Adding to an offer with `/trade add` posts the new version, and **Accept** on an older copy shows the new one instead of agreeing to it. Offers stay open for 7 days, and a player can have 10 open at a time. Cards a deck uses can't be traded away.

**Decks.** A deck may use each card you own as many times as you own it, main and side deck together, and the same cards can go in any number of decks. Basic lands are free and unlimited. Deck edits answer only you. Formats (Standard, Commander, Limited, Casual) are recorded now; their deck rules are checked once matches arrive (steps 3 and 4).

## How the data is kept

Card data comes from DiscordPHP-MTG's local copy of MTGJSON's AllPrintings SQLite build. The game copies what it needs out of it into its own JSON files under `var/data/` (or `MTGPOCKET_DATA`):

| File | What it holds |
| --- | --- |
| `pools/{SET}.json` | The cards packs of a set are drawn from, sorted by color (`W` `U` `B` `R` `G`, `M` multicolor, `C` colorless) and rarity (`common` to `mythic`). Each card keeps its MTGJSON `uuid` and its `scryfallId` for images. |
| `players/{userId}.json` | A player's profile, when they last opened their daily pack, and their shop points. |
| `inventories/{userId}.json` | The cards a player owns: printing uuid → copies. |
| `decks/{userId}.json` | A player's decks, each with a format, a main deck and a side deck: printing uuid → copies, with basic lands as `basic:Plains` and so on. |
| `matches/{id}.json` | A challenge, or a game with everything in it, saved after every action so games survive a restart. |
| `live/{userId}.json` | The match a player is in. |
| `trades/{id}.json` | An open trade offer. The file is deleted once the offer is accepted, declined, cancelled or expired. |

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

Pools imported before matches existed lack the rules data (mana costs, power and toughness, rules text). Run `composer import-cards` again before playing; a deck with such cards cannot start a match until then.

## Tests

```sh
composer unit
composer pint
```

## License

MIT. See [LICENSE.md](LICENSE.md). Magic: The Gathering is © Wizards of the Coast; this project is not affiliated with or endorsed by Wizards of the Coast.
