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

namespace MTGPocket\Quests;

use MTGPocket\Models\Player;
use MTGPocket\Repository\PlayerRepository;

/**
 * Daily and weekly quests: each player gets a few of the {@see QuestBook}'s
 * quests a day and a week, picked by a hash of their id and the period so
 * they stay the same all day and differ between players. Progress is kept
 * on the player and starts over when the period does; a quest pays its
 * points the moment it is done.
 *
 * @since 0.4.0
 */
final class Quests
{
    public const string DAILY = 'daily';
    public const string WEEKLY = 'weekly';

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param PlayerRepository       $players
     * @param QuestBook              $book
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly PlayerRepository $players,
        public readonly QuestBook $book,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Counts something a player did towards their quests and pays the ones
     * it completes.
     *
     * @param string $playerId
     * @param string $event    One of {@see Quest::EVENTS}.
     * @param int    $amount
     *
     * @return list<array{label: string, points: int}> The quests it completed.
     */
    public function record(string $playerId, string $event, int $amount = 1): array
    {
        if (! isset(Quest::EVENTS[$event])) {
            throw new \InvalidArgumentException("Quests do not count \"{$event}\".");
        }
        if ($amount <= 0) {
            return [];
        }

        $now = ($this->clock)();
        $done = [];
        $this->players->findOrCreate($playerId);
        $this->players->modify($playerId, function (Player $player) use ($playerId, $event, $amount, $now, &$done): void {
            $done = [];
            foreach ([self::DAILY, self::WEEKLY] as $kind) {
                $progress = $this->progress($player, $kind, $now);
                foreach ($this->picks($playerId, $kind, $now) as $quest) {
                    if ($quest->event !== $event) {
                        continue;
                    }
                    $before = $progress[$quest->id] ?? 0;
                    $progress[$quest->id] = min($quest->goal, $before + $amount);
                    if ($before < $quest->goal && $progress[$quest->id] >= $quest->goal) {
                        $player->points += $quest->points;
                        $done[] = ['label' => $quest->label, 'points' => $quest->points];
                    }
                }
                $player->quests[$kind] = ['period' => self::period($kind, $now), 'progress' => $progress];
            }
        });

        return $done;
    }

    /**
     * A player's quests now, with their progress.
     *
     * @param string $playerId
     *
     * @return array{daily: list<array{quest: Quest, progress: int}>, weekly: list<array{quest: Quest, progress: int}>, dailyEnds: int, weeklyEnds: int}
     */
    public function board(string $playerId): array
    {
        $now = ($this->clock)();
        $player = $this->players->find($playerId) ?? new Player($playerId);
        $board = ['dailyEnds' => self::ends(self::DAILY, $now), 'weeklyEnds' => self::ends(self::WEEKLY, $now)];
        foreach ([self::DAILY, self::WEEKLY] as $kind) {
            $progress = $this->progress($player, $kind, $now);
            $board[$kind] = array_map(fn (Quest $quest) => ['quest' => $quest, 'progress' => $progress[$quest->id] ?? 0], $this->picks($playerId, $kind, $now));
        }

        return $board;
    }

    /**
     * A player's quests for the current day or week.
     *
     * @param string $playerId
     * @param string $kind     {@see self::DAILY} or {@see self::WEEKLY}.
     * @param int    $now
     *
     * @return list<Quest>
     */
    public function picks(string $playerId, string $kind, int $now): array
    {
        [$quests, $count] = $kind === self::DAILY ? [$this->book->daily, $this->book->dailyCount] : [$this->book->weekly, $this->book->weeklyCount];
        $period = self::period($kind, $now);
        usort($quests, fn (Quest $a, Quest $b) => [hash('crc32b', "{$playerId}:{$period}:{$a->id}"), $a->id] <=> [hash('crc32b', "{$playerId}:{$period}:{$b->id}"), $b->id]);

        return array_slice($quests, 0, $count);
    }

    /**
     * The current day (`2026-10-04`) or ISO week (`2026-W40`), UTC.
     *
     * @param string $kind
     * @param int    $now
     *
     * @return string
     */
    public static function period(string $kind, int $now): string
    {
        return $kind === self::DAILY ? gmdate('Y-m-d', $now) : gmdate('o-\\WW', $now);
    }

    /**
     * When the current day or week ends: midnight UTC, or Monday's.
     *
     * @param string $kind
     * @param int    $now
     *
     * @return int Unix time.
     */
    public static function ends(string $kind, int $now): int
    {
        $midnight = intdiv($now, 86400) * 86400 + 86400;

        return $kind === self::DAILY ? $midnight : $midnight + (7 - (int) gmdate('N', $now)) * 86400;
    }

    /**
     * A player's progress in the current period, by quest id.
     *
     * @param Player $player
     * @param string $kind
     * @param int    $now
     *
     * @return array<string, int>
     */
    private function progress(Player $player, string $kind, int $now): array
    {
        $saved = $player->quests[$kind] ?? [];

        return ($saved['period'] ?? null) === self::period($kind, $now) ? array_map('intval', (array) ($saved['progress'] ?? [])) : [];
    }
}
