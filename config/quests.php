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
 * Daily and weekly quests, which pay points when done. Change them here;
 * the bot reads this file when it starts. Set MTGPOCKET_QUESTS to use a copy
 * kept somewhere else.
 *
 * Each day (UTC) every player gets `daily_count` quests picked from `daily`,
 * and each week (Monday to Sunday, UTC) `weekly_count` picked from `weekly`.
 * Different players get different picks. A quest pays its points once, the
 * moment it is done.
 *
 * What a quest counts (`event`):
 * - `ranked`: ranked games (the ones `/match queue` pairs) played to the end
 * - `win`:    ranked games won
 * - `rental`: ranked games played to the end with a rental deck
 * - `pack`:   packs opened, free or bought
 *
 * Games count only once they reach `reward_min_turns` in config/economy.php,
 * and friendly challenges never count, for the same reason they pay nothing.
 * `id` is how a player's progress is saved: keep it when changing a quest,
 * and use a new one for a new quest.
 */
return [
    'daily_count' => 3,
    'weekly_count' => 2,

    'daily' => [
        ['id' => 'daily-play-2', 'label' => 'Play 2 ranked games', 'event' => 'ranked', 'goal' => 2, 'points' => 30],
        ['id' => 'daily-play-4', 'label' => 'Play 4 ranked games', 'event' => 'ranked', 'goal' => 4, 'points' => 60],
        ['id' => 'daily-win-1', 'label' => 'Win a ranked game', 'event' => 'win', 'goal' => 1, 'points' => 40],
        ['id' => 'daily-win-2', 'label' => 'Win 2 ranked games', 'event' => 'win', 'goal' => 2, 'points' => 80],
        ['id' => 'daily-rental-1', 'label' => 'Play a ranked game with a rental deck', 'event' => 'rental', 'goal' => 1, 'points' => 30],
        ['id' => 'daily-pack-1', 'label' => 'Open a pack', 'event' => 'pack', 'goal' => 1, 'points' => 20],
    ],

    'weekly' => [
        ['id' => 'weekly-play-15', 'label' => 'Play 15 ranked games', 'event' => 'ranked', 'goal' => 15, 'points' => 250],
        ['id' => 'weekly-win-7', 'label' => 'Win 7 ranked games', 'event' => 'win', 'goal' => 7, 'points' => 300],
        ['id' => 'weekly-rental-5', 'label' => 'Play 5 ranked games with rental decks', 'event' => 'rental', 'goal' => 5, 'points' => 150],
        ['id' => 'weekly-pack-7', 'label' => 'Open 7 packs', 'event' => 'pack', 'goal' => 7, 'points' => 150],
    ],
];
