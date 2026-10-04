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
 * The game modes: what a deck needs to be played in each, and which cards
 * each mode's library allows. Change them here; the bot reads this file
 * when it starts. Set MTGPOCKET_MODES to use a copy kept somewhere else.
 *
 * The keys are the formats a deck can be built for (`/decks format`).
 *
 * - `main_min`, `main_max`: main deck size; `main_max` null for no limit.
 * - `side_max`: side deck size limit; null for no limit.
 * - `copies`: most copies of one card (by name, basic lands aside) in the
 *   main and side deck together; null for no limit.
 * - `life`: each player's starting life.
 * - `sets`: the set codes whose cards are allowed; null for every imported set.
 * - `released_within_days`: only sets released at most this many days ago;
 *   null for any age. With `sets` too, a set has to pass both.
 * - `banned`: card names that are not allowed.
 * - `playable`: false while the rules engine cannot run the mode yet; its
 *   decks can still be built and checked.
 */
return [
    // Recent sets only. Real Standard rotates by set; this keeps the sets
    // of about the last three years. List them in `sets` to match it exactly.
    'standard' => [
        'main_min' => 60,
        'main_max' => null,
        'side_max' => 15,
        'copies' => 4,
        'life' => 20,
        'sets' => null,
        'released_within_days' => 1095,
        'banned' => [],
    ],

    // Anything goes, as long as you own it.
    'casual' => [
        'main_min' => 40,
        'main_max' => null,
        'side_max' => 15,
        'copies' => null,
        'life' => 20,
        'sets' => null,
        'released_within_days' => null,
        'banned' => [],
    ],

    // Small decks built from whatever your packs gave you.
    'limited' => [
        'main_min' => 40,
        'main_max' => null,
        'side_max' => null,
        'copies' => null,
        'life' => 20,
        'sets' => null,
        'released_within_days' => null,
        'banned' => [],
    ],

    // 100 cards, one of each (basic lands aside), 40 life.
    'commander' => [
        'main_min' => 100,
        'main_max' => 100,
        'side_max' => 0,
        'copies' => 1,
        'life' => 40,
        'sets' => null,
        'released_within_days' => null,
        'banned' => [],
        'playable' => false,
    ],
];
