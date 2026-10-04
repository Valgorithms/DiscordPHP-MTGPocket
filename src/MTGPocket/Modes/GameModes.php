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

namespace MTGPocket\Modes;

use MTGPocket\Decks\DeckBuilder;

/**
 * The game modes, one per deck format, read from `config/modes.php`.
 *
 * @since 0.4.0
 */
final class GameModes
{
    /**
     * The modes that come with the game.
     */
    public const string DEFAULT_FILE = __DIR__.'/../../../config/modes.php';

    /** @var array<string, GameMode> */
    private array $modes = [];

    /**
     * @param GameMode[] $modes
     */
    public function __construct(array $modes)
    {
        foreach ($modes as $mode) {
            $this->modes[$mode->id] = $mode;
        }
        foreach (array_keys(DeckBuilder::FORMATS) as $format) {
            if (! isset($this->modes[$format])) {
                throw new \InvalidArgumentException("The game modes need an entry for {$format}.");
            }
        }
    }

    /**
     * @param string $file
     *
     * @throws \InvalidArgumentException
     *
     * @return self
     */
    public static function fromFile(string $file = self::DEFAULT_FILE): self
    {
        if (! is_file($file)) {
            throw new \InvalidArgumentException("There are no game modes at {$file}.");
        }

        return self::fromArray((array) require $file);
    }

    /**
     * @param array $config As `config/modes.php` returns it.
     *
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $modes = [];
        foreach ($config as $id => $mode) {
            $id = strtolower((string) $id);
            if (! isset(DeckBuilder::FORMATS[$id])) {
                throw new \InvalidArgumentException("{$id} is not a deck format; game modes must be one of ".implode(', ', array_keys(DeckBuilder::FORMATS)).'.');
            }
            $modes[] = GameMode::fromArray($id, DeckBuilder::FORMATS[$id], (array) $mode);
        }

        return new self($modes);
    }

    /**
     * @param string $id
     *
     * @throws \InvalidArgumentException When there is no such mode.
     *
     * @return GameMode
     */
    public function get(string $id): GameMode
    {
        return $this->modes[strtolower(trim($id))] ?? throw new \InvalidArgumentException('The mode must be one of '.implode(', ', DeckBuilder::FORMATS).'.');
    }

    /**
     * @return array<string, GameMode>
     */
    public function all(): array
    {
        return $this->modes;
    }

    /**
     * The modes that can be played now: id => label.
     *
     * @return array<string, string>
     */
    public function playable(): array
    {
        $labels = [];
        foreach ($this->modes as $id => $mode) {
            if ($mode->playable) {
                $labels[$id] = $mode->label;
            }
        }

        return $labels;
    }
}
