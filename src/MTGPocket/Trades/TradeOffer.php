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

use MTGPocket\Models\CardCounts;

/**
 * A trade one player offers another: cards and points they give, for cards
 * and points they want back. The offer is open until the other player
 * accepts or declines it, its sender cancels it, or it expires.
 *
 * Every change to an open offer bumps its revision, so an **Accept** click
 * on an older copy of the offer never agrees to something different.
 *
 * @since 0.4.0
 */
final class TradeOffer
{
    public const string OPEN = 'open';
    public const string ACCEPTED = 'accepted';
    public const string DECLINED = 'declined';
    public const string CANCELLED = 'cancelled';
    public const string EXPIRED = 'expired';

    /**
     * @param string                          $id
     * @param string                          $status
     * @param array{id: string, name: string} $from       Who offers.
     * @param array{id: string, name: string} $to         Who is offered.
     * @param CardCounts                      $give       Cards the sender gives.
     * @param CardCounts                      $want       Cards the sender wants from the other player.
     * @param int                             $givePoints Points the sender gives.
     * @param int                             $wantPoints Points the sender wants.
     * @param int                             $revision   Bumped by every change.
     * @param int                             $createdAt
     * @param int                             $expiresAt
     */
    public function __construct(
        public readonly string $id,
        public string $status,
        public readonly array $from,
        public readonly array $to,
        public readonly CardCounts $give = new CardCounts(),
        public readonly CardCounts $want = new CardCounts(),
        public int $givePoints = 0,
        public int $wantPoints = 0,
        public int $revision = 1,
        public int $createdAt = 0,
        public int $expiresAt = 0,
    ) {
    }

    /**
     * Whether it can still be accepted at a moment.
     *
     * @param int $now
     *
     * @return bool
     */
    public function isOpen(int $now): bool
    {
        return $this->status === self::OPEN && $now < $this->expiresAt;
    }

    /**
     * Whether a player is either side of it.
     *
     * @param string $playerId
     *
     * @return bool
     */
    public function involves(string $playerId): bool
    {
        return $this->from['id'] === $playerId || $this->to['id'] === $playerId;
    }

    /**
     * Whether nothing changes hands.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->give->total() === 0 && $this->want->total() === 0 && $this->givePoints === 0 && $this->wantPoints === 0;
    }

    /**
     * @param array $data As stored.
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['status'],
            ['id' => (string) $data['from']['id'], 'name' => (string) ($data['from']['name'] ?? '')],
            ['id' => (string) $data['to']['id'], 'name' => (string) ($data['to']['name'] ?? '')],
            new CardCounts((array) ($data['give'] ?? [])),
            new CardCounts((array) ($data['want'] ?? [])),
            (int) ($data['givePoints'] ?? 0),
            (int) ($data['wantPoints'] ?? 0),
            (int) ($data['revision'] ?? 1),
            (int) ($data['createdAt'] ?? 0),
            (int) ($data['expiresAt'] ?? 0),
        );
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return json_decode(json_encode([
            'id' => $this->id,
            'status' => $this->status,
            'from' => $this->from,
            'to' => $this->to,
            'give' => $this->give,
            'want' => $this->want,
            'givePoints' => $this->givePoints,
            'wantPoints' => $this->wantPoints,
            'revision' => $this->revision,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
        ], JSON_THROW_ON_ERROR), true);
    }
}
