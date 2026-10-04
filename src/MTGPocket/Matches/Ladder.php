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

namespace MTGPocket\Matches;

use MTGPocket\Storage\JsonStore;

/**
 * Each mode's ratings ladder, kept under `ladders/{mode}.json`. Ranked
 * games (the ones matchmaking pairs) move both players' Elo ratings and
 * add to their win, loss and draw record.
 *
 * @since 0.4.0
 */
final class Ladder
{
    public const string COLLECTION = 'ladders';
    public const int START = 1000;

    /**
     * How far one game moves a rating, at most.
     */
    public const int K = 32;

    public function __construct(private readonly JsonStore $store)
    {
    }

    /**
     * A player's standing in a mode; a player who has not played yet has
     * the starting rating and no games.
     *
     * @param string $mode
     * @param string $playerId
     *
     * @return array{id: string, name: string, rating: int, wins: int, losses: int, draws: int}
     */
    public function entry(string $mode, string $playerId): array
    {
        foreach ($this->entries($mode) as $entry) {
            if ($entry['id'] === $playerId) {
                return $entry;
            }
        }

        return ['id' => $playerId, 'name' => '', 'rating' => self::START, 'wins' => 0, 'losses' => 0, 'draws' => 0];
    }

    /**
     * Everyone who has played a ranked game in a mode, best rating first.
     *
     * @param string $mode
     *
     * @return list<array{id: string, name: string, rating: int, wins: int, losses: int, draws: int}>
     */
    public function standings(string $mode): array
    {
        $entries = $this->entries($mode);
        usort($entries, fn (array $a, array $b) => [$b['rating'], $b['wins'], $a['name']] <=> [$a['rating'], $a['wins'], $b['name']]);

        return $entries;
    }

    /**
     * Records a finished ranked game.
     *
     * @param string                                   $mode
     * @param array{0: array{id: string, name: string}, 1: array{id: string, name: string}} $players
     * @param int|null                                 $winner Index into `$players`; null for a draw.
     *
     * @return array{0: int, 1: int} How each player's rating changed.
     */
    public function record(string $mode, array $players, ?int $winner): array
    {
        $changes = [0, 0];
        $this->store->update(self::COLLECTION, $mode, function (?array $data) use ($players, $winner, &$changes): array {
            $entries = [];
            foreach ((array) ($data['players'] ?? []) as $entry) {
                $entries[(string) $entry['id']] = self::clean((array) $entry);
            }
            $sides = [];
            foreach ($players as $index => $player) {
                $sides[$index] = $entries[(string) $player['id']] ?? ['id' => (string) $player['id'], 'name' => '', 'rating' => self::START, 'wins' => 0, 'losses' => 0, 'draws' => 0];
                $sides[$index]['name'] = (string) $player['name'];
            }

            $expected = 1 / (1 + 10 ** (($sides[1]['rating'] - $sides[0]['rating']) / 400));
            $score = $winner === null ? 0.5 : ($winner === 0 ? 1.0 : 0.0);
            $delta = (int) round(self::K * ($score - $expected));
            $changes = [$delta, -$delta];
            foreach ([0, 1] as $index) {
                $sides[$index]['rating'] += $changes[$index];
                $result = $winner === null ? 'draws' : ($winner === $index ? 'wins' : 'losses');
                $sides[$index][$result]++;
                $entries[$sides[$index]['id']] = $sides[$index];
            }

            return ['players' => array_values($entries)];
        });

        return $changes;
    }

    /**
     * @param string $mode
     *
     * @return list<array{id: string, name: string, rating: int, wins: int, losses: int, draws: int}>
     */
    private function entries(string $mode): array
    {
        return array_values(array_map(fn ($entry) => self::clean((array) $entry), (array) ($this->store->get(self::COLLECTION, $mode)['players'] ?? [])));
    }

    private static function clean(array $entry): array
    {
        return [
            'id' => (string) $entry['id'],
            'name' => (string) ($entry['name'] ?? ''),
            'rating' => (int) ($entry['rating'] ?? self::START),
            'wins' => (int) ($entry['wins'] ?? 0),
            'losses' => (int) ($entry['losses'] ?? 0),
            'draws' => (int) ($entry['draws'] ?? 0),
        ];
    }
}
