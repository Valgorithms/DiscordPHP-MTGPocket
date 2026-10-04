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

namespace MTGPocket\Models;

/**
 * A Discord user who plays: their profile, when they last opened their
 * free daily pack, and the points they have to spend in the shop. Their
 * cards are an {@see Inventory} and their decks are {@see Deck}s, each
 * stored on its own.
 *
 * @since 0.1.0
 */
class Player implements \JsonSerializable
{
    /**
     * @param string      $id              The Discord user id.
     * @param string      $name            Display name when last seen.
     * @param int         $createdAt       Unix time the player first played.
     * @param int|null    $lastDailyPackAt Unix time of the last free daily pack, or null if never.
     * @param string|null $activeDeckId    The deck the player queues with.
     * @param int         $points          Shop points, earned by selling cards and winning ranked games, and spent on cards and packs.
     * @param string|null $activeRental    The rental deck the player queues with instead of their own, if any.
     * @param string|null $matchDay        The UTC day (`YYYY-MM-DD`) the counts below are for.
     * @param int         $rentalGames     Games started with a rental deck that day.
     * @param int         $rewardedGames   Ranked games that paid points that day.
     * @param array<string, array{period: string, progress: array<string, int>}> $quests Quest progress, by `daily` and `weekly`; see {@see \MTGPocket\Quests\Quests}.
     */
    public function __construct(
        public readonly string $id,
        public string $name = '',
        public int $createdAt = 0,
        public ?int $lastDailyPackAt = null,
        public ?string $activeDeckId = null,
        public int $points = 0,
        public ?string $activeRental = null,
        public ?string $matchDay = null,
        public int $rentalGames = 0,
        public int $rewardedGames = 0,
        public array $quests = [],
    ) {
        if ($this->createdAt === 0) {
            $this->createdAt = time();
        }
    }

    /**
     * Starts the daily match counts over on a new day.
     *
     * @param string $day `YYYY-MM-DD`, UTC.
     *
     * @return void
     */
    public function onDay(string $day): void
    {
        if ($this->matchDay !== $day) {
            $this->matchDay = $day;
            $this->rentalGames = 0;
            $this->rewardedGames = 0;
        }
    }

    /**
     * @param array $data As stored.
     *
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (string) $data['id'],
            (string) ($data['name'] ?? ''),
            (int) ($data['createdAt'] ?? 0),
            isset($data['lastDailyPackAt']) ? (int) $data['lastDailyPackAt'] : null,
            isset($data['activeDeckId']) ? (string) $data['activeDeckId'] : null,
            (int) ($data['points'] ?? 0),
            isset($data['activeRental']) ? (string) $data['activeRental'] : null,
            isset($data['matchDay']) ? (string) $data['matchDay'] : null,
            (int) ($data['rentalGames'] ?? 0),
            (int) ($data['rewardedGames'] ?? 0),
            (array) ($data['quests'] ?? []),
        );
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'createdAt' => $this->createdAt,
            'lastDailyPackAt' => $this->lastDailyPackAt,
            'activeDeckId' => $this->activeDeckId,
            'points' => $this->points,
            'activeRental' => $this->activeRental,
            'matchDay' => $this->matchDay,
            'rentalGames' => $this->rentalGames,
            'rewardedGames' => $this->rewardedGames,
            'quests' => $this->quests,
        ];
    }
}
