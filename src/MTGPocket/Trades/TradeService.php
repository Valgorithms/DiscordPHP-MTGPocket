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

namespace MTGPocket\Trades;

use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Inventory;
use MTGPocket\Models\Player;
use MTGPocket\Repository\CardPoolRepository;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\PlayerRepository;
use MTGPocket\Repository\TradeRepository;

/**
 * Trades between players. One player offers cards and points for cards and
 * points of another's; the other accepts or declines. Sending the offer is
 * the sender's confirmation and accepting it is the other player's, so a
 * trade happens only when both have agreed to the same revision of it.
 *
 * Nothing is held back while an offer is open. When it is accepted, both
 * sides are checked again (the cards owned, not needed by a deck, and the
 * points) and the whole trade happens at once, under the offer's lock, both
 * players' locks and both inventories' locks, always taken in the same
 * order, so a trade never half happens and two trades never trip over each
 * other.
 *
 * @since 0.4.0
 */
class TradeService
{
    /**
     * Most open offers a player may have sent at once.
     */
    public const int MAX_OPEN = 10;

    /**
     * How long an offer stays open, in seconds.
     */
    public const int LIFETIME = 7 * 86400;

    /**
     * Most different cards on each side of an offer.
     */
    public const int MAX_CARDS = 20;

    /**
     * Most points on each side of an offer.
     */
    public const int MAX_POINTS = 10_000_000;

    /**
     * @var \Closure(): int
     */
    protected \Closure $clock;

    /**
     * @var \Closure(): string
     */
    protected \Closure $random;

    /**
     * @param TradeRepository            $trades
     * @param PlayerRepository           $players
     * @param InventoryRepository        $inventories
     * @param CardPoolRepository         $pools
     * @param DeckBuilder                $decks
     * @param (\Closure(): int)|null     $clock  Defaults to {@see time()}.
     * @param (\Closure(): string)|null  $random Random hex for offer ids.
     */
    public function __construct(
        protected TradeRepository $trades,
        protected PlayerRepository $players,
        protected InventoryRepository $inventories,
        protected CardPoolRepository $pools,
        protected DeckBuilder $decks,
        ?\Closure $clock = null,
        ?\Closure $random = null,
    ) {
        $this->clock = $clock ?? time(...);
        $this->random = $random ?? fn () => bin2hex(random_bytes(16));
    }

    /**
     * Offers a trade.
     *
     * @param string             $fromId
     * @param string             $fromName
     * @param string             $toId
     * @param string             $toName
     * @param array<string, int> $give       Cards (uuid or name) => copies the sender gives.
     * @param array<string, int> $want       Cards (uuid or name) => copies the sender wants.
     * @param int                $givePoints
     * @param int                $wantPoints
     *
     * @throws \InvalidArgumentException When the offer could not go through as it stands.
     * @throws \OutOfBoundsException     When a card is not owned.
     *
     * @return TradeOffer
     */
    public function offer(string $fromId, string $fromName, string $toId, string $toName, array $give = [], array $want = [], int $givePoints = 0, int $wantPoints = 0): TradeOffer
    {
        if ($fromId === $toId) {
            throw new \InvalidArgumentException('You cannot trade with yourself.');
        }
        $now = ($this->clock)();
        $sent = array_filter($this->forPlayer($fromId)['sent'], fn (TradeOffer $offer) => $offer->isOpen($now));
        if (count($sent) >= self::MAX_OPEN) {
            throw new \InvalidArgumentException('You already have '.self::MAX_OPEN.' open trade offers. Cancel one with `/trade cancel` first.');
        }

        $this->players->findOrCreate($fromId, $fromName);
        $offer = new TradeOffer(
            substr(($this->random)(), 0, 10),
            TradeOffer::OPEN,
            ['id' => $fromId, 'name' => $fromName],
            ['id' => $toId, 'name' => $toName],
            createdAt: $now,
            expiresAt: $now + self::LIFETIME,
        );
        foreach ($give as $card => $count) {
            $offer->give->add($this->resolve($fromId, (string) $card, 'You'), self::checkCount($count));
        }
        foreach ($want as $card => $count) {
            $offer->want->add($this->resolve($toId, (string) $card, "**{$toName}**"), self::checkCount($count));
        }
        $offer->givePoints = self::checkPoints($givePoints);
        $offer->wantPoints = self::checkPoints($wantPoints);

        if ($offer->isEmpty()) {
            throw new \InvalidArgumentException('Put at least one card or some points in the offer.');
        }
        $this->check($offer);
        $this->trades->save($offer);

        return $offer;
    }

