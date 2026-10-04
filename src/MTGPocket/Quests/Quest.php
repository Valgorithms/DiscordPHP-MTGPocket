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

namespace MTGPocket\Quests;

/**
 * One daily or weekly quest: do something a number of times for points.
 *
 * @since 0.4.0
 */
final class Quest
{
    /**
     * What quests can count.
     *
     * @var array<string, string> Event => what one of it is.
     */
    public const array EVENTS = [
        'ranked' => 'ranked game played',
        'win' => 'ranked game won',
        'rental' => 'ranked game played with a rental deck',
        'pack' => 'pack opened',
    ];

    /**
     * @param string $id     Where progress is saved; starts with a letter.
     * @param string $label  What to do, e.g. `Win a ranked game`.
     * @param string $event  One of {@see self::EVENTS}.
     * @param int    $goal   How many times.
     * @param int    $points What it pays when done.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $event,
        public readonly int $goal,
        public readonly int $points,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id)) {
            throw new \InvalidArgumentException("The quest id \"{$id}\" must start with a lowercase letter and use only a-z, 0-9, - and _.");
        }
        if (! isset(self::EVENTS[$event])) {
            throw new \InvalidArgumentException("The quest {$id} counts \"{$event}\"; it must be one of ".implode(', ', array_keys(self::EVENTS)).'.');
        }
        if ($goal < 1 || $points < 0 || trim($label) === '') {
            throw new \InvalidArgumentException("The quest {$id} needs a label, a goal of at least 1 and points that are not negative.");
        }
    }

    /**
     * @param array $config As in `config/quests.php`.
     *
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (string) ($config['id'] ?? ''),
            (string) ($config['label'] ?? ''),
            (string) ($config['event'] ?? ''),
            (int) ($config['goal'] ?? 1),
            (int) ($config['points'] ?? 0),
        );
    }
}
