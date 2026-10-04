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

namespace MTGPocket\Drafts;

use MTGPocket\Models\CardCounts;

/**
 * One player's seat at a draft: what they paid, the cards they have taken,
 * the deck they build from them, and whether they have left the event or
 * had their cards moved to their collection.
 *
 * @since 0.5.0
 */
final class DraftSeat
{
    /**
     * @param string     $id        Discord user id.
     * @param string     $name
     * @param int        $paid      Points they paid to join.
     * @param CardCounts $picks     Every card they have taken.
     * @param CardCounts $deck      Their main deck: picks and basic lands. The rest of their picks is their side deck.
     * @param bool       $ready     They sent in their deck.
     * @param bool       $dropped   They left the event.
     * @param bool       $collected Their picks are in their collection.
     * @param list<string> $pickLog Card uuids in the order they took them.
     */
    public function __construct(
        public readonly string $id,
        public string $name,
        public int $paid = 0,
        public readonly CardCounts $picks = new CardCounts(),
        public readonly CardCounts $deck = new CardCounts(),
        public bool $ready = false,
        public bool $dropped = false,
        public bool $collected = false,
        public array $pickLog = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) ($data['name'] ?? ''),
            (int) ($data['paid'] ?? 0),
            new CardCounts((array) ($data['picks'] ?? [])),
            new CardCounts((array) ($data['deck'] ?? [])),
            (bool) ($data['ready'] ?? false),
            (bool) ($data['dropped'] ?? false),
            (bool) ($data['collected'] ?? false),
            array_values(array_map('strval', (array) ($data['pickLog'] ?? []))),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'paid' => $this->paid,
            'picks' => $this->picks,
            'deck' => $this->deck,
            'ready' => $this->ready,
            'dropped' => $this->dropped,
            'collected' => $this->collected,
            'pickLog' => $this->pickLog,
        ];
    }
}