    /**
     * Adds cards or points to one of the sender's open offers. The other
     * player sees it change, and an accept of the old offer no longer works.
     *
     * @param string      $fromId
     * @param string|null $tradeId Null for their newest open offer.
     * @param bool        $want    Ask for the cards (from the other player) instead of giving them.
     * @param string|null $card    A uuid or name; null to add only points.
     * @param int         $count
     * @param int         $points  Points to add to that side.
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfBoundsException
     *
     * @return TradeOffer
     */
    public function add(string $fromId, ?string $tradeId, bool $want, ?string $card, int $count = 1, int $points = 0): TradeOffer
    {
        $offer = $this->sentOffer($fromId, $tradeId);
        $uuid = null;
        if ($card !== null && trim($card) !== '') {
            self::checkCount($count);
            $uuid = $want
                ? $this->resolve($offer->to['id'], $card, "**{$offer->to['name']}**")
                : $this->resolve($fromId, $card, 'You');
        } elseif ($points === 0) {
            throw new \InvalidArgumentException('Add a card or some points.');
        }
        self::checkPoints($points);

        return $this->trades->modify($offer->id, function (TradeOffer $offer) use ($fromId, $want, $uuid, $count, $points): void {
            $this->checkOpen($offer);
            if ($offer->from['id'] !== $fromId) {
                throw new \InvalidArgumentException('Only the player who offered a trade can change it.');
            }
            if ($uuid !== null) {
                ($want ? $offer->want : $offer->give)->add($uuid, $count);
            }
            if ($want) {
                $offer->wantPoints = self::checkPoints($offer->wantPoints + $points);
            } else {
                $offer->givePoints = self::checkPoints($offer->givePoints + $points);
            }
            $this->check($offer);
            $offer->revision++;
        });
    }

    /**
     * Accepts an offer: the trade happens now, all of it or none of it.
     *
     * @param string $tradeId
     * @param string $playerId The player the offer was made to.
     * @param string $name     Their display name.
     * @param int    $revision The revision they were shown.
     *
     * @throws StaleOfferException       When the offer changed since they saw it.
     * @throws \InvalidArgumentException When either side can no longer hold up their end.
     * @throws \OutOfBoundsException     When the offer is no longer open.
     *
     * @return TradeOffer
     */
    public function accept(string $tradeId, string $playerId, string $name, int $revision): TradeOffer
    {
        $offer = $this->trades->find($tradeId) ?? throw new \OutOfBoundsException('That trade offer is no longer open.');
        $this->players->findOrCreate($offer->from['id']);
        $this->players->findOrCreate($playerId, $name);

        return $this->trades->modify($tradeId, function (TradeOffer $offer) use ($playerId, $revision): void {
            $this->checkOpen($offer);
            if ($offer->to['id'] !== $playerId) {
                throw new \InvalidArgumentException('Only **'.$offer->to['name'].'** can accept this offer.');
            }
            if ($offer->revision !== $revision) {
                throw new StaleOfferException($offer);
            }
            $this->settle($offer);
            $offer->status = TradeOffer::ACCEPTED;
        });
    }

    /**
     * Declines an offer made to the player.
     *
     * @param string $tradeId
     * @param string $playerId
     *
     * @return TradeOffer
     */
    public function decline(string $tradeId, string $playerId): TradeOffer
    {
        return $this->trades->modify($tradeId, function (TradeOffer $offer) use ($playerId): void {
            $this->checkOpen($offer);
            if ($offer->to['id'] !== $playerId) {
                throw new \InvalidArgumentException('Only **'.$offer->to['name'].'** can decline this offer. Cancel your own offers with `/trade cancel`.');
            }
            $offer->status = TradeOffer::DECLINED;
        });
    }

    /**
     * Cancels an offer the player sent.
     *
     * @param string      $playerId
     * @param string|null $tradeId  Null for their newest open offer.
     *
     * @return TradeOffer
     */
    public function cancel(string $playerId, ?string $tradeId = null): TradeOffer
    {
        $offer = $this->sentOffer($playerId, $tradeId);

        return $this->trades->modify($offer->id, function (TradeOffer $offer) use ($playerId): void {
            if ($offer->from['id'] !== $playerId) {
                throw new \InvalidArgumentException('Only **'.$offer->from['name'].'** can cancel this offer.');
            }
            $offer->status = TradeOffer::CANCELLED;
        });
    }

