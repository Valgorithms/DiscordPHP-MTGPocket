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

namespace MTGPocket\Game;

use MTGPocket\Game\Mana\ManaPool;

/**
 * One player in a game: life, poison, their own zones (library top last,
 * hand, graveyard top last) and mana pool.
 *
 * @since 0.3.0
 */
final class GamePlayer
{
    public const int STARTING_LIFE = 20;
    public const int HAND_SIZE = 7;

    public int $life = self::STARTING_LIFE;
    public int $poison = 0;

    /** @var int[] Object ids; the last is the top. */
    public array $library = [];

    /** @var int[] */
    public array $hand = [];

    /** @var int[] Object ids; the last is the top. */
    public array $graveyard = [];

    public ManaPool $manaPool;

    public int $landsPlayed = 0;

    public int $mulligans = 0;

    /** Kept an opening hand. */
    public bool $kept = false;

    /** Cards still to put on the bottom after keeping (London mulligan, rule 103.5). */
    public int $toBottom = 0;

    /** Tried to draw from an empty library since state-based actions were last checked (rule 704.5b). */
    public bool $drewFromEmpty = false;

    public bool $lost = false;

    public ?string $lossReason = null;

    /**
     * @param int    $seat 0 or 1.
     * @param string $id   Discord user id.
     * @param string $name
     */
    public function __construct(
        public readonly int $seat,
        public readonly string $id,
        public readonly string $name,
    ) {
        $this->manaPool = new ManaPool();
    }

    public function toArray(): array
    {
        return [
            'seat' => $this->seat,
            'id' => $this->id,
            'name' => $this->name,
            'life' => $this->life,
            'poison' => $this->poison,
            'library' => $this->library,
            'hand' => $this->hand,
            'graveyard' => $this->graveyard,
            'manaPool' => $this->manaPool->toArray(),
            'landsPlayed' => $this->landsPlayed,
            'mulligans' => $this->mulligans,
            'kept' => $this->kept,
            'toBottom' => $this->toBottom,
            'drewFromEmpty' => $this->drewFromEmpty,
            'lost' => $this->lost,
            'lossReason' => $this->lossReason,
        ];
    }

    public static function fromArray(array $data): self
    {
        $player = new self((int) $data['seat'], (string) $data['id'], (string) $data['name']);
        $player->life = (int) $data['life'];
        $player->poison = (int) ($data['poison'] ?? 0);
        $player->library = array_map('intval', (array) $data['library']);
        $player->hand = array_map('intval', (array) $data['hand']);
        $player->graveyard = array_map('intval', (array) $data['graveyard']);
        $player->manaPool = new ManaPool((array) ($data['manaPool'] ?? []));
        $player->landsPlayed = (int) ($data['landsPlayed'] ?? 0);
        $player->mulligans = (int) ($data['mulligans'] ?? 0);
        $player->kept = (bool) ($data['kept'] ?? false);
        $player->toBottom = (int) ($data['toBottom'] ?? 0);
        $player->drewFromEmpty = (bool) ($data['drewFromEmpty'] ?? false);
        $player->lost = (bool) ($data['lost'] ?? false);
        $player->lossReason = $data['lossReason'] ?? null;

        return $player;
    }
}
