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

use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Deck;

/**
 * An official preconstructed deck players can borrow for a match without
 * owning its cards. It carries its own card data, so it does not depend
 * on which card pools are imported.
 *
 * @since 0.4.0
 */
final class RentalDeck
{
    /**
     * Deck ids of rentals start with this, so they never clash with a
     * player's own decks.
     */
    public const string PREFIX = 'rental:';

    /**
     * @param string                                 $id          A storage name, e.g. `fdn-starter-kit-cats`.
     * @param string                                 $name
     * @param string                                 $setCode
     * @param string                                 $setName
     * @param string                                 $type        MTGJSON's deck type, e.g. `Starter Kit`.
     * @param string|null                            $releaseDate `YYYY-MM-DD`.
     * @param list<array{count: int, card: array}>   $main
     * @param list<array{count: int, card: array}>   $side
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $setCode,
        public readonly string $setName = '',
        public readonly string $type = '',
        public readonly ?string $releaseDate = null,
        public readonly array $main = [],
        public readonly array $side = [],
    ) {
    }

    /**
     * A storage id from a set code and deck name.
     *
     * @param string $setCode
     * @param string $name
     *
     * @return string
     */
    public static function idFor(string $setCode, string $name): string
    {
        return substr(strtolower($setCode).'-'.trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), 0, 100);
    }

    /**
     * Whether a deck id is a rental's.
     *
     * @param string $deckId
     *
     * @return bool
     */
    public static function isRental(string $deckId): bool
    {
        return str_starts_with($deckId, self::PREFIX);
    }

    public function mainCount(): int
    {
        return array_sum(array_column($this->main, 'count'));
    }

    /**
     * It as a deck for a player, with the cards keyed by uuid.
     *
     * @param string $playerId
     * @param string $format
     *
     * @return Deck
     */
    public function deck(string $playerId, string $format): Deck
    {
        $counts = fn (array $entries) => new CardCounts(array_reduce($entries, function (array $carry, array $entry) {
            $carry[$entry['card']['uuid']] = ($carry[$entry['card']['uuid']] ?? 0) + $entry['count'];

            return $carry;
        }, []));

        return new Deck(self::PREFIX.$this->id, $playerId, "{$this->name} (rental)", $format, $counts($this->main), $counts($this->side));
    }

    /**
     * A card of the deck by uuid. Every card counts as the deck's own set,
     * since an official deck of a set is legal wherever the set is.
     *
     * @param string $uuid
     *
     * @return array
     */
    public function card(string $uuid): array
    {
        foreach ([...$this->main, ...$this->side] as $entry) {
            if ($entry['card']['uuid'] === $uuid) {
                return ['setCode' => $this->setCode, 'setName' => $this->setName] + $entry['card'];
            }
        }

        return ['uuid' => $uuid, 'name' => 'Unknown card', 'rarity' => 'common', 'colors' => [], 'manaValue' => 0.0, 'type' => '', 'setCode' => $this->setCode];
    }

    /**
     * The main deck as card data, one entry per copy.
     *
     * @return array[]
     */
    public function mainCards(): array
    {
        $cards = [];
        foreach ($this->main as $entry) {
            array_push($cards, ...array_fill(0, $entry['count'], $this->card($entry['card']['uuid'])));
        }

        return $cards;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'setCode' => $this->setCode,
            'setName' => $this->setName,
            'type' => $this->type,
            'releaseDate' => $this->releaseDate,
            'main' => $this->main,
            'side' => $this->side,
        ];
    }

    public static function fromArray(array $data): self
    {
        $entries = fn ($list) => array_values(array_map(fn ($entry) => ['count' => (int) $entry['count'], 'card' => (array) $entry['card']], (array) $list));

        return new self(
            (string) $data['id'],
            (string) $data['name'],
            (string) $data['setCode'],
            (string) ($data['setName'] ?? ''),
            (string) ($data['type'] ?? ''),
            isset($data['releaseDate']) ? (string) $data['releaseDate'] : null,
            $entries($data['main'] ?? []),
            $entries($data['side'] ?? []),
        );
    }
}
