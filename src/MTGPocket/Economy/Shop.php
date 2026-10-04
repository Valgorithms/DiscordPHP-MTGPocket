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

namespace MTGPocket\Economy;

use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Models\Inventory;
use MTGPocket\Models\Player;
use MTGPocket\Packs\DailyPacks;
use MTGPocket\Packs\OpenedPack;
use MTGPocket\Packs\PackGenerator;
use MTGPocket\Repository\CardPoolRepository;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\PlayerRepository;

/**
 * The points shop: players sell cards they don't want for points, and spend
 * points on single cards or on packs of one color of one set.
 *
 * Prices come from a {@see PriceList}. Cards a player's decks use are never
 * sold: a sale only takes copies beyond what any one deck needs.
 *
 * Every purchase and sale holds the player's lock and then their
 * inventory's (the order daily packs use), and writes the points only after
 * the cards, so a failure never takes points without cards or cards without
 * points.
 *
 * @since 0.4.0
 */
class Shop
{
    /**
     * Most copies bought or sold in one go.
     */
    public const int MAX_COUNT = 99;

    /**
     * The current Unix time.
     *
     * @var \Closure(): int
     */
    protected \Closure $clock;

    /**
     * @param PlayerRepository       $players
     * @param InventoryRepository    $inventories
     * @param CardPoolRepository     $pools
     * @param DeckBuilder            $decks
     * @param DailyPacks             $packs       For picking a pack's set and color.
     * @param PackGenerator          $generator
     * @param PriceList              $prices
     * @param (\Closure(): int)|null $clock       Defaults to {@see time()}.
     */
    public function __construct(
        protected PlayerRepository $players,
        protected InventoryRepository $inventories,
        protected CardPoolRepository $pools,
        protected DeckBuilder $decks,
        protected DailyPacks $packs,
        protected PackGenerator $generator,
        public readonly PriceList $prices,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * A player's points.
     *
     * @param string $playerId
     *
     * @return int
     */
    public function balance(string $playerId): int
    {
        return $this->players->find($playerId)?->points ?? 0;
    }

    /**
     * What a card costs to buy and pays to sell.
     *
     * @param string $card A printing uuid or a card name.
     *
     * @throws \OutOfBoundsException When no imported set has it.
     *
     * @return array{card: array, buy: int, sell: int}
     */
    public function quote(string $card): array
    {
        $data = $this->findCard($card);

        return ['card' => $data, 'buy' => $this->buyPrice($data), 'sell' => $this->sellPrice($data)];
    }

    /**
     * What a card costs to buy now.
     *
     * @param array $card Card data with its `setCode`.
     *
     * @return int
     */
    public function buyPrice(array $card): int
    {
        return $this->prices->buyPrice($card['rarity'], $this->releaseDate($card), ($this->clock)());
    }

    /**
     * What selling a card pays now.
     *
     * @param array $card Card data with its `setCode`.
     *
     * @return int
     */
    public function sellPrice(array $card): int
    {
        return $this->prices->sellPrice($card['rarity'], $this->releaseDate($card), ($this->clock)());
    }

    /**
     * What a pack of each set costs, newest set first.
     *
     * @return array<string, array{name: string, price: int, colors: string[]}> By set code.
     */
    public function packPrices(): array
    {
        $prices = [];
        foreach (array_reverse($this->packs->choices(), true) as $code => $choice) {
            $prices[$code] = $choice + ['price' => $this->prices->packPrice($this->pools->find((string) $code)?->releaseDate, ($this->clock)())];
        }

        return $prices;
    }

    /**
     * Sells copies of a card the player owns.
     *
     * @param string $playerId
     * @param string $name     Their display name.
     * @param string $card     A printing uuid, as autocomplete gives it, or a card name.
     * @param int    $count
     *
     * @throws \InvalidArgumentException When they have too few copies to spare.
     * @throws \OutOfBoundsException     When they don't own the card.
     *
     * @return Receipt
     */
    public function sell(string $playerId, string $name, string $card, int $count = 1): Receipt
    {
        self::checkCount($count);
        $uuid = $this->ownedCard($playerId, $card);

        return $this->sellCards($playerId, $name, function (Inventory $inventory, array $inUse) use ($uuid, $count): array {
            $owned = $inventory->cards->get($uuid);
            $data = $this->pools->card($uuid);
            $needed = $inUse[$uuid]['count'] ?? 0;
            if ($owned - $needed < $count) {
                throw new \InvalidArgumentException($needed === 0
                    ? "You own {$owned} **{$data['name']}**."
                    : sprintf(
                        'You can sell %d of your %d **%s**: your deck **%s** uses %d. Take %s out with `/decks remove` first.',
                        max(0, $owned - $needed),
                        $owned,
                        $data['name'],
                        $inUse[$uuid]['deck'],
                        $needed,
                        $needed === 1 ? 'it' : 'them',
                    ));
            }

            return [$uuid => $count];
        });
    }

    /**
     * The cards {@see sellExtras()} would sell: every copy beyond `$keep`
     * and beyond what the player's decks use.
     *
     * @param string      $playerId
     * @param int         $keep     Copies of each card to keep.
     * @param string|null $rarity   Only this rarity.
     * @param string|null $set      Only this set code.
     *
     * @return array{cards: array<string, int>, points: int}
     */
    public function extras(string $playerId, int $keep = 4, ?string $rarity = null, ?string $set = null): array
    {
        $cards = $this->spareCopies($this->inventories->get($playerId), $this->decks->copiesInUse($playerId), $keep, $rarity, $set);

        $points = 0;
        foreach ($cards as $uuid => $count) {
            $points += $count * $this->sellPrice($this->pools->card($uuid));
        }

        return ['cards' => $cards, 'points' => $points];
    }

    /**
     * Sells every copy beyond `$keep` and beyond what the player's decks
     * use, worked out again at the moment of the sale.
     *
     * @param string      $playerId
     * @param string      $name
     * @param int         $keep
     * @param string|null $rarity
     * @param string|null $set
     *
     * @throws \InvalidArgumentException When there is nothing to sell.
     *
     * @return Receipt
     */
    public function sellExtras(string $playerId, string $name, int $keep = 4, ?string $rarity = null, ?string $set = null): Receipt
    {
        return $this->sellCards($playerId, $name, function (Inventory $inventory, array $inUse) use ($keep, $rarity, $set): array {
            $cards = $this->spareCopies($inventory, $inUse, $keep, $rarity, $set);
            if ($cards === []) {
                throw new \InvalidArgumentException("You have no cards to sell beyond {$keep} of each and what your decks use.");
            }

            return $cards;
        });
    }

    /**
     * Buys copies of a card from any imported set.
     *
     * @param string $playerId
     * @param string $name
     * @param string $card     A printing uuid or a card name (its newest printing).
     * @param int    $count
     *
     * @throws \InvalidArgumentException When they cannot afford it.
     * @throws \OutOfBoundsException     When no imported set has the card.
     *
     * @return Receipt
     */
    public function buyCard(string $playerId, string $name, string $card, int $count = 1): Receipt
    {
        self::checkCount($count);
        $data = $this->findCard($card);
        $price = $this->buyPrice($data) * $count;

        $this->players->findOrCreate($playerId, $name);
        $player = $this->players->modify($playerId, function (Player $player) use ($playerId, $data, $count, $price): void {
            self::checkFunds($player, $price, $count === 1 ? "**{$data['name']}**" : "{$count} **{$data['name']}**");
            $this->inventories->addCards($playerId, [$data['uuid'] => $count]);
            $player->points -= $price;
        });

        return new Receipt([['card' => $data, 'count' => $count, 'points' => $price]], $price, $player->points);
    }

    /**
     * Buys a pack: 15 cards of one color of one set, built like a free pack.
     *
     * @param string      $playerId
     * @param string      $name
     * @param string|null $set      A set code or name; null for any.
     * @param string|null $color    One of {@see \MTGPocket\Cards\CardPool::COLORS}; null for any.
     *
     * @throws \InvalidArgumentException When there is no such pack or they cannot afford it.
     *
     * @return OpenedPack
     */
    public function buyPack(string $playerId, string $name, ?string $set = null, ?string $color = null): OpenedPack
    {
        [$pool, $color] = $this->packs->pick($set, $color);
        $price = $this->prices->packPrice($pool->releaseDate, ($this->clock)());
        $pack = $this->generator->generate($pool, $color);

        $new = [];
        $this->players->findOrCreate($playerId, $name);
        $player = $this->players->modify($playerId, function (Player $player) use ($playerId, $pack, $price, &$new): void {
            self::checkFunds($player, $price, "A {$pack->setName} pack");
            $this->inventories->modify($playerId, function (Inventory $inventory) use ($pack, &$new): void {
                foreach ($pack->counts() as $uuid => $count) {
                    if ($inventory->cards->get($uuid) === 0) {
                        $new[$uuid] = true;
                    }
                    $inventory->cards->add($uuid, $count);
                }
            });
            $player->points -= $price;
        });

        return new OpenedPack($pack, $new, null, $price, $player->points);
    }

    /**
     * Card data for a uuid or a name from any imported set. A name finds its
     * newest printing.
     *
     * @param string $card
     *
     * @throws \OutOfBoundsException
     *
     * @return array With `setCode` and `setName`.
     */
    public function findCard(string $card): array
    {
        $card = trim($card);
        if ($data = $this->pools->card($card)) {
            return $data;
        }
        foreach (array_reverse($this->pools->all(), true) as $pool) {
            foreach ($pool->cards() as $data) {
                if (strcasecmp($data['name'], $card) === 0) {
                    return $data + ['setCode' => $pool->setCode, 'setName' => $pool->setName];
                }
            }
        }

        throw new \OutOfBoundsException("No imported set has a card called **{$card}**. Pick one from the list as you type.");
    }

    /**
     * Autocomplete for cards anyone can buy: uuid => "Name (SET) · price".
     *
     * @param string $typed
     *
     * @return array<string, string>
     */
    public function suggest(string $typed): array
    {
        $choices = [];
        foreach ($this->pools->search($typed, 25) as $card) {
            $choices[$card['uuid']] = "{$card['name']} ({$card['setCode']}) · ".ucfirst($card['rarity']).' · '.number_format($this->buyPrice($card)).' points';
        }

        return $choices;
    }

    /**
     * Takes cards out of an inventory and pays for them, under the player's
     * lock and then their inventory's.
     *
     * @param string                                                         $playerId
     * @param string                                                         $name
     * @param callable(Inventory, array<string, array{count: int, deck: string}>): array<string, int> $pick The cards to sell; it may throw to sell nothing.
     *
     * @return Receipt
     */
    protected function sellCards(string $playerId, string $name, callable $pick): Receipt
    {
        $lines = [];
        $total = 0;
        $this->players->findOrCreate($playerId, $name);
        $player = $this->players->modify($playerId, function (Player $player) use ($playerId, $pick, &$lines, &$total): void {
            $inUse = $this->decks->copiesInUse($playerId);
            $this->inventories->modify($playerId, function (Inventory $inventory) use ($pick, $inUse, &$lines, &$total): void {
                foreach ($pick($inventory, $inUse) as $uuid => $count) {
                    $data = $this->pools->card((string) $uuid) ?? throw new \OutOfBoundsException('That card is no longer in any imported set.');
                    if (! $inventory->cards->remove((string) $uuid, $count)) {
                        throw new \InvalidArgumentException("You don't have {$count} **{$data['name']}** to sell.");
                    }
                    $points = $count * $this->sellPrice($data);
                    $lines[] = ['card' => $data, 'count' => $count, 'points' => $points];
                    $total += $points;
                }
            });
            $player->points += $total;
        });

        return new Receipt($lines, $total, $player->points);
    }

    /**
     * Copies of each card beyond `$keep` and beyond what decks use.
     *
     * @param Inventory                                          $inventory
     * @param array<string, array{count: int, deck: string}>     $inUse
     * @param int                                                $keep
     * @param string|null                                        $rarity
     * @param string|null                                        $set
     *
     * @return array<string, int>
     */
    protected function spareCopies(Inventory $inventory, array $inUse, int $keep, ?string $rarity, ?string $set): array
    {
        $keep = max(0, $keep);
        $rarity = ($rarity === null || $rarity === '') ? null : strtolower($rarity);
        $set = ($set === null || trim($set) === '') ? null : strtoupper(trim($set));

        $cards = [];
        foreach ($inventory->cards as $uuid => $owned) {
            $data = $this->pools->card((string) $uuid);
            if ($data === null || ($rarity !== null && $data['rarity'] !== $rarity) || ($set !== null && $data['setCode'] !== $set)) {
                continue;
            }
            $spare = $owned - max($keep, $inUse[$uuid]['count'] ?? 0);
            if ($spare > 0) {
                $cards[(string) $uuid] = $spare;
            }
        }

        return $cards;
    }

    /**
     * The printing of a card the player owns: the uuid, or for a name, the
     * printing with the most copies their decks don't use.
     *
     * @param string $playerId
     * @param string $card
     *
     * @throws \OutOfBoundsException
     *
     * @return string
     */
    protected function ownedCard(string $playerId, string $card): string
    {
        $card = trim($card);
        $inventory = $this->inventories->get($playerId);
        if ($inventory->cards->get($card) > 0) {
            return $card;
        }

        $inUse = $this->decks->copiesInUse($playerId);
        $best = null;
        $spare = PHP_INT_MIN;
        foreach ($inventory->cards as $uuid => $count) {
            $data = $this->pools->card((string) $uuid);
            if ($data !== null && strcasecmp($data['name'], $card) === 0 && $count - ($inUse[$uuid]['count'] ?? 0) > $spare) {
                [$best, $spare] = [(string) $uuid, $count - ($inUse[$uuid]['count'] ?? 0)];
            }
        }

        $name = $this->pools->card($card)['name'] ?? $card;

        return $best ?? throw new \OutOfBoundsException("You don't own **{$name}**. See `/collection`.");
    }

    /**
     * A card's set's release date.
     *
     * @param array $card
     *
     * @return string|null
     */
    protected function releaseDate(array $card): ?string
    {
        return isset($card['setCode']) ? $this->pools->find($card['setCode'])?->releaseDate : null;
    }

    /**
     * @param int $count
     *
     * @throws \InvalidArgumentException
     */
    protected static function checkCount(int $count): void
    {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new \InvalidArgumentException('Pick between 1 and '.self::MAX_COUNT.' copies.');
        }
    }

    /**
     * @param Player $player
     * @param int    $price
     * @param string $what   What is being bought, for the message.
     *
     * @throws \InvalidArgumentException When they have too few points.
     */
    protected static function checkFunds(Player $player, int $price, string $what): void
    {
        if ($player->points < $price) {
            throw new \InvalidArgumentException(sprintf(
                '%s costs %s points and you have %s. Sell cards you don\'t need with `/shop sell` or `/shop sell-extras` to earn more.',
                $what,
                number_format($price),
                number_format($player->points),
            ));
        }
    }
}
