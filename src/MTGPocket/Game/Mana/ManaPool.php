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

namespace MTGPocket\Game\Mana;

/**
 * The mana a player has floating (rule 106.4). It empties at the end of
 * every step and phase.
 *
 * @since 0.3.0
 */
final class ManaPool
{
    /**
     * @var array<string, int> Type => amount, for each of {@see ManaCost::TYPES}.
     */
    private array $mana;

    /**
     * @param array<string, int> $mana
     */
    public function __construct(array $mana = [])
    {
        $this->mana = array_fill_keys(ManaCost::TYPES, 0);
        foreach ($mana as $type => $amount) {
            $this->add((string) $type, (int) $amount);
        }
    }

    /**
     * @param string $type   One of {@see ManaCost::TYPES}.
     * @param int    $amount
     *
     * @return void
     */
    public function add(string $type, int $amount = 1): void
    {
        if (! isset($this->mana[$type]) || $amount < 0) {
            throw new \InvalidArgumentException("Cannot add {$amount} {$type} mana.");
        }
        $this->mana[$type] += $amount;
    }

    /**
     * @param string $type
     * @param int    $amount
     *
     * @return void
     */
    public function remove(string $type, int $amount = 1): void
    {
        if (($this->mana[$type] ?? 0) < $amount) {
            throw new \LogicException("The pool has no {$amount} {$type} mana.");
        }
        $this->mana[$type] -= $amount;
    }

    /**
     * @param string $type
     *
     * @return int
     */
    public function get(string $type): int
    {
        return $this->mana[$type] ?? 0;
    }

    /**
     * @return int
     */
    public function total(): int
    {
        return array_sum($this->mana);
    }

    /**
     * @return void
     */
    public function empty(): void
    {
        $this->mana = array_fill_keys(ManaCost::TYPES, 0);
    }

    /**
     * @return array<string, int> Only the types there is some of.
     */
    public function toArray(): array
    {
        return array_filter($this->mana);
    }

    /**
     * The pool as mana symbols, e.g. `{G}{G}{C}`.
     *
     * @return string
     */
    public function __toString(): string
    {
        $text = '';
        foreach ($this->mana as $type => $amount) {
            $text .= str_repeat('{'.$type.'}', $amount);
        }

        return $text;
    }
}