    /**
     * An open offer by id.
     *
     * @param string $tradeId
     *
     * @return TradeOffer|null
     */
    public function find(string $tradeId): ?TradeOffer
    {
        $offer = $this->trades->find($tradeId);
        if ($offer !== null && ! $offer->isOpen(($this->clock)())) {
            $this->expire($offer);

            return null;
        }

        return $offer;
    }

    /**
     * A player's open offers, newest first. Expired offers are cleared out.
     *
     * @param string $playerId
     *
     * @return array{sent: TradeOffer[], received: TradeOffer[]}
     */
    public function forPlayer(string $playerId): array
    {
        $now = ($this->clock)();
        $sent = $received = [];
        foreach ($this->trades->all() as $offer) {
            if (! $offer->involves($playerId)) {
                continue;
            }
            if (! $offer->isOpen($now)) {
                $this->expire($offer);
                continue;
            }
            if ($offer->from['id'] === $playerId) {
                $sent[] = $offer;
            } else {
                $received[] = $offer;
            }
        }
        $newest = fn (TradeOffer $a, TradeOffer $b) => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id];
        usort($sent, $newest);
        usort($received, $newest);

        return ['sent' => $sent, 'received' => $received];
    }

    /**
     * Card data for an offer's cards.
     *
     * @param string $uuid
     *
     * @return array
     */
    public function cardData(string $uuid): array
    {
        return $this->pools->card($uuid) ?? ['uuid' => $uuid, 'name' => 'Unknown card', 'rarity' => 'common'];
    }

    /**
     * Moves the cards and points, checking both sides again first. Locks are
     * taken lowest player id first, players before inventories, the order
     * every other change takes them in.
     *
     * @param TradeOffer $offer
     *
     * @throws \InvalidArgumentException When either side can no longer hold up their end.
     */
    protected function settle(TradeOffer $offer): void
    {
        $fromId = $offer->from['id'];
        $toId = $offer->to['id'];
        [$first, $second] = strcmp($fromId, $toId) < 0 ? [$fromId, $toId] : [$toId, $fromId];

        $this->players->modify($first, function (Player $one) use ($offer, $first, $second, $fromId): void {
            $this->players->modify($second, function (Player $two) use ($offer, $first, $second, $fromId, $one): void {
                [$from, $to] = $one->id === $fromId ? [$one, $two] : [$two, $one];
                if ($from->points < $offer->givePoints) {
                    throw new \InvalidArgumentException("**{$offer->from['name']}** no longer has the ".number_format($offer->givePoints).' points this trade needs.');
                }
                if ($to->points < $offer->wantPoints) {
                    throw new \InvalidArgumentException("**{$offer->to['name']}** doesn't have the ".number_format($offer->wantPoints).' points this trade needs.');
                }

                $this->inventories->modify($first, function (Inventory $a) use ($offer, $second, $fromId): void {
                    $this->inventories->modify($second, function (Inventory $b) use ($offer, $fromId, $a): void {
                        [$fromCards, $toCards] = $a->playerId === $fromId ? [$a, $b] : [$b, $a];
                        $this->checkCards($fromCards, $offer->give, $offer->from['name']);
                        $this->checkCards($toCards, $offer->want, $offer->to['name']);
                        foreach ($offer->give as $uuid => $count) {
                            $fromCards->cards->remove((string) $uuid, $count);
                            $toCards->cards->add((string) $uuid, $count);
                        }
                        foreach ($offer->want as $uuid => $count) {
                            $toCards->cards->remove((string) $uuid, $count);
                            $fromCards->cards->add((string) $uuid, $count);
                        }
                    });
                });

                $from->points += $offer->wantPoints - $offer->givePoints;
                $to->points += $offer->givePoints - $offer->wantPoints;
            });
        });
    }

    /**
     * Checks both sides could hold up their end right now.
     *
     * @param TradeOffer $offer
     *
     * @throws \InvalidArgumentException
     */
    protected function check(TradeOffer $offer): void
    {
        if (count($offer->give) > self::MAX_CARDS || count($offer->want) > self::MAX_CARDS) {
            throw new \InvalidArgumentException('An offer can hold up to '.self::MAX_CARDS.' different cards on each side.');
        }
        if (($this->players->find($offer->from['id'])?->points ?? 0) < $offer->givePoints) {
            throw new \InvalidArgumentException('You have '.number_format($this->players->find($offer->from['id'])?->points ?? 0).' points, fewer than you offered.');
        }
        $this->checkCards($this->inventories->get($offer->from['id']), $offer->give, $offer->from['name'], true);
        $this->checkCards($this->inventories->get($offer->to['id']), $offer->want, $offer->to['name']);
    }

    /**
     * Checks a player owns cards, beyond what their decks use.
     *
     * @param Inventory  $inventory
     * @param CardCounts $cards
     * @param string     $name      The player's name, for the message.
     * @param bool       $self      Whether the message is to them.
     *
     * @throws \InvalidArgumentException
     */
    protected function checkCards(Inventory $inventory, CardCounts $cards, string $name, bool $self = false): void
    {
        $inUse = $this->decks->copiesInUse($inventory->playerId);
        foreach ($cards as $uuid => $count) {
            $owned = $inventory->cards->get((string) $uuid);
            $needed = $inUse[$uuid]['count'] ?? 0;
            if ($owned - $needed >= $count) {
                continue;
            }
            $card = $this->cardData((string) $uuid)['name'];
            $who = $self ? 'You' : "**{$name}**";
            if ($owned < $count) {
                throw new \InvalidArgumentException("{$who} ".($self ? 'own' : 'owns')." {$owned} **{$card}**, not {$count}.");
            }

            throw new \InvalidArgumentException("{$who} can trade only ".($owned - $needed)." **{$card}**: the deck **{$inUse[$uuid]['deck']}** uses {$needed}.".($self ? ' Take them out with `/decks remove` first.' : ''));
        }
    }

    /**
     * A card someone owns, by uuid or name.
     *
     * @param string $playerId
     * @param string $card
     * @param string $who      For the message.
     *
     * @throws \OutOfBoundsException
     *
     * @return string The uuid.
     */
    protected function resolve(string $playerId, string $card, string $who): string
    {
        $card = trim($card);
        $inventory = $this->inventories->get($playerId);
        if ($inventory->cards->get($card) > 0) {
            return $card;
        }
        $best = null;
        foreach ($inventory->cards as $uuid => $count) {
            $data = $this->pools->card((string) $uuid);
            if ($data !== null && strcasecmp($data['name'], $card) === 0 && $count > ($best[1] ?? 0)) {
                $best = [(string) $uuid, $count];
            }
        }

        $name = $this->pools->card($card)['name'] ?? $card;

        return $best[0] ?? throw new \OutOfBoundsException("{$who} ".($who === 'You' ? "don't" : "doesn't")." own **{$name}**.");
    }

    /**
     * One of a player's open sent offers.
     *
     * @param string      $playerId
     * @param string|null $tradeId  Null for the newest.
     *
     * @throws \OutOfBoundsException
     *
     * @return TradeOffer
     */
    protected function sentOffer(string $playerId, ?string $tradeId): TradeOffer
    {
        $sent = $this->forPlayer($playerId)['sent'];
        if ($tradeId === null || trim($tradeId) === '') {
            return $sent[0] ?? throw new \OutOfBoundsException('You have no open trade offers. Make one with `/trade offer`.');
        }
        foreach ($sent as $offer) {
            if ($offer->id === trim($tradeId)) {
                return $offer;
            }
        }

        throw new \OutOfBoundsException('You have no open trade offer like that. See `/trade list`.');
    }

    /**
     * @param TradeOffer $offer
     *
     * @throws \OutOfBoundsException When it has expired.
     */
    protected function checkOpen(TradeOffer $offer): void
    {
        if (! $offer->isOpen(($this->clock)())) {
            throw new \OutOfBoundsException('That trade offer has expired.');
        }
    }

    /**
     * Closes an expired offer.
     *
     * @param TradeOffer $offer
     */
    protected function expire(TradeOffer $offer): void
    {
        try {
            $this->trades->modify($offer->id, function (TradeOffer $offer): void {
                if (! $offer->isOpen(($this->clock)())) {
                    $offer->status = TradeOffer::EXPIRED;
                }
            });
        } catch (\OutOfBoundsException) {
            // Already closed.
        }
    }

    /**
     * @param int $count
     *
     * @throws \InvalidArgumentException
     *
     * @return int
     */
    protected static function checkCount(int $count): int
    {
        if ($count < 1 || $count > 99) {
            throw new \InvalidArgumentException('Trade between 1 and 99 copies of a card.');
        }

        return $count;
    }

    /**
     * @param int $points
     *
     * @throws \InvalidArgumentException
     *
     * @return int
     */
    protected static function checkPoints(int $points): int
    {
        if ($points < 0 || $points > self::MAX_POINTS) {
            throw new \InvalidArgumentException('Points in a trade must be between 0 and '.number_format(self::MAX_POINTS).'.');
        }

        return $points;
    }
}
