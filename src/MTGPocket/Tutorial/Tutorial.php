<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-MTGPocket project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace MTGPocket\Tutorial;

use MTGPocket\Game\Game;
use MTGPocket\Game\Step;

/**
 * The quick start for someone who has never played Magic: a few short
 * pages (`/tutorial`, or **How to play** in `/menu`), then a practice game
 * against {@see PracticeBot} where the action panel adds a tip for each
 * decision.
 *
 * @since 0.7.0
 */
final class Tutorial
{
    /**
     * The pages: title => text.
     *
     * @var array<string, string>
     */
    public const array PAGES = [
        'Welcome' => <<<'TEXT'
            Magic: The Gathering is a card game for two players. You play a wizard, a *planeswalker*, and your deck is your spellbook.
            - You both start at **20 life**. Bring your opponent to **0** and you win.
            - You start with **7 cards** in your hand and draw one more each turn.
            - Cards come in five colors, each with its own style: ⚪ **White** fights in groups, 🔵 **Blue** tricks and draws cards, ⚫ **Black** destroys, 🔴 **Red** burns and rushes, 🟢 **Green** grows big creatures.

            This guide takes about five minutes. At the end you can play a practice game against a bot with a ready-made deck.
            TEXT,
        'Cards' => <<<'TEXT'
            A card's **type line**, under its picture, says what it is.
            - 🏔️ **Land**: makes the mana you pay for everything else.
            - 🐻 **Creature**: stays on the battlefield and fights. Its two numbers are **power/toughness**: a 3/2 deals 3 damage and dies once it has taken 2.
            - ⚡ **Instant**: a one-shot spell you can cast any time, even on your opponent's turn.
            - 📜 **Sorcery**: a one-shot spell for your own main phase only.
            - ✨ **Enchantment**, ⚙️ **Artifact** and 🧙 **Planeswalker**: stay on the battlefield and keep doing something.

            Cards that stay on the battlefield are called **permanents**. Instants and sorceries go to your **graveyard** (your discard pile) once used, and so do creatures that die.
            TEXT,
        'Mana and lands' => <<<'TEXT'
            Every spell has a **mana cost**, written here like `{2}{G}`: two mana of any color plus one green.
            - Each basic land **taps** (turns sideways) for one mana of its color: Plains ⚪, Island 🔵, Swamp ⚫, Mountain 🔴, Forest 🟢.
            - You may play **one land per turn**, in your main phase. Playing a land costs nothing.
            - Your lands untap at the start of each of your turns, so you have more mana every turn as they pile up.
            - About 40% of a deck is lands: 17 in a 40-card deck, 24 in a 60-card one.

            Here the game taps your lands for you when you cast a spell.
            TEXT,
        'Your turn' => <<<'TEXT'
            Every turn goes through the same steps:
            1. **Beginning**: untap your cards, then draw a card. The player who goes first skips that first draw.
            2. **Main phase**: play a land, cast creatures and other spells.
            3. **Combat**: attack with your creatures (next page).
            4. **Second main phase**: cast what you held back until after combat.
            5. **End**: damage on creatures wears off. With more than 7 cards in hand, you discard down to 7.

            A creature can't attack the turn it arrives (nor use an ability with {T} in its cost): it has **summoning sickness**. Creatures with **haste** can.
            TEXT,
        'Combat' => <<<'TEXT'
            - **Attack**: choose which of your untapped creatures attack. They tap and go for your opponent.
            - **Block**: your opponent chooses which of their untapped creatures block. Each blocker stops one attacker.
            - **Damage**: an unblocked attacker deals its power to the player. A blocked attacker and its blocker deal damage to each other, and a creature dies once its damage reaches its toughness.

            Say your 3/3 Hill Giant is blocked by a 2/2 bear: the bear dies, and the Giant lives with 2 damage, which heals at the end of the turn.

            Keywords you will meet first: **Flying** (only creatures with flying or reach can block it), **Reach**, **Haste**, **Vigilance** (attacks without tapping), **Deathtouch** (any damage it deals kills), **Trample** (damage beyond what kills the blocker hits the player) and **First strike** (deals its damage first).
            TEXT,
        'Winning and the stack' => <<<'TEXT'
            You win when your opponent drops to **0 life**, has to draw from an empty library, or concedes.

            **The stack**: a spell doesn't happen the moment it is cast. It waits on the *stack*, so both players get a chance to answer with instants, and the last spell cast happens first. When both players **pass priority** in a row, the spell on top resolves; with nothing on the stack, passing moves the game to its next step.

            You don't need to master this yet: when you have nothing you can do, the game passes for you.
            TEXT,
        'Playing here' => <<<'TEXT'
            - A game has a **board** that shows both sides, and **🃏 Your hand & actions**, a panel only you see. Play lands, cast spells, attack and block from there; ✅ marks the cards you can play now.
            - **Pass priority** moves the game on when you are done.
            - `/menu` has the rest: 🎁 a free **pack** every day, 📚 your **collection**, 🃏 **decks** (40 cards for Casual, 60 for Standard; basic lands are free), and ⚔️ **Play** to challenge a player or find a ranked game. No cards yet? **Rent** a ready-made deck under Decks.

            Ready? Start a **practice game**: you play a red-green starter deck against the bot. Nothing is at stake, only you see it, and tips show up as you play.
            TEXT,
    ];

