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

namespace MTGPocket\Cards;

use MTG\Database\Database;

/**
 * Reads card pools out of the MTGJSON build that DiscordPHP-MTG keeps, so
 * the game has its own stable copy of every set it sells packs of.
 *
 * A set's pool is the cards its real boosters hold: front faces only, no
 * promos and no basic lands, one printing per card name (the lowest
 * collector number, so showcase and borderless variants do not double up).
 * When MTGJSON marks which cards are in the set's default boosters, only
 * those are kept.
 *
 * Images are not copied: each card keeps its `scryfallId`, from which
 * DiscordPHP-MTG builds Scryfall image URLs.
 *
 * @since 0.1.0
 */
class CardPoolImporter
{
    /**
     * Set types whose packs players can open by default.
     *
     * @var string[]
     */
    public const array SET_TYPES = ['core', 'expansion', 'draft_innovation', 'masters'];

    /**
     * @param Database $database An open MTGJSON build (wait for `ready()` first).
     */
    public function __construct(protected Database $database)
    {
    }

    /**
     * The sets that can have packs: paper sets of {@see SET_TYPES} already
     * released, oldest first.
     *
     * @param string[] $types Set types to include.
     *
     * @return array<string, array{code: string, name: string, releaseDate: ?string, type: string}> By set code.
     */
    public function sets(array $types = self::SET_TYPES): array
    {
        if ($types === []) {
            return [];
        }

        $rows = $this->database->select(
            'SELECT "code", "name", "releaseDate", "type" FROM "sets"'
            .' WHERE "type" IN ('.implode(', ', array_fill(0, count($types), '?')).')'
            .' AND COALESCE("isOnlineOnly", 0) = 0'
            .' AND ("releaseDate" IS NULL OR "releaseDate" <= ?)'
            .' ORDER BY "releaseDate", "code"',
            [...array_values($types), gmdate('Y-m-d')]
        );

        $sets = [];
        foreach ($rows as $row) {
            $sets[$row['code']] = $row;
        }

        return $sets;
    }

    /**
     * Builds one set's pool.
     *
     * @param string $setCode
     *
     * @throws \OutOfBoundsException When the build has no such set.
     *
     * @return CardPool
     */
    public function import(string $setCode): CardPool
    {
        $setCode = strtoupper($setCode);
        $set = $this->database->select('SELECT "code", "name", "releaseDate" FROM "sets" WHERE "code" = ?', [$setCode])[0] ?? null;
        if ($set === null) {
            throw new \OutOfBoundsException("The MTGJSON build has no set {$setCode}.");
        }

        $rarities = CardPool::RARITIES;
        $rows = $this->database->select(
            'SELECT "c"."uuid", "c"."name", "c"."number", "c"."rarity", "c"."colors", "c"."manaValue", "c"."type", "c"."boosterTypes", "i"."scryfallId"'
            .' FROM "cards" AS "c" LEFT JOIN "cardIdentifiers" AS "i" ON "i"."uuid" = "c"."uuid"'
            .' WHERE "c"."setCode" = ?'
            .' AND COALESCE("c"."side", \'a\') = \'a\''
            .' AND COALESCE("c"."isPromo", 0) = 0'
            .' AND COALESCE("c"."supertypes", \'\') NOT LIKE \'%Basic%\''
            .' AND "c"."rarity" IN ('.implode(', ', array_fill(0, count($rarities), '?')).')'
            .' ORDER BY CAST("c"."number" AS INTEGER), "c"."number"',
            [$setCode, ...$rarities]
        );
        $rows = array_map(fn (array $row) => $this->database->decode('cards', $row), $rows);

        $inBoosters = array_filter($rows, fn (array $row) => in_array('default', $row['boosterTypes'] ?? [], true));
        if ($inBoosters !== []) {
            $rows = $inBoosters;
        }

        $pool = new CardPool($set['code'], (string) $set['name'], $set['releaseDate'] ?? null, $this->database->getVersion(), time());
        $names = [];
        foreach ($rows as $row) {
            if (isset($names[$row['name']])) {
                continue;
            }
            $names[$row['name']] = true;

            $pool->add([
                'uuid' => $row['uuid'],
                'name' => $row['name'],
                'number' => $row['number'] ?? null,
                'rarity' => $row['rarity'],
                'colors' => $row['colors'] ?? [],
                'manaValue' => (float) ($row['manaValue'] ?? 0),
                'type' => $row['type'] ?? null,
                'scryfallId' => $row['scryfallId'] ?? null,
            ]);
        }

        return $pool;
    }
}
