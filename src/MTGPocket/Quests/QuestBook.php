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
 * The quests players can get, from `config/quests.php`.
 *
 * @since 0.4.0
 */
final class QuestBook
{
    public const string DEFAULT_FILE = __DIR__.'/../../../config/quests.php';

    /**
     * @param Quest[] $daily       Daily quests to pick from.
     * @param Quest[] $weekly      Weekly quests to pick from.
     * @param int     $dailyCount  Daily quests each player gets.
     * @param int     $weeklyCount Weekly quests each player gets.
     */
    public function __construct(
        public readonly array $daily = [],
        public readonly array $weekly = [],
        public readonly int $dailyCount = 3,
        public readonly int $weeklyCount = 2,
    ) {
        if ($dailyCount < 0 || $weeklyCount < 0) {
            throw new \InvalidArgumentException('Quest counts cannot be negative.');
        }
        $ids = array_map(fn (Quest $quest) => $quest->id, [...$daily, ...$weekly]);
        if (count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('Every quest needs its own id.');
        }
    }

    /**
     * @param string $file Like `config/quests.php`.
     *
     * @return self
     */
    public static function fromFile(string $file = self::DEFAULT_FILE): self
    {
        if (! is_file($file)) {
            throw new \InvalidArgumentException("There are no quests at {$file}.");
        }

        return self::fromArray((array) require $file);
    }

    /**
     * @param array $config As in `config/quests.php`.
     *
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $quests = fn ($list) => array_values(array_map(fn ($quest) => Quest::fromArray((array) $quest), (array) $list));

        return new self(
            $quests($config['daily'] ?? []),
            $quests($config['weekly'] ?? []),
            (int) ($config['daily_count'] ?? 3),
            (int) ($config['weekly_count'] ?? 2),
        );
    }
}
