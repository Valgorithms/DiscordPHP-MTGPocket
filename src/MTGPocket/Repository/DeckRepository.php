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

namespace MTGPocket\Repository;

use MTGPocket\Models\Deck;
use MTGPocket\Storage\JsonStore;

/**
 * Player decks. All of a player's decks share one JSON file,
 * `decks/{discordUserId}.json`, keyed by deck id, so listing them is one read.
 *
 * @since 0.1.0
 */
class DeckRepository
{
    public const COLLECTION = 'decks';

    public function __construct(protected JsonStore $store)
    {
    }

    /**
     * A player's decks, by id.
     *
     * @param string $playerId
     *
     * @return array<string, Deck>
     */
    public function forPlayer(string $playerId): array
    {
        $decks = [];
        foreach ($this->store->get(self::COLLECTION, $playerId)['decks'] ?? [] as $id => $data) {
            $decks[(string) $id] = Deck::fromArray($data);
        }

        return $decks;
    }

    /**
     * @param string $playerId
     * @param string $deckId
     *
     * @return Deck|null
     */
    public function find(string $playerId, string $deckId): ?Deck
    {
        return $this->forPlayer($playerId)[$deckId] ?? null;
    }

    /**
     * Starts a new empty deck with an id that is free among the player's
     * decks.
     *
     * @param string $playerId
     * @param string $name
     * @param string $format
     *
     * @return Deck
     */
    public function create(string $playerId, string $name, string $format = 'standard'): Deck
    {
        $deck = null;
        $this->store->update(self::COLLECTION, $playerId, function (?array $data) use ($playerId, $name, $format, &$deck): array {
            $data ??= ['playerId' => $playerId, 'decks' => []];
            do {
                $id = 'd'.bin2hex(random_bytes(4));
            } while (isset($data['decks'][$id]));

            $deck = new Deck($id, $playerId, $name, $format);
            $data['decks'][$id] = self::encode($deck);

            return $data;
        });

        return $deck;
    }

    /**
     * Saves a deck, adding it or replacing the old version.
     *
     * @param Deck $deck
     */
    public function save(Deck $deck): void
    {
        $deck->updatedAt = time();
        $this->store->update(self::COLLECTION, $deck->playerId, function (?array $data) use ($deck): array {
            $data ??= ['playerId' => $deck->playerId, 'decks' => []];
            $data['decks'][$deck->id] = self::encode($deck);

            return $data;
        });
    }

    /**
     * Changes a deck under a lock, so two edits to it never lose each other.
     *
     * @param string               $playerId
     * @param string               $deckId
     * @param callable(Deck): void $change
     *
     * @throws \OutOfBoundsException When there is no such deck.
     *
     * @return Deck The deck as saved.
     */
    public function modify(string $playerId, string $deckId, callable $change): Deck
    {
        $saved = null;
        $this->store->update(self::COLLECTION, $playerId, function (?array $data) use ($deckId, $change, &$saved): array {
            if (! isset($data['decks'][$deckId])) {
                throw new \OutOfBoundsException("No deck {$deckId}.");
            }
            $deck = Deck::fromArray($data['decks'][$deckId]);
            $change($deck);
            $deck->updatedAt = time();
            $data['decks'][$deckId] = self::encode($deck);
            $saved = $deck;

            return $data;
        });

        return $saved;
    }

    /**
     * @param string $playerId
     * @param string $deckId
     *
     * @return bool Whether there was such a deck.
     */
    public function delete(string $playerId, string $deckId): bool
    {
        $found = false;
        $this->store->update(self::COLLECTION, $playerId, function (?array $data) use ($deckId, &$found): ?array {
            if ($data === null) {
                return null;
            }
            $found = isset($data['decks'][$deckId]);
            unset($data['decks'][$deckId]);

            return $data;
        });

        return $found;
    }

    /**
     * @param Deck $deck
     *
     * @return array
     */
    protected static function encode(Deck $deck): array
    {
        return json_decode(json_encode($deck, JSON_THROW_ON_ERROR), true);
    }
}
