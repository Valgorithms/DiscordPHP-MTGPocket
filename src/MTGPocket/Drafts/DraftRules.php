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

/**
 * How booster drafts run, from `config/drafts.php`.
 *
 * @since 0.5.0
 */
final class DraftRules
{
    /**
     * The rules that come with the game.
     */
    public const string DEFAULT_FILE = __DIR__.'/../../../config/drafts.php';

    /**
     * @param int    $entryFee     Points to join a pod.
     * @param int    $podSize
     * @param int    $minPlayers
     * @param int    $packs        Packs each player opens.
     * @param int    $pickSeconds  Before the bot picks for a player.
     * @param int    $buildMinutes
     * @param int    $deckMin
     * @param int    $roundHours
     * @param int    $signupHours
     * @param int    $eventDays
     * @param string $mode         The game mode whose rules the games use.
     */
    public function __construct(
        public readonly int $entryFee = 1200,
        public readonly int $podSize = 8,
        public readonly int $minPlayers = 2,
        public readonly int $packs = 3,
        public readonly int $pickSeconds = 180,
        public readonly int $buildMinutes = 60,
        public readonly int $deckMin = 40,
        public readonly int $roundHours = 24,
        public readonly int $signupHours = 24,
        public readonly int $eventDays = 7,
        public readonly string $mode = 'limited',
    ) {
        if ($entryFee < 0) {
            throw new \InvalidArgumentException('The draft entry fee cannot be negative.');
        }
        if ($minPlayers < 2 || $podSize < $minPlayers || $podSize > 16) {
            throw new \InvalidArgumentException('A draft pod needs at least 2 players to start, and room for no more than 16.');
        }
        if ($packs < 1 || $pickSeconds < 1 || $buildMinutes < 1 || $deckMin < 1 || $roundHours < 1 || $signupHours < 1 || $eventDays < 1) {
            throw new \InvalidArgumentException('Draft packs, deck size and time limits must be at least 1.');
        }
    }

    /**
     * @param string $file Like `config/drafts.php`.
     *
     * @return self
     */
    public static function fromFile(string $file = self::DEFAULT_FILE): self
    {
        if (! is_file($file)) {
            throw new \InvalidArgumentException("There are no draft rules at {$file}.");
        }

        return self::fromArray((array) require $file);
    }

    /**
     * @param array $config As `config/drafts.php` returns it.
     *
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (int) ($config['entry_fee'] ?? 1200),
            (int) ($config['pod_size'] ?? 8),
            (int) ($config['min_players'] ?? 2),
            (int) ($config['packs'] ?? 3),
            (int) ($config['pick_seconds'] ?? 180),
            (int) ($config['build_minutes'] ?? 60),
            (int) ($config['deck_min'] ?? 40),
            (int) ($config['round_hours'] ?? 24),
            (int) ($config['signup_hours'] ?? 24),
            (int) ($config['event_days'] ?? 7),
            (string) ($config['mode'] ?? 'limited'),
        );
    }
}
