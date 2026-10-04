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

namespace MTGPocket\Economy;

/**
 * The points ranked games pay and the daily limits on them and on rental
 * decks, from the `matches` part of `config/economy.php`.
 *
 * @since 0.4.0
 */
final class MatchRewards
{
    /**
     * @param int    $winPoints
     * @param int    $playPoints
     * @param int    $minTurns           The turn a game must reach to pay.
     * @param int    $rewardedPerDay
     * @param int    $rentalGamesPerDay
     * @param string $rentalMode         The mode whose library decides which rental decks are offered.
     */
    public function __construct(
        public readonly int $winPoints = 50,
        public readonly int $playPoints = 10,
        public readonly int $minTurns = 3,
        public readonly int $rewardedPerDay = 10,
        public readonly int $rentalGamesPerDay = 3,
        public readonly string $rentalMode = 'standard',
    ) {
        if ($winPoints < 0 || $playPoints < 0 || $rewardedPerDay < 0 || $rentalGamesPerDay < 0) {
            throw new \InvalidArgumentException('Match points and daily limits cannot be negative.');
        }
    }

    /**
     * @param string $file Like `config/economy.php`.
     *
     * @return self
     */
    public static function fromFile(string $file = PriceList::DEFAULT_FILE): self
    {
        if (! is_file($file)) {
            throw new \InvalidArgumentException("There is no price list at {$file}.");
        }

        $config = (array) require $file;

        return self::fromArray((array) ($config['matches'] ?? []));
    }

    /**
     * @param array $config The `matches` part of `config/economy.php`.
     *
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (int) ($config['win_points'] ?? 50),
            (int) ($config['play_points'] ?? 10),
            (int) ($config['reward_min_turns'] ?? 3),
            (int) ($config['rewarded_games_per_day'] ?? 10),
            (int) ($config['rental_games_per_day'] ?? 3),
            (string) ($config['rental_mode'] ?? 'standard'),
        );
    }
}
