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

/**
 * The cards of one set that packs can hold, sorted the way packs are built:
 * by color, then by rarity. Packs are one set plus one color, so each
 * color of a set is its own pool.
 *
 * Each card appears once, under one color: its own color when it has
 * exactly one, {@see MULTICOLOR} when it has several and {@see COLORLESS}
 * when it has none (artifacts, most lands).
 *
 * @since 0.1.0
 */
class CardPool implements \JsonSerializable
{
    public const string MULTICOLOR = 'M';
    public const string COLORLESS = 'C';

    /**
     * Color buckets, in display order: white, blue, black, red, green,
     * multicolor, colorless.
     *
     * @var string[]
     */
    public const array COLORS = ['W', 'U', 'B', 'R', 'G', self::MULTICOLOR, self::COLORLESS];

    /**
     * Rarities a pool keeps, lowest first.
     *
     * @var string[]
     */
    public const array RARITIES = ['common', 'uncommon', 'rare', 'mythic'];

    /**
     * Card data by uuid: uuid, name, number, rarity, colors, manaValue,
     * type and scryfallId.
     *
     * @var array<string, array>
     */
    protected array $cards = [];

    /**
     * Uuids by color then rarity.
     *
     * @var array<string, array<string, string[]>>
     */
    protected array $index = [];

    /**
     * @param string      $setCode         The MTGJSON set code, e.g. `KTK`.
     * @param string      $setName
     * @param string|null $releaseDate     `YYYY-MM-DD`.
     * @param string|null $mtgjsonVersion  The MTGJSON build the pool was read from.
     * @param int         $importedAt      Unix time.
     */
    public function __construct(
        public readonly string $setCode,
        public readonly string $setName = '',
        public readonly ?string $releaseDate = null,
        public readonly ?string $mtgjsonVersion = null,
        public readonly int $importedAt = 0,
    ) {
        foreach (self::COLORS as $color) {
            $this->index[$color] = array_fill_keys(self::RARITIES, []);
        }
    }

    /**
     * The bucket a card goes in, from its colors.
     *
     * @param string[] $colors Color letters.
     *
     * @return string One of {@see COLORS}.
     */
    public static function colorOf(array $colors): string
    {
        return match (count($colors)) {
            0 => self::COLORLESS,
            1 => in_array($colors[0], self::COLORS, true) ? $colors[0] : self::COLORLESS,
            default => self::MULTICOLOR,
        };
    }

    /**
     * Adds a card. A card with a rarity outside {@see RARITIES} is skipped.
     *
     * @param array $card At least `uuid` and `rarity`; `colors` as a list of letters.
     *
     * @return bool Whether it was added.
     */
    public function add(array $card): bool
    {
        $rarity = $card['rarity'] ?? null;
        if (! isset($card['uuid']) || ! in_array($rarity, self::RARITIES, true) || isset($this->cards[$card['uuid']])) {
            return false;
        }

        $card['colors'] = array_values((array) ($card['colors'] ?? []));
        if (isset($card['manaValue'])) {
            // JSON writes 3.0 as 3; keep the type stable across a save and load.
            $card['manaValue'] = (float) $card['manaValue'];
        }
        $this->cards[$card['uuid']] = $card;
        $this->index[self::colorOf($card['colors'])][$rarity][] = $card['uuid'];

        return true;
    }

    /**
     * A card's data.
     *
     * @param string $uuid
     *
     * @return array|null
     */
    public function card(string $uuid): ?array
    {
        return $this->cards[$uuid] ?? null;
    }

    /**
     * Uuids of the cards of a color, optionally of one rarity.
     *
     * @param string      $color  One of {@see COLORS}.
     * @param string|null $rarity One of {@see RARITIES}, or null for all.
     *
     * @return string[]
     */
    public function uuids(string $color, ?string $rarity = null): array
    {
        if ($rarity !== null) {
            return $this->index[$color][$rarity] ?? [];
        }

        return array_merge(...array_values($this->index[$color] ?? [[]]));
    }

    /**
     * The colors that have at least one card.
     *
     * @return string[]
     */
    public function colors(): array
    {
        return array_values(array_filter(self::COLORS, fn (string $color) => $this->uuids($color) !== []));
    }

    /**
     * Whether a color has at least one rare or mythic rare, which every pack
     * of it must hold.
     *
     * @param string $color
     *
     * @return bool
     */
    public function hasRareSlot(string $color): bool
    {
        return $this->uuids($color, 'rare') !== [] || $this->uuids($color, 'mythic') !== [];
    }

    /**
     * The number of cards in the pool.
     *
     * @return int
     */
    public function size(): int
    {
        return count($this->cards);
    }

    /**
     * @param array $data As stored.
     *
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $pool = new static(
            (string) $data['setCode'],
            (string) ($data['setName'] ?? ''),
            $data['releaseDate'] ?? null,
            $data['mtgjsonVersion'] ?? null,
            (int) ($data['importedAt'] ?? 0),
        );
        foreach ($data['cards'] ?? [] as $card) {
            $pool->add($card);
        }

        return $pool;
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        $counts = [];
        foreach (self::COLORS as $color) {
            $counts[$color] = array_map('count', $this->index[$color]);
        }

        return [
            'setCode' => $this->setCode,
            'setName' => $this->setName,
            'releaseDate' => $this->releaseDate,
            'mtgjsonVersion' => $this->mtgjsonVersion,
            'importedAt' => $this->importedAt,
            'counts' => $counts,
            'cards' => array_values($this->cards),
        ];
    }
}
