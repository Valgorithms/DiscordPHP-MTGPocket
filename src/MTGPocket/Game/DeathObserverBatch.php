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
 * A destroy-all effect's pre-event observer registry and actual death records.
 * Planned destruction can be prevented/replaced, so only actual deaths notify.
 * This is a synchronous notification boundary, not a saved game or a complete
 * replacement/continuous-effect/intervening-condition model.
 */
final class DeathObserverBatch
{
    /** @var GameObject[] Pre-event source state; shared across this batch's notifications. */
    public readonly array $observers;

    /** @var CreatureDeath[] */
    public array $deaths = [];

    /** @var array<int, int> Planned object incarnations. */
    private array $planned = [];

    /**
     * @param GameObject[] $observers
     * @param GameObject[] $doomed
     */
    public function __construct(array $observers, array $doomed)
    {
        $this->observers = array_map(fn (GameObject $object) => clone $object, $observers);
        foreach ($doomed as $object) {
            $this->planned[$object->id] = $object->incarnation;
        }
    }

    /** Only this effect's original object incarnations belong to its event. */
    public function record(CreatureDeath $death): bool
    {
        if (($this->planned[$death->id] ?? null) !== $death->incarnation) {
            return false;
        }
        unset($this->planned[$death->id]);
        $this->deaths[] = $death;

        return true;
    }
}
