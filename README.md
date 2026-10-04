# DiscordPHP-MTGPocket

A Magic: The Gathering collectible card game played entirely through Discord, in the spirit of Pokémon TCG Pocket. Built on [DiscordPHP-MTG](https://github.com/Valgorithms/DiscordPHP-MTG), with card data from [MTGJSON](https://mtgjson.com/) and card images from [Scryfall](https://scryfall.com/).

Planned features:

1. **Foundation** (done): the project, the card pool import, and JSON storage for players, inventories, decks and side decks.
2. **Packs and collection** (done): one free pack a day. A pack is one set plus one color, and every pack holds at least one rare or mythic rare. Commands to view your collection and build decks.
3. **Rules engine and matches** (done): full Magic rules, played through Discord buttons and menus: opening hands, turns, priority and the stack, mana, casting, combat, state-based actions, and triggered, activated and loyalty abilities with tokens and Equipment. More card text is read in later releases.
4. **Matchmaking and game modes** (done): ranked queues and a ratings ladder for each mode, friendly challenges, deck rules and a card library for each mode (Commander included), rental decks, and points from ranked games and daily and weekly quests.

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
| `/decks commander <deck> [card]` | Makes a legendary creature you own the commander of a Commander deck, or takes it out. |
| `/decks rentals` · `/decks rent <rental>` | The official decks you can play without owning the cards; or plays with one until you pick your own deck with `/decks use`. |
| `/match queue [mode] [deck]` | Waits for a ranked game in a mode (default: your deck's format) against a player of about your rating. |
| `/match challenge <opponent> [deck] [mode]` | Challenges another player to a friendly game. They get **Accept** and **Decline** buttons. |
| `/match modes` · `/match ladder [mode]` | The game modes with their deck rules and who is waiting; or a mode's ratings. |
| `/match board` | The board of the game you are in. |
| `/match leave` | Leaves the queue, calls off your challenge, or concedes your game. |
| `/match log [match]` | The full record of your current or last game (or any game by id), as a text file. Finished boards also have a **Game record** button. |
| `/quests` | Your daily and weekly quests and how far along you are. |
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

**Matches.** A match is one game between two players, each playing the main deck of their active deck (or the deck they name). The board is public: life, cards in hand, library and graveyard, everything on the battlefield, the stack and what just happened. **Your hand & actions** opens your own panel, which only you see: your hand and the choice in front of you (keep or mulligan, play a land or cast a spell, use an ability, choose X and targets, attack, block, discard). Each action posts a fresh board that mentions whoever the game waits on.

What the rules engine does today:

- **Game flow**: a coin flip for who plays first (they skip their first draw), seven-card hands with the London mulligan, every step of the turn, the maximum hand size, and losing at 0 life, 10 poison counters or when drawing from an empty library. Conceding is `/match leave`.
- **Priority and the stack**: spells are cast at instant or sorcery speed (flash counts), resolve last in, first out, and do nothing if all their targets are gone. Like MTG Arena, a player who has nothing they could do passes automatically, except in their own first main phase; **Auto-pass** in your panel turns that off. An ability you could activate stops auto-pass only when something is on the stack or it is your own main phase.
- **Mana**: costs are paid for you from floating mana and your untapped lands and mana creatures, saving dual lands for the colors only they make. Hybrid, Phyrexian (2 life when you have no mana for it), colorless and X costs work, and lands that say they enter tapped do so.
- **Combat**: attacking (summoning sickness, haste, defender, vigilance), blocking (flying and reach, menace), first strike and double strike, trample, deathtouch and lifelink. An attacker assigns lethal damage to its blockers in the order they were declared and the rest to the last one, or with trample to the player.
- **Abilities**: triggered abilities go on the stack the next time a player would get priority, the active player's first, so the other player's resolve first. Their controller picks targets in their panel (or they are picked for them when there is only one choice), and one with no legal target is removed. Activated abilities, loyalty abilities (one per planeswalker per turn, at sorcery speed) and Equip are used from the panel's **Use an ability** menu; their costs (mana, `{T}`, sacrificing it, life, loyalty) are paid for you.
- **Tokens** enter like any permanent and stop existing when they leave the battlefield, though they still "die" on the way.
- **State-based actions**: lethal damage and deathtouch, 0 toughness, planeswalkers with no loyalty, the legend rule (the newest stays), Auras attached to nothing, Equipment attached to something that is not a creature, and +1/+1 against -1/-1 counters.
- **Card text** it reads: keyword lines (flying, reach, first strike, double strike, deathtouch, lifelink, trample, vigilance, haste, defender, menace, indestructible, hexproof, shroud, flash, prowess, fear, intimidate, shadow, skulk, infect, wither, devoid, changeling, landwalk, exalted, persist, undying, convoke, affinity for artifacts, rebound, unearth, bestow, saddle, bushido, toxic, bloodthirst, ward, crew, cycling, basic landcycling and the like, flashback, kicker, morph, megamorph, disguise, level up and Job select), `{T}: Add …` mana abilities (several on one land, and painlands and `Pay 1 life` lands that hurt for their colors), Auras and Equipment that give +N/+N and keywords, Auras that stop a creature attacking, blocking, untapping or using abilities (Pacifism, Arrest, Claustrophobia), `This creature can't block`, `can't be blocked`, `can't be blocked by creatures with power 2 or greater`, `can block only creatures with flying`, `attacks each combat if able`, `This spell can't be countered` and the like, `Equip {N}` (named ones too), `enters with N (or X) +1/+1 counters` and `-1/-1 counters`, lands that enter tapped unless you control something or pay life, `Creatures you control get +1/+1` and `Other creatures you control …`, `As an additional cost to cast this spell, sacrifice a creature` or `discard a card`, level up bands, and the most common effects (damage, draw, gain and lose life, destroy, exile, return to hand, counter a spell, counter unless its controller pays {N}, tap and untap, +N/+N until end of turn for one creature or all of yours, `can't block this turn`, +1/+1 counters, creature tokens, scry, surveil, regenerate, discard and loot, fight, search for a basic land, Empower Jace, `Exile this spell`, mill, Food, Clue and Treasure tokens, investigate, gain control until end of turn, `Target player reveals their hand. You choose …`, return a creature card from your graveyard to your hand or the battlefield, look at or reveal the top N cards and put one or two (or a creature, land, Dwarf and so on) into your hand, energy (`you get {E}{E}` and `Pay {E}{E}:` costs)). Modal spells (`Choose one —`, `Choose two —`, `Choose one or both —`, `Choose one or more —`) and `If this spell was kicked, …` are read too, and so are modal triggered abilities (`When this creature enters, choose one —`). Those effects are also read as triggered abilities (`When … enters`, `… dies`, `Whenever … attacks`, `… deals combat damage to a player`, `When … is turned face up`, Landfall (`Whenever a land you control enters`), `When this Equipment enters, attach it to target creature you control`, `Whenever you cast a noncreature spell`, `At the beginning of your upkeep` or `end step`), as activated abilities with mana, `{T}`, sacrifice and life costs (and `Activate only as a sorcery` or `once each turn`), and as planeswalker loyalty abilities. An ability is read whole or not at all. Text it cannot read yet is listed under the card in your panel, and the card still works without it. `composer card-coverage` shows how much of the imported cards it reads, set by set for the newest ones, and the most common lines it does not read in sets of the last two years; `composer card-coverage -- 100 modern` measures only the cards legal in Modern.
- **Ways to play a card** are offered one by one in the play menu: each mode of a modal spell, with or without kicker, flashback from the graveyard, face down for {3}, and cycling. Crewing taps the creatures that could not attack this turn first, then the weakest. Ward is paid along with the spell or ability that targets the permanent; a triggered ability whose controller cannot pay it is countered. A modal triggered ability asks its controller for its mode before targets, offering only modes with something to target. Looking at the top cards, scry, surveil and discard stop the spell while its player picks which cards go to their hand, the bottom, the graveyard or away from their hand; with no more cards than they must discard, they discard them all without being asked. A counterspell that can be paid off is paid for its target's controller when they can. A land that enters tapped unless you pay life is paid for on your own turn while you have more than 10 life. Searching for a basic land takes the color your hand needs most that your lands make least. An additional cost sacrifices your weakest creature (tokens first) or discards your cheapest card. Convoke taps creatures once lands are used up. `As this enters, choose a creature type` (or `a color`) is chosen for the player: the creature type most of their creature cards have, or the color most common in their mana costs; `Creatures you control of the chosen type get +1/+1` and `Add one mana of the chosen color` use it. Bestow is offered as its own way to cast the card: it enters attached to a creature and becomes a creature again when that creature leaves. Saddle taps creatures like crew, as a sorcery, and turns on `Whenever this creature attacks while saddled` abilities. Unearth is offered in the play menu from your graveyard; the creature is exiled at the end step or whenever it would leave the battlefield. A spell with rebound cast from your hand is exiled as it resolves and offered again, free, in your next upkeep.

**Game modes.** Each mode has deck rules and a card library, set in [`config/modes.php`](config/modes.php): **Standard** (60+ cards, a side deck of up to 15, up to 4 copies of a card, sets released in the last 3 years), **Casual** (40+ cards, anything you own), **Limited** (40+ cards, no copy limit) and **Commander** (exactly 100 cards counting the commander, one copy of each, every card within the commander's colors, 40 life). Basic lands are never limited. `/decks show` says whether a deck meets its format's rules, and what to fix if not.

**Match records.** Every game is recorded move by move and saved with the match, like a chess game score. The record opens with tags (players, decks, mode, result, why the game ended), then a score sheet with one numbered line per turn (`+Forest` plays a land, `Shock > Bob` casts a spell at a target, `Card*` activates an ability, `atk` and `blk` are attacks and blocks, `†Card` goes to the graveyard, life totals at the end of each turn), then the full log step by step with each player's position at the end of every turn. Nothing hidden is in it: drawn cards are not named, and the shuffle seed is only shown once the game is over. Each player's last 25 games are kept for `/match log`.

**Matchmaking.** `/match queue` pairs you at once with a player waiting in the same mode whose rating is within 200 of yours, a range that widens by 50 for every minute they have waited; the closest rating goes first. A spot in the queue lasts 30 minutes. Queued games are ranked: each mode has its own Elo ladder (everyone starts at 1000). Challenges are friendly: they never move the ladder or pay points.

**Commander.** Pick a legendary creature you own to lead a deck with `/decks commander`. It starts the game in the command zone, listed with your hand, and can be cast from there; each time after the first it costs {2} more. When it would go to the graveyard or exile, it returns to the command zone instead (its "dies" abilities still trigger). A player who takes 21 combat damage from one commander loses, whatever their life. The board shows each command zone, the current tax, and commander damage taken. Pools imported before this release lack color identity data; it is then worked out from each card's colors, cost and text, and `composer import-cards` fills it in.

**Rental decks.** Official preconstructed decks of the sets in the Standard library (Commander decks aside), imported with the card pools. Anyone can play one with `/decks rent`, up to 3 games a day, so new players can play before they own enough cards. Rentals can't be edited or exported.

**Points from games and quests.** A ranked game that reaches turn 3 pays 50 points to the winner and 10 to the loser, for up to 10 games a day. Every day you get 3 quests and every week 2, such as "Win a ranked game" or "Open 7 packs", which pay their points the moment they are done; different players get different quests. Quests count ranked games (from turn 3) and opened packs. The amounts are in [`config/economy.php`](config/economy.php) (`matches`) and the quests in [`config/quests.php`](config/quests.php).

**Points and the shop.** Selling a card earns points; points buy single cards or packs. Prices are set in [`config/economy.php`](config/economy.php): a card costs 20, 60, 300 or 900 points by rarity (common to mythic), times 2 for a set from the last year, 1.5 for the last three years, 1 for the last ten and 0.75 for older ones. A pack costs 500 points with the same multiplier. Selling pays 20% of the buy price (at least 1 point), so selling a card and buying it back always loses points. Cards your decks use are never sold: a deck that needs 2 copies keeps 2.

**Trades.** An offer can hold cards and points on both sides, up to 20 different cards each. Sending it is your agreement and **Accept** is theirs. Nothing is set aside while the offer is open: when it is accepted, both sides are checked again and the whole trade happens at once or not at all. Adding to an offer with `/trade add` posts the new version, and **Accept** on an older copy shows the new one instead of agreeing to it. Offers stay open for 7 days, and a player can have 10 open at a time. Cards a deck uses can't be traded away.

**Decks.** A deck may use each card you own as many times as you own it, main and side deck together, and the same cards can go in any number of decks. Basic lands are free and unlimited. Deck edits answer only you. A deck's format (Standard, Commander, Limited, Casual) decides which rules it is checked against and its default game mode.

## How the data is kept

Card data comes from DiscordPHP-MTG's local copy of MTGJSON's AllPrintings SQLite build. The game copies what it needs out of it into its own JSON files under `var/data/` (or `MTGPOCKET_DATA`):

| File | What it holds |
| --- | --- |
| `pools/{SET}.json` | The cards packs of a set are drawn from, sorted by color (`W` `U` `B` `R` `G`, `M` multicolor, `C` colorless) and rarity (`common` to `mythic`). Each card keeps its MTGJSON `uuid` and its `scryfallId` for images. |
| `players/{userId}.json` | A player's profile, when they last opened their daily pack, their shop points, their rental deck, today's rental and rewarded games, and their quest progress. |
| `inventories/{userId}.json` | The cards a player owns: printing uuid → copies. |
| `decks/{userId}.json` | A player's decks, each with a format, a main deck and a side deck: printing uuid → copies, with basic lands as `basic:Plains` and so on. |
| `matches/{id}.json` | A challenge, or a game with everything in it, saved after every action so games survive a restart. |
| `live/{userId}.json` | The match a player is in. |
| `queues/{mode}.json` | Who is waiting for a ranked game in a mode, with their deck and rating. |
| `ladders/{mode}.json` | A mode's ratings, wins, losses and draws. |
| `rentals/{id}.json` | An official preconstructed deck players can rent, with its cards' data. |
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

`composer import-cards -- KTK DMU` imports just those sets, and their official decks as rentals. Re-run the import after MTGJSON adds a set.

The bot reads its settings from `config/`: shop prices and match points from `economy.php`, game modes from `modes.php` and quests from `quests.php`. Set `MTGPOCKET_ECONOMY`, `MTGPOCKET_MODES` or `MTGPOCKET_QUESTS` to use copies kept elsewhere.

Pools imported before matches existed lack the rules data (mana costs, power and toughness, rules text). Run `composer import-cards` again before playing; a deck with such cards cannot start a match until then.

## Tests

```sh
composer unit
composer pint
```

## License

MIT. See [LICENSE.md](LICENSE.md). Magic: The Gathering is © Wizards of the Coast; this project is not affiliated with or endorsed by Wizards of the Coast.
