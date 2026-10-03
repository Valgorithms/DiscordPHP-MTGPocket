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

namespace MTGPocket\Collection;

use MTGPocket\Cards\CardPool;
use MTGPocket\Models\Inventory;
use MTGPocket\Repository\CardPoolRepository;

/**
 * A player's collection as a list to show: their cards with the copies they
 * own, optionally narrowed to a set, a color, a rarity or part of a name,
 * sorted by set, then color, then rarity (rarest first), then name.
 *
 * @since 0.2.0
 */
class CollectionQuery
{
    /**
     * @param CardPoolRepository $pools Where card data is looked up.
     */
    public function __construct(protected CardPoolRepository $pools)
    {
    }

    /**
     * The matching cards.
     *
     * @param Inventory $inventory
     * @param array{set?: ?string, color?: ?string, rarity?: ?string, name?: ?string} $filters
     *
     * @return list<array{card: array, count: int}>
     */
    public function entries(Inventory $inventory, array $filters = []): array
    {
        $set = self::filter($filters['set'] ?? null, true);
        $color = self::filter($filters['color'] ?? null, true);
        $rarity = self::filter($filters['rarity'] ?? null);
        $name = self::filter($filters['name'] ?? null);

        $setOrder = array_flip(array_keys($this->pools->all()));
        $colorOrder = array_flip(CardPool::COLORS);
        $rarityOrder = array_flip(array_reverse(CardPool::RARITIES));

        $entries = [];
        foreach ($inventory->cards as $uuid => $count) {
            $card = $this->pools->card((string) $uuid);
            if ($card === null
                || ($set !== null && $card['setCode'] !== $set)
                || ($color !== null && CardPool::colorOf($card['colors']) !== $color)
                || ($rarity !== null && $card['rarity'] !== $rarity)
                || ($name !== null && ! str_contains(mb_strtolower($card['name']), $name))) {
                continue;
            }
            $entries[] = ['card' => $card, 'count' => $count];
        }

        usort($entries, fn (array $a, array $b) => [
            $setOrder[$a['card']['setCode']] ?? PHP_INT_MAX,
            $colorOrder[CardPool::colorOf($a['card']['colors'])] ?? PHP_INT_MAX,
            $rarityOrder[$a['card']['rarity']] ?? PHP_INT_MAX,
            $a['card']['name'],
        ] <=> [
            $setOrder[$b['card']['setCode']] ?? PHP_INT_MAX,
            $colorOrder[CardPool::colorOf($b['card']['colors'])] ?? PHP_INT_MAX,
            $rarityOrder[$b['card']['rarity']] ?? PHP_INT_MAX,
            $b['card']['name'],
        ]);

        return $entries;
    }

    /**
     * Copies owned by rarity, across the whole collection.
     *
     * @param Inventory $inventory
     *
     * @return array<string, int> Rarity => copies, rarest last.
     */
    public function rarityTotals(Inventory $inventory): array
    {
        $totals = array_fill_keys(CardPool::RARITIES, 0);
        foreach ($inventory->cards as $uuid => $count) {
            if ($card = $this->pools->card((string) $uuid)) {
                $totals[$card['rarity']] = ($totals[$card['rarity']] ?? 0) + $count;
            }
        }

        return $totals;
    }

    /**
     * Cards the player owns whose name contains some text, for
     * autocomplete: uuid => "Name (SET)".
     *
     * @param Inventory $inventory
     * @param string    $typed
     * @param int       $limit
     *
     * @return array<string, string>
     */
    public function suggest(Inventory $inventory, string $typed, int $limit = 25): array
    {
        $choices = [];
        foreach ($this->entries($inventory, ['name' => $typed]) as $entry) {
            $choices[$entry['card']['uuid']] = "{$entry['card']['name']} ({$entry['card']['setCode']}) ×{$entry['count']}";
            if (count($choices) >= $limit) {
                break;
            }
        }

        return $choices;
    }

    /**
     * A filter value, or null when it is empty.
     *
     * @param string|null $value
     * @param bool        $upper Set codes and colors are upper case; the rest lower.
     *
     * @return string|null
     */
    protected static function filter(?string $value, bool $upper = false): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $upper ? strtoupper($value) : mb_strtolower($value);
    }
}