    /**
     * How many pages there are.
     *
     * @return int
     */
    public static function count(): int
    {
        return count(self::PAGES);
    }

    /**
     * A page's title and text, by number from 0; out of range gives the nearest page.
     *
     * @param int $page
     *
     * @return array{0: int, 1: string, 2: string} The page number, its title and its text.
     */
    public static function page(int $page): array
    {
        $page = max(0, min(self::count() - 1, $page));
        $title = array_keys(self::PAGES)[$page];

        return [$page, $title, self::PAGES[$title]];
    }

    /**
     * A beginner's tip for the decision a player has in a practice game.
     *
     * @param Game        $game
     * @param int         $seat
     * @param string|null $decision {@see Game::decision()}.
     * @param array       $choice   What they have picked so far in their panel.
     *
     * @return string|null
     */
    public static function tip(Game $game, int $seat, ?string $decision, array $choice = []): ?string
    {
        $player = $game->players[$seat];
        $mine = $game->active === $seat;

        return match ($decision) {
            'mulligan' => 'A good opening hand has 2 to 5 lands. With fewer or more, a mulligan shuffles it away for a new seven, and you then put one card on the bottom.',
            'bottom' => 'Put back what helps you least: an extra land, or a spell too expensive to cast soon.',
            'attack' => 'Attacking taps your creatures. Attack when the bot has no untapped creature that could block and kill yours without dying too; pick none to skip combat.',
            'block' => 'Blocking keeps the damage off you. A blocker that would die without killing the attacker is usually better kept back, unless the damage would finish you.',
            'discard' => 'You may hold at most seven cards at the end of your turn. Discard extra lands first once you have plenty on the battlefield.',
            'priority' => match (true) {
                isset($choice['cast']) => 'Pick the target, then confirm. Your lands pay the cost by themselves.',
                $game->stack !== [] => 'Something is on the stack. You may answer with an instant like **Shock** or **Giant Growth**, or pass to let it happen.',
                ! $mine => 'It is the bot\'s turn. Instants can be cast now; otherwise pass.',
                $game->step->isMain() && $player->landsPlayed === 0 && self::hasLand($game, $seat) => 'Start your turn by playing a land from your hand: one each turn.',
                $game->step->isMain() && $game->playableCards($seat) !== [] => 'The ✅ cards are the ones you can afford now. Creatures and sorceries can only be cast in your main phase.',
                $game->step === Step::PrecombatMain => 'Nothing more to cast: pass to go to combat.',
                $game->step->isMain() => 'Nothing more to do: pass to end your turn.',
                default => 'Instants still work in combat: **Giant Growth** can save a blocked creature. Otherwise pass.',
            },
            default => null,
        };
    }

    private static function hasLand(Game $game, int $seat): bool
    {
        foreach ($game->players[$seat]->hand as $id) {
            if ($game->objects[$id]->definition()->isLand()) {
                return true;
            }
        }

        return false;
    }
}
