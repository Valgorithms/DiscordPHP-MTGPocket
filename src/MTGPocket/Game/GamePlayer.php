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

    /** Energy counters (rule 107.14). */
    public int $energy = 0;

    /** Speed, 0 to 4 (rule 702.179); 0 until a permanent with "Start your engines!" is theirs. */
    public int $speed = 0;

    /** The turn their speed last went up: once each turn. */
    public int $speedTurn = 0;

    /** Cards drawn this turn, and the turn that was. */
    public int $drawn = 0;

    public int $drawnTurn = 0;

    /** Their life when the turn began, to tell when they have lost life this turn. */
    public int $lifeMark = self::STARTING_LIFE;

    /** @var int[] Object ids; the last is the top. */
    public array $library = [];

    /** @var int[] */
    public array $hand = [];

    /** @var int[] Object ids; the last is the top. */
    public array $graveyard = [];

    public ManaPool $manaPool;

    public int $landsPlayed = 0;

    public int $mulligans = 0;

    /** The last turn they were dealt damage, for bloodthirst. */
    public int $damagedOnTurn = 0;

    /** Kept an opening hand. */
    public bool $kept = false;

    /** Cards still to put on the bottom after keeping (London mulligan, rule 103.5). */
    public int $toBottom = 0;

    /** Tried to draw from an empty library since state-based actions were last checked (rule 704.5b). */
    public bool $drewFromEmpty = false;

    /** @var array<int, int> Combat damage dealt to this player by each commander, by object id (rule 903.10a). */
    public array $commanderDamage = [];

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
            'energy' => $this->energy,
            'speed' => $this->speed,
            'speedTurn' => $this->speedTurn,
            'lifeMark' => $this->lifeMark,
            'drawn' => $this->drawn,
            'drawnTurn' => $this->drawnTurn,
            'library' => $this->library,
            'hand' => $this->hand,
            'graveyard' => $this->graveyard,
            'manaPool' => $this->manaPool->toArray(),
            'landsPlayed' => $this->landsPlayed,
            'mulligans' => $this->mulligans,
            'damagedOnTurn' => $this->damagedOnTurn,
            'kept' => $this->kept,
            'toBottom' => $this->toBottom,
            'drewFromEmpty' => $this->drewFromEmpty,
            'commanderDamage' => array_map(fn ($id, $damage) => [$id, $damage], array_keys($this->commanderDamage), $this->commanderDamage),
            'lost' => $this->lost,
            'lossReason' => $this->lossReason,
        ];
    }

    public static function fromArray(array $data): self
    {
        $player = new self((int) $data['seat'], (string) $data['id'], (string) $data['name']);
        $player->life = (int) $data['life'];
        $player->poison = (int) ($data['poison'] ?? 0);
        $player->energy = (int) ($data['energy'] ?? 0);
        $player->speed = (int) ($data['speed'] ?? 0);
        $player->speedTurn = (int) ($data['speedTurn'] ?? 0);
        $player->lifeMark = (int) ($data['lifeMark'] ?? $player->life);
        $player->drawn = (int) ($data['drawn'] ?? 0);
        $player->drawnTurn = (int) ($data['drawnTurn'] ?? 0);
        $player->library = array_map('intval', (array) $data['library']);
        $player->hand = array_map('intval', (array) $data['hand']);
        $player->graveyard = array_map('intval', (array) $data['graveyard']);
        $player->manaPool = new ManaPool((array) ($data['manaPool'] ?? []));
        $player->landsPlayed = (int) ($data['landsPlayed'] ?? 0);
        $player->mulligans = (int) ($data['mulligans'] ?? 0);
        $player->damagedOnTurn = (int) ($data['damagedOnTurn'] ?? 0);
        $player->kept = (bool) ($data['kept'] ?? false);
        $player->toBottom = (int) ($data['toBottom'] ?? 0);
        $player->drewFromEmpty = (bool) ($data['drewFromEmpty'] ?? false);
        foreach ((array) ($data['commanderDamage'] ?? []) as [$id, $damage]) {
            $player->commanderDamage[(int) $id] = (int) $damage;
        }
        $player->lost = (bool) ($data['lost'] ?? false);
        $player->lossReason = $data['lossReason'] ?? null;

        return $player;
    }
}
