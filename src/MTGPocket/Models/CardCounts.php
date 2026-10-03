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
 * A multiset of card printings: MTGJSON uuid => how many copies.
 * Used for a player's collection and for a deck's main and side decks.
 * Counts are always positive; a card whose count reaches zero is removed.
 *
 * @since 0.1.0
 */
class CardCounts implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * @var array<string, int>
     */
    protected array $counts = [];

    /**
     * @param array<string, int> $counts
     */
    public function __construct(array $counts = [])
    {
        foreach ($counts as $uuid => $count) {
            $this->add((string) $uuid, (int) $count);
        }
    }

    /**
     * Adds copies of a card.
     *
     * @param string $uuid
     * @param int    $count
     *
     * @throws \InvalidArgumentException When `$count` is negative.
     *
     * @return static
     */
    public function add(string $uuid, int $count = 1): static
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Cannot add a negative number of cards.');
        }
        if ($uuid === '') {
            throw new \InvalidArgumentException('A card needs a uuid.');
        }
        if ($count > 0) {
            $this->counts[$uuid] = ($this->counts[$uuid] ?? 0) + $count;
        }

        return $this;
    }

    /**
     * Adds every card in `$other`.
     *
     * @param CardCounts $other
     *
     * @return static
     */
    public function merge(CardCounts $other): static
    {
        foreach ($other as $uuid => $count) {
            $this->add((string) $uuid, $count);
        }

        return $this;
    }

    /**
     * Removes copies of a card, if there are enough of them.
     *
     * @param string $uuid
     * @param int    $count
     *
     * @return bool False, and nothing removed, when there are fewer than `$count`.
     */
    public function remove(string $uuid, int $count = 1): bool
    {
        if ($count < 0 || $this->get($uuid) < $count) {
            return false;
        }

        $this->counts[$uuid] -= $count;
        if ($this->counts[$uuid] === 0) {
            unset($this->counts[$uuid]);
        }

        return true;
    }

    /**
     * How many copies of a card there are.
     *
     * @param string $uuid
     *
     * @return int
     */
    public function get(string $uuid): int
    {
        return $this->counts[$uuid] ?? 0;
    }

    /**
     * Whether every card in `$other` is here at least as many times.
     *
     * @param CardCounts $other
     *
     * @return bool
     */
    public function contains(CardCounts $other): bool
    {
        foreach ($other as $uuid => $count) {
            if ($this->get((string) $uuid) < $count) {
                return false;
            }
        }

        return true;
    }

    /**
     * The total number of cards, counting copies.
     *
     * @return int
     */
    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * The number of distinct printings.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->counts);
    }

    /**
     * @return \ArrayIterator<string, int>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->counts);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->counts;
    }

    /**
     * Encodes as a JSON object even when empty.
     *
     * @return object
     */
    public function jsonSerialize(): object
    {
        return (object) $this->counts;
    }
}
