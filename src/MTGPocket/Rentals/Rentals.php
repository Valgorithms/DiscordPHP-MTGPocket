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

use MTGPocket\Economy\MatchRewards;
use MTGPocket\Models\Player;
use MTGPocket\Modes\GameMode;
use MTGPocket\Modes\GameModes;
use MTGPocket\Repository\PlayerRepository;
use MTGPocket\Repository\RentalRepository;

/**
 * Rental decks: official preconstructed decks of the current sets that any
 * player can play without owning the cards, a few games a day.
 *
 * Which decks are offered follows the library of one mode (Standard by
 * default, see `config/economy.php`): when its sets rotate, so do the
 * rentals. A rental can then be played in any mode whose rules it meets.
 *
 * @since 0.4.0
 */
final class Rentals
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param RentalRepository       $rentals
     * @param PlayerRepository       $players
     * @param GameModes              $modes
     * @param MatchRewards           $rules
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly RentalRepository $rentals,
        private readonly PlayerRepository $players,
        private readonly GameModes $modes,
        public readonly MatchRewards $rules,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn () => time();
    }

    /**
     * The mode whose library decides which rentals are offered.
     *
     * @return GameMode
     */
    public function mode(): GameMode
    {
        return $this->modes->get($this->rules->rentalMode);
    }

    /**
     * The rental decks offered now, newest first.
     *
     * @return RentalDeck[]
     */
    public function available(): array
    {
        return array_values(array_map($this->fill(...), array_filter($this->rentals->all(), fn (RentalDeck $deck) => $this->isOffered($deck))));
    }

    public function isOffered(RentalDeck $deck): bool
    {
        return $this->mode()->allowsSet($deck->setCode, fn () => $deck->releaseDate, ($this->clock)());
    }

    /**
     * An offered rental by id or name.
     *
     * @param string $deck
     *
     * @throws \OutOfBoundsException
     *
     * @return RentalDeck
     */
    public function find(string $deck): RentalDeck
    {
        $deck = trim($deck);
        if (RentalDeck::isRental($deck)) {
            $deck = substr($deck, strlen(RentalDeck::PREFIX));
        }
        $found = $this->rentals->find($deck);
        if ($found === null) {
            foreach ($this->rentals->all() as $candidate) {
                if (strcasecmp($candidate->name, $deck) === 0 || strcasecmp("{$candidate->name} (rental)", $deck) === 0) {
                    $found = $candidate;
                    break;
                }
            }
        }
        if ($found === null) {
            throw new \OutOfBoundsException("There is no rental deck called **{$deck}**. See `/decks rentals`.");
        }
        if (! $this->isOffered($found)) {
            throw new \OutOfBoundsException("**{$found->name}** is from a set that has left {$this->mode()->label}, so it is no longer for rent.");
        }

        return $this->fill($found);
    }

    /**
     * A rental with basic lands added up to the main deck size of the mode
     * rentals are offered for, so a 40-card precon still plays there.
     *
     * @param RentalDeck $deck
     *
     * @return RentalDeck
     */
    private function fill(RentalDeck $deck): RentalDeck
    {
        return $deck->filledTo($this->mode()->mainMin);
    }

    /**
     * Rentals whose name contains some text, for autocomplete.
     *
     * @param string $typed
     *
     * @return array<string, string> Deck id => label.
     */
    public function suggest(string $typed): array
    {
        $choices = [];
        foreach ($this->available() as $deck) {
            if ($typed === '' || stripos($deck->name, trim($typed)) !== false) {
                $choices[RentalDeck::PREFIX.$deck->id] = "{$deck->name} (rental, {$deck->setCode})";
            }
        }

        return $choices;
    }

    /**
     * Makes a rental the deck a player plays with, until they pick one of
     * their own with `/decks use`.
     *
     * @param string $playerId
     * @param string $playerName
     * @param string $deck       Id or name.
     *
     * @return RentalDeck
     */
    public function rent(string $playerId, string $playerName, string $deck): RentalDeck
    {
        $rental = $this->find($deck);
        $this->players->findOrCreate($playerId, $playerName);
        $this->players->modify($playerId, fn (Player $player) => $player->activeRental = $rental->id);

        return $rental;
    }

    /**
     * The rental a player plays with, if any.
     *
     * @param string $playerId
     *
     * @return string|null A deck id with {@see RentalDeck::PREFIX}.
     */
    public function activeRental(string $playerId): ?string
    {
        $id = $this->players->find($playerId)?->activeRental;

        return $id === null ? null : RentalDeck::PREFIX.$id;
    }

    /**
     * Rental games a player has left today.
     *
     * @param string $playerId
     *
     * @return int
     */
    public function gamesLeft(string $playerId): int
    {
        $player = $this->players->find($playerId);
        if ($player === null) {
            return $this->rules->rentalGamesPerDay;
        }
        $player->onDay($this->today());

        return max(0, $this->rules->rentalGamesPerDay - $player->rentalGames);
    }

    /**
     * Counts a game started with a rental.
     *
     * @param string $playerId
     *
     * @return void
     */
    public function countGame(string $playerId): void
    {
        $this->players->findOrCreate($playerId);
        $this->players->modify($playerId, function (Player $player): void {
            $player->onDay($this->today());
            $player->rentalGames++;
        });
    }

    public function today(): string
    {
        return gmdate('Y-m-d', ($this->clock)());
    }
}
