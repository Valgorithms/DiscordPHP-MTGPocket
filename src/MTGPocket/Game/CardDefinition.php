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

use MTGPocket\Game\Mana\ManaCost;

/**
 * What the rules need to know about a card, read from its pool data: its
 * cost, types, power and toughness, keywords, mana abilities and the
 * spell effects the engine understands.
 *
 * Rules text is read with {@see TextParser}; any part of it the engine
 * does not understand yet is listed in {@see $unsupported} so players can
 * be told, instead of it silently doing nothing.
 *
 * @since 0.3.0
 */
final class CardDefinition
{
    /**
     * Keyword abilities the engine applies.
     *
     * @var string[]
     */
    public const array KEYWORDS = [
        'flying', 'reach', 'first strike', 'double strike', 'deathtouch', 'lifelink', 'trample',
        'vigilance', 'haste', 'defender', 'menace', 'indestructible', 'hexproof', 'shroud', 'flash',
    ];

    public readonly string $key;
    public readonly string $name;
    public readonly ManaCost $cost;
    public readonly string $typeLine;

    /** @var string[] */
    public readonly array $supertypes;

    /** @var string[] */
    public readonly array $types;

    /** @var string[] */
    public readonly array $subtypes;

    /** @var string[] */
    public readonly array $colors;

    public readonly ?int $power;
    public readonly ?int $toughness;
    public readonly ?int $loyalty;
    public readonly string $text;

    /** @var string[] Lowercase, from {@see KEYWORDS}. */
    public readonly array $keywords;

    /** @var array{count: int, colors: string[]}|null A `{T}: Add …` ability. */
    public readonly ?array $manaAbility;

    public readonly bool $entersTapped;

    /** @var array[] What an instant or sorcery does, in order; see {@see TextParser}. */
    public readonly array $effects;

    /** @var array{enchant: string, power: int, toughness: int, keywords: string[]}|null An Aura's restriction and what it gives. */
    public readonly ?array $aura;

    /** @var string[] Rules text the engine does not apply yet. */
    public readonly array $unsupported;

    /**
     * @param array $card Pool card data (see {@see \MTGPocket\Cards\CardPool}) or a basic land's.
     */
    public function __construct(public readonly array $card)
    {
        $this->key = (string) ($card['uuid'] ?? '');
        $this->name = (string) ($card['name'] ?? 'Unknown card');
        $this->typeLine = (string) ($card['type'] ?? '');

        [$supertypes, $types, $subtypes] = self::splitTypeLine($this->typeLine);
        $this->supertypes = isset($card['supertypes']) ? array_values((array) $card['supertypes']) : $supertypes;
        $this->types = isset($card['types']) ? array_values((array) $card['types']) : $types;
        $this->subtypes = isset($card['subtypes']) ? array_values((array) $card['subtypes']) : $subtypes;
        $this->colors = array_values((array) ($card['colors'] ?? []));

        $this->cost = array_key_exists('manaCost', $card)
            ? ManaCost::parse($card['manaCost'])
            : ManaCost::generic((int) ($card['manaValue'] ?? 0));

        $this->power = self::stat($card['power'] ?? null);
        $this->toughness = self::stat($card['toughness'] ?? null);
        $this->loyalty = self::stat($card['loyalty'] ?? null);
        $this->text = (string) ($card['text'] ?? '');

        $parsed = TextParser::parse($this);
        $this->keywords = $parsed['keywords'];
        $this->manaAbility = $parsed['mana'] ?? self::basicLandMana($this->subtypes);
        $this->entersTapped = $parsed['entersTapped'];
        $this->effects = $parsed['effects'];
        $this->aura = $parsed['aura'];
        $this->unsupported = $parsed['unsupported'];
    }

    /**
     * Supertypes, card types and subtypes from a type line such as
     * `Legendary Creature — Elf Druid`.
     *
     * @param string $line
     *
     * @return array{0: string[], 1: string[], 2: string[]}
     */
    public static function splitTypeLine(string $line): array
    {
        [$left, $right] = array_pad(preg_split('/\s+[—-]\s+/u', $line, 2), 2, '');
        $supertypes = $types = [];
        foreach (preg_split('/\s+/', trim($left), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (in_array($word, ['Basic', 'Legendary', 'Snow', 'World', 'Ongoing'], true)) {
                $supertypes[] = $word;
            } else {
                $types[] = $word;
            }
        }

        return [$supertypes, $types, preg_split('/\s+/', trim($right), -1, PREG_SPLIT_NO_EMPTY)];
    }

    /**
     * A power, toughness or loyalty. `*` and other variable values count
     * as 0 until characteristic-defining abilities are applied.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private static function stat(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^[+-]?\d+/', (string) $value, $match) ? (int) $match[0] : 0;
    }

    /**
     * The intrinsic mana ability of basic land types (rule 305.6).
     *
     * @param string[] $subtypes
     *
     * @return array{count: int, colors: string[]}|null
     */
    private static function basicLandMana(array $subtypes): ?array
    {
        $colors = [];
        foreach (['Plains' => 'W', 'Island' => 'U', 'Swamp' => 'B', 'Mountain' => 'R', 'Forest' => 'G'] as $type => $color) {
            if (in_array($type, $subtypes, true)) {
                $colors[] = $color;
            }
        }

        return $colors === [] ? null : ['count' => 1, 'colors' => $colors];
    }

    public function is(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function isLand(): bool
    {
        return $this->is('Land');
    }

    public function isCreature(): bool
    {
        return $this->is('Creature');
    }

    public function isPlaneswalker(): bool
    {
        return $this->is('Planeswalker');
    }

    public function isAura(): bool
    {
        return $this->is('Enchantment') && in_array('Aura', $this->subtypes, true);
    }

    public function isLegendary(): bool
    {
        return in_array('Legendary', $this->supertypes, true);
    }

    /**
     * Instants and sorceries; everything else is a permanent card (rule 110.4a).
     *
     * @return bool
     */
    public function isPermanentCard(): bool
    {
        return ! $this->is('Instant') && ! $this->is('Sorcery');
    }

    /**
     * Whether it can be cast any time its controller has priority.
     *
     * @return bool
     */
    public function hasInstantSpeed(): bool
    {
        return $this->is('Instant') || $this->hasKeyword('flash');
    }

    public function hasKeyword(string $keyword): bool
    {
        return in_array($keyword, $this->keywords, true);
    }

    /**
     * Whether a spell of this card needs targets, an Aura's included.
     *
     * @return int The number of targets.
     */
    public function targetCount(): int
    {
        if ($this->aura !== null) {
            return 1;
        }

        return count(array_filter($this->effects, fn (array $effect) => isset($effect['target'])));
    }

    /**
     * What each target of a spell may be, in order.
     *
     * @return string[] Target kinds; see {@see Game::isLegalTarget()}.
     */
    public function targetKinds(): array
    {
        if ($this->aura !== null) {
            return [$this->aura['enchant']];
        }

        return array_values(array_map(fn (array $effect) => $effect['target'], array_filter($this->effects, fn (array $effect) => isset($effect['target']))));
    }
}
