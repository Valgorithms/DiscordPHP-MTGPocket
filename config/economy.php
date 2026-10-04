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

/*
 * The shop's prices, in points. Change them here; the bot reads this file
 * when it starts. Set MTGPOCKET_ECONOMY to use a copy kept somewhere else.
 *
 * A card's price is its rarity's price times its set's age multiplier,
 * rounded. Selling pays `sell_rate` of what the card costs to buy right now,
 * so selling a card and buying it back always loses points.
 */
return [
    // What a card costs to buy, by rarity, before the set's age is counted.
    'buy' => [
        'common' => 20,
        'uncommon' => 60,
        'rare' => 300,
        'mythic' => 900,
    ],

    // What a pack (15 cards of one color of one set) costs, before the set's
    // age is counted. Its cards would cost about 800 points one by one.
    'pack' => 500,

    // The share of the buy price a sold card pays, more than 0 and less than
    // 1. Selling a card always pays at least 1 point.
    'sell_rate' => 0.2,

    // How a set's age changes its prices: the first tier the set is young
    // enough for applies. `days` is the most days since the set's release
    // for the tier; null means any age. A set with no release date counts as
    // the oldest.
    'age' => [
        ['days' => 365, 'multiplier' => 2.0],      // released in the last year
        ['days' => 3 * 365, 'multiplier' => 1.5],  // the last three years
        ['days' => 10 * 365, 'multiplier' => 1.0], // the last ten years
        ['days' => null, 'multiplier' => 0.75],    // older
    ],

    // Points for ranked games (the ones `/match queue` pairs) and the limits
    // on rental decks. Friendly challenges earn nothing, so two friends
    // cannot farm points by conceding to each other.
    'matches' => [
        // Points for winning a ranked game, and for playing one to the end
        // without winning.
        'win_points' => 50,
        'play_points' => 10,
        // A game pays only if it reached this turn, so conceding at once earns nothing.
        'reward_min_turns' => 3,
        // Ranked games a player can earn points from each day (UTC).
        'rewarded_games_per_day' => 10,
        // Games a player can start with rental decks each day (UTC).
        'rental_games_per_day' => 3,
        // Rental decks are the official decks of the sets this mode's library
        // allows: Standard's are the current sets.
        'rental_mode' => 'standard',
    ],
];
