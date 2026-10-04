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

/**
 * One card in a game, in whatever zone it is in, with the state it has as
 * a permanent: tapped, summoning sick, damage, counters and what it is
 * attached to.
 *
 * Each time it changes zones it becomes a new object (rule 400.7): its
 * permanent state is cleared and {@see $incarnation} goes up, so targets
 * chosen for the old object no longer find it.
 *
 * @since 0.3.0
 */
final class GameObject
{
    public const string LIBRARY = 'library';
    public const string HAND = 'hand';
    public const string BATTLEFIELD = 'battlefield';
    public const string GRAVEYARD = 'graveyard';
    public const string STACK = 'stack';
    public const string EXILE = 'exile';

    /** Where commanders start and return to (rule 903.6). */
    public const string COMMAND = 'command';

    /** Where a token goes when it leaves the battlefield: it has ceased to exist (rule 111.7). */
    public const string GONE = 'gone';

    public bool $tapped = false;

    /** Came under its controller's control since their most recent turn began (rule 302.6). */
    public bool $sick = false;

    public int $damage = 0;

    /** Dealt damage by a source with deathtouch since damage was last removed (rule 704.5h). */
    public bool $deathtouched = false;

    /** @var array<string, int> Counter kind => how many, e.g. `+1/+1`, `-1/-1`, `loyalty`. */
    public array $counters = [];

    public ?int $attachedTo = null;

    /** @var array<int, array{power: int, toughness: int, keywords: string[]}> Effects that last until end of turn. */
    public array $untilEndOfTurn = [];

    public int $incarnation = 0;

    /** @var array<int, int> Activated ability index => the turn it was last activated, for once-a-turn limits. */
    public array $used = [];

    private ?CardDefinition $definition = null;

    /**
     * @param int    $id
     * @param int    $owner      The owner's seat.
     * @param array  $card       Pool card data.
     * @param string $zone
     * @param int    $controller
     */
    public function __construct(
        public readonly int $id,
        public readonly int $owner,
        public readonly array $card,
        public string $zone = self::LIBRARY,
        public int $controller = -1,
    ) {
        if ($this->controller < 0) {
            $this->controller = $this->owner;
        }
    }

    public function definition(): CardDefinition
    {
        return $this->definition ??= new CardDefinition($this->card);
    }

    public function name(): string
    {
        return $this->definition()->name;
    }

    /**
     * Moves it to a new zone as a new object.
     *
     * @param string $zone
     *
     * @return void
     */
    public function moveTo(string $zone): void
    {
        $this->zone = $zone;
        $this->controller = $this->owner;
        $this->tapped = false;
        $this->sick = false;
        $this->damage = 0;
        $this->deathtouched = false;
        $this->counters = [];
        $this->attachedTo = null;
        $this->untilEndOfTurn = [];
        $this->used = [];
        $this->incarnation++;
    }

    public function counter(string $kind): int
    {
        return $this->counters[$kind] ?? 0;
    }

    public function addCounters(string $kind, int $amount): void
    {
        $this->counters[$kind] = max(0, $this->counter($kind) + $amount);
        if ($this->counters[$kind] === 0) {
            unset($this->counters[$kind]);
        }
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'owner' => $this->owner,
            'controller' => $this->controller,
            'zone' => $this->zone,
            'card' => $this->card,
        ];
        // Only state that differs from a fresh object, to keep saved games small.
        $state = [
            'tapped' => $this->tapped,
            'sick' => $this->sick,
            'damage' => $this->damage,
            'deathtouched' => $this->deathtouched,
            'counters' => $this->counters,
            'attachedTo' => $this->attachedTo,
            'untilEndOfTurn' => $this->untilEndOfTurn,
            'incarnation' => $this->incarnation,
            'used' => $this->used,
        ];

        return $data + array_filter($state, fn ($value) => ! in_array($value, [false, null, [], 0], true));
    }

    /**
     * @param array $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $object = new self((int) $data['id'], (int) $data['owner'], (array) $data['card'], (string) $data['zone'], (int) ($data['controller'] ?? $data['owner']));
        $object->tapped = (bool) ($data['tapped'] ?? false);
        $object->sick = (bool) ($data['sick'] ?? false);
        $object->damage = (int) ($data['damage'] ?? 0);
        $object->deathtouched = (bool) ($data['deathtouched'] ?? false);
        $object->counters = array_map('intval', (array) ($data['counters'] ?? []));
        $object->attachedTo = isset($data['attachedTo']) ? (int) $data['attachedTo'] : null;
        $object->untilEndOfTurn = array_values((array) ($data['untilEndOfTurn'] ?? []));
        $object->incarnation = (int) ($data['incarnation'] ?? 0);
        foreach ((array) ($data['used'] ?? []) as $index => $turn) {
            $object->used[(int) $index] = (int) $turn;
        }

        return $object;
    }
}
