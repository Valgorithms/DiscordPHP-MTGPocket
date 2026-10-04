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

use MTGPocket\Cards\BasicLands;
use MTGPocket\Cards\ColorIdentity;
use MTGPocket\Models\Deck;

/**
 * One way to play, from `config/modes.php`: the deck sizes it needs, how
 * many copies of a card a deck may have, the starting life, and its card
 * library (the sets it allows and the cards it bans).
 *
 * @since 0.4.0
 */
final class GameMode
{
    /**
     * Problems listed for one deck, at most.
     */
    public const int MAX_PROBLEMS = 10;

    /**
     * @param string        $id
     * @param string        $label
     * @param int           $mainMin
     * @param int|null      $mainMax
     * @param int|null      $sideMax
     * @param int|null      $copies             Per card name, basic lands aside.
     * @param int           $life
     * @param string[]|null $sets               Allowed set codes, uppercase; null for all.
     * @param int|null      $releasedWithinDays
     * @param string[]      $banned             Lowercase card names.
     * @param bool          $playable
     * @param bool          $commander          Decks need a commander, and games use the Commander rules (rule 903).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int $mainMin,
        public readonly ?int $mainMax = null,
        public readonly ?int $sideMax = null,
        public readonly ?int $copies = null,
        public readonly int $life = 20,
        public readonly ?array $sets = null,
        public readonly ?int $releasedWithinDays = null,
        public readonly array $banned = [],
        public readonly bool $playable = true,
        public readonly bool $commander = false,
    ) {
        if ($mainMin < 1 || ($mainMax !== null && $mainMax < $mainMin)) {
            throw new \InvalidArgumentException("The {$label} mode needs a main deck minimum of at least 1, and a maximum no lower than it.");
        }
        if ($life < 1) {
            throw new \InvalidArgumentException("The {$label} mode needs a starting life of at least 1.");
        }
        if ($copies !== null && $copies < 1) {
            throw new \InvalidArgumentException("The {$label} mode needs a copy limit of at least 1, or none.");
        }
    }

    /**
     * @param string $id
     * @param string $label
     * @param array  $config One entry of `config/modes.php`.
     *
     * @return self
     */
    public static function fromArray(string $id, string $label, array $config): self
    {
        $int = fn (string $key) => isset($config[$key]) ? (int) $config[$key] : null;

        return new self(
            $id,
            $label,
            (int) ($config['main_min'] ?? 40),
            $int('main_max'),
            $int('side_max'),
            $int('copies'),
            (int) ($config['life'] ?? 20),
            isset($config['sets']) ? array_values(array_map(fn ($set) => strtoupper((string) $set), (array) $config['sets'])) : null,
            $int('released_within_days'),
            array_values(array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) ($config['banned'] ?? []))),
            (bool) ($config['playable'] ?? true),
            (bool) ($config['commander'] ?? false),
        );
    }

    /**
     * What keeps a deck from being played in this mode.
     *
     * @param Deck                          $deck
     * @param callable(string): array       $card        Card data by deck key, with `setCode` for pool cards.
     * @param callable(string): ?string     $releaseDate A set's release date, `YYYY-MM-DD`.
     * @param int                           $now         Unix time.
     *
     * @return string[] Empty when it is legal.
     */
    public function problems(Deck $deck, callable $card, callable $releaseDate, int $now): array
    {
        $problems = [];
        $main = $deck->size();
        $what = $deck->commander === null ? 'The main deck has' : 'The deck has, with its commander,';
        if ($main < $this->mainMin) {
            $problems[] = "{$what} {$main} cards; {$this->label} needs ".($this->mainMax === $this->mainMin ? 'exactly' : 'at least')." {$this->mainMin}.";
        } elseif ($this->mainMax !== null && $main > $this->mainMax) {
            $problems[] = "{$what} {$main} cards; {$this->label} allows ".($this->mainMax === $this->mainMin ? 'exactly' : 'at most')." {$this->mainMax}.";
        }
        if ($this->commander) {
            array_push($problems, ...$this->commanderProblems($deck, $card));
        } elseif ($deck->commander !== null) {
            $problems[] = "{$this->label} decks have no commander; take it out with `/decks commander` and no card.";
        }
        if ($this->sideMax !== null && $deck->side->total() > $this->sideMax) {
            $problems[] = "The side deck has {$deck->side->total()} cards; {$this->label} allows ".($this->sideMax === 0 ? 'none' : "at most {$this->sideMax}").'.';
        }

        $names = [];
        $sets = [];
        foreach ($deck->allCards() as $key => $count) {
            $key = (string) $key;
            if (BasicLands::isBasic($key)) {
                continue;
            }
            $data = $card($key);
            $name = (string) ($data['name'] ?? $key);
            if (str_starts_with((string) ($data['type'] ?? ''), 'Basic ')) {
                // A printed basic land, as rental decks hold them.
                continue;
            }
            $names[mb_strtolower($name)] = [$name, ($names[mb_strtolower($name)][1] ?? 0) + $count];
            if (in_array(mb_strtolower($name), $this->banned, true)) {
                $problems[] = "**{$name}** is banned in {$this->label}.";
            }
            $set = strtoupper((string) ($data['setCode'] ?? ''));
            $sets[$set][] = $name;
        }

        if ($this->copies !== null) {
            foreach ($names as [$name, $count]) {
                if ($count > $this->copies) {
                    $problems[] = "{$count} copies of **{$name}**; {$this->label} allows ".($this->copies === 1 ? 'one' : $this->copies).'.';
                }
            }
        }

        foreach ($sets as $set => $cards) {
            if (! $this->allowsSet($set, $releaseDate, $now)) {
                $list = implode(', ', array_map(fn ($name) => "**{$name}**", array_slice(array_unique($cards), 0, 3))).(count(array_unique($cards)) > 3 ? ' and more' : '');
                $problems[] = ($set === '' ? 'Cards from an unknown set' : "Cards from {$set}")." are not in the {$this->label} library: {$list}.";
            }
        }

        return array_slice($problems, 0, self::MAX_PROBLEMS);
    }

    /**
     * Rule 903.3 and 903.5c: a legendary creature as commander, and every
     * card within its color identity.
     *
     * @param Deck                    $deck
     * @param callable(string): array $card
     *
     * @return string[]
     */
    private function commanderProblems(Deck $deck, callable $card): array
    {
        if ($deck->commander === null) {
            return ["A {$this->label} deck needs a commander: pick a legendary creature with `/decks commander`."];
        }
        $commander = $card($deck->commander);
        $problems = [];
        if (! self::canBeCommander($commander)) {
            $problems[] = "**{$commander['name']}** cannot be a commander: it is not a legendary creature.";
        }
        $identity = ColorIdentity::of($commander);
        $outside = [];
        foreach ($deck->allCards() as $key => $count) {
            $data = $card((string) $key);
            if (array_diff(ColorIdentity::of($data), $identity) !== []) {
                $outside[] = (string) $data['name'];
            }
        }
        if ($outside !== []) {
            $outside = array_values(array_unique($outside));
            $list = implode(', ', array_map(fn ($name) => "**{$name}**", array_slice($outside, 0, 3))).(count($outside) > 3 ? ' and more' : '');
            $problems[] = "Cards outside **{$commander['name']}**'s colors (".ColorIdentity::describe($identity)."): {$list}.";
        }

        return $problems;
    }

    /**
     * Whether a card can be a commander: a legendary creature, or a card
     * that says it can be (rule 903.3).
     *
     * @param array $card
     *
     * @return bool
     */
    public static function canBeCommander(array $card): bool
    {
        $type = (string) ($card['type'] ?? '');

        return (str_contains($type, 'Legendary') && str_contains($type, 'Creature'))
            || str_contains((string) ($card['text'] ?? ''), 'can be your commander');
    }

    /**
     * Whether this mode's library has a set's cards.
     *
     * @param string                    $setCode
     * @param callable(string): ?string $releaseDate
     * @param int                       $now
     *
     * @return bool
     */
    public function allowsSet(string $setCode, callable $releaseDate, int $now): bool
    {
        if ($this->sets !== null && ! in_array(strtoupper($setCode), $this->sets, true)) {
            return false;
        }
        if ($this->releasedWithinDays !== null) {
            $date = $setCode === '' ? null : $releaseDate($setCode);
            $released = $date === null ? false : strtotime($date.' 00:00:00 UTC');
            if ($released === false || $released < $now - $this->releasedWithinDays * 86400) {
                return false;
            }
        }

        return true;
    }

    /**
     * The deck rules in one line, for players.
     *
     * @return string
     */
    public function summary(): string
    {
        $main = $this->mainMax === $this->mainMin ? "exactly {$this->mainMin}" : "at least {$this->mainMin}".($this->mainMax !== null ? " and at most {$this->mainMax}" : '');
        $parts = ["main deck {$main} cards"];
        $parts[] = match ($this->sideMax) {
            null => 'any side deck',
            0 => 'no side deck',
            default => "side deck up to {$this->sideMax}",
        };
        $parts[] = match ($this->copies) {
            null => 'any number of copies',
            1 => 'one copy of each card',
            default => "up to {$this->copies} copies of a card",
        };
        if ($this->commander) {
            $parts[] = 'a legendary creature as commander';
        }
        $parts[] = "{$this->life} life";
        if ($this->sets !== null) {
            $parts[] = 'sets '.implode(', ', $this->sets);
        }
        if ($this->releasedWithinDays !== null) {
            $parts[] = 'sets from the last '.round($this->releasedWithinDays / 365, 1).' years';
        }
        if ($this->banned !== []) {
            $parts[] = count($this->banned).' banned';
        }

        return implode(' · ', $parts);
    }
}
