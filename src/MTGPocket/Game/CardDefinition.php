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
        'vigilance', 'haste', 'defender', 'menace', 'indestructible', 'hexproof', 'shroud', 'flash', 'prowess',
        'fear', 'intimidate', 'shadow', 'skulk', 'infect', 'wither', 'devoid', 'changeling',
        'plainswalk', 'islandwalk', 'swampwalk', 'mountainwalk', 'forestwalk', 'exalted', 'persist', 'undying', 'convoke', 'affinity for artifacts', 'rebound', 'cipher',
        'evolve', 'improvise', 'no maximum hand size', 'additional land', 'leyline', 'unleash', 'split second', 'umbra armor', 'totem armor', 'delve', 'any number', 'you have hexproof', 'opponents cast nothing on your turn', 'lands tap for any color', 'sunburst', 'compleated', 'look at top any time', 'top revealed', 'lands from top', 'play from top', 'creatures from top', 'ascend', 'jump-start', 'retrace', 'bargain', 'flanking', 'storm', 'battle cry', 'daybound', 'nightbound', 'exile instead of graveyard', 'exile dying opponent creatures', 'replicate', 'increment', 'enters prepared', 'fuse', "can't attack or block alone", "can't attack alone", "can't block alone", 'soulbond',
    ];

    /**
     * Restrictions kept with a permanent's keywords, from its own text
     * (`This creature can't block.`) or an Aura's (`Enchanted creature can't
     * attack or block.`).
     *
     * @var string[]
     */
    public const array RESTRICTIONS = ["can't attack", "can't block", "doesn't untap", "abilities can't be activated", "can't be blocked", 'attacks each combat if able', "can't be countered"];

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

    /** @var array{count: int, colors: string[], pain?: string[]}|null Its `{T}: Add …` abilities as one source; making a `pain` color costs 1 life. */
    public readonly ?array $manaAbility;

    public readonly bool $entersTapped;

    /** How many +1/+1 counters it enters with, or `X` for the X it was cast with. */
    public readonly int|string $entersWithCounters;

    /** @var array[] What an instant or sorcery does, in order; see {@see TextParser}. Effects marked `kicked` are done only when it was kicked. */
    public readonly array $effects;

    /** @var array<int, array{text: string, effects: array[]}> A modal spell's modes. */
    public readonly array $modes;

    /** @var array{min: int, max: int}|null How many modes are chosen. */
    public readonly ?array $choose;

    /** @var array{mana?: string, life?: int}|null What an opponent pays to target it (rule 702.21). */
    public readonly ?array $ward;

    /** A kicker cost (rule 702.33). */
    public readonly ?string $kicker;

    /** An entwine cost (rule 702.42): every mode for this much more. */
    public readonly ?string $entwine;

    /** How many +1/+1 counters it enters with when kicked. */
    public readonly int $kickerCounters;

    /** A flashback cost (rule 702.34). */
    public readonly ?string $flashback;

    /** A cycling cost (rule 702.29). */
    public readonly ?string $cycling;

    /** What landcycling finds instead of drawing: `basic land` or a basic land type (rule 702.29e). */
    public readonly ?string $cyclingFinds;

    /** An unearth cost (rule 702.84). */
    public readonly ?string $unearth;

    /** @var array<int, array{min: int, keywords: string[]}> A Spacecraft's station thresholds; it is a creature at the highest (rule 702.184). */
    public readonly array $stationBands;

    /** @var array{power: int, toughness: int, keywords: string[]}|null What it gets and has while its controller is at max speed. */
    public readonly ?array $maxSpeed;

    /** @var array{of: string, power: bool, toughness: bool}|null Power and/or toughness equal to how many lands (creatures …) its controller controls. */
    public readonly ?array $countsAs;

    /** @var array<string, string> Other ways to cast it: `dash`, `evoke`, `warp` or `plot` => cost. */
    public readonly array $altCosts;

    /** @var array<int, array{kind: string, amount: int}> Spells its controller casts cost less (`Instant and sorcery spells you cast cost {1} less to cast.`). */
    public readonly array $costReductions;

    /** @var array<string, int> Other counters it enters with (`This artifact enters with three oil counters on it.`). */
    public readonly array $entersWithOther;

    /** @var string[] Keywords it has during its controller's turn (`During your turn, this creature has first strike.`). */
    public readonly array $yourTurnKeywords;

    /** `type` or `color`: what is chosen as it enters (`As this enters, choose a creature type.`). */
    public readonly ?string $chooses;

    /** @var array{cost: string, enchant: string, power: int, toughness: int, keywords: string[]}|null Its bestow cost and what it gives as an Aura (rule 702.103). */
    public readonly ?array $bestow;

    /** @var array<int, array{power: int, toughness: int, keywords: string[], other: bool}> What it gives the creatures its controller controls (`other`: but itself). */
    public readonly array $anthem;

    /** `sacrifice_creature` or `discard`: an additional cost to cast it. */
    public readonly ?string $additionalCost;

    /** How many -1/-1 counters it enters with. */
    public readonly int $entersWithMinusCounters;

    /** @var array{life?: int, lands_min?: int, lands_max?: int, any?: string[]}|null What keeps a land from entering tapped: paying life, or controlling something. */
    public readonly ?array $entersTappedUnless;

    /** @var array{kind: string, cost: string}|null Morph, megamorph or disguise, and the cost to turn it face up (rule 702.37). */
    public readonly ?array $morph;

    /** @var array<int, array{min: int, max: int|null, power: int|null, toughness: int|null, keywords: string[]}> A level up creature's bands (rule 711). */
    public readonly array $levels;

    /** @var array{enchant: string, power: int, toughness: int, keywords: string[]}|null An Aura's restriction and what it gives. */
    public readonly ?array $aura;

    /** @var array{power: int, toughness: int, keywords: string[]}|null What Equipment gives the creature it is attached to. */
    public readonly ?array $equipment;

    /** @var array<int, array{text: string, event: string, effects: array[]}> Triggered abilities; see {@see TextParser}. */
    public readonly array $triggered;

    /** @var array<int, array{text: string, cost: array, effects: array[], sorcery: bool, once: bool}> Activated abilities other than mana abilities, loyalty and Equip included. */
    public readonly array $activated;

    /** @var string[] Rules text the engine does not apply yet, the back face's included. */
    public readonly array $unsupported;

    /** The back face of a transforming or modal double-faced card (rule 712). */
    public readonly ?CardDefinition $back;

    /** A split card (rule 709): its halves are its modes, each with its own cost; see Game::castCost(). */
    public readonly bool $split;

    /** A preparation card's prepare spell: a copy can be cast while the creature is prepared. */
    public readonly ?CardDefinition $prepare;

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

        // Playtest and Un- cards print symbols that are not mana, such as {D} for a land drop: pay their mana value as generic.
        $costUnsupported = [];
        try {
            $cost = array_key_exists('manaCost', $card)
                ? ManaCost::parse($card['manaCost'])
                : ManaCost::generic((int) ($card['manaValue'] ?? 0));
        } catch (\InvalidArgumentException) {
            $cost = ManaCost::generic((int) ($card['manaValue'] ?? 0));
            $costUnsupported[] = "Mana cost {$card['manaCost']} (paid as generic mana)";
        }

        $this->power = self::stat($card['power'] ?? null);
        $this->toughness = self::stat($card['toughness'] ?? null);
        $this->loyalty = self::stat($card['loyalty'] ?? null);
        $this->text = (string) ($card['text'] ?? '');

        $parsed = TextParser::parse($this);
        $this->keywords = $parsed['keywords'];
        $this->manaAbility = $parsed['mana'] ?? self::basicLandMana($this->subtypes);
        $this->entersTapped = $parsed['entersTapped'];
        $this->entersWithCounters = $parsed['counters'];
        // A split card: each half is a mode with its own cost, one of them or (with fuse) both; its mana value is both halves'.
        $right = is_array($card['split'] ?? null) ? new CardDefinition(['uuid' => $this->key.'-right'] + $card['split']) : null;
        $halves = $right !== null && ! $this->isPermanentCard() && ! $right->isPermanentCard() && $parsed['modes'] === [] && $right->modes === []
            && $parsed['effects'] !== [] && $right->effects !== [] && $cost->xCount === 0 && $right->cost->xCount === 0;
        $this->split = $halves;
        $this->cost = $halves ? ManaCost::parse($cost.$right->cost) : $cost;
        if ($halves) {
            $left = (string) $cost;
            $parsed['modes'] = [
                ['text' => explode(' // ', $this->name)[0].': '.self::halfText($this->text), 'effects' => $parsed['effects'], 'cost' => $left],
                ['text' => "{$right->name}: ".self::halfText($right->text), 'effects' => $right->effects, 'cost' => (string) $right->cost],
            ];
            $parsed['choose'] = ['min' => 1, 'max' => in_array('fuse', $parsed['keywords'], true) ? 2 : 1];
            $parsed['effects'] = [];
        } elseif ($right !== null && $right->unsupported === [] && $parsed['unsupported'] === []) {
            // Both halves read, but not as two modes (a modal or X half): the card as a whole isn't.
            $parsed['unsupported'][] = "Split card: {$right->name}";
        }
        if (in_array('fuse', $parsed['keywords'], true) && $right === null) {
            $parsed['unsupported'][] = 'Fuse';
        }
        $parsed['unsupported'] = [...$parsed['unsupported'], ...array_diff($right?->unsupported ?? [], ['Fuse'])];
        $this->effects = $parsed['effects'];
        $this->modes = $parsed['modes'];
        $this->choose = $parsed['choose'];
        $this->ward = $parsed['ward'];
        $this->kicker = $parsed['kicker'];
        $this->entwine = $parsed['entwine'];
        $this->kickerCounters = $parsed['kickerCounters'];
        $this->flashback = $parsed['flashback'];
        $this->cycling = $parsed['cycling'];
        $this->cyclingFinds = $parsed['cyclingFinds'];
        $this->unearth = $parsed['unearth'];
        $this->bestow = $parsed['bestow'];
        $this->chooses = $parsed['chooses'];
        $this->stationBands = $parsed['stationBands'];
        $this->maxSpeed = $parsed['maxSpeed'];
        $this->countsAs = $parsed['countsAs'];
        $this->yourTurnKeywords = $parsed['yourTurnKeywords'];
        $this->entersWithOther = $parsed['otherCounters'];
        $this->costReductions = $parsed['costReductions'];
        $this->altCosts = $parsed['altCosts'];
        $this->entersWithMinusCounters = $parsed['minusCounters'];
        $this->entersTappedUnless = $parsed['tappedUnless'];
        $this->anthem = $parsed['anthem'];
        $this->additionalCost = $parsed['additionalCost'];
        $this->morph = $parsed['morph'];
        $this->levels = $parsed['levels'];
        $this->aura = $parsed['aura'];
        $this->equipment = $parsed['equipment'];
        $this->triggered = $parsed['triggered'];
        $this->back = is_array($card['back'] ?? null) ? new CardDefinition(['uuid' => $this->key] + $card['back']) : null;
        $this->prepare = is_array($card['prepare'] ?? null) ? new CardDefinition(['uuid' => $this->key.'-prepare'] + $card['prepare']) : null;
        $activated = $parsed['activated'];
        $prepareUnsupported = [];
        $entersPrepared = in_array('enters prepared', $parsed['keywords'], true);
        $becomesPrepared = array_filter([...$parsed['triggered'], ...$activated], fn (array $ability) => in_array('prepare', array_column($ability['effects'], 'type'), true)) !== [];
        if ($entersPrepared || $becomesPrepared) {
            // While prepared, its controller may cast a copy of the prepare spell, which unprepares it; see Game::activate().
            if ($this->prepare === null || $this->prepare->modes !== [] || $this->prepare->effects === [] || $this->prepare->cost->xCount > 0) {
                $prepareUnsupported[] = $entersPrepared ? 'CARDNAME enters prepared.' : 'CARDNAME becomes prepared.';
            } else {
                $activated[] = [
                    'text' => "Prepared: cast a copy of {$this->prepare->name}", 'cost' => ['mana' => (string) $this->prepare->cost],
                    'effects' => $this->prepare->effects, 'sorcery' => ! $this->prepare->is('Instant'), 'once' => false, 'prepare' => true,
                ];
            }
        }
        $this->activated = $activated;
        $this->unsupported = [...$costUnsupported, ...$parsed['unsupported'], ...$prepareUnsupported, ...($this->back?->unsupported ?? []), ...($this->prepare?->unsupported ?? [])];
    }

    /**
     * Supertypes, card types and subtypes from a type line such as
     * `Legendary Creature — Elf Druid`.
     *
     * @param string $line
     *
     * @return array{0: string[], 1: string[], 2: string[]}
     */
    /**
     * A split card half's rules text without its fuse line.
     *
     * @param string $text
     *
     * @return string
     */
    private static function halfText(string $text): string
    {
        return trim(preg_replace('/\n?Fuse\b.*$/s', '', $text));
    }

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

    public function isEquipment(): bool
    {
        return $this->is('Artifact') && in_array('Equipment', $this->subtypes, true);
    }

    public function isToken(): bool
    {
        return (bool) ($this->card['token'] ?? false);
    }

    /**
     * What an Aura or Equipment gives the permanent it is attached to.
     *
     * @return array{power: int, toughness: int, keywords: string[]}|null
     */
    public function attachmentBonus(): ?array
    {
        return $this->aura ?? $this->equipment;
    }

    /**
     * The triggered abilities for an event.
     *
     * @param string $event `enters`, `dies`, `attacks`, `combat_damage`, `upkeep` or `end_step`.
     *
     * @return array[]
     */
    public function triggersOn(string $event): array
    {
        return array_values(array_filter($this->triggered, fn (array $ability) => $ability['event'] === $event));
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
     * What a spell of this card does, with the modes chosen and whether it
     * was kicked.
     *
     * @param int[] $modes
     * @param bool  $kicked
     *
     * @return array[]
     */
    public function spellEffects(array $modes = [], bool $kicked = false): array
    {
        $effects = [];
        foreach ($this->effects as $effect) {
            if ((($effect['kicked'] ?? false) && ! $kicked) || (($effect['unkicked'] ?? false) && $kicked)) {
                continue;
            }
            if ($kicked && isset($effect['kickedAmount'])) {
                $effect['amount'] = $effect['kickedAmount'];
            }
            if ($kicked && isset($effect['kickedPT'])) {
                [$effect['power'], $effect['toughness']] = $effect['kickedPT'];
            }
            unset($effect['kicked'], $effect['unkicked'], $effect['kickedAmount'], $effect['kickedPT']);
            $effects[] = $effect;
        }
        sort($modes);
        foreach ($modes as $mode) {
            array_push($effects, ...($this->modes[$mode]['effects'] ?? []));
        }

        return $effects;
    }

    /**
     * Every legal choice of modes, each in order.
     *
     * @return array<int, int[]> Just `[]` for a spell that is not modal.
     */
    public function modeChoices(): array
    {
        return $this->choose === null ? [[]] : self::choices(count($this->modes), $this->choose);
    }

    /**
     * Every set of modes that could be chosen, fewest first.
     *
     * @param int                         $count  How many modes there are.
     * @param array{min: int, max: int, repeat?: bool} $choose
     *
     * @return int[][]
     */
    public static function choices(int $count, array $choose): array
    {
        // `You may choose the same mode more than once.`: every multiset of that size, modes in order.
        if ($choose['repeat'] ?? false) {
            $choices = [[]];
            for ($size = 0; $size < $choose['max']; $size++) {
                $choices = array_merge(...array_map(fn (array $choice) => array_map(fn (int $mode) => [...$choice, $mode], range($choice === [] ? 0 : end($choice), $count - 1)), $choices));
            }

            return $choices;
        }
        $choices = [[]];
        for ($mode = 0; $mode < $count; $mode++) {
            foreach ($choices as $choice) {
                $choices[] = [...$choice, $mode];
            }
        }
        $choices = array_values(array_filter($choices, fn (array $choice) => count($choice) >= $choose['min'] && count($choice) <= $choose['max']));
        usort($choices, fn (array $a, array $b) => [count($a), $a] <=> [count($b), $b]);

        return $choices;
    }

    /**
     * Whether a spell of this card needs targets, an Aura's included.
     *
     * @param int[] $modes
     * @param bool  $kicked
     *
     * @return int The number of targets.
     */
    public function targetCount(array $modes = [], bool $kicked = false): int
    {
        return count($this->targetKinds($modes, $kicked));
    }

    /**
     * What each target of a spell may be, in order.
     *
     * @param int[] $modes
     * @param bool  $kicked
     *
     * @return string[] Target kinds; see {@see Game::isLegalTarget()}.
     */
    public function targetKinds(array $modes = [], bool $kicked = false): array
    {
        if ($this->aura !== null) {
            return [$this->aura['enchant']];
        }

        return array_values(array_map(fn (array $effect) => $effect['target'], array_filter($this->spellEffects($modes, $kicked), fn (array $effect) => isset($effect['target']))));
    }

    /**
     * A level up creature's band for a number of level counters.
     *
     * @param int $level
     *
     * @return array{min: int, max: int|null, power: int|null, toughness: int|null, keywords: string[]}|null
     */
    public function levelBand(int $level): ?array
    {
        foreach ($this->levels as $band) {
            if ($level >= $band['min'] && ($band['max'] === null || $level <= $band['max'])) {
                return $band;
            }
        }

        return null;
    }

    /**
     * Card data for this card face down: a 2/2 creature with no name or
     * text but the cost to turn it face up, and ward {2} for disguise.
     *
     * @return array
     */
    public function faceDownCard(): array
    {
        return [
            'uuid' => $this->key.':face-down',
            'name' => 'Face-down creature',
            'type' => 'Creature',
            'types' => ['Creature'],
            'subtypes' => [],
            'supertypes' => [],
            'colors' => [],
            'manaCost' => null,
            'power' => '2',
            'toughness' => '2',
            'text' => $this->morph === null ? '' : "{$this->morph['cost']}: Turn this permanent face up.".($this->morph['kind'] === 'disguise' ? "\nWard {2}" : ''),
        ];
    }
}
