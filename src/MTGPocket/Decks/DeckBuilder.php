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

namespace MTGPocket\Decks;

use MTGPocket\Cards\BasicLands;
use MTGPocket\Collection\CollectionQuery;
use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Deck;
use MTGPocket\Modes\GameMode;
use MTGPocket\Models\Player;
use MTGPocket\Repository\CardPoolRepository;
use MTGPocket\Repository\DeckRepository;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\PlayerRepository;

/**
 * Builds and edits a player's decks and side decks from the cards they own.
 *
 * A deck may use each card the player owns as many times as they own it,
 * main and side deck together; the same cards can go in any number of
 * decks. Basic lands are free and unlimited ({@see BasicLands}).
 *
 * Which cards a format allows, and the deck sizes it needs, are checked
 * when a match starts, not here.
 *
 * Mistakes the player can fix (no such deck, not enough copies, …) are
 * thrown as {@see \InvalidArgumentException} or {@see \OutOfBoundsException}
 * with a message to show them.
 *
 * @since 0.2.0
 */
class DeckBuilder
{
    /**
     * Formats a deck can be built for: id => label.
     *
     * @var array<string, string>
     */
    public const array FORMATS = [
        'standard' => 'Standard',
        'commander' => 'Commander',
        'limited' => 'Limited',
        'casual' => 'Casual',
    ];

    /**
     * Decks a player can have at once.
     */
    public const int MAX_DECKS = 25;

    /**
     * Longest deck name.
     */
    public const int MAX_NAME = 50;

    public function __construct(
        protected DeckRepository $decks,
        protected InventoryRepository $inventories,
        protected PlayerRepository $players,
        protected CardPoolRepository $pools,
    ) {
    }

    /**
     * A player's decks, by name.
     *
     * @param string $playerId
     *
     * @return array<string, Deck> By id.
     */
    public function list(string $playerId): array
    {
        $decks = $this->decks->forPlayer($playerId);
        uasort($decks, fn (Deck $a, Deck $b) => strcasecmp($a->name, $b->name));

        return $decks;
    }

    /**
     * The copies of each card the player's decks need: for each card, the
     * most any one deck uses (main and side deck together), since the same
     * cards may go in many decks. Selling or trading cards away never takes
     * these. Basic lands are left out.
     *
     * @param string $playerId
     *
     * @return array<string, array{count: int, deck: string}> Uuid => copies and the name of the deck that needs the most.
     */
    public function copiesInUse(string $playerId): array
    {
        $used = [];
        foreach ($this->decks->forPlayer($playerId) as $deck) {
            foreach ($deck->allCards() as $key => $count) {
                $key = (string) $key;
                if (! BasicLands::isBasic($key) && $count > ($used[$key]['count'] ?? 0)) {
                    $used[$key] = ['count' => $count, 'deck' => $deck->name];
                }
            }
        }

        return $used;
    }

    /**
     * Starts an empty deck. A player's first deck becomes their active one.
     *
     * @param string $playerId
     * @param string $playerName
     * @param string $name
     * @param string $format     One of {@see FORMATS}.
     *
     * @throws \InvalidArgumentException
     *
     * @return Deck
     */
    public function create(string $playerId, string $playerName, string $name, string $format = 'standard'): Deck
    {
        $name = self::cleanName($name);
        $format = self::checkFormat($format);

        // Checked under the deck file's lock, so two creates at once cannot both pass.
        $deck = $this->decks->create($playerId, $name, $format, function (array $decks) use ($name): void {
            if (count($decks) >= self::MAX_DECKS) {
                throw new \InvalidArgumentException('You already have '.self::MAX_DECKS.' decks. Delete one first.');
            }
            self::checkUnique($decks, $name);
        });

        $this->players->findOrCreate($playerId, $playerName);
        $this->players->modify($playerId, function (Player $player) use ($playerId, $deck): void {
            if ($player->activeDeckId === null || ! $this->decks->find($playerId, $player->activeDeckId)) {
                $player->activeDeckId = $deck->id;
            }
        });

        return $deck;
    }

