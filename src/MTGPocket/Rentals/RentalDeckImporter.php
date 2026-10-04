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

namespace MTGPocket\Rentals;

use MTG\Database\Database;
use MTGPocket\Cards\CardPoolImporter;

/**
 * Reads a set's official preconstructed decks from DiscordPHP-MTG's
 * MTGJSON build (its `setDecks` table) as rental decks, with the card data
 * the rules engine needs.
 *
 * Commander decks are left for Commander games, and decks smaller than
 * {@see MIN_CARDS} (Jumpstart halves and the like) are skipped.
 *
 * @since 0.4.0
 */
final class RentalDeckImporter
{
    public const int MIN_CARDS = 40;

    /**
     * @param Database $database An open MTGJSON build (wait for `ready()` first).
     */
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param string $setCode
     *
     * @return RentalDeck[]
     */
    public function import(string $setCode): array
    {
        $setCode = strtoupper($setCode);
        $columns = array_keys($this->database->getColumns('setDecks'));
        if ($columns === []) {
            return []; // A build from before MTGJSON listed decks.
        }
        $setColumn = in_array('setCode', $columns, true) ? 'setCode' : 'code';
        $set = $this->database->select('SELECT "name", "releaseDate" FROM "sets" WHERE "code" = ?', [$setCode])[0] ?? [];

        $decks = [];
        foreach ($this->database->select("SELECT * FROM \"setDecks\" WHERE \"{$setColumn}\" = ? ORDER BY \"name\"", [$setCode]) as $row) {
            $row = $this->database->decode('setDecks', $row);
            if (stripos((string) ($row['type'] ?? ''), 'commander') !== false || ! empty($row['commander'])) {
                continue;
            }
            $main = self::entries($row['mainBoard'] ?? []);
            if (array_sum($main) < self::MIN_CARDS) {
                continue;
            }
            $side = self::entries($row['sideBoard'] ?? []);
            $cards = $this->cards(array_keys($main + $side));
            $list = fn (array $counts) => array_values(array_filter(array_map(
                fn (string $uuid, int $count) => isset($cards[$uuid]) ? ['count' => $count, 'card' => $cards[$uuid]] : null,
                array_keys($counts),
                $counts,
            )));

            $deck = new RentalDeck(
                RentalDeck::idFor($setCode, (string) $row['name']),
                (string) $row['name'],
                $setCode,
                (string) ($set['name'] ?? $setCode),
                (string) ($row['type'] ?? ''),
                $row['releaseDate'] ?? $set['releaseDate'] ?? null,
                $list($main),
                $list($side),
            );
            // Every card has to be known, or the deck would play short.
            if ($deck->mainCount() === array_sum($main)) {
                $decks[] = $deck;
            }
        }

        return $decks;
    }

    /**
     * MTGJSON deck entries as uuid => count.
     *
     * @param mixed $board
     *
     * @return array<string, int>
     */
    private static function entries(mixed $board): array
    {
        $counts = [];
        foreach ((array) $board as $entry) {
            $entry = (array) $entry;
            if (isset($entry['uuid'])) {
                $counts[(string) $entry['uuid']] = ($counts[(string) $entry['uuid']] ?? 0) + (int) ($entry['count'] ?? 1);
            }
        }

        return $counts;
    }

    /**
     * Card data by uuid, as the card pools keep it.
     *
     * @param string[] $uuids
     *
     * @return array<string, array>
     */
    private function cards(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $rules = array_values(array_intersect(CardPoolImporter::RULES_COLUMNS, array_keys($this->database->getColumns('cards'))));
        $rows = $this->database->select(
            'SELECT "c"."uuid", "c"."name", "c"."number", "c"."rarity", "c"."colors", "c"."manaValue", "c"."type", "i"."scryfallId"'
            .implode('', array_map(fn (string $column) => ", \"c\".\"{$column}\"", $rules))
            .' FROM "cards" AS "c" LEFT JOIN "cardIdentifiers" AS "i" ON "i"."uuid" = "c"."uuid"'
            .' WHERE "c"."uuid" IN ('.implode(', ', array_fill(0, count($uuids), '?')).')',
            array_values($uuids)
        );

        $cards = [];
        foreach ($rows as $row) {
            $row = $this->database->decode('cards', $row);
            $card = [
                'uuid' => $row['uuid'],
                'name' => $row['name'],
                'number' => $row['number'] ?? null,
                'rarity' => $row['rarity'] ?? 'common',
                'colors' => $row['colors'] ?? [],
                'manaValue' => (float) ($row['manaValue'] ?? 0),
                'type' => $row['type'] ?? null,
                'scryfallId' => $row['scryfallId'] ?? null,
            ];
            foreach ($rules as $column) {
                if (isset($row[$column])) {
                    $card[$column] = $row[$column];
                }
            }
            $card['manaCost'] ??= null;
            $cards[$row['uuid']] = $card;
        }

        return $cards;
    }
}