    /**
     * Finds one of a player's decks by id (as autocomplete gives it) or name.
     *
     * @param string $playerId
     * @param string $deck     Id or name.
     *
     * @throws \OutOfBoundsException
     *
     * @return Deck
     */
    public function find(string $playerId, string $deck): Deck
    {
        $deck = trim($deck);
        $decks = $this->decks->forPlayer($playerId);
        if (isset($decks[$deck])) {
            return $decks[$deck];
        }
        foreach ($decks as $candidate) {
            if (strcasecmp($candidate->name, $deck) === 0) {
                return $candidate;
            }
        }

        throw new \OutOfBoundsException("You have no deck called **{$deck}**. See `/decks list`.");
    }

    /**
     * Adds copies of a card to a deck.
     *
     * @param string $playerId
     * @param string $deck     Id or name.
     * @param string $card     A printing uuid, as autocomplete gives it, or a card name.
     * @param int    $count
     * @param bool   $side     To the side deck instead of the main deck.
     *
     * @throws \InvalidArgumentException When the player does not own enough copies.
     * @throws \OutOfBoundsException     When there is no such deck or card.
     *
     * @return array{0: Deck, 1: array} The deck as saved and the card added.
     */
    public function add(string $playerId, string $deck, string $card, int $count = 1, bool $side = false): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Add at least one card.');
        }
        $deck = $this->find($playerId, $deck);
        [$key, $data] = $this->resolveCard($playerId, $card, $deck);

        $saved = $this->decks->modify($playerId, $deck->id, function (Deck $deck) use ($playerId, $key, $data, $count, $side): void {
            if (! BasicLands::isBasic($key)) {
                $owned = $this->inventories->get($playerId)->cards->get($key);
                $used = $deck->allCards()->get($key);
                if ($used + $count > $owned) {
                    throw new \InvalidArgumentException(sprintf(
                        'You own %d %s of **%s**, and this deck already uses %d.',
                        $owned,
                        $owned === 1 ? 'copy' : 'copies',
                        $data['name'],
                        $used,
                    ));
                }
            }
            ($side ? $deck->side : $deck->main)->add($key, $count);
        });

        return [$saved, $data];
    }

    /**
     * Takes copies of a card out of a deck.
     *
     * @param string   $playerId
     * @param string   $deck     Id or name.
     * @param string   $card     A key in the deck, a printing uuid or a card name.
     * @param int|null $count    Null for every copy.
     * @param bool     $side     From the side deck instead of the main deck.
     *
     * @throws \InvalidArgumentException When the deck has fewer copies.
     * @throws \OutOfBoundsException     When there is no such deck or card.
     *
     * @return array{0: Deck, 1: array} The deck as saved and the card removed.
     */
    public function remove(string $playerId, string $deck, string $card, ?int $count = null, bool $side = false): array
    {
        $deck = $this->find($playerId, $deck);
        $part = $side ? 'side deck' : 'main deck';
        $key = $this->keyInDeck(($side ? $deck->side : $deck->main), $card);
        if ($key === null) {
            throw new \OutOfBoundsException("The {$part} of **{$deck->name}** has no **{$card}**.");
        }
        $data = $this->cardData($key);

        $saved = $this->decks->modify($playerId, $deck->id, function (Deck $deck) use ($key, $count, $side, $part, $data): void {
            $cards = $side ? $deck->side : $deck->main;
            $count ??= $cards->get($key);
            if ($count < 1 || ! $cards->remove($key, $count)) {
                throw new \InvalidArgumentException("The {$part} has only {$cards->get($key)} **{$data['name']}**.");
            }
        });

        return [$saved, $data];
    }

    /**
     * Renames a deck.
     *
     * @param string $playerId
     * @param string $deck
     * @param string $name
     *
     * @return Deck
     */
    public function rename(string $playerId, string $deck, string $name): Deck
    {
        $deck = $this->find($playerId, $deck);
        $name = self::cleanName($name);

        return $this->decks->modify($playerId, $deck->id, function (Deck $deck, array $decks) use ($name): void {
            self::checkUnique($decks, $name, $deck->id);
            $deck->name = $name;
        });
    }

    /**
     * Changes the format a deck is built for.
     *
     * @param string $playerId
     * @param string $deck
     * @param string $format
     *
     * @return Deck
     */
    public function setFormat(string $playerId, string $deck, string $format): Deck
    {
        $deck = $this->find($playerId, $deck);
        $format = self::checkFormat($format);

        return $this->decks->modify($playerId, $deck->id, fn (Deck $deck) => $deck->format = $format);
    }

    /**
     * Makes a legendary creature the player owns a deck's commander, or
     * takes the commander out.
     *
     * @param string      $playerId
     * @param string      $deck
     * @param string|null $card     Uuid or name; null to take it out.
     *
     * @return array{0: Deck, 1: array|null} The deck as saved and the commander's data.
     */
    public function setCommander(string $playerId, string $deck, ?string $card): array
    {
        $deck = $this->find($playerId, $deck);
        if ($card === null || trim($card) === '') {
            return [$this->decks->modify($playerId, $deck->id, fn (Deck $deck) => $deck->commander = null), null];
        }
        [$key, $data] = $this->resolveCard($playerId, $card, $deck);
        if (! GameMode::canBeCommander($data)) {
            throw new \InvalidArgumentException("**{$data['name']}** cannot be a commander: it is not a legendary creature.");
        }

        $saved = $this->decks->modify($playerId, $deck->id, function (Deck $deck) use ($playerId, $key, $data): void {
            $owned = $this->inventories->get($playerId)->cards->get($key);
            $used = $deck->allCards()->get($key) - ($deck->commander === $key ? 1 : 0);
            if ($used + 1 > $owned) {
                throw new \InvalidArgumentException("You own {$owned} of **{$data['name']}**, and this deck already uses {$used}.");
            }
            $deck->commander = $key;
        });

        return [$saved, $data];
    }

    /**
     * Deletes a deck. When it was the active deck, none is active after.
     *
     * @param string $playerId
     * @param string $deck
     *
     * @return Deck The deleted deck.
     */
    public function delete(string $playerId, string $deck): Deck
    {
        $deck = $this->find($playerId, $deck);
        $this->decks->delete($playerId, $deck->id);
        if ($this->players->find($playerId) !== null) {
            $this->players->modify($playerId, function (Player $player) use ($deck): void {
                if ($player->activeDeckId === $deck->id) {
                    $player->activeDeckId = null;
                }
            });
        }

        return $deck;
    }

    /**
     * Makes a deck the one the player plays with.
     *
     * @param string $playerId
     * @param string $playerName
     * @param string $deck
     *
     * @return Deck
     */
    public function activate(string $playerId, string $playerName, string $deck): Deck
    {
        $deck = $this->find($playerId, $deck);
        $this->players->findOrCreate($playerId, $playerName);
        $this->players->modify($playerId, function (Player $player) use ($playerId, $deck): void {
            // Checked under the player's lock; delete clears the active deck under the same lock after removing the deck.
            if ($this->decks->find($playerId, $deck->id) === null) {
                throw new \OutOfBoundsException("**{$deck->name}** was just deleted.");
            }
            $player->activeDeckId = $deck->id;
            $player->activeRental = null;
        });

        return $deck;
    }

    /**
     * The player's active deck id, if any.
     *
     * @param string $playerId
     *
     * @return string|null
     */
    public function activeDeckId(string $playerId): ?string
    {
        return $this->players->find($playerId)?->activeDeckId;
    }

    /**
     * A deck card's data: a pool card or a basic land. A card no imported
     * pool knows any more keeps a placeholder.
     *
     * @param string $key
     *
     * @return array
     */
    public function cardData(string $key): array
    {
        return BasicLands::card($key)
            ?? $this->pools->card($key)
            ?? ['uuid' => $key, 'name' => 'Unknown card', 'rarity' => 'common', 'colors' => [], 'manaValue' => 0.0, 'type' => '', 'scryfallId' => null];
    }

    /**
     * Cards that can go in a deck, for autocomplete: basic lands and the
     * cards the player owns.
     *
     * @param string $playerId
     * @param string $typed
     *
     * @return array<string, string> Key => label.
     */
    public function suggestCards(string $playerId, string $typed): array
    {
        $choices = [];
        foreach (array_keys(BasicLands::NAMES) as $basic) {
            if ($typed !== '' && stripos($basic, trim($typed)) === 0) {
                $choices[BasicLands::PREFIX.$basic] = "{$basic} (basic land)";
            }
        }

        return $choices + (new CollectionQuery($this->pools))->suggest($this->inventories->get($playerId), $typed, 25 - count($choices));
    }

    /**
     * The deck key and data of a card the player names.
     *
     * @param string $playerId
     * @param string $card     Uuid, basic land key or name.
     * @param Deck   $deck     For preferring printings with copies to spare.
     *
     * @throws \OutOfBoundsException
     *
     * @return array{0: string, 1: array}
     */
    protected function resolveCard(string $playerId, string $card, Deck $deck): array
    {
        $card = trim($card);
        if ($basic = BasicLands::key($card)) {
            return [$basic, BasicLands::card($basic)];
        }

        $inventory = $this->inventories->get($playerId);
        if ($inventory->cards->get($card) > 0 && ($data = $this->pools->card($card))) {
            return [$card, $data];
        }

        // A name: the owned printing with the most copies not yet in this deck.
        $best = null;
        $spare = PHP_INT_MIN;
        foreach ($inventory->cards as $uuid => $count) {
            $data = $this->pools->card((string) $uuid);
            if ($data !== null && strcasecmp($data['name'], $card) === 0) {
                $left = $count - $deck->allCards()->get((string) $uuid);
                if ($left > $spare) {
                    [$best, $spare] = [[(string) $uuid, $data], $left];
                }
            }
        }

        return $best ?? throw new \OutOfBoundsException("You don't own **{$card}**. See `/collection`.");
    }

    /**
     * Finds a card in part of a deck by key or name.
     *
     * @param CardCounts $cards
     * @param string     $card
     *
     * @return string|null
     */
    protected function keyInDeck(CardCounts $cards, string $card): ?string
    {
        $card = trim($card);
        $basic = BasicLands::key($card);
        foreach ($cards as $key => $count) {
            $key = (string) $key;
            if ($key === $card || $key === $basic || strcasecmp($this->cardData($key)['name'], $card) === 0) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A deck name with its spaces tidied.
     *
     * @param string $name
     *
     * @throws \InvalidArgumentException When it is empty or too long.
     *
     * @return string
     */
    protected static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException('A deck name needs 1 to '.self::MAX_NAME.' characters.');
        }

        return $name;
    }

    /**
     * Refuses a name another of the player's decks already has.
     *
     * @param array<string, Deck> $decks
     * @param string              $name
     * @param string|null         $except The deck being renamed.
     *
     * @throws \InvalidArgumentException
     */
    protected static function checkUnique(array $decks, string $name, ?string $except = null): void
    {
        foreach ($decks as $deck) {
            if ($deck->id !== $except && strcasecmp($deck->name, $name) === 0) {
                throw new \InvalidArgumentException("You already have a deck called **{$deck->name}**.");
            }
        }
    }

    /**
     * @param string $format
     *
     * @throws \InvalidArgumentException
     *
     * @return string
     */
    protected static function checkFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (! isset(self::FORMATS[$format])) {
            throw new \InvalidArgumentException('The format must be one of '.implode(', ', self::FORMATS).'.');
        }

        return $format;
    }
}
