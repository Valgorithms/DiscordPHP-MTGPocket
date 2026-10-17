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
 * Reads the parts of a card's rules text the engine applies:
 *
 * - keyword lines, e.g. `Flying, vigilance` ({@see CardDefinition::KEYWORDS}),
 *   with `Ward {2}`, `Crew 3`, `Cycling {2}`, `Flashback {1}{R}`,
 *   `Kicker {2}`, `Morph {3}` (and megamorph and disguise) and `Level up {1}`
 * - mana abilities: `{T}: Add {G}.`, `{T}: Add {W} or {U}.`, `{T}: Add {C}{C}.`,
 *   `{T}: Add one mana of any color.`, several on one permanent, and the
 *   painful ones (`… CARDNAME deals 1 damage to you.`, `{T}, Pay 1 life: …`)
 * - `This land enters tapped.` and `… enters with N +1/+1 counters on it.`
 * - an Aura's `Enchant …` line and `Enchanted creature gets +N/+N` / `has …` /
 *   `can't attack or block` / `doesn't untap …`, and the same bonuses for
 *   Equipment (`Equipped creature …`) with `Equip {N}`
 * - `CARDNAME can't block.` and the like ({@see CardDefinition::RESTRICTIONS})
 * - the most common effects: damage, card draw, life gain and loss,
 *   destroy, exile, return to hand, counter, tap and untap, +N/+N until end
 *   of turn, +1/+1 counters, creature tokens, scry, surveil and regenerate
 * - modal spells: `Choose one —`, `Choose two —`, `Choose one or both —` and
 *   `Choose one or more —` with a `•` line per mode
 * - `If this spell was kicked, …` on a spell, and `When this creature enters,
 *   if it was kicked, …`
 * - those effects as triggered abilities (`When CARDNAME enters, …`,
 *   `… dies, …`, `Whenever CARDNAME attacks, …`, `… deals combat damage to a
 *   player, …`, `When CARDNAME is turned face up, …`, `At the beginning of
 *   your upkeep / end step, …`, `Whenever you cast a noncreature spell, …`),
 *   as activated abilities (`{2}, {T}, Sacrifice CARDNAME: …`) and as a
 *   planeswalker's loyalty abilities (`+1: …`)
 * - level up creatures' `LEVEL N-M` bands: their power, toughness and keywords
 *
 * An ability is applied whole or not at all: if any sentence of it is not
 * understood, the line is reported as unsupported.
 *
 * @since 0.3.0
 */
final class TextParser
{
    private const array NUMBERS = [
        'a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];

    /**
     * Target phrases => target kinds.
     */
    public const array TARGETS = [
        'any target' => 'any',
        'target creature or player' => 'any',
        'target creature' => 'creature',
        // Not the source itself; see Game::trigger().
        'another target creature' => 'creature_other',
        'target player' => 'player',
        'target opponent' => 'opponent',
        'target creature or planeswalker' => 'creature_or_planeswalker',
        'target player or planeswalker' => 'player_or_planeswalker',
        'target opponent or planeswalker' => 'player_or_planeswalker',
        'target artifact' => 'artifact',
        'target enchantment' => 'enchantment',
        'target artifact or enchantment' => 'artifact_or_enchantment',
        'target artifact or creature' => 'artifact_or_creature',
        'target artifact creature' => 'artifact_creature',
        'target artifact or land' => 'artifact_or_land',
        'target nonblack creature' => 'creature_nonblack',
        'target nonartifact, nonblack creature' => 'creature_nonartifact_nonblack',
        'target nonartifact creature' => 'creature_nonartifact',
        'target land' => 'land',
        'target planeswalker' => 'planeswalker',
        'target nonland permanent' => 'nonland_permanent',
        'target nonland permanent an opponent controls' => 'nonland_permanent_opponent',
        'target nonland permanent you don\'t control' => 'nonland_permanent_opponent',
        'target artifact or creature an opponent controls' => 'artifact_or_creature_opponent',
        'target artifact, creature, or enchantment an opponent controls' => 'artifact_creature_enchantment_opponent',
        'target artifact, enchantment, or creature with flying' => 'artifact_enchantment_flier',
        'target permanent' => 'permanent',
        'target creature you control' => 'creature_you_control',
        'target creature an opponent controls' => 'creature_opponent',
        'target creature you don\'t control' => 'creature_opponent',
        'target creature or planeswalker an opponent controls' => 'creature_or_planeswalker_opponent',
        'target creature or planeswalker you don\'t control' => 'creature_or_planeswalker_opponent',
        'target attacking or blocking creature' => 'attacking_or_blocking',
        'target attacking creature' => 'attacking',
        'target blocking creature' => 'blocking',
        'target creature with flying' => 'creature_flying',
        'target creature without flying' => 'creature_no_flying',
        'target tapped creature' => 'tapped_creature',
        'target untapped creature' => 'untapped_creature',
        'target creature with power 2 or greater' => 'creature_power_ge_2',
        'target creature with power 3 or greater' => 'creature_power_ge_3',
        'target creature with power 4 or greater' => 'creature_power_ge_4',
        'target creature with power 5 or greater' => 'creature_power_ge_5',
        'target creature with power 6 or greater' => 'creature_power_ge_6',
        'target creature with power 1 or less' => 'creature_power_le_1',
        'target creature with power 2 or less' => 'creature_power_le_2',
        'target creature with power 3 or less' => 'creature_power_le_3',
        'target creature with power 4 or less' => 'creature_power_le_4',
        'target creature with mana value 1 or less' => 'creature_mv_le_1',
        'target creature with mana value 2 or less' => 'creature_mv_le_2',
        'target creature with mana value 3 or less' => 'creature_mv_le_3',
        'target creature with mana value 4 or less' => 'creature_mv_le_4',
        'target creature with mana value 5 or less' => 'creature_mv_le_5',
        'target nonwhite creature' => 'creature_noncolor_W',
        'target nonblue creature' => 'creature_noncolor_U',
        'target nonred creature' => 'creature_noncolor_R',
        'target nongreen creature' => 'creature_noncolor_G',
        'target white creature' => 'creature_color_W',
        'target blue creature' => 'creature_color_U',
        'target black creature' => 'creature_color_B',
        'target red creature' => 'creature_color_R',
        'target green creature' => 'creature_color_G',
        'target non-zombie creature' => 'creature_nonsub_Zombie',
        'target non-human creature' => 'creature_nonsub_Human',
        'target non-vampire creature' => 'creature_nonsub_Vampire',
        'target non-angel creature' => 'creature_nonsub_Angel',
        'target non-dragon creature' => 'creature_nonsub_Dragon',
        'target non-elf creature' => 'creature_nonsub_Elf',
        'target non-goblin creature' => 'creature_nonsub_Goblin',
        'target non-wall creature' => 'creature_nonsub_Wall',
        'target creature that isn\'t enchanted' => 'creature_unenchanted',
        'target creature card from your graveyard' => 'creature_card_yours',
        'target legendary creature card from your graveyard' => 'legendary_creature_card_yours',
        'target creature card with mana value 1 or less from your graveyard' => 'creature_card_yours_mv_le_1',
        'target creature card with mana value 2 or less from your graveyard' => 'creature_card_yours_mv_le_2',
        'target creature card with mana value 3 or less from your graveyard' => 'creature_card_yours_mv_le_3',
        'target creature card with mana value 4 or less from your graveyard' => 'creature_card_yours_mv_le_4',
        'target creature card with mana value 5 or less from your graveyard' => 'creature_card_yours_mv_le_5',
        'target creature card with mana value 6 or less from your graveyard' => 'creature_card_yours_mv_le_6',
        'target creature card from a graveyard' => 'creature_card_graveyard',
        'target card from a graveyard' => 'card_graveyard',
        'target card from your graveyard' => 'card_yours',
        'target instant or sorcery card from your graveyard' => 'instant_sorcery_card_yours',
        'target artifact card from your graveyard' => 'artifact_card_yours',
        'target permanent card from your graveyard' => 'permanent_card_yours',
        'target land card from your graveyard' => 'land_card_yours',
        'target enchantment card from your graveyard' => 'enchantment_card_yours',
        'target card in a graveyard other than a basic land card' => 'card_graveyard_nonbasic',
        'target nonbasic land' => 'nonbasic_land',
        'target spell' => 'spell',
        'target creature spell' => 'creature_spell',
        'target noncreature spell' => 'noncreature_spell',
        'target instant or sorcery spell' => 'instant_sorcery_spell',
        'target instant or sorcery spell you control' => 'instant_sorcery_spell_yours',
    ];

    /**
     * Enchant lines => what an Aura may be attached to.
     */
    private const array ENCHANT = [
        'creature' => 'creature',
        'land' => 'land',
        'artifact' => 'artifact',
        'enchantment' => 'enchantment',
        'permanent' => 'permanent',
        'creature you control' => 'creature_you_control',
        'creature an opponent controls' => 'creature_opponent',
        'creature you don\'t control' => 'creature_opponent',
        'creature or planeswalker' => 'creature_or_planeswalker',
        'planeswalker' => 'planeswalker',
        'nonland permanent' => 'nonland_permanent',
        'artifact or creature' => 'artifact_or_creature',
        'land you control' => 'land_you_control',
        'creature or vehicle' => 'creature_or_vehicle',
        'player' => 'player',
        'opponent' => 'opponent',
        'tapped creature' => 'tapped_creature',
        'untapped creature' => 'untapped_creature',
        'creature with flying' => 'creature_flying',
        'creature without flying' => 'creature_no_flying',
        'artifact creature' => 'artifact_creature',
        'nonbasic land' => 'nonbasic_land',
        'artifact or enchantment' => 'artifact_or_enchantment',
        'artifact or land' => 'artifact_or_land',
        'nonland permanent an opponent controls' => 'nonland_permanent_opponent',
        'nonland permanent you don\'t control' => 'nonland_permanent_opponent',
        'creature or planeswalker an opponent controls' => 'creature_or_planeswalker_opponent',
        'creature or planeswalker you don\'t control' => 'creature_or_planeswalker_opponent',
        'artifact or creature an opponent controls' => 'artifact_or_creature_opponent',
    ];

    /** Predefined artifact tokens (rule 111.10). */
    private const array ARTIFACT_TOKENS = [
        'Food' => ['name' => 'Food', 'type' => 'Token Artifact — Food', 'types' => ['Artifact'], 'subtypes' => ['Food'], 'colors' => [], 'text' => '{2}, {T}, Sacrifice this token: You gain 3 life.', 'manaCost' => null],
        'Clue' => ['name' => 'Clue', 'type' => 'Token Artifact — Clue', 'types' => ['Artifact'], 'subtypes' => ['Clue'], 'colors' => [], 'text' => '{2}, Sacrifice this token: Draw a card.', 'manaCost' => null],
        'Treasure' => ['name' => 'Treasure', 'type' => 'Token Artifact — Treasure', 'types' => ['Artifact'], 'subtypes' => ['Treasure'], 'colors' => [], 'text' => '{T}, Sacrifice this token: Add one mana of any color.', 'manaCost' => null],
        'Blood' => ['name' => 'Blood', 'type' => 'Token Artifact — Blood', 'types' => ['Artifact'], 'subtypes' => ['Blood'], 'colors' => [], 'text' => '{1}, {T}, Discard a card, Sacrifice this token: Draw a card.', 'manaCost' => null],
        'Lander' => ['name' => 'Lander', 'type' => 'Token Artifact — Lander', 'types' => ['Artifact'], 'subtypes' => ['Lander'], 'colors' => [], 'text' => '{2}, {T}, Sacrifice this token: Search your library for a basic land card, put it onto the battlefield tapped, then shuffle.', 'manaCost' => null],
        'Map' => ['name' => 'Map', 'type' => 'Token Artifact — Map', 'types' => ['Artifact'], 'subtypes' => ['Map'], 'colors' => [], 'text' => '{1}, {T}, Sacrifice this token: Target creature you control explores. Activate only as a sorcery.', 'manaCost' => null],
        'Junk' => ['name' => 'Junk', 'type' => 'Token Artifact — Junk', 'types' => ['Artifact'], 'subtypes' => ['Junk'], 'colors' => [], 'text' => '{T}, Sacrifice this token: Exile the top card of your library. You may play that card this turn. Activate only as a sorcery.', 'manaCost' => null],
    ];

    /** Target kinds that are always a creature. */
    private const array CREATURE_KINDS = [
        'creature', 'creature_other', 'creature_you_control', 'creature_opponent', 'attacking_or_blocking', 'attacking', 'blocking',
        'creature_flying', 'creature_no_flying', 'tapped_creature', 'untapped_creature',
        'creature_power_ge_2', 'creature_power_ge_3', 'creature_power_ge_4', 'creature_power_ge_5', 'creature_power_ge_6', 'creature_power_le_1', 'creature_power_le_2', 'creature_power_le_3', 'creature_power_le_4',
        'creature_mv_le_1', 'creature_mv_le_2', 'creature_mv_le_3', 'creature_mv_le_4', 'creature_mv_le_5',
        'creature_noncolor_W', 'creature_noncolor_U', 'creature_noncolor_R', 'creature_noncolor_G', 'creature_unenchanted',
        'creature_color_W', 'creature_color_U', 'creature_color_B', 'creature_color_R', 'creature_color_G',
        'creature_nonsub_Zombie', 'creature_nonsub_Human', 'creature_nonsub_Vampire', 'creature_nonsub_Angel', 'creature_nonsub_Dragon', 'creature_nonsub_Elf', 'creature_nonsub_Goblin', 'creature_nonsub_Wall',
    ];

    /**
     * `Choose …` => the least and most modes chosen (null: as many as there are).
     */
    private const array CHOOSE = [
        'one' => [1, 1],
        'two' => [2, 2],
        'three' => [3, 3],
        'one or both' => [1, 2],
        'one or more' => [1, null],
    ];

    /** A mana cost, e.g. `{2}{G/U}`. */
    private const string COST = '((?:\{[0-9WUBRGCSX\/P]+\})+)';

    /**
     * A predefined artifact token: `Food`, `Clue` or `Treasure`.
     *
     * @param string $name
     *
     * @return array
     */
    public static function artifactToken(string $name): array
    {
        return self::ARTIFACT_TOKENS[$name];
    }

    /**
     * @param CardDefinition $card With its name, types and text set.
     *
     * @return array{keywords: string[], mana: array|null, entersTapped: bool, counters: int, effects: array[], modes: array[], choose: array|null, aura: array|null, equipment: array|null, triggered: array[], activated: array[], ward: array|null, kicker: string|null, kickerCounters: int, flashback: string|null, cycling: string|null, morph: array|null, levels: array[], unsupported: string[]}
     */
    public static function parse(CardDefinition $card): array
    {
        $result = [
            'keywords' => [], 'mana' => null, 'entersTapped' => false, 'counters' => 0, 'effects' => [], 'modes' => [], 'choose' => null,
            'aura' => null, 'equipment' => null, 'triggered' => [], 'activated' => [],
            'ward' => null, 'kicker' => null, 'kickerCounters' => 0, 'entwine' => null, 'flashback' => null, 'cycling' => null, 'cyclingFinds' => null, 'unearth' => null, 'bestow' => null, 'chooses' => null, 'stationBands' => [], 'maxSpeed' => null, 'countsAs' => null, 'yourTurnKeywords' => [], 'otherCounters' => [], 'costReductions' => [], 'altCosts' => [], 'morph' => null, 'levels' => [],
            'minusCounters' => 0, 'tappedUnless' => null, 'anthem' => [], 'additionalCost' => null,
            'unsupported' => [],
        ];
        $spell = ! $card->isPermanentCard();
        $lines = self::lines($card);
        $classLevel = 1;
        // `Kicker {G} and/or {1}{U}`: read as one kicker paying both, so either kicker's `if it was kicked with its {G} kicker` happens.
        foreach ($lines as $index => $line) {
            if (preg_match('/^Kicker '.self::COST.' and\/or '.self::COST.'$/', $line, $both)) {
                $lines[$index] = "Kicker {$both[1]}{$both[2]}";
                $lines = array_map(fn (string $other) => (string) preg_replace('/ (was|were) kicked with (?:its|their) (?:\{[^ ]+\}|both) kickers?/', ' $1 kicked', $other), $lines);
            }
        }

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Any other triggered ability with modes: `Landfall — Whenever a land you control enters, choose one —`.
            if (! $spell && preg_match('/^(.+), choose (one|two|three|one or both|one or more) —$/u', $line, $match)
                && ! preg_match('/^(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks)$/', $match[1])) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    $bullets[] = trim(mb_substr($lines[++$i], 1));
                }
                $probe = $result;
                $modes = array_map(fn (string $bullet) => ['text' => $bullet, 'effects' => self::effects($bullet)], $bullets);
                [$min, $max] = self::CHOOSE[$match[2]];
                if (count($bullets) >= 2 && ! in_array(null, array_column($modes, 'effects'), true) && $min <= count($modes)
                    && self::triggered($match[1].', you gain 1 life.', $probe) && count($probe['triggered']) === count($result['triggered']) + 1) {
                    $ability = end($probe['triggered']);
                    $result['triggered'][] = [
                        'text' => implode("\n", [$line, ...array_map(fn ($bullet) => "• {$bullet}", $bullets)]),
                        'effects' => [],
                        'modes' => $modes,
                        'choose' => ['min' => $min, 'max' => min($max ?? count($modes), count($modes))],
                    ] + $ability;
                } else {
                    $unread = array_values(array_filter($bullets, fn (string $bullet) => self::effects($bullet) === null));
                    $result['unsupported'] = [...$result['unsupported'], ...($unread === [] ? [$line] : array_map(fn ($bullet) => "• {$bullet}", $unread))];
                }

                continue;
            }
            // Teamwork's `Choose one. If this spell was cast using teamwork, choose both instead.`: entwined by tapping the team.
            if ($spell && isset($result['altCosts']['teamwork']) && preg_match('/^Choose one\. If CARDNAME was cast using teamwork, choose both instead\.?$/', $line)) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    // `• Street Justice — Exile …`: the mode's name is flavor.
                    $bullets[] = preg_replace('/^[A-Z][\w\'’ ]* — /u', '', trim(mb_substr($lines[++$i], 1)));
                }
                if (count($bullets) === 2 && self::modes($spell, 'one', $bullets, $result)) {
                    $result['entwine'] = '';
                } else {
                    $result['unsupported'][] = $line;
                }

                continue;
            }
            // Mystic Confluence: `Choose three. You may choose the same mode more than once.`; see CardDefinition::choices().
            if ($spell && preg_match('/^Choose (two|three|four|five)\. You may choose the same mode more than once\.$/', $line, $match)) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    $bullets[] = trim(mb_substr($lines[++$i], 1));
                }
                if (self::modes($spell, 'one', $bullets, $result)) {
                    $n = self::amount($match[1]);
                    $result['choose'] = ['min' => $n, 'max' => $n, 'repeat' => true];
                } else {
                    $unread = array_values(array_filter($bullets, fn (string $bullet) => self::effects($bullet) === null));
                    $result['unsupported'] = [...$result['unsupported'], ...($unread === [] ? [$line] : array_map(fn ($bullet) => "• {$bullet}", $unread))];
                }

                continue;
            }
            // A modal spell's `Choose one —` and its `•` modes, or a triggered ability's `When CARDNAME enters, choose one —`.
            if (preg_match('/^(?:(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks), )?[Cc]hoose (one|two|three|one or both|one or more) —$/u', $line, $match)) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    $bullets[] = trim(mb_substr($lines[++$i], 1));
                }
                $read = $match[1] === ''
                    ? self::modes($spell, $match[2], $bullets, $result)
                    : ! $spell && self::modalTrigger(['enters the battlefield' => 'enters', 'enters' => 'enters', 'dies' => 'dies', 'attacks' => 'attacks'][$match[1]], $line, $match[2], $bullets, $result);
                if (! $read) {
                    // Each mode not read yet, so the coverage report counts them one by one.
                    $unread = array_values(array_filter($bullets, fn (string $bullet) => self::effects($bullet) === null));
                    $result['unsupported'] = [...$result['unsupported'], ...($unread === [] ? [$line] : array_map(fn ($bullet) => "• {$bullet}", $unread))];
                }

                continue;
            }

            // A level up creature's `LEVEL 2-6`, then its power and toughness, then its abilities at that level.
            if (preg_match('/^LEVEL (\d+)(?:-(\d+)|\+)$/', $line, $match)) {
                $band = ['min' => (int) $match[1], 'max' => isset($match[2]) ? (int) $match[2] : null, 'power' => null, 'toughness' => null, 'keywords' => []];
                if (isset($lines[$i + 1]) && preg_match('/^(\d+)\/(\d+)$/', $lines[$i + 1], $stats)) {
                    [$band['power'], $band['toughness']] = [(int) $stats[1], (int) $stats[2]];
                    $i++;
                }
                while (isset($lines[$i + 1]) && ! preg_match('/^LEVEL /', $lines[$i + 1])) {
                    $keywords = self::keywordList(str_replace(',', ', ', $lines[++$i]));
                    if ($keywords === null) {
                        $result['unsupported'][] = "{$line}: {$lines[$i]}";
                    } else {
                        $band['keywords'] = [...$band['keywords'], ...$keywords];
                    }
                }
                $result['levels'][] = $band;

                continue;
            }

            // A Class's `{2}{G}: Level 2`, then the abilities it gains at that level (rule 716).
            if (! $spell && in_array('Class', $card->subtypes, true) && preg_match('/^((?:\{[0-9WUBRGC\/]+\})+): Level (\d+)$/', $line, $match)) {
                $classLevel = (int) $match[2];
                $result['activated'][] = ['text' => $line, 'cost' => ['mana' => $match[1]], 'effects' => [['type' => 'class_level', 'amount' => $classLevel, 'self' => true]], 'sorcery' => true, 'once' => false, 'fromLevel' => $classLevel - 1];

                continue;
            }
            // Spree (rule 702.172): `+ {1} — …` modes, each an additional cost; one or more of them.
            // Tiered: `• Insight — {1} — …` modes, each an additional cost; exactly one of them.
            if ($spell && preg_match('/^(Spree|Tiered)\.?$/', $line, $kind)) {
                [$modes, $read, $tiered] = [[], true, $kind[1] === 'Tiered'];
                while (isset($lines[$i + 1]) && preg_match($tiered ? '/^• [^—]+ — '.self::COST.' — (.+)$/u' : '/^\+ '.self::COST.' — (.+)$/u', $lines[$i + 1], $match)) {
                    $i++;
                    if (str_contains($match[1], 'X') || ($effects = self::effects($match[2])) === null) {
                        $result['unsupported'][] = $lines[$i];
                        $read = false;
                    } else {
                        $modes[] = ['text' => $match[2], 'effects' => $effects, 'cost' => $match[1]];
                    }
                }
                if (! $read) {
                    continue;
                }
                if (count($modes) >= 2 && $result['modes'] === []) {
                    $result['modes'] = $modes;
                    $result['choose'] = ['min' => 1, 'max' => $tiered ? 1 : count($modes)];
                } else {
                    $result['unsupported'][] = $kind[1];
                }

                continue;
            }
            // Outlast (rule 702.107): `{cost}, {T}: Put a +1/+1 counter on CARDNAME. Activate only as a sorcery.`
            if (preg_match('/^Outlast '.self::COST.'$/', $line, $match)) {
                $line = "{$match[1]}, {T}: Put a +1/+1 counter on CARDNAME. Activate only as a sorcery.";
            }
            // `{2}{B}: Return CARDNAME from your graveyard to your hand.`: see Game::regrow().
            // Also `… to the battlefield tapped.` (Haunted Dead, Tunnel Rats).
            if (preg_match('/^'.self::COST.': Return CARDNAME from your graveyard to (your hand|the battlefield(?: tapped)?)\.$/', $line, $match) && ! str_contains($match[1], 'X')) {
                $result['altCosts']['regrow'] = $match[1];
                if ($match[2] !== 'your hand') {
                    $result['altCosts']['regrowTo'] = str_replace(['the ', ' '], ['', '_'], $match[2]);
                }

                continue;
            }
            // Static lines that work like keywords.
            $statics = [
                'You have no maximum hand size.' => 'no maximum hand size', 'Skip your draw step.' => 'skip your draw step', 'You may play an additional land on each of your turns.' => 'additional land', 'You may play lands from your graveyard.' => 'lands from graveyard',
                'If CARDNAME is in your opening hand, you may begin the game with it on the battlefield.' => 'leyline',
                'A deck can have any number of cards named CARDNAME.' => 'any number',
                'You have hexproof.' => 'you have hexproof',
                // Read by Game::whyNotCast().
                "Your opponents can't cast spells during your turn." => 'opponents cast nothing on your turn',
                // Chromatic Lantern; read by Game::manaSources().
                'Lands you control have "{T}: Add one mana of any color."' => 'lands tap for any color',
                'You may look at the top card of your library any time.' => 'look at top any time',
                // Shown on the board; see MatchMessageBuilder::side().
                'Play with the top card of your library revealed.' => 'top revealed',
                // Read by Game::playableFromTop().
                'You may play lands from the top of your library.' => 'lands from top',
                'You may play lands and cast spells from the top of your library.' => 'play from top',
                'You may cast creature spells from the top of your library.' => 'creatures from top',
                // Daybound sets it to day as it enters when it is neither (rule 702.145); see Game::dayNight().
                "If it's neither day nor night, it becomes day as CARDNAME enters." => 'daybound',
                'If CARDNAME would be put into a graveyard from anywhere, exile it instead.' => 'exile instead of graveyard',
                // A preparation card; see CardDefinition::$prepare.
                'CARDNAME enters prepared.' => 'enters prepared',
                // Both halves of a split card at once; see CardDefinition::$split.
                'Fuse' => 'fuse', 'Fuse.' => 'fuse',
                // Converge: counters for each color spent, as sunburst does for a creature.
                'Converge — CARDNAME enters with a +1/+1 counter on it for each color of mana spent to cast it.' => 'sunburst',
                // Read by Game::moveTo().
                'If a creature an opponent controls would die, exile it instead.' => 'exile dying opponent creatures',
                'CARDNAME escapes with a +1/+1 counter on it.' => 'escapes with 1',
                'CARDNAME escapes with two +1/+1 counters on it.' => 'escapes with 2',
                'CARDNAME escapes with three +1/+1 counters on it.' => 'escapes with 3',
                'CARDNAME escapes with four +1/+1 counters on it.' => 'escapes with 4',
                // Read by Game::canBlock().
                "Creatures with power less than CARDNAME's power can't block it." => "can't be blocked by lesser power",
                // Read ahead (rule 714.3a): the controller may start a Saga on a later chapter; Game always starts it on chapter I, which is one of the legal choices.
                'Read ahead' => 'read ahead',
                // Rule 502.3: untapping it anyway is always one of the legal choices, and Game always does.
                'You may choose not to untap CARDNAME during your untap step.' => 'may not untap',
                // Read by Game::declareAttackers() and Game::declareBlockers().
                "CARDNAME can't attack or block alone." => "can't attack or block alone",
                "CARDNAME can't attack alone." => "can't attack alone",
                "CARDNAME can't block alone." => "can't block alone",
                // Read by Game::gainLife().
                "Players can't gain life." => "players can't gain life",
                "Your opponents can't gain life." => "opponents can't gain life",
            ];
            if (! $spell && isset($statics[$line])) {
                $result['keywords'][] = $statics[$line];

                continue;
            }
            // Wild Growth / Fertile Ground: `Whenever enchanted land is tapped for mana, its controller adds an additional {G}.`; see Game::manaSources().
            if (! $spell && ($result['aura']['enchant'] ?? null) !== null && preg_match('/^Whenever enchanted land is tapped for mana, its controller adds an additional ((?:\{[WUBRGC]\})+|one mana of any colou?r)\.$/', $line, $match)) {
                preg_match_all('/\{([WUBRGC])\}/', $match[1], $symbols);
                $result['keywords'][] = 'enchanted land adds '.($symbols[1] === [] ? 'any' : implode('', $symbols[1]));

                continue;
            }
            // `If you would gain life, you gain that much life plus 1 instead.`: see Game::gainLife().
            if (! $spell && preg_match('/^If you would gain life, you gain that much life plus (\w+) instead\.$/', $line, $match) && is_int($n = self::amount($match[1]))) {
                $result['keywords'][] = "gain life plus {$n}";

                continue;
            }
            // `Spells your opponents cast that target CARDNAME cost {2} more to cast.`: ward's cost without the counter; see Game::wardCost().
            if (! $spell && $result['ward'] === null && preg_match('/^Spells your opponents cast that target CARDNAME cost ((?:\{\d+\})+) more to cast\.$/', $line, $match)) {
                $result['ward'] = ['mana' => $match[1], 'tax' => true];

                continue;
            }
            // A characteristic-defining ability (rule 604.3): `CARDNAME's power and toughness are each equal to the number of lands you control.`
            if (! $spell && preg_match("/^CARDNAME's (power and toughness are each|power is|toughness is) equal to the number of (?:(lands|creatures|artifacts|enchantments|Forests|Islands|Mountains|Plains|Swamps) you control|cards in your (hand|graveyard)|(creature) cards in your graveyard)\\.$/", $line, $match)) {
                $of = match (true) {
                    ($match[4] ?? '') !== '' => 'creature cards in graveyard',
                    ($match[3] ?? '') !== '' => $match[3],
                    default => strtolower($match[2]),
                };
                $result['countsAs'] = ['of' => $of, 'power' => $match[1] !== 'toughness is', 'toughness' => $match[1] !== 'power is'];

                continue;
            }
            // `CARDNAME gets +1/+1 for each artifact you control.`: read by Game::scaling().
            if (! $spell && preg_match('/^CARDNAME gets \+(\d+)\/\+(\d+) for each (artifact|creature|other creature|land|enchantment) you control\.$/', $line, $match)) {
                $result['keywords'][] = "gets +{$match[1]}/+{$match[2]} for each {$match[3]}";

                continue;
            }
            // `CARDNAME gets +2/+0 for each Mountain you control.`, `… for each other Elf you control.`, `… for each Aura attached to it.`
            if (! $spell && preg_match('/^CARDNAME gets \+(\d+)\/\+(\d+) for each (?:(other )?([A-Z][a-z]+) you control|(Aura|Equipment|Aura and Equipment) attached to it)\.$/', $line, $match)) {
                $result['keywords'][] = "gets +{$match[1]}/+{$match[2]} for each ".(($match[5] ?? '') !== '' ? 'attached:'.str_replace(' and ', '|', $match[5]) : $match[3].'sub:'.$match[4]);

                continue;
            }
            // `CARDNAME costs {1} less to cast for each creature in your party.`: read by Game::paymentFor().
            if (preg_match('/^(?:This spell|CARDNAME) costs \{(\d+)\} less to cast for each (creature in your party|artifact you control|creature you control|land you control|card in your hand|opponent you have|instant and sorcery card in your graveyard|creature card in your graveyard|card in your graveyard)\.$/', $line, $match)) {
                $result['keywords'][] = "costs {$match[1]} less for each ".match ($match[2]) {
                    'creature in your party' => 'party',
                    'opponent you have' => 'opponent',
                    'card in your hand' => 'card',
                    'instant and sorcery card in your graveyard' => 'graveyard_spell',
                    'creature card in your graveyard' => 'graveyard_creature',
                    'card in your graveyard' => 'graveyard_card',
                    default => strtok($match[2], ' '),
                };

                continue;
            }
            // `CARDNAME costs {1} less to cast if you control a Wizard.`
            if (preg_match('/^(?:This spell|CARDNAME) costs \{(\d+)\} less to cast if you control an? ([A-Z][a-z]+|artifact|enchantment|legendary creature)\.$/', $line, $match)) {
                $result['keywords'][] = "costs {$match[1]} less if you control {$match[2]}";

                continue;
            }
            if (preg_match('/^(?:This spell|CARDNAME) costs \{(\d+)\} less to cast if it targets a tapped creature\.$/', $line, $match)) {
                $result['keywords'][] = "costs {$match[1]} less if it targets a tapped creature";

                continue;
            }
            $kinds = ['' => 'any', 'Instant and sorcery ' => 'instant_sorcery', 'Creature ' => 'creature', 'Noncreature ' => 'noncreature', 'Artifact ' => 'artifact', 'Enchantment ' => 'enchantment'];
            if (! $spell && preg_match('/^(Instant and sorcery |Creature |Noncreature |Artifact |Enchantment )?spells you cast cost \{(\d+)\} less to cast\.$/', $line, $match)) {
                $result['costReductions'][] = ['kind' => $kinds[$match[1]], 'amount' => (int) $match[2]];

                continue;
            }
            // The Medallions and `Dragon spells you cast cost {1} less to cast.`
            if (! $spell && preg_match('/^(White|Blue|Black|Red|Green|[A-Z][a-z]+) spells you cast cost \{(\d+)\} less to cast\.$/', $line, $match)
                && ($kind = ['White' => 'color_W', 'Blue' => 'color_U', 'Black' => 'color_B', 'Red' => 'color_R', 'Green' => 'color_G'][$match[1]] ?? (($type = self::subtypeOf($match[1])) !== null ? "subtype_{$type}" : null)) !== null) {
                $result['costReductions'][] = ['kind' => $kind, 'amount' => (int) $match[2]];

                continue;
            }
            if (! $spell && preg_match('/^During your turn, CARDNAME has (.+?)\.$/', $line, $match) && ($keywords = self::keywordList($match[1])) !== null) {
                $result['yourTurnKeywords'] = [...$result['yourTurnKeywords'], ...$keywords];

                continue;
            }
            if (! $spell && preg_match('/^Max speed — (.+)$/u', $line, $match)) {
                $before = $result;
                if (preg_match('/^CARDNAME (?:gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))?|has (.+?))\.?$/', $match[1], $has)
                    && ($keywords = self::keywordList(($has[3] ?? '') !== '' ? $has[3] : ($has[4] ?? ''))) !== null) {
                    $result['maxSpeed'] = ['power' => (int) ($has[1] ?? 0), 'toughness' => (int) ($has[2] ?? 0), 'keywords' => $keywords];
                } elseif (self::triggered($match[1], $result) || self::activated($match[1], $result) || self::anthem($match[1], $result)) {
                    foreach (['triggered', 'activated', 'anthem'] as $key) {
                        foreach (array_slice(array_keys($result[$key]), count($before[$key])) as $index) {
                            $result[$key][$index]['maxSpeed'] = true;
                        }
                    }
                } else {
                    $result = $before;
                    $result['unsupported'][] = $line;
                }

                continue;
            }
            // A Spacecraft's `12+ | Flying, lifelink`: what it has with that many charge counters.
            if (! $spell && preg_match('/^(\d+)\+ \| (.+)$/', $line, $match)) {
                $before = $result;
                if (($keywords = self::keywordList(rtrim($match[2], '.'))) !== null) {
                    $result['stationBands'][] = ['min' => (int) $match[1], 'keywords' => $keywords];
                } elseif (self::triggered($match[2], $result) || self::activated($match[2], $result) || self::anthem($match[2], $result)) {
                    foreach (['triggered', 'activated', 'anthem'] as $key) {
                        foreach (array_slice(array_keys($result[$key]), count($before[$key])) as $index) {
                            $result[$key][$index]['charge'] = (int) $match[1];
                        }
                    }
                    $result['stationBands'][] = ['min' => (int) $match[1], 'keywords' => []];
                } else {
                    $result = $before;
                    $result['unsupported'][] = $line;
                }

                continue;
            }
            if ($classLevel > 1) {
                $before = $result;
                if (! $spell && (self::triggered($line, $result) || self::activated($line, $result) || self::anthem($line, $result))) {
                    foreach (['triggered', 'activated', 'anthem'] as $key) {
                        foreach (array_slice(array_keys($result[$key]), count($before[$key])) as $index) {
                            $result[$key][$index]['classLevel'] = $classLevel;
                        }
                    }
                } else {
                    $result = $before;
                    $result['unsupported'][] = $line;
                }

                continue;
            }

            // Cleave (rule 702.148): the whole text, or without the words in square brackets when cast for its cleave cost (read as kicked; see Game::cast()).
            if ($spell && isset($result['altCosts']['cleave']) && str_contains($line, '[')
                && ($whole = self::effects(str_replace(['[', ']'], '', $line))) !== null
                && ($cleaved = self::effects((string) preg_replace(['/\s*\[[^\]]*\]/', '/\s+([.,])/'], ['', '$1'], $line))) !== null) {
                foreach ($whole as $effect) {
                    $result['effects'][] = $effect + ['unkicked' => true];
                }
                foreach ($cleaved as $effect) {
                    $result['effects'][] = $effect + ['kicked' => true];
                }

                continue;
            }
            if (self::keywords($line, $result, $spell)
                || self::manaAbility($line, $result)
                || self::entersTapped($line, $result)
                || (! $spell && self::restriction($line, $result))
                || (! $spell && self::anthem($line, $result))
                || self::additionalCost($line, $result)
                || (($card->isAura() || $result['bestow'] !== null) && self::aura($line, $result))
                || ($card->isEquipment() && self::equipment($line, $result))
                || ($card->isPlaneswalker() && self::loyalty($line, $result))
                || (in_array('Saga', $card->subtypes, true) && self::chapter($line, $result))
                || (! $spell && self::triggered($line, $result))
                || ((! $spell || str_contains($line, 'Discard CARDNAME:') || str_starts_with($line, 'Reinforce ')) && self::activated($line, $result))) {
                continue;
            }

            if (! $spell) {
                $result['unsupported'][] = $line;

                continue;
            }

            if (($impulse = self::impulse($line)) !== null) {
                $result['effects'][] = $impulse;

                continue;
            }
            // Haste Magic: other sentences, then the impulse.
            if (preg_match('/^(.+?\.)\s+(Exile the top .*)$/s', $line, $split) && ($impulse = self::impulse($split[2])) !== null && ($before = self::effects($split[1])) !== null) {
                array_push($result['effects'], ...$before, ...[$impulse]);

                continue;
            }
            if (($look = self::look($line)) === null && preg_match('/^(.+?\.)\s+((?:Look at|Reveal) the top .*)$/s', $line, $split)
                && ($later = self::look($split[2])) !== null && ($before = self::effects($split[1])) !== null) {
                array_push($result['effects'], ...$before);
                $look = $later;
            }
            if ($look !== null) {
                [$result['effects'][], $line] = $look;
                if ($line === '') {
                    continue;
                }
            }
            // A haunt spell's `When the creature CARDNAME haunts dies, …`, from exile.
            if (str_starts_with($line, 'When the creature CARDNAME haunts dies, ') && self::triggered($line, $result)) {
                continue;
            }
            // Tail Swipe: `Choose target creature you control and target creature you don't control. [The creature you control gets …] Then those creatures fight each other.`
            if (preg_match("/^Choose target creature you control and target creature you (?:don't|do not) control\\.\\s*(.*?)\\s*(?:Then )?(?:[Tt]hose creatures|[Tt]hey) fight each other\\.$/", $line, $fight)) {
                $middle = [];
                foreach (self::sentences($fight[1]) as $sentence) {
                    $found = self::sentenceEffects(preg_replace('/\\bthe creature you control\\b/i', 'target creature you control', $sentence));
                    if ($found === null || array_filter($found, fn (array $effect) => ($effect['target'] ?? null) !== 'creature_you_control') !== []) {
                        $middle = null;
                        break;
                    }
                    foreach ($found as $effect) {
                        unset($effect['target']);
                        $middle[] = $effect + ['sameTarget' => true];
                    }
                }
                if ($middle !== null) {
                    array_push($result['effects'], ['type' => 'chosen', 'target' => 'creature_you_control'], ...$middle, ...[['type' => 'fight', 'target' => 'creature_opponent', 'mutual' => true]]);

                    continue;
                }
            }
            // `Kicker—Sacrifice an artifact or creature.`: paid like bargain, by sacrificing one; see Game::bargainFodder().
            if (preg_match('/^Kicker—Sacrifice an? (artifact|creature|enchantment)(?: or (artifact|creature|enchantment))?\.$/u', $line, $sacrifice)) {
                $result['keywords'][] = 'bargain';
                $result['altCosts']['bargain'] = ucfirst($sacrifice[1]).(isset($sacrifice[2]) ? '|'.ucfirst($sacrifice[2]) : '');

                continue;
            }
            // Snuff Out: `If you control a Swamp, you may pay 4 life rather than pay CARDNAME's mana cost.`, and casting free if you control a Dragon.
            if (preg_match("/^(?:If you control an? ([A-Z][a-z]+), you may (?:pay (\\w+) life rather than pay CARDNAME's mana cost|cast CARDNAME without paying its mana cost)|You may cast CARDNAME without paying its mana cost if you control an? ([A-Z][a-z]+))\\.$/", $line, $pitch)
                && (($pitch[2] ?? '') === '' || is_int(self::amount($pitch[2])))) {
                $result['altCosts']['conditional'] = '';
                $result['keywords'][] = 'conditional control '.(($pitch[1] ?? '') !== '' ? $pitch[1] : $pitch[3]);
                if (($pitch[2] ?? '') !== '') {
                    $result['keywords'][] = 'conditional life '.self::amount($pitch[2]);
                }

                continue;
            }
            // Strive: `{2}{R}` more for each target beyond the first; see Game::paymentFor().
            if (preg_match('/^Strive — CARDNAME costs '.self::COST.' more to cast for each target beyond the first\.$/u', $line, $strive)) {
                $result['altCosts']['strive'] = $strive[1];

                continue;
            }
            if (($reveal = self::revealDiscard($line)) !== null) {
                [$result['effects'][], $line] = $reveal;
            }
            foreach (self::sentences($line) as $sentence) {
                if ($sentence === "CARDNAME can't be countered") {
                    $result['keywords'][] = "can't be countered";
                } elseif (preg_match("/^You may pay ".self::COST." rather than pay CARDNAME's mana cost$/", $sentence, $mastery)) {
                    // An alternative cost (rule 118.9), cast like surge; "If the {M} cost was paid" reads as kicked.
                    $result['altCosts']['mastery'] = $mastery[1];
                } elseif ($sentence === 'Exile CARDNAME') {
                    // The spell goes to exile instead of its owner's graveyard as it finishes resolving.
                    $result['effects'][] = ['type' => 'exile_spell'];
                } elseif (! self::kickedSentence($sentence, $result) && ! self::modifies($sentence, $result['effects'])) {
                    $effects = self::sentenceEffects($sentence);
                    if ($effects === null) {
                        $result['unsupported'][] = $sentence.'.';
                    } else {
                        array_push($result['effects'], ...$effects);
                    }
                }
            }
        }

        if ($card->isAura() && $result['aura'] === null) {
            $result['unsupported'][] = 'Enchant …';
        }
        // Bestowed, it is an Aura with enchant creature (rule 702.103); otherwise a creature.
        if ($result['bestow'] !== null) {
            $result['bestow'] += ($result['aura'] ?? []) + ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['bestow']['enchant'] = 'creature';
            $result['aura'] = null;
        }
        // Backup grants only keywords; other abilities it would grant are not read yet.
        if (in_array('backup', $result['keywords'], true) && (count($result['triggered']) > 1 || $result['activated'] !== [] || $result['anthem'] !== [] || in_array('prowess', $result['keywords'], true))) {
            $result['unsupported'][] = 'Backup N';
        }
        if (in_array('prowess', $result['keywords'], true)) {
            $result['triggered'][] = ['text' => 'Prowess', 'event' => 'cast_noncreature', 'effects' => [['type' => 'pump', 'power' => 1, 'toughness' => 1, 'keywords' => [], 'self' => true]]];
        }

        return $result;
    }

    /**
     * A modal spell's modes, each read whole.
     *
     * @param bool     $spell
     * @param string   $choose  `one`, `two`, `one or both` …
     * @param string[] $bullets
     * @param array    $result
     *
     * @return bool
     */
    private static function modes(bool $spell, string $choose, array $bullets, array &$result): bool
    {
        if (! $spell || $result['modes'] !== [] || count($bullets) < 2) {
            return false;
        }
        $modes = [];
        foreach ($bullets as $bullet) {
            $effects = self::effects($bullet);
            if ($effects === null) {
                return false;
            }
            $modes[] = ['text' => $bullet, 'effects' => $effects];
        }
        [$min, $max] = self::CHOOSE[$choose];
        if ($min > count($modes)) {
            return false;
        }
        $result['modes'] = $modes;
        $result['choose'] = ['min' => $min, 'max' => min($max ?? count($modes), count($modes))];

        return true;
    }

    /**
     * A triggered ability with modes, such as `When CARDNAME enters, choose
     * one —`.
     *
     * @param string   $event
     * @param string   $line
     * @param string   $choose
     * @param string[] $bullets
     * @param array    $result
     *
     * @return bool
     */
    private static function modalTrigger(string $event, string $line, string $choose, array $bullets, array &$result): bool
    {
        if (count($bullets) < 2) {
            return false;
        }
        $modes = [];
        foreach ($bullets as $bullet) {
            if (($effects = self::effects($bullet)) === null) {
                return false;
            }
            $modes[] = ['text' => $bullet, 'effects' => $effects];
        }
        [$min, $max] = self::CHOOSE[$choose];
        if ($min > count($modes)) {
            return false;
        }
        $result['triggered'][] = [
            'text' => implode("\n", [$line, ...array_map(fn ($bullet) => "• {$bullet}", $bullets)]),
            'event' => $event,
            'effects' => [],
            'modes' => $modes,
            'choose' => ['min' => $min, 'max' => min($max ?? count($modes), count($modes))],
        ];

        return true;
    }

    /**
     * `If this spell was kicked, draw a card.` adds an effect done only
     * when kicked; `… it deals 5 damage instead.` raises the damage before it.
     *
     * @param string $sentence
     * @param array  $result
     *
     * @return bool
     */
    private static function kickedSentence(string $sentence, array &$result): bool
    {
        $unkicked = false;
        if (isset($result['altCosts']['gift']) && preg_match("/^If the gift (was|wasn't) promised, (.+)$/", $sentence, $gift)) {
            [$unkicked, $match] = [$gift[1] !== 'was', [$gift[0], $gift[2]]];
        } elseif (preg_match('/^If (?:an? ([A-Z][a-z]+) card was revealed|you revealed an? ([A-Z][a-z]+) card(?: or controlled an? \\2)?) as you cast CARDNAME, (.+)$/', $sentence, $reveal)
            && in_array('reveal '.($reveal[1] !== '' ? $reveal[1] : $reveal[2]), $result['keywords'], true)) {
            // The reveal reads as kicked; `or controlled a Dragon` too (Game::revealed()).
            if (str_contains($sentence, 'or controlled')) {
                $result['keywords'][] = 'reveal or control';
            }
            $match = [$reveal[0], $reveal[3]];
        } elseif (preg_match("/^If CARDNAME's (surge|spectacle) cost was paid, (.+)$/", $sentence, $paid) && isset($result['altCosts'][$paid[1]])
            || isset($result['altCosts']['mastery']) && preg_match('/^If the (\\S+) cost was paid, (.+)$/', $sentence, $paid) && $paid[1] === $result['altCosts']['mastery']) {
            // Surge and spectacle read as kicked (see Game::cast()).
            $match = [$paid[0], $paid[2]];
        } elseif (($result['kicker'] === null && ! isset($result['altCosts']['bargain']) || ! preg_match('/^If CARDNAME was kicked, (.+)$/', $sentence, $match))
            && (! in_array('bargain', $result['keywords'], true) || ! preg_match('/^If CARDNAME was bargained, (.+)$/', $sentence, $match))
            && (! isset($result['altCosts']['evidence']) || ! preg_match('/^If evidence was collected, (.+)$/', $sentence, $match))) {
            return false;
        }
        if (preg_match('/^(?:it|CARDNAME) deals (\w+) damage(?: to (?:that|this) [\w ]+)? instead$/', $match[1], $instead)) {
            $last = array_key_last($result['effects']);
            if ($last === null || $result['effects'][$last]['type'] !== 'damage' || ($amount = self::amount($instead[1])) === null) {
                return false;
            }
            $result['effects'][$last]['kickedAmount'] = $amount;

            return true;
        }
        // Stomped by the Foot: `If this spell was kicked, that creature gets -5/-5 until end of turn instead.`
        if (preg_match('/^(?:that creature|it) gets ([+-]\d+)\/([+-]\d+) until end of turn instead$/', $match[1], $instead)) {
            $last = array_key_last($result['effects']);
            if ($last === null || $result['effects'][$last]['type'] !== 'pump' || ! isset($result['effects'][$last]['target'])) {
                return false;
            }
            $result['effects'][$last]['kickedPT'] = [(int) $instead[1], (int) $instead[2]];

            return true;
        }
        // `Draw a card. If evidence was collected, draw two cards instead.`: the earlier effect only when not kicked.
        if (! $unkicked && preg_match('/^(.+) instead$/', $match[1], $instead) && ($last = array_key_last($result['effects'])) !== null
            && ($alt = self::effects(ucfirst($instead[1]).'.')) !== null && count($alt) === 1 && $alt[0]['type'] === $result['effects'][$last]['type']
            && ! isset($result['effects'][$last]['kicked']) && ! isset($result['effects'][$last]['unkicked'])) {
            $result['effects'][$last]['unkicked'] = true;
            $result['effects'][] = $alt[0] + ['kicked' => true];

            return true;
        }
        $effects = self::effects($match[1]) ?? self::thatCreature(preg_replace('/^that creature also /', 'that creature ', $match[1]), $result['effects']);
        if ($effects === null) {
            return false;
        }
        foreach ($effects as $effect) {
            $result['effects'][] = $effect + [$unkicked ? 'unkicked' : 'kicked' => true];
        }

        return true;
    }

    /**
     * The text's abilities, one per line, with the card's own name as
     * `CARDNAME` and reminder text removed.
     *
     * @param CardDefinition $card
     *
     * @return string[]
     */
    private static function lines(CardDefinition $card): array
    {
        $text = $card->text;
        if ($text === '') {
            return [];
        }

        // A double-faced or split card's face is named before ` // `.
        $face = explode(' // ', $card->name)[0];
        $names = array_filter([$card->name, $face, explode(',', $face)[0]], fn ($name) => strlen(trim($name)) > 2);
        foreach (array_unique($names) as $name) {
            $text = str_replace($name, 'CARDNAME', $text);
        }
        $text = preg_replace('/\b[Tt]his ([Ss]pell|creature|land|artifact|enchantment|card|permanent|[Aa]ura|[Ee]quipment|planeswalker|[Vv]ehicle|[Ss]aga|[Cc]lass|[Ss]pacecraft|[Mm]ount|[Bb]attle|[Tt]oken)\b/', 'CARDNAME', $text);
        // A named ability such as `Gae Bolg — Equip {4}` works like the plain one.
        $text = preg_replace('/^[^\n—]+ — (Equip\b)/m', '$1', $text);
        $text = preg_replace('/\s*\([^)]*\)/', '', $text);

        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== ''));
    }

    /**
     * @param string $line
     *
     * @return string[] Without their final periods.
     */
    private static function sentences(string $line): array
    {
        // `tap target creature an opponent controls and put a stun counter on it.`: two sentences, see modifies().
        $line = preg_replace('/\b(tap target [^.]+?) and put a stun counter on it\./i', '$1. Put a stun counter on it.', $line);

        return array_values(array_filter(array_map(fn ($s) => trim($s, " .\t"), preg_split('/(?<=\.)\s+/', $line)), fn ($s) => $s !== ''));
    }

    /**
     * A line of keywords, which may include the ones with a cost or number:
     * `Flying, ward {2}`, `Crew 3`, `Cycling {2}`, `Kicker {1}{G}` …
     *
     * @param string $line
     * @param array  $result
     * @param bool   $spell
     *
     * @return bool
     */
    private static function keywords(string $line, array &$result, bool $spell): bool
    {
        $found = $result;
        // Escape (rule 702.138): `Escape—{4}{B}, Exile five other cards from your graveyard.`
        if (preg_match('/^Escape—'.self::COST.', Exile (\w+) other cards? from your graveyard\.?$/u', $line, $m) && is_int($n = self::amount($m[2])) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['escape'] = $m[1];
            $result['keywords'][] = "escape {$n}";

            return true;
        }
        // Bloodrush (an ability word): `Bloodrush — {R}, Discard CARDNAME: Target attacking creature gets +4/+4 until end of turn.`; see Game::bloodrush().
        if (preg_match('/^Bloodrush — '.self::COST.', Discard CARDNAME: (Target attacking creature gets .+)\.$/u', $line, $m) && ! str_contains($m[1], 'X')
            && ($effects = self::effects($m[2])) !== null && count($effects) === 1 && $effects[0]['type'] === 'pump' && ($effects[0]['target'] ?? null) === 'attacking') {
            $result['altCosts']['bloodrush'] = $m[1];
            $result['altCosts']['bloodrushText'] = $m[2];

            return true;
        }
        // Recover (rule 702.59): see Game::recover().
        if (preg_match('/^Recover '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['recover'] = $m[1];

            return true;
        }
        // Mayhem: cast from your graveyard the turn you discarded it; see Game::whyNotFromGraveyard().
        if (preg_match('/^Mayhem '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['mayhem'] = $m[1];

            return true;
        }
        // Sneak (rule 702.190): cast during the declare blockers step by returning an unblocked attacker; see Game::whyNotCast().
        if (preg_match('/^Sneak '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['sneak'] = $m[1];

            return true;
        }
        // Emerge (rule 702.119): cast for this cost by sacrificing a creature, less that creature's mana value; see Game::emergeFodder().
        if (preg_match('/^Emerge '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['emerge'] = $m[1];

            return true;
        }
        // Miracle (rule 702.94): cast for this cost as the first card its owner draws in a turn; see Game::draw().
        if (preg_match('/^Miracle '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['miracle'] = $m[1];

            return true;
        }
        // Splice onto Arcane (rule 702.47): revealed from hand as an Arcane spell is cast, adding its effects for this cost; see Game::splicers().
        if ($spell && preg_match('/^Splice onto Arcane '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['splice'] = $m[1];

            return true;
        }
        // Harmonize (rule 702.180): cast from your graveyard, tapping a creature for its power off the cost, then exiled.
        if ($spell && preg_match('/^Harmonize '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['harmonize'] = $m[1];

            return true;
        }
        // Disturb (rule 702.146): cast transformed from your graveyard.
        if (preg_match('/^Disturb '.self::COST.'$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['disturb'] = $m[1];

            return true;
        }
        // `Convoke.` and `Rebound.` are written with a period in some sets.
        foreach (array_map('trim', preg_split('/[,;]\s*/', rtrim($line, '.'))) as $part) {
            $word = strtolower($part);
            if (in_array($word, CardDefinition::KEYWORDS, true) && ! in_array($word, ['evolve', 'unleash'], true)) {
                $found['keywords'][] = $word;
            } elseif (preg_match('/^(bushido|toxic|bloodthirst|dredge) (\d+)$/', $word, $m)) {
                // Dredge (rule 702.52): see Game::draw().
                $found['keywords'][] = "{$m[1]} {$m[2]}";
            } elseif (preg_match('/^afflict (\d+)$/', $word, $m) && ! $spell) {
                // Afflict (rule 702.130): the defending player loses N life when it becomes blocked.
                $found['triggered'][] = ['text' => $part, 'event' => 'blocked', 'effects' => [['type' => 'lose_life', 'amount' => (int) $m[1], 'each' => 'opponent']]];
            } elseif (preg_match('/^ward '.self::COST.'$/i', $part, $m)) {
                $found['ward'] = ['mana' => $m[1]];
            } elseif (preg_match('/^ward—pay (\d+) life\.?$/iu', $part, $m)) {
                $found['ward'] = ['life' => (int) $m[1]];
            } elseif (preg_match('/^ward—discard a card\.?$/iu', $part)) {
                $found['ward'] = ['discard' => 1];
            } elseif (preg_match('/^kicker '.self::COST.'$/i', $part, $m)) {
                $found['kicker'] = $m[1];
            } elseif (preg_match('/^multikicker '.self::COST.'$/i', $part, $m)) {
                // Multikicker (rule 702.33c): kicker paid any number of times; see Game::castOptions().
                $found['kicker'] = $m[1];
                $found['keywords'][] = 'multikicker';
            } elseif (preg_match('/^replicate '.self::COST.'\.?$/i', $part, $m) && $spell && ! isset($found['kicker'])) {
                // Replicate (rule 702.56): paid like multikicker, a copy for each payment; see Game::castTriggers().
                $found['kicker'] = $m[1];
                $found['keywords'][] = 'multikicker';
                $found['keywords'][] = 'replicate';
            } elseif (preg_match('/^teamwork (\d+)$/i', $part, $m) && $spell) {
                // Teamwork: tap creatures with that much total power as you cast it; see Game::teamworkFodder().
                $found['altCosts']['teamwork'] = $m[1];
            } elseif (preg_match('/^Affinity for ([A-Z][a-z]+)$/', ucfirst($part), $m) && strtolower($m[1]) !== 'artifacts' && ($type = $m[1] === 'Equipment' ? 'Equipment' : self::subtypeOf($m[1])) !== null) {
                // Affinity for Equipment, Slivers …: {1} less for each one you control; see Game::paymentFor().
                $found['keywords'][] = "affinity for sub:{$type}";
            } elseif (preg_match('/^gift (a card|a food|a treasure|a clue|a tapped fish)$/i', $part, $m)) {
                // Gift (rule 702.174): an opponent gets it as the spell resolves, if it was promised; see Game::giveGift().
                $found['altCosts']['gift'] = substr(strtolower($m[1]), 2);
            } elseif (preg_match('/^entwine '.self::COST.'$/i', $part, $m) && $spell) {
                // Entwine (rule 702.42): every mode, for this much more.
                $found['entwine'] = $m[1];
            } elseif (preg_match('/^flashback '.self::COST.'$/i', $part, $m) && $spell) {
                $found['flashback'] = $m[1];
            } elseif (preg_match('/^bestow '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['bestow'] = ['cost' => $m[1]];
            } elseif (preg_match('/^unearth '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['unearth'] = $m[1];
            } elseif (preg_match('/^scavenge '.self::COST.'$/i', $part, $m) && ! $spell && ! str_contains($m[1], 'X')) {
                // Scavenge (rule 702.97): exile it from your graveyard, as a sorcery, for +1/+1 counters equal to its power.
                $found['altCosts']['scavenge'] = $m[1];
            } elseif (preg_match('/^cycling '.self::COST.'$/i', $part, $m)) {
                $found['cycling'] = $m[1];
            } elseif (preg_match('/^(basic land|plains|island|swamp|mountain|forest)cycling '.self::COST.'$/i', $part, $m)) {
                // Landcycling (rule 702.29e): search for that land instead of drawing.
                $found['cycling'] = $m[2];
                $found['cyclingFinds'] = strtolower($m[1]) === 'basic land' ? 'basic land' : ucfirst(strtolower($m[1]));
            } elseif (strtolower($part) === 'job select' && ! $spell) {
                $found['triggered'][] = ['text' => 'Job select', 'event' => 'enters', 'effects' => [['type' => 'job_select', 'self' => true]]];
            } elseif (preg_match('/^(morph|megamorph|disguise) '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['morph'] = ['kind' => strtolower($m[1]), 'cost' => $m[2]];
            } elseif (preg_match('/^crew (\d+)$/i', $part, $m) && ! $spell) {
                $found['activated'][] = ['text' => $part, 'cost' => ['crew' => (int) $m[1]], 'effects' => [['type' => 'crewed', 'self' => true]], 'sorcery' => false, 'once' => false];
            } elseif (strtolower($part) === 'start your engines!' && ! $spell) {
                // Speed (rule 702.179): see Game::updateSpeed().
                $found['keywords'][] = 'start your engines';
            } elseif (strtolower($part) === 'station' && ! $spell) {
                // Station (rule 702.184): tap another creature for charge counters equal to its power, as a sorcery.
                $found['activated'][] = ['text' => 'Station', 'cost' => ['station' => true], 'effects' => [['type' => 'charge', 'amount' => 0, 'self' => true]], 'sorcery' => true, 'once' => false];
            } elseif (preg_match('/^soulshift (\d+)$/i', $part, $m) && ! $spell) {
                // Soulshift (rule 702.46): when it dies, return a Spirit card with mana value N or less.
                $found['triggered'][] = ['text' => $part, 'event' => 'dies', 'effects' => [['type' => 'bounce', 'target' => "spirit_card_yours_{$m[1]}"]]];
            } elseif (preg_match('/^hideaway (\d+)$/i', $part, $m) && ! $spell) {
                // Hideaway (rule 702.75): one of the top N exiled face down for later; see Game::hideaway().
                $found['triggered'][] = ['text' => $part, 'event' => 'enters', 'effects' => [['type' => 'hideaway', 'amount' => (int) $m[1], 'self' => true]]];
            } elseif (preg_match('/^fabricate (\d+)$/i', $part, $m) && ! $spell) {
                // Fabricate (rule 702.123): always the counters, not the Servos.
                $found['triggered'][] = ['text' => $part, 'event' => 'enters', 'effects' => [['type' => 'counters', 'amount' => (int) $m[1], 'self' => true]]];
            } elseif (preg_match('/^renown (\d+)$/i', $part, $m) && ! $spell) {
                // Renown (rule 702.112): the first time it deals combat damage to a player.
                $found['triggered'][] = ['text' => $part, 'event' => 'combat_damage', 'effects' => [['type' => 'renown', 'amount' => (int) $m[1], 'self' => true]]];
            } elseif (preg_match('/^modular (\d+)$/i', $part, $m) && ! $spell) {
                // Modular (rule 702.43): its +1/+1 counters move to an artifact creature as it dies.
                $found['counters'] = (int) $found['counters'] + (int) $m[1];
                $found['triggered'][] = ['text' => $part, 'event' => 'dies', 'effects' => [['type' => 'counters', 'amount' => 'counters', 'target' => 'artifact_creature']]];
            } elseif ($word === 'evolve' && ! $spell) {
                // Evolve (rule 702.100): see Game::putOntoBattlefield().
                $found['keywords'][] = 'evolve';
                $found['triggered'][] = ['text' => 'Evolve', 'event' => 'evolve', 'effects' => [['type' => 'counters', 'amount' => 1, 'self' => true]]];
            } elseif ($word === 'for mirrodin!' && ! $spell) {
                // For Mirrodin! (rule 702.163): a 2/2 red Rebel token to carry it.
                $found['triggered'][] = ['text' => 'For Mirrodin!', 'event' => 'enters', 'effects' => [['type' => 'for_mirrodin', 'self' => true]]];
            } elseif (preg_match('/^awaken (\d+)—'.self::COST.'$/iu', $part, $m) && $spell) {
                // Awaken (rule 702.113): see Game::awaken().
                $found['altCosts']['awaken'] = $m[2];
                $found['keywords'][] = "awaken {$m[1]}";
            } elseif ($word === 'conspire' && $spell) {
                // Conspire (rule 702.78): see Game::conspirators().
                $found['keywords'][] = 'conspire';
            } elseif (preg_match('/^casualty (\d+)$/', $word, $m) && $spell) {
                // Casualty (rule 702.153): see Game::castOptions().
                $found['keywords'][] = "casualty {$m[1]}";
            } elseif ($word === 'living weapon' && ! $spell) {
                // Living weapon (rule 702.92): a 0/0 Germ token to carry it.
                $found['triggered'][] = ['text' => 'Living weapon', 'event' => 'enters', 'effects' => [['type' => 'living_weapon', 'self' => true]]];
            } elseif ($word === 'extort' && ! $spell) {
                // Extort (rule 702.101): paid whenever its controller can.
                $found['triggered'][] = ['text' => 'Extort', 'event' => 'cast_spell', 'effects' => [['type' => 'extort']]];
            } elseif (preg_match('/^overload '.self::COST.'$/i', $part, $m) && $spell) {
                // Overload (rule 702.96): see Game::castOptions().
                $found['altCosts']['overload'] = $m[1];
            } elseif (preg_match('/^(eternalize|embalm) '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Eternalize and embalm: see Game::embalm().
                $found['altCosts'][strtolower($m[1])] = $m[2];
            } elseif ($word === 'increment' && ! $spell) {
                // Increment: a +1/+1 counter when you cast a spell with more mana than its power or toughness; see Game::castTriggers().
                $found['keywords'][] = 'increment';
            } elseif ($word === 'enlist' && ! $spell) {
                $found['keywords'][] = 'enlist';
            } elseif (preg_match('/^protection from (white|blue|black|red|green|artifacts|creatures|enchantments|instants|sorceries|planeswalkers)$/', $word) && ! $spell) {
                // Protection from a color or a card type (rule 702.16): see Game::protectedFrom().
                $found['keywords'][] = $word;
            } elseif (preg_match('/^[Pp]rotection from ([A-Z][a-z]+s)$/', $part, $m) && ($type = self::subtypeOf($m[1])) !== null && ! $spell) {
                // Haytham Kenway: `Protection from Assassins`; see Game::protectedFrom().
                $found['keywords'][] = "protection from sub:{$type}";
            } elseif (preg_match('/^impending (\d+) ?— ?'.self::COST.'$/iu', $part, $m) && ! $spell) {
                // Impending (rule 702.176): cast for less, a non-creature until its time counters are gone; see Game::isCreature().
                $found['altCosts']['impending'] = $m[2];
                $found['keywords'][] = "impending {$m[1]}";
            } elseif (preg_match('/^prototype '.self::COST.' — (\d+)\/(\d+)$/iu', $part, $m) && ! $spell) {
                // Prototype (rule 702.160): cast smaller for less, see Game::prototype().
                $found['altCosts']['prototype'] = $m[1];
                $found['keywords'][] = "prototype {$m[2]}/{$m[3]}";
            } elseif (preg_match('/^tribute (\d+)$/', $word, $m) && ! $spell) {
                // Tribute (rule 702.104): the opponent never pays it, so `if tribute wasn't paid` always happens.
                $found['keywords'][] = "tribute {$m[1]}";
            } elseif (preg_match('/^buyback '.self::COST.'$/i', $part, $m) && $spell) {
                // Buyback (rule 702.27): an additional cost that returns the spell to its owner's hand.
                $found['altCosts']['buyback'] = $m[1];
            } elseif (preg_match('/^escalate '.self::COST.'$/i', $part, $m) && $spell) {
                // Escalate (rule 702.120): paid for each mode chosen beyond the first; see Game::castCost().
                $found['altCosts']['escalate'] = $m[1];
            } elseif ($word === 'ingest' && ! $spell) {
                // Ingest (rule 702.115): the damaged player exiles the top card of their library.
                $found['triggered'][] = ['text' => 'Ingest', 'event' => 'combat_damage', 'effects' => [['type' => 'ingest']]];
            } elseif (preg_match('/^transmute '.self::COST.'$/i', $part, $m)) {
                // Transmute (rule 702.53): discard it as a sorcery to search for a card with the same mana value; see Game::transmute().
                $found['altCosts']['transmute'] = $m[1];
            } elseif (preg_match('/^(dash|evoke|warp|plot|blitz|freerunning|surge|spectacle|cleave|web-slinging|mutate) '.self::COST.'$/i', $part, $m)) {
                // Other ways to cast it: see Game::castOptions().
                $found['altCosts'][str_replace('-', '', strtolower($m[1]))] = $m[2];
            } elseif (preg_match('/^echo '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Echo (rule 702.30): see Game::applyEffect().
                $found['altCosts']['echo'] = $m[1];
                $found['triggered'][] = ['text' => $part, 'event' => 'upkeep', 'effects' => [['type' => 'echo', 'self' => true]]];
            } elseif ($word === 'haunt') {
                // Haunt (rule 702.55): exiled haunting a creature as it dies (or as the spell resolves); see Game::haunt().
                $found['keywords'][] = 'haunt';
                if (! $spell) {
                    $found['triggered'][] = ['text' => 'Haunt', 'event' => 'dies', 'effects' => [['type' => 'haunt', 'self' => true]]];
                }
            } elseif ($word === 'paradigm' && $spell) {
                // Paradigm: exiled as it resolves, then copied for free each first main phase; see Game::spellLeavesStack().
                $found['keywords'][] = 'paradigm';
            } elseif ($word === 'cascade') {
                // Cascade (rule 702.85): see Game::cascade().
                $found['keywords'][] = 'cascade';
                $found['triggered'][] = ['text' => 'Cascade', 'event' => 'cascade', 'effects' => [['type' => 'cascade']]];
            } elseif (preg_match('/^cumulative upkeep '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Cumulative upkeep (rule 702.24): an age counter each upkeep, then pay for each or sacrifice it.
                $found['altCosts']['cumulative'] = $m[1];
                $found['triggered'][] = ['text' => $part, 'event' => 'upkeep', 'effects' => [['type' => 'cumulative_upkeep', 'self' => true]]];
            } elseif (preg_match('/^foretell '.self::COST.'$/i', $part, $m)) {
                // Foretell (rule 702.143): exiled face down for {2} on your turn, cast on a later turn for this.
                $found['altCosts']['foretell'] = $m[1];
            } elseif (preg_match('/^madness '.self::COST.'$/i', $part, $m)) {
                // Madness (rule 702.35): discarded, it is exiled and may be cast for this while its trigger waits.
                $found['altCosts']['madness'] = $m[1];
                $found['triggered'][] = ['text' => 'Madness', 'event' => 'madness', 'effects' => [['type' => 'madness']]];
            } elseif (preg_match('/^suspend (\d+)—'.self::COST.'$/iu', $part, $m)) {
                // Suspend (rule 702.62): see Game::suspend().
                $found['altCosts']['suspend'] = $m[2];
                $found['keywords'][] = "suspend {$m[1]}";
            } elseif (preg_match('/^ninjutsu '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Ninjutsu (rule 702.49): see Game::ninjutsu().
                $found['altCosts']['ninjutsu'] = $m[1];
            } elseif (preg_match('/^offspring '.self::COST.'$/i', $part, $m) && ! $spell && $found['kicker'] === null) {
                // Offspring (rule 702.175): an optional additional cost, like kicker; a 1/1 token copy when it enters.
                $found['kicker'] = $m[1];
                $found['keywords'][] = 'offspring';
                $found['triggered'][] = ['text' => $part, 'event' => 'enters', 'kicked' => true, 'effects' => [['type' => 'offspring']]];
            } elseif (preg_match('/^afterlife (\d+)$/i', $part, $m) && ! $spell && ($spirit = self::effect('create a 1/1 white and black Spirit creature token with flying')) !== null) {
                // Afterlife (rule 702.135): flying Spirits when it goes to the graveyard from the battlefield.
                $found['triggered'][] = ['text' => $part, 'event' => 'to_graveyard', 'effects' => [['amount' => (int) $m[1]] + $spirit]];
            } elseif (preg_match('/^annihilator (\d+)$/i', $part, $m) && ! $spell) {
                // Annihilator (rule 702.86): the defending player sacrifices that many permanents, their weakest.
                $found['triggered'][] = ['text' => $part, 'event' => 'attacks', 'effects' => [['type' => 'edict', 'amount' => (int) $m[1]]]];
            } elseif (preg_match('/^backup (\d+)$/i', $part, $m) && ! $spell) {
                // Backup (rule 702.165): counters on target creature; another one gains this creature's keywords until end of turn.
                $found['keywords'][] = 'backup';
                $found['triggered'][] = ['text' => $part, 'event' => 'enters', 'effects' => [['type' => 'backup', 'amount' => (int) $m[1], 'target' => 'creature']]];
            } elseif (preg_match('/^mobilize (\d+)$/i', $part, $m) && ! $spell) {
                // Mobilize (rule 702.181): attacking Warrior tokens, sacrificed at the end step.
                $found['triggered'][] = ['text' => $part, 'event' => 'attacks', 'effects' => [['type' => 'mobilize', 'amount' => (int) $m[1]]]];
            } elseif (preg_match('/^firebending (\d+)$/i', $part, $m) && ! $spell) {
                // Firebending (rule 702.188): {R} for each, when it attacks.
                $found['triggered'][] = ['text' => $part, 'event' => 'attacks', 'effects' => [['type' => 'add_mana', 'color' => 'R', 'amount' => (int) $m[1]]]];
            } elseif (preg_match('/^graft (\d+)$/i', $part, $m) && ! $spell) {
                // Graft (rule 702.58): its counters move, one at a time, to your creatures as they enter.
                $found['counters'] = (int) $found['counters'] + (int) $m[1];
                $found['keywords'][] = 'graft';
                $found['triggered'][] = ['text' => $part, 'event' => 'graft', 'effects' => [['type' => 'graft', 'self' => true]]];
            } elseif (preg_match('/^devour (\d+)$/i', $part, $m) && ! $spell) {
                // Devour (rule 702.82): see Game::putOntoBattlefield().
                $found['keywords'][] = "devour {$m[1]}";
            } elseif ($word === 'unleash' && ! $spell) {
                // Unleash (rule 702.98): always with the counter, so it can't block.
                $found['counters'] = (int) $found['counters'] + 1;
                $found['keywords'][] = 'unleash';
            } elseif ($word === 'riot' && ! $spell) {
                // Riot (rule 702.136): always the counter, not haste.
                $found['counters'] = (int) $found['counters'] + 1;
            } elseif (preg_match('/^vanishing (\d+)$/i', $part, $m) && ! $spell) {
                // Vanishing (rule 702.63): time counters, one removed each upkeep; sacrificed when the last goes.
                $found['keywords'][] = "vanishing {$m[1]}";
                $found['triggered'][] = ['text' => $part, 'event' => 'upkeep', 'effects' => [['type' => 'vanishing', 'self' => true]]];
            } elseif ($word === 'mentor' && ! $spell) {
                // Mentor (rule 702.134): a +1/+1 counter on an attacking creature with lesser power.
                $found['triggered'][] = ['text' => 'Mentor', 'event' => 'attacks', 'effects' => [['type' => 'counters', 'amount' => 1, 'target' => 'attacking_lesser']]];
            } elseif ($word === 'training' && ! $spell) {
                // Training (rule 702.149): a +1/+1 counter when it attacks with a creature with greater power.
                $found['triggered'][] = ['text' => 'Training', 'event' => 'attacks', 'effects' => [['type' => 'training', 'self' => true]]];
            } elseif ($word === 'exploit' && ! $spell) {
                // Exploit (rule 702.110): see Game::applyEffect().
                $found['triggered'][] = ['text' => 'Exploit', 'event' => 'enters', 'effects' => [['type' => 'exploit', 'self' => true]]];
            } elseif (preg_match('/^saddle (\d+)$/i', $part, $m) && ! $spell) {
                // Saddle (rule 702.171): like crew, but only as a sorcery, and it stays a creature.
                $found['activated'][] = ['text' => $part, 'cost' => ['crew' => (int) $m[1]], 'effects' => [['type' => 'saddled', 'self' => true]], 'sorcery' => true, 'once' => false];
            } elseif (preg_match('/^craft with (artifact|creature|land|[A-Z][a-z]+) '.self::COST.'$/i', $part, $m) && ! $spell
                && (in_array(strtolower($m[1]), ['artifact', 'creature', 'land'], true) || self::subtypeOf($m[1]) !== null)) {
                // Craft (rule 702.167): exile it and another artifact (yours, or a card from your graveyard), then return it transformed; see Game::craftMaterial().
                $kind = in_array(strtolower($m[1]), ['artifact', 'creature', 'land'], true) ? strtolower($m[1]) : self::subtypeOf($m[1]);
                $found['activated'][] = ['text' => $part, 'cost' => ['mana' => $m[2], 'craft' => $kind], 'effects' => [['type' => 'exile_transformed', 'self' => true]], 'sorcery' => true, 'once' => false];
            } elseif (preg_match('/^level up '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['activated'][] = ['text' => $part, 'cost' => ['mana' => $m[1]], 'effects' => [['type' => 'level', 'self' => true]], 'sorcery' => true, 'once' => false];
            } else {
                return false;
            }
        }
        foreach ($found['altCosts'] as $cost) {
            if (str_contains($cost, 'X')) {
                return false;
            }
        }
        foreach (['kicker', 'entwine', 'flashback', 'cycling', 'morph', 'unearth', 'bestow'] as $cost) {
            if ($found[$cost] !== null && str_contains(is_array($found[$cost]) ? $found[$cost]['cost'] : $found[$cost], 'X')) {
                return false;
            }
        }
        $found['keywords'] = array_values(array_unique($found['keywords']));
        $result = $found;

        return true;
    }

    /**
     * A mana ability, or another one on the same permanent: a painland's
     * `{T}: Add {C}.` and `{T}: Add {R} or {G}. CARDNAME deals 1 damage to
     * you.` make one source of {C}, {R} or {G} that hurts for the colors.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function manaAbility(string $line, array &$result): bool
    {
        // Beastcaller Savant: `{T}: Add one mana of any color. Spend this mana only to cast a creature spell.`; see Game::payFor().
        if (preg_match('/^(.+\.) Spend this mana only to cast (?:an? )?(creature|artifact) spells?\.$/', $line, $match)) {
            if ($result['mana'] !== null) {
                return false;
            }
            if (! self::manaAbility($match[1], $result) || ($result['mana']['noTap'] ?? false) || ($result['mana']['filter'] ?? false)) {
                $result['mana'] = null;

                return false;
            }
            $result['mana']['only'] = ucfirst($match[2]);

            return true;
        }
        // `{2}: Add one mana of any color.` and `{1}: … Activate only once each turn.`: a filter that doesn't tap; see Game::payFor().
        if (preg_match('/^\{(\d)\}: Add one mana of any colou?r\.( Activate only once each turn\.)?$/', $line, $match)) {
            if ($result['mana'] !== null) {
                return false;
            }
            $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true, 'noTap' => true] + ((int) $match[1] !== 1 ? ['filterCost' => (int) $match[1]] : []) + (($match[2] ?? '') !== '' ? ['once' => true] : []);

            return true;
        }
        // The Devotees: `{1}: Add {R}, {W}, or {B}. Activate only once each turn.`
        if (preg_match('/^\{(\d)\}: Add (\{[WUBRG]\}(?:,? (?:or )?\{[WUBRG]\})+)\.( Activate only once each turn\.)?$/', $line, $match)) {
            if ($result['mana'] !== null) {
                return false;
            }
            preg_match_all('/\{([WUBRG])\}/', $match[2], $symbols);
            $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true, 'noTap' => true, 'filterAs' => ['count' => 1, 'colors' => array_values(array_unique($symbols[1]))]]
                + ((int) $match[1] !== 1 ? ['filterCost' => (int) $match[1]] : []) + (($match[3] ?? '') !== '' ? ['once' => true] : []);

            return true;
        }
        if ($result['mana']['noTap'] ?? false) {
            return false;
        }
        // `{1}, {T}: Add one mana of any color.`: see Game::payFor().
        if (preg_match('/^\{1\}, \{T\}: Add one mana of any colou?r\.$/', $line)) {
            if ($result['mana'] === null) {
                $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true];
            } elseif (($result['mana']['filter'] ?? false) || ($result['mana']['sacrifice'] ?? false)) {
                return false;
            } else {
                $result['mana']['filter'] = true;
            }

            return true;
        }
        // A Signet's `{1}, {T}: Add {W}{U}.`: two mana for {1}, see Game::payFor().
        if (preg_match('/^\{1\}, \{T\}: Add \{([WUBRGC])\}\{([WUBRGC])\}\.$/', $line, $match)) {
            $two = ['count' => 2, 'colors' => array_values(array_unique([$match[1], $match[2]])), 'fixed' => [[$match[1]], [$match[2]]]];
            if ($result['mana'] === null) {
                $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true, 'filterAs' => $two];
            } elseif (($result['mana']['filter'] ?? false) || ($result['mana']['sacrifice'] ?? false) || isset($result['mana']['fixed'])) {
                return false;
            } else {
                $result['mana'] += ['filter' => true, 'filterAs' => $two];
            }

            return true;
        }
        // A filter land's `{W/U}, {T}: Add {W}{W}, {W}{U}, or {U}{U}.`: like a Signet, its cost paid with either color.
        if (preg_match('/^\{([WUBRG])\/([WUBRG])\}, \{T\}: Add \{(\w)\}\{(\w)\}, \{(\w)\}\{(\w)\}, or \{(\w)\}\{(\w)\}\.$/', $line, $match)
            && [$match[3], $match[4], $match[5], $match[6], $match[7], $match[8]] === [$match[1], $match[1], $match[1], $match[2], $match[2], $match[2]]) {
            $pair = [$match[1], $match[2]];
            $two = ['count' => 2, 'colors' => $pair, 'fixed' => [$pair, $pair]];
            if ($result['mana'] === null) {
                $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true, 'filterAs' => $two, 'filterPays' => $pair];
            } elseif (($result['mana']['filter'] ?? false) || ($result['mana']['sacrifice'] ?? false) || isset($result['mana']['fixed'])) {
                return false;
            } else {
                $result['mana'] += ['filter' => true, 'filterAs' => $two, 'filterPays' => $pair];
            }

            return true;
        }
        // Topiary Lecturer: `{T}: Add an amount of {G} equal to CARDNAME's power.`; see Game::manaSources().
        if (preg_match("/^\{T\}: Add an amount of \{([WUBRGC])\} equal to CARDNAME's power\.$/", $line, $match) && $result['mana'] === null) {
            $result['mana'] = ['count' => 1, 'colors' => [$match[1]], 'same' => true, 'powerCount' => true];

            return true;
        }
        // Springleaf Drum: `{T}, Tap an untapped creature you control: Add one mana of any color.`; see Game::manaSources().
        if (preg_match('/^\{T\}, Tap an untapped creature you control: Add one mana of any colou?r\.$/', $line)) {
            $any = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];
            if ($result['mana'] === null) {
                $result['mana'] = $any + ['tapCreature' => true];
            } elseif ($result['mana']['count'] === 1 && ! isset($result['mana']['withCreature']) && array_diff(array_keys($result['mana']), ['count', 'colors']) === []) {
                // Scene of the Crime: `{T}: Add {C}.` too, so either.
                $result['mana']['withCreature'] = $any;
            } else {
                return false;
            }

            return true;
        }
        if (! preg_match('/^\{T\}(, Pay 1 life|, Sacrifice CARDNAME|, Remove an? (?:charge|storage|oil) counter from CARDNAME)?: Add (.+?)\.?(?: CARDNAME deals 1 damage to you\.)?$/', $line, $match)) {
            return false;
        }
        $sacrifice = ($match[1] ?? '') === ', Sacrifice CARDNAME';
        // `{T}, Remove a charge counter from CARDNAME: Add one mana of any color.`: see Game::manaSources().
        $counter = preg_match('/Remove an? (\w+) counter/', $match[1] ?? '', $c) ? $c[1] : null;
        $plain = fn (?array $mana) => $mana !== null && $mana['count'] === 1 && array_diff(array_keys($mana), ['count', 'colors']) === [];
        if ($counter !== null && $result['mana'] !== null && ! $plain($result['mana'])) {
            return false;
        }
        $pain = (($match[1] ?? '') !== '' && ! $sacrifice && $counter === null) || str_ends_with($line, 'deals 1 damage to you.');
        $what = $match[2];
        $ability = null;
        if (preg_match('/^one mana of any colou?r$/i', $what)) {
            $ability = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];
        } elseif (preg_match('/^(two|three|four|five) mana of any one colou?r$/i', $what, $n)) {
            // Gilded Lotus: all of one color; see ManaPayer::plan().
            $ability = ['count' => self::amount($n[1]), 'colors' => ['W', 'U', 'B', 'R', 'G'], 'same' => true];
        } elseif (preg_match('/^one mana of the chosen color$/i', $what) && $result['chooses'] === 'color') {
            // See Game::manaSources().
            $ability = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G'], 'chosen' => true];
        } elseif (preg_match('/^(\{[WUBRGC]\})+$/', $what)) {
            preg_match_all('/\{([WUBRGC])\}/', $what, $symbols);
            $ability = count(array_unique($symbols[1])) === 1
                ? ['count' => count($symbols[1]), 'colors' => [$symbols[1][0]]]
                // A bounce land's `{W}{U}`: one of each.
                : ['count' => count($symbols[1]), 'colors' => array_values(array_unique($symbols[1])), 'fixed' => array_map(fn (string $color) => [$color], $symbols[1])];
        } elseif (preg_match('/^\{[WUBRGC]\}(?:,? (?:or )?\{[WUBRGC]\})+$/', $what)) {
            preg_match_all('/\{([WUBRGC])\}/', $what, $symbols);
            $ability = ['count' => 1, 'colors' => array_values(array_unique($symbols[1]))];
        }
        if ($ability === null) {
            return false;
        }
        if ($pain) {
            $ability['pain'] = $ability['colors'];
        }
        if ($counter !== null) {
            // Vivid Crag / Tendo Ice Bridge: `{T}: Add {R}.` as well, so the counter is only spent for the other colors.
            $result['mana'] = $result['mana'] !== null && $ability['count'] === 1 && ! isset($ability['pain'])
                ? $result['mana'] + ['withCounter' => [$counter, $ability['colors']]]
                : $ability + ['counter' => $counter];

            return true;
        }
        if ($sacrifice) {
            // A Treasure: one use.
            if ($result['mana'] !== null) {
                return false;
            }
            $result['mana'] = $ability + ['sacrifice' => true];

            return true;
        }

        $existing = $result['mana'];
        if (($existing['tapCreature'] ?? false) && $ability['count'] === 1 && array_diff(array_keys($ability), ['count', 'colors']) === []) {
            // The creature-tapping ability came first.
            $result['mana'] = $ability + ['withCreature' => array_diff_key($existing, ['tapCreature' => true])];

            return true;
        }
        if ($existing !== null && (isset($existing['tapCreature']) || isset($existing['withCreature']))) {
            return false;
        }
        if ($existing !== null && $existing['count'] === 0) {
            // The filter ability came first.
            $ability += array_intersect_key($existing, ['filter' => true, 'filterAs' => true, 'filterPays' => true]);
            $existing = null;
        }
        if ($existing === null) {
            $result['mana'] = $ability;

            return true;
        }
        if (isset($existing['fixed']) || isset($ability['fixed']) || isset($existing['counter'])) {
            return false;
        }
        // Two abilities of one mana each: one source of either, the painless colors first.
        if ($existing['count'] !== 1 || $ability['count'] !== 1) {
            return false;
        }
        $pain = array_values(array_unique([...($existing['pain'] ?? []), ...($ability['pain'] ?? [])]));
        $colors = array_values(array_unique([...$existing['colors'], ...$ability['colors']]));
        // A color one ability makes without pain is never painful.
        $painless = array_values(array_unique([...array_diff($existing['colors'], $existing['pain'] ?? []), ...array_diff($ability['colors'], $ability['pain'] ?? [])]));
        $pain = array_values(array_diff($pain, $painless));
        usort($colors, fn (string $a, string $b) => in_array($a, $pain, true) <=> in_array($b, $pain, true));
        $result['mana'] = ['count' => 1, 'colors' => $colors] + ($pain === [] ? [] : ['pain' => $pain]) + (($existing['filter'] ?? false) ? ['filter' => true] : []);

        return true;
    }

    private static function entersTapped(string $line, array &$result): bool
    {
        if (preg_match('/^CARDNAME enters(?: the battlefield)? tapped\.?$/', $line)) {
            $result['entersTapped'] = true;

            return true;
        }
        // "As CARDNAME enters, choose a creature type." (rule 614.12): chosen as it enters.
        if (preg_match('/^(CARDNAME enters(?: the battlefield)? tapped\. )?As (?:CARDNAME|it) enters(?: the battlefield)?, choose a (creature type|color)\.?$/', $line, $match)) {
            $result['entersTapped'] = $result['entersTapped'] || $match[1] !== '';
            $result['chooses'] = $match[2] === 'color' ? 'color' : 'type';

            return true;
        }
        if (preg_match('/^As CARDNAME enters(?: the battlefield)?, you may pay (\d+) life\. If you don\'t, it enters(?: the battlefield)? tapped\.?$/', $line, $match)) {
            $result['tappedUnless'] = ['life' => (int) $match[1]];

            return true;
        }
        // Ancient Amphitheater: `As CARDNAME enters, you may reveal a Giant card from your hand. If you don't, CARDNAME enters tapped.`
        if (preg_match('/^As CARDNAME enters(?: the battlefield)?, you may reveal an? ([A-Z][a-z]+) card from your hand\. If you don\'t, (?:CARDNAME|it) enters(?: the battlefield)? tapped\.?$/', $line, $match)) {
            $result['tappedUnless'] = ['reveal' => $match[1]];

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? tapped unless a player has (\d+) or less life\.?$/', $line, $match)) {
            $result['tappedUnless'] = ['player_life' => (int) $match[1]];

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? tapped unless you control (.+?)\.?$/', $line, $match) && ($unless = self::tappedUnless($match[1])) !== null) {
            $result['tappedUnless'] = $unless;

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with (\w+) -1\/-1 counters? on it\.?$/', $line, $match) && is_int($n = self::amount($match[1]))) {
            $result['minusCounters'] = $n;

            return true;
        }
        // Vivid lands: `This land enters tapped with two charge counters on it.`
        if (preg_match('/^CARDNAME enters(?: the battlefield)?( tapped)? with (\w+) (oil|charge|time|lore|loyalty|verse|fade|ice|age|quest|study|storage|page|shield) counters? on it\.?$/', $line, $match) && ($n = self::amount($match[2])) !== null) {
            $result['otherCounters'][$match[3]] = $n;
            $result['entersTapped'] = $result['entersTapped'] || $match[1] !== '';

            return true;
        }
        // Chalice of the Void: `Whenever a player casts a spell with mana value equal to the number of charge counters on CARDNAME, counter that spell.`; see Game::castTriggers().
        if ($line === 'Whenever a player casts a spell with mana value equal to the number of charge counters on CARDNAME, counter that spell.') {
            $result['keywords'][] = 'chalice';

            return true;
        }
        // Raid: `CARDNAME enters with a +1/+1 counter on it if you attacked this turn.`; see Game::resolve().
        if (preg_match('/^Raid — CARDNAME enters(?: the battlefield)? with (an?|\w+) \+1\/\+1 counters? on it if you attacked this turn\.?$/u', $line, $match) && is_int($n = self::amount($match[1]))) {
            $result['keywords'][] = "raid counters {$n}";

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with (\w+) \+1\/\+1 counters? on it\.?$/', $line, $match) && ($n = self::amount($match[1])) !== null) {
            $result['counters'] = $n;

            return true;
        }
        if (in_array('multikicker', $result['keywords'], true) && preg_match('/^CARDNAME enters(?: the battlefield)? with an? \+1\/\+1 counter on it for each time it was kicked\.?$/', $line)) {
            $result['kickerCounters'] = 1;

            return true;
        }
        if ($result['kicker'] !== null && preg_match('/^If CARDNAME was kicked, it enters(?: the battlefield)? with (\w+) (?:additional )?\+1\/\+1 counters? on it\.?$/', $line, $match) && is_int($n = self::amount($match[1]))) {
            $result['kickerCounters'] = $n;

            return true;
        }

        return false;
    }

    /**
     * `Creatures you control get +1/+1.`, `Other creatures you control get
     * +1/+0 and have haste.`: a static bonus to its controller's creatures.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function anthem(string $line, array &$result): bool
    {
        if (preg_match('/^Creatures enchanted player controls get ([+-]\d+)\/([+-]\d+)\.?$/', $line, $m) && in_array($result['aura']['enchant'] ?? null, ['player', 'opponent'], true)) {
            $result['anthem'][] = ['power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => [], 'other' => false, 'enchantedPlayer' => true];

            return true;
        }
        // `Creatures your opponents control get -1/-1.` (see Game::anthems()).
        if (preg_match('/^Creatures your opponents control get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?\.?$/', $line, $m) && ($keywords = self::keywordList($m[3] ?? '')) !== null) {
            $result['anthem'][] = ['power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => $keywords, 'other' => false, 'opponents' => true];

            return true;
        }
        // Soulbond (rule 702.95): `As long as CARDNAME is paired with another creature, each of those creatures gets +1/+1 (and has flying).`
        if (preg_match('/^As long as CARDNAME is paired with another creature, (?:each of those creatures|both creatures) (?:gets? \+(\d+)\/\+(\d+)(?: and ha(?:s|ve) (.+?))?|ha(?:s|ve) (.+?))\.?$/', $line, $m)) {
            if (($keywords = self::keywordList(($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''))) === null) {
                return false;
            }
            $result['anthem'][] = ['power' => (int) ($m[1] ?? 0), 'toughness' => (int) ($m[2] ?? 0), 'keywords' => $keywords, 'other' => false, 'paired' => true];

            return true;
        }
        // `Each creature you control with a +1/+1 counter on it has trample.`
        if (preg_match('/^(?:Each (other )?creature you control with a \+1\/\+1 counter on it has|(Other )?[Cc]reatures you control with \+1\/\+1 counters on them have) (.+?)\.?$/', $line, $m)) {
            if (($keywords = self::keywordList($m[3])) === null) {
                return false;
            }
            $result['anthem'][] = ['power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'other' => $m[1] !== '' || $m[2] !== '', 'withCounter' => true];

            return true;
        }
        // `During your turn, CARDNAME gets +2/+0.` and `As long as …, CARDNAME has flying.`: the same, condition last.
        if (preg_match('/^(?:([A-Z][a-z]+(?: [a-z]+)? — ))?(?:During your turn|As long as (.+?)), (CARDNAME (?:gets|has) .+?)\.$/u', $line, $m)) {
            $line = $m[1].$m[3].' as long as '.($m[2] !== '' ? $m[2] : "it's your turn").'.';
        }
        // Metalcraft, Threshold: `CARDNAME gets +2/+2 as long as you control three or more artifacts.`; see Game::conditionMet().
        if (preg_match('/^(?:[A-Z][a-z]+(?: [a-z]+)? — )?CARDNAME gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))? as long as (.+?)\.$/u', $line, $m)
            && ($keywords = self::keywordList($m[3] ?? '')) !== null && ($if = self::staticCondition($m[4])) !== null) {
            $result['anthem'][] = ['power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => $keywords, 'other' => false, 'self' => true, 'if' => $if];

            return true;
        }
        // `CARDNAME has hexproof as long as it's untapped.`
        if (preg_match('/^(?:[A-Z][a-z]+(?: [a-z]+)? — )?CARDNAME has (.+?) as long as (.+?)\.$/u', $line, $m)
            && ($keywords = self::keywordList($m[1])) !== null && ($if = self::staticCondition($m[2])) !== null) {
            $result['anthem'][] = ['power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'other' => false, 'self' => true, 'if' => $if];

            return true;
        }
        // `Other green creatures you control get +1/+1.` (the Lieges) and `Attacking creatures you control get +1/+0.` (Goblin Oriflamme); see Game::anthems().
        if (preg_match('/^(Other )?(white|blue|black|red|green|[Aa]ttacking|[Aa]rtifact) creatures you control (?:get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?|have (.+?))\.?$/', $line, $m)) {
            $keywords = self::keywordList(($m[6] ?? '') !== '' ? $m[6] : ($m[5] ?? ''));
            if ($keywords === null) {
                return false;
            }
            $which = in_array(strtolower($m[2]), ['attacking', 'artifact'], true) ? [strtolower($m[2]) => true] : ['color' => ['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G'][$m[2]]];
            $result['anthem'][] = ['power' => (int) ($m[3] ?? 0), 'toughness' => (int) ($m[4] ?? 0), 'keywords' => $keywords, 'other' => $m[1] !== ''] + $which;

            return true;
        }
        // The Slivers: `All Sliver creatures get +1/+1.` and `All Slivers have haste.`, every player's; see Game::anthems().
        if (preg_match('/^All ([A-Z][a-z]+)(?: creatures)? (?:get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?|have (.+?))\.?$/', $line, $m)
            && ($type = self::subtypeOf($m[1])) !== null && ($keywords = self::keywordList(($m[5] ?? '') !== '' ? $m[5] : ($m[4] ?? ''))) !== null) {
            $result['anthem'][] = ['power' => (int) ($m[2] ?? 0), 'toughness' => (int) ($m[3] ?? 0), 'keywords' => $keywords, 'other' => false, 'subtype' => $type, 'everyone' => true];

            return true;
        }
        // A lord: `Other Kithkin you control get +1/+1.`, `Elf creatures you control get +1/+1 and have trample.`
        if (preg_match('/^(Other )?([A-Z][a-z]+(?:-[a-z]+)?)(?: creatures)? you control (?:get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?|have (.+?))\.?$/', $line, $m)
            && ($type = self::subtypeOf($m[2])) !== null) {
            $keywords = self::keywordList(($m[6] ?? '') !== '' ? $m[6] : ($m[5] ?? ''));
            if ($keywords === null) {
                return false;
            }
            $result['anthem'][] = ['power' => (int) ($m[3] ?? 0), 'toughness' => (int) ($m[4] ?? 0), 'keywords' => $keywords, 'other' => $m[1] !== '', 'subtype' => $type];

            return true;
        }
        // `Other creatures you control with flying get +1/+1.`
        if (preg_match('/^(Other )?[Cc]reatures you control with (flying|haste|trample|vigilance|deathtouch|lifelink) get ([+-]\d+)\/([+-]\d+)\.?$/', $line, $m)) {
            $result['anthem'][] = ['power' => (int) $m[3], 'toughness' => (int) $m[4], 'keywords' => [], 'other' => $m[1] !== '', 'withKeyword' => $m[2]];

            return true;
        }
        if (preg_match('/^(Other )?[Cc]reatures you control( of the chosen type)? get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?\.?$/', $line, $m)) {
            [$power, $toughness, $keywords] = [(int) $m[3], (int) $m[4], self::keywordList($m[5] ?? '')];
        } elseif (preg_match('/^(Other )?[Cc]reatures you control( of the chosen type)? have (.+?)\.?$/', $line, $m)) {
            [$power, $toughness, $keywords] = [0, 0, self::keywordList($m[3])];
        } else {
            return false;
        }
        if ($keywords === null || ($m[2] !== '' && $result['chooses'] !== 'type')) {
            return false;
        }
        $result['anthem'][] = ['power' => $power, 'toughness' => $toughness, 'keywords' => $keywords, 'other' => $m[1] !== ''] + ($m[2] !== '' ? ['chosenType' => true] : []);

        return true;
    }

    /**
     * A condition of a static ability, as Game::conditionMet() reads it:
     * `you control three or more artifacts`, `seven or more cards are in
     * your graveyard`, `you have 25 or more life`, `it's your turn`.
     *
     * @param string $text
     *
     * @return ?string
     */
    private static function staticCondition(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/^you control (\w+) or more (artifacts|lands|enchantments)$/', $text, $m) && is_int($n = self::amount($m[1])) => rtrim($m[2], 's')."s {$n}",
            (bool) preg_match('/^you control an? (artifact|enchantment)$/', $text, $m) => "{$m[1]}s 1",
            (bool) preg_match('/^(?:(\w+) or more cards are in your graveyard|there are (\w+) or more cards in your graveyard)$/', $text, $m) && is_int($n = self::amount($m[1] !== '' ? $m[1] : $m[2])) => "graveyard {$n}",
            $text === "it's untapped" => 'untapped',
            $text === "it's tapped" => 'tapped',
            (bool) preg_match('/^you have (\w+) or more life$/', $text, $m) && is_int($n = self::amount($m[1])) => "life {$n}",
            $text === "it's your turn" => 'your_turn',
            $text === "it's attacking" => 'attacking',
            // Kird Ape: `as long as you control a Forest`.
            (bool) preg_match('/^you control an? ([A-Z][a-z]+)$/', $text, $m) => "controls {$m[1]}",
            (bool) preg_match('/^there are (\w+) or more card types among cards in your graveyard$/', $text, $m) && is_int($n = self::amount($m[1])) => "delirium {$n}",
            default => null,
        };
    }

    /**
     * A creature type as a lord names it, singular: `Elves` is `Elf`,
     * `Kithkin` stays. Null for words that are not a creature type.
     *
     * @param string $word
     *
     * @return string|null
     */
    private static function subtypeOf(string $word): ?string
    {
        if (in_array($word, ['Other', 'Creatures', 'Artifact', 'Artifacts', 'Enchantment', 'Legendary', 'Token', 'Tokens', 'Nontoken', 'Attacking', 'Blocking', 'Tapped', 'Untapped', 'White', 'Blue', 'Black', 'Red', 'Green', 'Colorless', 'Multicolored', 'Monocolored', 'Snow', 'Land', 'Lands', 'Permanents', 'Equipped', 'Enchanted'], true)) {
            return null;
        }

        return match (true) {
            isset(['Elves' => 1, 'Wolves' => 1, 'Dwarves' => 1][$word]) => substr($word, 0, -3).'f',
            in_array($word, ['Allies', 'Mercenaries'], true) => substr($word, 0, -3).'y',
            str_ends_with($word, 's') && ! str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }

    /**
     * `As an additional cost to cast this spell, sacrifice a creature.`,
     * `… discard a card.` or `… exile a creature card from your graveyard.`
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function additionalCost(string $line, array &$result): bool
    {
        // Collect evidence (rule 701.59): `As an additional cost to cast CARDNAME, you may collect evidence 6.`; reads as kicked (see Game::evidence()).
        if (preg_match('/^As an additional cost to cast CARDNAME, you may collect evidence (\d+)\.$/', $line, $m)) {
            $result['altCosts']['evidence'] = $m[1];

            return true;
        }
        // Dragons of Tarkir: `As an additional cost to cast CARDNAME, you may reveal a Dragon card from your hand.`; revealed whenever one can be (see Game::cast()).
        if (preg_match('/^As an additional cost to cast CARDNAME, you may reveal an? ([A-Z][a-z]+) card from your hand\.$/', $line, $m)) {
            $result['keywords'][] = "reveal {$m[1]}";

            return true;
        }
        // Lorwyn: `As an additional cost to cast CARDNAME, reveal an Elemental card from your hand or pay {3}.`; see Game::orPay().
        if (preg_match('/^As an additional cost to cast CARDNAME, reveal an? ([A-Z][a-z]+) card from your hand or pay ((?:\{[^}]+\})+)\.$/', $line, $m)) {
            array_push($result['keywords'], "reveal or pay {$m[1]}", "or pay {$m[2]}");

            return true;
        }
        if (! preg_match('/^As an additional cost to cast CARDNAME, (sacrifice a creature|sacrifice an artifact or creature|sacrifice an artifact|discard a card|exile a creature card from your graveyard|exile a card from your graveyard)(?: or pay ((?:\{[^}]+\})+))?\.?$/', $line, $m)) {
            return false;
        }
        if (isset($m[2])) {
            $result['keywords'][] = "or pay {$m[2]}";
        }
        $result['additionalCost'] = match ($m[1]) {
            'sacrifice a creature' => 'sacrifice_creature',
            'sacrifice an artifact or creature' => 'sacrifice_artifact_or_creature',
            'sacrifice an artifact' => 'sacrifice_artifact',
            'exile a creature card from your graveyard' => 'exile_creature_card',
            'exile a card from your graveyard' => 'exile_card',
            default => 'discard',
        };

        return true;
    }

    /**
     * What a land needs you to control to enter untapped: `two or more other
     * lands`, `two or fewer other lands`, `a Forest or an Island`, `a basic
     * land`, `a planeswalker`, `a Mount or Vehicle` …
     *
     * @param string $text
     *
     * @return array{lands_min?: int, lands_max?: int, any?: string[]}|null
     */
    private static function tappedUnless(string $text): ?array
    {
        if (preg_match('/^(\w+) or (more|fewer) other lands$/', $text, $match) && is_int($n = self::amount($match[1]))) {
            return [$match[2] === 'more' ? 'lands_min' : 'lands_max' => $n];
        }
        // The Battlebond lands: `… unless you control two or more basic lands.`
        if (preg_match('/^(\w+) or more basic lands$/', $text, $match) && is_int($n = self::amount($match[1]))) {
            return ['basics_min' => $n];
        }
        $any = [];
        foreach (preg_split('/,? or |, /', $text) as $noun) {
            $noun = preg_replace('/^an? /', '', trim($noun));
            if (! preg_match('/^(basic land|legendary creature|[A-Za-z]+)$/', $noun)) {
                return null;
            }
            $any[] = $noun;
        }

        return $any === [] ? null : ['any' => $any];
    }

    /**
     * `can't attack or block, and its activated abilities can't be
     * activated` and the like, as {@see CardDefinition::RESTRICTIONS}.
     *
     * @param string $text
     *
     * @return string[]|null
     */
    private static function restrictions(string $text): ?array
    {
        $text = rtrim(trim($text), '.');
        $found = [];
        if (preg_match('/^(.*?),? and its activated abilities can\'t be activated$/', $text, $match)) {
            $found[] = "abilities can't be activated";
            $text = $match[1];
        }
        if (preg_match('/^(.*?),? and (can\'t be blocked by creatures with power \d+ or (?:greater|less)|can block only creatures with flying)$/', $text, $match)) {
            $text = $match[1];
            $found = [...$found, ...(self::restrictions($match[2]) ?? [null])];
        }
        // The Mare cycle: `can't be blocked by black creatures`; read by Game::canBlock().
        if (preg_match('/^can\'t be blocked by (white|blue|black|red|green|artifact) creatures$/', $text, $match)) {
            return [...$found, "can't be blocked by {$match[1]} creatures"];
        }
        // Juggernaut: `can't be blocked by Walls`.
        if (preg_match('/^can\'t be blocked by ([A-Z][a-z]+s)$/', $text, $match) && ($type = self::subtypeOf($match[1])) !== null) {
            return [...$found, "can't be blocked by sub:{$type}"];
        }
        if (preg_match('/^can\'t be blocked by creatures with power (\d+) or (greater|less)$/', $text, $match)) {
            // Read by Game::canBlock().
            return [...$found, "can't be blocked by power {$match[1]} or {$match[2]}"];
        }
        $found = [...$found, ...match ($text) {
            'can block only creatures with flying' => ['can block only creatures with flying'],
            "can't attack" => ["can't attack"],
            "can't block" => ["can't block"],
            "can't attack or block", "can't attack, block, or crew Vehicles" => ["can't attack", "can't block"],
            "doesn't untap during its controller's untap step", "doesn't untap during your untap step" => ["doesn't untap"],
            "can't be blocked" => ["can't be blocked"],
            "can't be blocked by more than one creature" => ["can't be blocked by more than one creature"],
            // Read by Game::minBlockers().
            "can't be blocked except by two or more creatures" => ['menace'],
            "can't be blocked except by three or more creatures" => ["can't be blocked except by 3 or more creatures"],
            "can't be blocked except by four or more creatures" => ["can't be blocked except by 4 or more creatures"],
            "attacks each combat if able" => ["attacks each combat if able"],
            // Read by Game::declareBlockers().
            'must be blocked if able' => ['must be blocked if able'],
            'can block an additional creature each combat' => ['can block an additional creature each combat'],
            // Read by Game::attackCandidates().
            "can't attack unless defending player controls an Island" => ["can't attack unless defending player controls an Island"],
            "can't attack unless defending player controls a Swamp" => ["can't attack unless defending player controls a Swamp"],
            "can't attack unless defending player controls a Mountain" => ["can't attack unless defending player controls a Mountain"],
            "can't attack unless defending player controls a Forest" => ["can't attack unless defending player controls a Forest"],
            "can't attack unless defending player controls a Plains" => ["can't attack unless defending player controls a Plains"],
            "can't be countered" => ["can't be countered"],
            '' => [],
            default => [null],
        }];

        return in_array(null, $found, true) || $found === [] ? null : $found;
    }

    /**
     * `CARDNAME can't block.` and the like, as keywords.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function restriction(string $line, array &$result): bool
    {
        // The Theros gods: `As long as your devotion to white is less than five, CARDNAME isn't a creature.`; see Game::isCreature().
        if (preg_match("/^As long as your devotion to (white|blue|black|red|green)(?: and (white|blue|black|red|green))? is less than (\\w+), CARDNAME isn't a creature\\.$/", $line, $m) && is_int($n = self::amount($m[3]))) {
            $colors = implode('', array_map(fn (string $color) => self::COLOR_WORDS[$color], array_filter([$m[1], $m[2] ?? ''])));
            $result['keywords'][] = "devotion {$colors} {$n}";

            return true;
        }
        // Lure: every creature able to block it must (see Game::declareBlockers()).
        if (preg_match('/^All creatures able to block CARDNAME do so\.?$/', $line)) {
            $result['keywords'] = array_values(array_unique([...$result['keywords'], 'all creatures able to block it do so']));

            return true;
        }
        if (! preg_match('/^CARDNAME (.+)$/', $line, $match) || ($found = self::restrictions($match[1])) === null) {
            return false;
        }
        $result['keywords'] = array_values(array_unique([...$result['keywords'], ...$found]));

        return true;
    }

    /**
     * A combined Enchant line such as `creature or land`, `red or green creature`
     * or `artifact, creature, or planeswalker you control`, as `any:a|b@you`
     * (see Game::matchesKind()). A single subtype such as `Forest` is `sub_Forest`.
     */
    private static function enchantKind(string $text): ?string
    {
        if (preg_match('/^[A-Z][a-z]+$/', $text)) {
            return 'sub_'.$text;
        }
        $who = '';
        if (preg_match('/^(.+) (you control|an opponent controls|you don\'t control)$/', $text, $m)) {
            [$text, $who] = [$m[1], $m[2] === 'you control' ? '@you' : '@opp'];
        }
        $parts = preg_split('/,? or (?!less|greater)|, /', $text);
        $noun = null;
        $atoms = [];
        foreach (array_reverse($parts) as $part) {
            $atom = self::enchantAtom($part);
            // `red or green creature`: a bare color borrows the noun after it.
            if ($atom === null && $noun !== null && isset(self::COLOR_WORDS[$part])) {
                $atom = $noun === 'creature' ? 'creature_color_'.self::COLOR_WORDS[$part] : null;
            }
            if ($atom === null) {
                return null;
            }
            $noun ??= preg_match('/(creature|land|permanent)$/', $part, $n) ? $n[1] : null;
            $atoms[] = $atom;
        }

        return 'any:'.implode('|', array_reverse($atoms)).$who;
    }

    private const array COLOR_WORDS = ['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G'];

    /** One kind inside a combined Enchant line, or null when it isn't understood. */
    private static function enchantAtom(string $part): ?string
    {
        $simple = ['creature', 'land', 'artifact', 'enchantment', 'planeswalker', 'permanent', 'battle', 'artifact creature', 'nonbasic land',
            'basic land' => 'basic_land', 'snow land' => 'snow_land', 'legendary creature' => 'legendary_creature', 'modified creature' => 'modified_creature',
            'non-Aura enchantment' => 'nonaura_enchantment', 'nonland permanent' => 'nonland_permanent', 'tapped creature' => 'tapped_creature', 'untapped creature' => 'untapped_creature'];
        foreach ($simple as $key => $value) {
            if ($part === (is_int($key) ? $value : $key)) {
                return str_replace(' ', '_', $value);
            }
        }
        if (preg_match('/^(white|blue|black|red|green) creature$/', $part, $m)) {
            return 'creature_color_'.self::COLOR_WORDS[$m[1]];
        }
        if (preg_match('/^non(white|blue|black|red|green) creature$/', $part, $m)) {
            return 'creature_noncolor_'.self::COLOR_WORDS[$m[1]];
        }
        if (preg_match('/^creature with power (\d+) or (greater|less)$/', $part, $m)) {
            return 'creature_power_'.($m[2] === 'greater' ? 'ge' : 'le').'_'.$m[1];
        }
        if (preg_match('/^creature with mana value (\d+) or less$/', $part, $m)) {
            return 'creature_mv_le_'.$m[1];
        }
        if (preg_match('/^[A-Z][a-z]+$/', $part)) {
            return 'sub_'.$part;
        }

        return null;
    }

    private static function aura(string $line, array &$result): bool
    {
        // `Enchant Forest` or `Enchant Vehicle`: a permanent with that subtype.
        if (preg_match('/^Enchant (.+)$/', $line, $match) && ($kind = self::ENCHANT[strtolower($match[1])] ?? self::enchantKind($match[1])) !== null) {
            $result['aura'] ??= ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['aura']['enchant'] = $kind;

            return true;
        }
        // "You control enchanted creature.": see Game::updateControl().
        if (preg_match('/^You control enchanted (?:creature|permanent|artifact|land|planeswalker)\.?$/', $line)) {
            $result['aura'] ??= ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['aura']['control'] = true;

            return true;
        }
        // A Zendikon: `Enchanted land is a 2/2 white Knight creature with first strike. It's still a land.`; see Game::isCreature().
        if (($result['aura']['enchant'] ?? null) !== null && preg_match("/^Enchanted land is an? (\\d+)\\/(\\d+) [A-Za-z ]+? creature(?: with (.+?))?\\. It's still a land\\.$/", $line, $match)
            && ($keywords = self::keywordList($match[3] ?? '')) !== null) {
            $result['aura']['power'] += (int) $match[1];
            $result['aura']['toughness'] += (int) $match[2];
            $result['aura']['keywords'] = array_values(array_unique([...$result['aura']['keywords'], ...$keywords]));
            $result['aura']['animates'] = true;

            return true;
        }
        if (($result['aura']['enchant'] ?? null) !== null && preg_match("/^When enchanted (?:land|creature) dies, return that card to its owner's hand\\.$/", $line)) {
            $result['triggered'][] = ['text' => $line, 'event' => 'enchanted_dies', 'effects' => [['type' => 'return_that', 'each' => 'that']]];

            return true;
        }
        $keywords = null;
        if (preg_match('/^Enchanted creature gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))?\.?$/', $line, $match)
            || preg_match('/^Enchanted (?:creature|permanent|land|artifact|[A-Z][a-z]+) (has) (.+?)\.?$/', $line, $match)) {
            $keywords = self::keywordList($match[1] === 'has' ? $match[2] : ($match[3] ?? ''));
        } elseif (preg_match('/^Enchanted (?:creature|permanent|artifact|land) (.+)$/', $line, $match)) {
            $keywords = self::restrictions($match[1]);
            $match = [null, 'has'];
        } elseif (preg_match("/^Its activated abilities can't be activated\\.?$/", $line)) {
            $keywords = ["abilities can't be activated"];
            $match = [null, 'has'];
        }
        if ($keywords === null) {
            return false;
        }
        $aura = $result['aura'] ?? ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
        if ($match[1] !== 'has') {
            $aura['power'] += (int) $match[1];
            $aura['toughness'] += (int) $match[2];
        }
        $aura['keywords'] = array_values(array_unique([...$aura['keywords'], ...$keywords]));
        $result['aura'] = $aura;

        return true;
    }

    /**
     * `Equipped creature gets +N/+N (and has …)` and `Equip {N}`.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function equipment(string $line, array &$result): bool
    {
        // Reconfigure (rule 702.151): attach to another creature you control, or unattach, as a sorcery.
        if (preg_match('/^Reconfigure ((?:\{[0-9WUBRGC\/P]+\})+)$/', $line, $match)) {
            foreach ([['type' => 'attach', 'target' => 'creature_you_control'], ['type' => 'unattach', 'self' => true]] as $effect) {
                $result['activated'][] = ['text' => $line, 'cost' => ['mana' => $match[1]], 'effects' => [$effect], 'sorcery' => true, 'once' => false];
            }

            return true;
        }
        // `Equip Wizard {1}`: only onto a Wizard you control.
        if (preg_match('/^Equip ([A-Z][a-z]+) ((?:\{[0-9WUBRGC\/P]+\})+)$/', $line, $match) && $match[1] !== 'Legendary') {
            $result['activated'][] = ['text' => $line, 'cost' => ['mana' => $match[2]], 'effects' => [['type' => 'attach', 'target' => "any:sub_{$match[1]}@you"]], 'sorcery' => true, 'once' => false];

            return true;
        }
        if (preg_match('/^Equip ((?:\{[0-9WUBRGC\/P]+\})+)$/', $line, $match)) {
            $result['activated'][] = [
                'text' => $line,
                'cost' => ['mana' => $match[1]],
                'effects' => [['type' => 'attach', 'target' => 'creature_you_control']],
                'sorcery' => true,
                'once' => false,
            ];

            return true;
        }
        if (preg_match('/^Equipped creature gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))?\.?$/', $line, $match)
            || preg_match('/^Equipped creature (has) (.+?)\.?$/', $line, $match)) {
            $keywords = self::keywordList($match[1] === 'has' ? $match[2] : ($match[3] ?? ''));
            if ($keywords === null) {
                return false;
            }
            $bonus = $result['equipment'] ?? ['power' => 0, 'toughness' => 0, 'keywords' => []];
            if ($match[1] !== 'has') {
                $bonus['power'] += (int) $match[1];
                $bonus['toughness'] += (int) $match[2];
            }
            $bonus['keywords'] = array_values(array_unique([...$bonus['keywords'], ...$keywords]));
            $result['equipment'] = $bonus;

            return true;
        }

        return false;
    }

    /**
     * A Saga's chapter ability (rule 714), e.g. `I, II — Create a 1/1 …`:
     * it triggers when its lore counter is added. The last chapter is kept
     * as the keyword `saga N`.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function chapter(string $line, array &$result): bool
    {
        $numerals = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6];
        if (! preg_match('/^((?:I|II|III|IV|V|VI)(?:, (?:I|II|III|IV|V|VI))*) — (.+)$/u', $line, $match) || ($effects = self::effects($match[2])) === null) {
            return false;
        }
        $chapters = array_map(fn (string $numeral) => $numerals[$numeral], explode(', ', $match[1]));
        foreach ($chapters as $n) {
            $result['triggered'][] = ['text' => $line, 'event' => "chapter_{$n}", 'effects' => $effects];
        }
        $last = max([...$chapters, ...array_map(fn (string $keyword) => (int) substr($keyword, 5), preg_grep('/^saga \d+$/', $result['keywords']))]);
        $result['keywords'] = [...array_values(preg_grep('/^saga \d+$/', $result['keywords'], PREG_GREP_INVERT)), "saga {$last}"];

        return true;
    }

    /**
     * A planeswalker's loyalty ability, e.g. `+1: You gain 2 life.` or
     * `−3: Destroy target creature.`
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function loyalty(string $line, array &$result): bool
    {
        // Printed as `+1: …` or, in newer card text, `[+1]: …`.
        if (! preg_match('/^\[?([+−-]?)(\d+)\]?: (.+)$/u', $line, $match) || ($effects = self::effects($match[3])) === null) {
            return false;
        }
        $result['activated'][] = [
            'text' => $line,
            'cost' => ['loyalty' => ($match[1] === '+' || $match[1] === '' ? 1 : -1) * (int) $match[2]],
            'effects' => $effects,
            'sorcery' => true,
            'once' => true,
        ];

        return true;
    }

    /**
     * A triggered ability (rule 603) this engine knows the event of.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function triggered(string $line, array &$result): bool
    {
        $events = [
            'becomes the target of a spell or ability' => 'targeted',
            'enters' => 'enters', 'enters the battlefield' => 'enters', 'dies' => 'dies', 'attacks' => 'attacks',
            'deals combat damage to a player' => 'combat_damage', 'is turned face up' => 'turned_face_up', 'becomes tapped' => 'becomes_tapped',
            'exploits a creature' => 'exploits', 'becomes monstrous' => 'monstrous', 'mutates' => 'mutates',
            'is put into a graveyard from the battlefield' => 'to_graveyard', 'is put into a graveyard from anywhere' => 'to_graveyard_anywhere', 'becomes blocked' => 'blocked',
        ];
        $casts = [
            'a noncreature spell' => 'cast_noncreature', 'an instant or sorcery spell' => 'cast_instant_sorcery', 'a spell' => 'cast_spell', 'a Spirit or Arcane spell' => 'cast_spirit_arcane',
            'a creature spell' => 'cast_creature', 'an artifact spell' => 'cast_artifact', 'an enchantment spell' => 'cast_enchantment',
            'a white spell' => 'cast_color_W', 'a blue spell' => 'cast_color_U', 'a black spell' => 'cast_color_B', 'a red spell' => 'cast_color_R', 'a green spell' => 'cast_color_G',
            'a multicolored spell' => 'cast_multicolored',
        ];
        // Haunt: `When CARDNAME enters or the creature it haunts dies, …` and `When the creature CARDNAME haunts dies, …`
        if (preg_match('/^When (CARDNAME enters or the creature it haunts dies|the creature CARDNAME haunts dies), (.+)\.$/', $line, $m) && ($effects = self::effects($m[2].'.')) !== null) {
            foreach (str_starts_with($m[1], 'CARDNAME enters') ? ['enters', 'haunted_dies'] : ['haunted_dies'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects];
            }

            return true;
        }
        // `When CARDNAME enters or dies, surveil 1.`: the same ability on both events.
        if (preg_match('/^When(?:ever)? CARDNAME (enters|attacks|dies) or (attacks|dies)(?: the battlefield)?, (.+)\.$/', $line, $m) && $m[1] !== $m[2]
            && (($effects = self::effects($m[3].'.')) !== null || ! str_contains($m[3], 'target') && ($effects = self::effects(preg_replace('/\bit\b/', 'CARDNAME', $m[3]).'.')) !== null)) {
            foreach ([$m[1], $m[2]] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $events[$event], 'effects' => $effects];
            }

            return true;
        }
        // Frostwalk Bastion / Kashi-Tribe: on the creature it dealt combat damage to; see Game::combatDamage().
        if (preg_match("/^Whenever CARDNAME deals combat damage to a creature, tap that creature and it doesn't untap during its controller's next untap step\\.$/", $line)) {
            $result['triggered'][] = ['text' => $line, 'event' => 'combat_damage_creature', 'effects' => [['type' => 'tap', 'target' => 'creature', 'freeze' => true]]];

            return true;
        }
        // Court Hussar: `When CARDNAME enters, sacrifice it unless {W} was spent to cast it.`; see GameObject::$spent.
        if (preg_match('/^When CARDNAME enters(?: the battlefield)?, sacrifice it unless \{([WUBRG])\} was spent to cast it\.$/', $line, $m)) {
            $result['triggered'][] = ['text' => $line, 'event' => 'enters', 'effects' => [['type' => 'sacrifice_unless_spent', 'color' => $m[1], 'self' => true]]];

            return true;
        }
        // Ornery Goblin: `Whenever CARDNAME blocks or becomes blocked by a creature, CARDNAME deals 1 damage to that creature.`; see Game::declareBlockers().
        if (preg_match('/^Whenever CARDNAME (blocks|becomes blocked by|blocks or becomes blocked by) a creature, CARDNAME deals (\w+) damage to that creature\.$/', $line, $m) && is_int($n = self::amount($m[2]))) {
            $event = ['blocks' => 'blocks_creature', 'becomes blocked by' => 'blocked_by_creature'][$m[1]] ?? 'block_pair';
            $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => [['type' => 'damage', 'amount' => $n, 'each' => 'that']]];

            return true;
        }
        // Skystinger: `Whenever CARDNAME blocks a creature with flying, CARDNAME gets +5/+0 until end of turn.`
        if (preg_match('/^Whenever CARDNAME blocks a creature with flying, (.+)\.$/', $line, $m) && ($effects = self::effects($m[1].'.')) !== null) {
            $result['triggered'][] = ['text' => $line, 'event' => 'blocks_flyer', 'effects' => $effects];

            return true;
        }
        // Fiend Hunter: `When CARDNAME leaves the battlefield, return the exiled card to the battlefield under its owner's control.`
        // makes its enters exile last until it leaves, as `exile … until CARDNAME leaves the battlefield` does.
        // Or, with `return the exiled card to its owner's hand.`, back to its owner's hand.
        if (preg_match("/^When CARDNAME leaves the battlefield, return the exiled (?:card|creature|permanent) to (the battlefield under its owner's control|its owner's hand)\\.$/", $line, $back)) {
            foreach ($result['triggered'] as $i => $ability) {
                $last = count($ability['effects']) - 1;
                if ($ability['event'] === 'enters' && $last >= 0 && $ability['effects'][$last]['type'] === 'exile' && isset($ability['effects'][$last]['target']) && ! isset($ability['effects'][$last]['until'])) {
                    $result['triggered'][$i]['effects'][$last]['until'] = $back[1] === "its owner's hand" ? 'hand' : true;

                    return true;
                }
            }

            return false;
        }
        $saddled = false;
        if (preg_match('/^When CARDNAME becomes level (\d+), (.+)$/', $line, $match)) {
            $event = "class_level_{$match[1]}";
            $text = $match[2];
        } elseif (preg_match('/^(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks|deals combat damage to a player|is turned face up|exploits a creature|becomes monstrous|mutates|is put into a graveyard from the battlefield|is put into a graveyard from anywhere|becomes the target of a spell or ability|becomes blocked|becomes tapped)( while saddled)?, (.+)$/', $line, $match)) {
            $event = $events[$match[1]];
            $saddled = $match[2] !== '';
            $text = $match[3];
        } elseif (preg_match('/^Whenever a creature dealt damage by CARDNAME this turn dies, (.+)$/', $line, $match)) {
            // Sengir Vampire; see Game::moveTo().
            $event = 'damaged_dies';
            $text = $match[1];
        } elseif (preg_match('/^At the beginning of your (upkeep|end step), (.+)$/', $line, $match)) {
            $event = $match[1] === 'upkeep' ? 'upkeep' : 'end_step';
            $text = $match[2];
        } elseif (preg_match('/^At the beginning of each upkeep, if (no spells were cast last turn|a player cast two or more spells last turn), transform CARDNAME\.?$/', $line, $match)) {
            // The werewolves of Innistrad (rule 701.28).
            $result['triggered'][] = ['text' => $line, 'event' => 'each_upkeep', 'effects' => [['type' => 'transform', 'self' => true, 'if' => str_starts_with($match[1], 'no') ? 'no_spells' : 'two_spells']]];

            return true;
        } elseif (preg_match('/^At the beginning of your (?:first|precombat) main phase, you may pay '.self::COST.'\. If you do, transform CARDNAME\.?$/u', $line, $match) && ! str_contains($match[1], 'X')) {
            $result['triggered'][] = ['text' => $line, 'event' => 'first_main', 'effects' => [['type' => 'pay_transform', 'cost' => $match[1], 'self' => true]]];

            return true;
        } elseif (preg_match('/^At the beginning of your (first|precombat|second|postcombat) main phase, (.+)$/', $line, $match)) {
            $event = in_array($match[1], ['first', 'precombat'], true) ? 'first_main' : 'second_main';
            $text = $match[2];
        } elseif (preg_match('/^At the beginning of (?:the|each) end step, (.+)$/', $line, $match)) {
            $event = 'each_end_step';
            $text = $match[1];
        } elseif (preg_match("/^At the beginning of enchanted player's upkeep, (.+)$/", $line, $match) && in_array($result['aura']['enchant'] ?? null, ['player', 'opponent'], true)) {
            // A curse: "that player" is the enchanted player.
            $effects = self::effects(str_replace(['that player or a planeswalker that player controls', 'that player'], 'target player', $match[1]));
            if ($effects === null || array_filter($effects, fn (array $effect) => ($effect['target'] ?? 'player') !== 'player') !== []) {
                return false;
            }
            $effects = array_map(fn (array $effect) => isset($effect['target']) ? ['toEnchanted' => true] + array_diff_key($effect, ['target' => true]) : $effect, $effects);
            $result['triggered'][] = ['text' => $line, 'event' => 'enchanted_upkeep', 'effects' => $effects];

            return true;
        } elseif (preg_match('/^(?:Landfall — )?Whenever a land (?:you control enters|enters the battlefield under your control|enters under your control), (.+)$/', $line, $match)) {
            $event = 'landfall';
            $text = $match[1];
        } elseif (preg_match('/^Whenever CARDNAME or another Ally (?:you control enters|enters the battlefield under your control)(?: the battlefield)?, (.+)$/', $line, $match) && ($effects = self::effects($match[1])) !== null) {
            // Rally-style Allies: once for itself, once for each other Ally.
            foreach (['enters', 'ally_enters_other'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects];
            }

            return true;
        } elseif (preg_match('/^Whenever another creature you control enters(?: the battlefield)?, (.+)$/', $line, $match)) {
            $event = 'creature_enters_other';
            $text = $match[1];
        } elseif (preg_match('/^(?:When|Whenever) (a|another) ([A-Z][a-z]+) you control enters(?: the battlefield)?, (.+)$/', $line, $match) && $match[2] !== 'Creature') {
            // Tribal enters triggers: `Whenever another Elf you control enters, …`.
            $event = 'subtype_enters';
            $extra = ['subtype' => $match[2], 'other' => $match[1] === 'another'];
            $text = $match[3];
        } elseif (preg_match('/^Whenever another creature (you control )?dies, (.+)$/', $line, $match)) {
            // `creature_dies_any` fires for other players' creatures; "another creature dies" listens to both.
            $event = 'creature_dies_other';
            $extra = $match[1] === '' ? ['anyController' => true] : [];
            $text = $match[2];
        } elseif (preg_match('/^Whenever a player casts an? (white|blue|black|red|green) spell, (?:you may gain (\w+) life\.?$|(.+)$)/', $line, $match)) {
            // Kor Firewalker, the Crystal Rods; see Game::castSpell().
            $event = 'any_cast_color_'.['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G'][$match[1]];
            $text = ($match[2] ?? '') !== '' ? "you gain {$match[2]} life." : $match[3];
        } elseif (preg_match('/^Whenever you draw a card, (.+)$/', $line, $match)) {
            $event = 'you_draw';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you draw your second card each turn, (.+)$/', $line, $match)) {
            $event = 'second_draw';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you gain life( for the first time each turn)?, (.+)$/', $line, $match)) {
            $event = $match[1] !== '' ? 'first_gain_life' : 'gain_life';
            $text = $match[2];
        } elseif (preg_match('/^(?:Heroic — )?Whenever you cast a spell that targets CARDNAME, (.+)$/u', $line, $match)) {
            $event = 'heroic';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you cast your (second|third) spell each turn, (.+)$/', $line, $match)) {
            $event = "{$match[1]}_spell";
            $text = $match[2];
        } elseif (preg_match('/^Whenever you cast ('.implode('|', array_keys($casts)).'), (.+)$/', $line, $match)) {
            $event = $casts[$match[1]];
            $text = $match[2];
        } else {
            return false;
        }
        // `Whenever you cast your second spell each turn, copy it.`: the spell just cast.
        if (str_starts_with($event, 'cast_') || in_array($event, ['second_spell', 'third_spell'], true)) {
            $text = (string) preg_replace('/^copy it\./i', 'copy that spell.', $text);
        }
        if ($event === 'enters' && preg_match("/^if tribute wasn't paid, (.+)$/", $text, $match) && preg_grep('/^tribute \d+$/', $result['keywords']) !== []) {
            $text = $match[1];
        }
        // An intervening "if" clause (rule 603.4), checked as it triggers; see Game::conditionMet().
        $if = null;
        if (self::effects($text) === null && preg_match("/^if (CARDNAME isn't prepared|you control (\\w+) or more lands|(\\w+) or more creatures died this turn|an opponent has more cards in hand than you|you gained (\\w+) or more life this turn), (.+)$/", $text, $match)) {
            $if = match (true) {
                $match[1] === "CARDNAME isn't prepared" => 'unprepared',
                $match[2] !== '' => 'lands '.self::amount($match[2]),
                $match[3] !== '' => 'died '.self::amount($match[3]),
                $match[4] !== '' => 'gained '.self::amount($match[4]),
                default => 'hand_behind',
            };
            if (! preg_match('/^\w+( \d+)?$/', $if)) {
                return false;
            }
            $text = $match[5];
        }
        // `Whenever CARDNAME deals combat damage to a player, that player discards a card.`: the opponent, untargeted.
        if ($event === 'combat_damage' && self::effects($text) === null && preg_match('/^that player (discards|loses|mills|sacrifices) /', $text)) {
            $text = 'each opponent '.substr($text, strlen('that player '));
        }
        $kicked = false;
        if ($event === 'enters' && ($result['kicker'] !== null && preg_match('/^if it was kicked, (.+)$/', $text, $match)
            || isset($result['altCosts']['gift']) && preg_match('/^if the gift was promised, (.+)$/', $text, $match)
            || isset($result['altCosts']['evidence']) && preg_match('/^if evidence was collected, (.+)$/', $text, $match))) {
            [$kicked, $text] = [true, $match[1]];
        }
        // The Thriving creatures: `you may pay {2}{G}. If you do, put a +1/+1 counter on it.`; paid when it can be (see Game::applyEffect()).
        $pay = null;
        // Energy too (Scrapper Champion): `you may pay {E}{E}. If you do, …`.
        if (preg_match('/^you may pay ((?:\{E\})+|'.substr(self::COST, 1).'\. If you do, (.+)$/u', $text, $match) && ! str_contains($match[1], 'X') && ! str_contains($match[2], 'target')) {
            [$pay, $text] = [$match[1], $match[2]];
        }
        $effects = self::effects($text);
        // "Whenever CARDNAME attacks, put a +1/+1 counter on it.": with no target, "it" is CARDNAME.
        if ($effects === null && ! str_contains($text, 'target')) {
            $effects = self::effects(preg_replace('/\bit\b/', 'CARDNAME', $text));
        }
        if ($effects !== null && $pay !== null) {
            $effects = [['type' => 'pay', 'cost' => $pay, 'then' => $effects]];
        }
        if ($effects === null) {
            return false;
        }
        $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects] + ($kicked ? ['kicked' => true] : []) + ($saddled ? ['saddled' => true] : []) + ($if !== null ? ['if' => $if] : []) + array_diff_key($extra ?? [], ['anyController' => true]);
        if ($extra['anyController'] ?? false) {
            $result['triggered'][] = ['event' => 'creature_dies_any'] + $result['triggered'][count($result['triggered']) - 1];
        }

        return true;
    }

    /**
     * An activated ability: a cost of mana, `{T}`, `Sacrifice CARDNAME`
     * and `Pay N life`, then its effect, then optionally `Activate only as
     * a sorcery.` or `Activate only once each turn.`
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function activated(string $line, array &$result): bool
    {
        // Exhaust (rule 702.177): `Exhaust — {cost}: …` activates only once.
        $ever = false;
        if (str_starts_with($line, 'Exhaust — ')) {
            [$ever, $line] = [true, substr($line, strlen('Exhaust — '))];
        }
        // Power-up: once, as a sorcery (the discount when it entered this turn is not taken).
        $powerUp = false;
        if (str_starts_with($line, 'Power-up — ')) {
            [$ever, $powerUp, $line] = [true, true, substr($line, strlen('Power-up — '))];
        }
        // Channel: `Channel — {1}{G}, Discard CARDNAME: …`, activated from your hand; see Game::whyNotActivate().
        if (str_starts_with($line, 'Channel — ')) {
            $line = substr($line, strlen('Channel — '));
        }
        // Reinforce (rule 702.77): `Reinforce 2—{1}{W}`.
        if (preg_match('/^Reinforce (\w+)—((?:\{[0-9WUBRGC\/]+\})+)\.?$/u', $line, $reinforce) && is_int($n = self::amount($reinforce[1]))) {
            $line = "{$reinforce[2]}, Discard CARDNAME: Put {$reinforce[1]} +1/+1 counter".($n === 1 ? '' : 's').' on target creature.';
        }
        if (! preg_match('/^([^:"]+): (.+)$/', $line, $match)) {
            return false;
        }
        $cost = [];
        foreach (array_map('trim', explode(',', $match[1])) as $part) {
            if ($part === '{T}') {
                $cost['tap'] = true;
            } elseif (preg_match('/^(\{[0-9WUBRGCX\/P]+\})+$/', $part)) {
                $cost['mana'] = ($cost['mana'] ?? '').$part;
            } elseif ($part === 'Sacrifice CARDNAME') {
                $cost['sacrifice'] = true;
            } elseif ($part === 'Discard CARDNAME') {
                $cost['discardSelf'] = true;
            } elseif (preg_match('/^Sacrifice (an?|another) (creature|artifact)$/', $part, $sac)) {
                // Nantuko Husk: the weakest creature (or artifact); see Game::fodder().
                $cost['fodder'] = ($sac[1] === 'another' ? 'other' : 'any').($sac[2] === 'artifact' ? ' artifact' : '');
            } elseif ($part === 'Discard a card') {
                // Paid with the cheapest card in hand; see Game::discardCheapest().
                $cost['discard'] = 1;
            } elseif (preg_match('/^Pay (\d+) life$/', $part, $life)) {
                $cost['life'] = (int) $life[1];
            } elseif (preg_match('/^Pay ((?:\{E\})+)$/', $part, $energy)) {
                $cost['energy'] = substr_count($energy[1], '{E}');
            } elseif (preg_match('/^Remove (\w+) (\w+) counters? from CARDNAME$/', $part, $remove) && is_int($n = self::amount($remove[1])) && $remove[2] !== 'loyalty') {
                $cost['remove'] = [$remove[2], $n];
            } else {
                return false;
            }
        }
        if (str_contains($cost['mana'] ?? '', 'X')) {
            return false;
        }

        $text = $match[2];
        $sorcery = $once = false;
        if (preg_match('/^(.+?)\s*Activate only as a sorcery\.$/', $text, $limit)) {
            [$text, $sorcery] = [$limit[1], true];
        } elseif (preg_match('/^(.+?)\s*Activate only once each turn\.$/', $text, $limit)) {
            [$text, $once] = [$limit[1], true];
        }
        if (preg_match('/^(.+?)\s*Activate only once\.$/', $text, $limit)) {
            [$text, $ever] = [$limit[1], true];
        }
        if ($cost['sacrifice'] ?? false) {
            // `Sacrifice CARDNAME: It deals 2 damage to target creature.`: "it" is the sacrificed permanent.
            $text = preg_replace('/^It deals /', 'CARDNAME deals ', $text);
        }
        $effects = self::effects($text);
        if ($effects === null) {
            return false;
        }
        $result['activated'][] = ['text' => $line, 'cost' => $cost, 'effects' => $effects, 'sorcery' => $sorcery || $powerUp, 'once' => $once] + ($ever ? ['ever' => true] : []) + (($cost['discardSelf'] ?? false) ? ['hand' => true] : []);

        return true;
    }

    /**
     * Every sentence of an ability's text as effects, or null when any is
     * not understood.
     *
     * @param string $text
     *
     * @return array[]|null
     */
    public static function effects(string $text): ?array
    {
        if (($impulse = self::impulse($text)) !== null) {
            return [$impulse];
        }
        // Haste Magic: `Target creature gets +3/+1 … Exile the top card of your library. You may play it until your next end step.`
        if (preg_match('/^(.+?\.)\s+(Exile the top .*)$/s', trim($text), $split) && ($impulse = self::impulse($split[2])) !== null) {
            $before = self::effects($split[1]);

            return $before === null ? null : [...$before, $impulse];
        }
        $effects = [];
        // `Stress Dream deals 5 damage … Look at the top two cards …`: the sentences before the look, then the look.
        if (preg_match('/^(.+?\.)\s+((?:Look at|Reveal) the top .*)$/s', trim($text), $split) && self::look($split[2]) !== null) {
            $before = self::effects($split[1]);
            if ($before === null) {
                return null;
            }
            [$effects, $text] = [$before, $split[2]];
        }
        if (($look = self::look($text)) !== null) {
            [$effects[], $text] = $look;
            if ($text === '') {
                return $effects;
            }
        }
        if (($reveal = self::revealDiscard($text)) !== null) {
            [$effects[], $text] = $reveal;
            if ($text === '') {
                return $effects;
            }
        }
        foreach (self::sentences($text) as $sentence) {
            if (self::modifies($sentence, $effects)) {
                continue;
            }
            // In an ability, "it" at the start means the card itself: "When CARDNAME enters, it deals 4 damage …".
            $found = self::sentenceEffects(preg_replace('/^it /', 'CARDNAME ', $sentence));
            if ($found === null) {
                return null;
            }
            array_push($effects, ...$found);
        }

        return $effects === [] ? null : $effects;
    }

    /**
     * `Look at the top four cards of your library. Put one of them into
     * your hand and the rest on the bottom of your library in any order.`,
     * `… You may reveal a creature or land card from among them and put it
     * into your hand. Put the rest …`, `Reveal the top five cards …`, or
     * `… then put them back in any order.`
     *
     * @param string $text
     *
     * @return array|null
     */
    private static function look(string $text): ?array
    {
        if (! preg_match('/^(Look at|Reveal) the top (\w+) cards of your library(, where X is the number of lands you control)?(.*)$/s', ucfirst(trim($text)), $head) || (! is_int($n = self::amount($head[2])) && $n !== 'X') || $n === 0) {
            return null;
        }
        if ($head[3] !== '') {
            if ($n !== 'X') {
                return null;
            }
            $n = 'lands';
        }
        $head[3] = $head[4];
        // `Put up to two creature cards with mana value 3 or less from among them onto the battlefield.` (Collected Company)
        $mv = null;
        if (preg_match('/^(\. (?:You may put|Put) (?:an?|up to \w+) .+?) cards? with mana value (\d+) or (less|greater)( from among them.*)$/s', $head[3], $w)) {
            [$head[3], $mv] = [$w[1].' cards'.$w[4], 'mv_'.($w[3] === 'less' ? 'le' : 'ge').'_'.$w[2]];
        }
        $head[3] = (string) preg_replace('/^\. Put (up to \w+) /', '. You may put $1 ', $head[3]);
        $where = '(on the bottom of your library(?: in (?:a random|any) order)?|into your graveyard)';
        $to = '(into your hand|onto the battlefield tapped|onto the battlefield)';
        $after = '(?:\.|$)\s*(.*)$/s';
        $dest = 'hand';
        if ($head[1] === 'Look at' && preg_match("/^, then put them back in any order{$after}", $head[3], $m)) {
            [$take, $may, $filter, $rest, $tail] = [0, true, 'any', 'top', $m[1]];
        } elseif (preg_match("/^\\. Put (up to )?(\\w+) of (?:them|those cards) into your hand and the (?:rest|other|others) {$where}{$after}", $head[3], $m) && is_int($take = self::amount($m[2]))) {
            [$may, $filter, $rest, $tail] = [$m[1] !== '', 'any', $m[3], $m[4]];
        } elseif (preg_match("/^\\. (?:You may (?:reveal|put|choose) (an?|up to \\w+|any number of) (?:(.+?) )?cards? from among them(?: and put (?:it|that card|them|those cards|the revealed cards) {$to}| {$to})?|Put all (.+?) cards revealed this way {$to})(?:\\. (?:Then p|P)ut the rest| and the rest) {$where}{$after}", $head[3], $m)) {
            if (($m[5] ?? '') !== '') {
                [$take, $may, $words, $place] = [is_int($n) ? $n : 99, false, $m[5], $m[6]];
            } else {
                $take = match (true) {
                    $m[1] === 'any number of' => is_int($n) ? $n : 99,
                    str_starts_with($m[1], 'up to') => self::amount(substr($m[1], 6)),
                    default => 1,
                };
                [$may, $words, $place] = [true, $m[2], ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : 'into your hand')];
            }
            if (preg_match('/^(\w+) card and\/or an? (\w+)$/', $words, $pair)) {
                // `a creature card and/or a land card`: one of each, at most two.
                [$words, $take] = ["{$pair[1]} or {$pair[2]}", 2];
            }
            // `noncreature, nonland permanent`: all of them, not any.
            $filter = $words === '' ? 'any' : (preg_match('/^non[a-z]+, /', $words) ? self::revealFilter("{$words} cards") : self::cardFilter($words));
            if ($filter === null || ! is_int($take)) {
                return null;
            }
            if ($mv !== null) {
                $filter .= "&{$mv}";
            }
            [$rest, $tail, $dest] = [$m[7], $m[8], match ($place) {
                'onto the battlefield tapped' => 'tapped',
                'onto the battlefield' => 'battlefield',
                default => 'hand',
            }];
        } else {
            return null;
        }

        return [[
            'type' => 'look',
            'amount' => $n,
            'take' => $take,
            'may' => $may,
            'filter' => $filter,
            'rest' => $rest === 'top' ? 'top' : (str_starts_with($rest, 'into') ? 'graveyard' : 'bottom'),
        ] + ($dest === 'hand' ? [] : ['to' => $dest]), trim($tail)];
    }

    /**
     * `Exile the top two cards of your library. Until the end of your next
     * turn, you may play those cards.`, or `… You may play that card this turn.`
     *
     * @param string $text
     *
     * @return array|null
     */
    private static function impulse(string $text): ?array
    {
        // Also inside a trigger: `When CARDNAME enters, exile the top card of your library. …`
        if (! preg_match('/^[Ee]xile the top (card|(\w+) cards) of your library\. (.+?)\.?$/', trim($text), $m)) {
            return null;
        }
        $it = '(?:that card|those cards|them|it|the exiled cards?)';
        $until = match (true) {
            (bool) preg_match("/^(?:Until end of turn, you may play {$it}|You may play {$it} (?:this turn|until end of turn))$/", $m[3]) => 'this',
            (bool) preg_match("/^(?:Until the end of your next turn, you may play {$it}|You may play {$it} until the end of your next turn)$/", $m[3]) => 'next',
            // Haste Magic: the end step of this turn when it is yours, else of your next turn.
            (bool) preg_match("/^You may play {$it} until your next end step$/", $m[3]) => 'end_step',
            // Galvanic Relay: only during your next turn.
            (bool) preg_match("/^During your next turn, you may play {$it}$/", $m[3]) => 'during_next',
            default => null,
        };
        $n = $m[1] === 'card' ? 1 : self::amount($m[2]);
        if ($until === null || ! is_int($n) || $n < 1) {
            return null;
        }

        return ['type' => 'impulse', 'amount' => $n, 'until' => $until];
    }

    /**
     * The cards a phrase like `creature or land`, `Dwarf or Equipment` or
     * `nonland permanent` names, as a filter for {@see Game::matchesFilter()}:
     * kinds joined by `|`, subtypes as `sub:Dwarf`.
     *
     * @param string $words
     *
     * @return string|null
     */
    private static function cardFilter(string $words): ?string
    {
        $kinds = [
            'creature' => 'creature', 'land' => 'land', 'artifact' => 'artifact', 'enchantment' => 'enchantment', 'instant' => 'instant',
            'sorcery' => 'sorcery', 'planeswalker' => 'planeswalker', 'permanent' => 'permanent', 'nonland' => 'nonland',
            'noncreature' => 'noncreature', 'nonland permanent' => 'nonland_permanent', 'battle' => 'battle', 'colorless' => 'colorless',
        ];
        $pieces = [];
        foreach (preg_split('/,? or |, /', $words) as $piece) {
            if (isset($kinds[$piece])) {
                $pieces[] = $kinds[$piece];
            } elseif (preg_match('/^[A-Z][a-z]+$/', $piece)) {
                $pieces[] = "sub:{$piece}";
            } else {
                return null;
            }
        }

        return implode('|', $pieces);
    }

    /**
     * `Target opponent reveals their hand. You choose a nonland card from
     * it. That player discards that card.`: the caster picks the discard.
     *
     * @param string $text
     *
     * @return array{0: array, 1: string}|null The effect, and the text after it.
     */
    private static function revealDiscard(string $text): ?array
    {
        $filters = ['card' => 'any', 'nonland card' => 'nonland', 'creature card' => 'creature', 'noncreature card' => 'noncreature', 'noncreature, nonland card' => 'noncreature_nonland', 'instant or sorcery card' => 'instant_sorcery', 'nonland permanent card' => 'nonland_permanent', 'creature or planeswalker card' => 'creature|planeswalker', 'artifact or creature card' => 'artifact|creature'];
        // `… That player discards that card.`, or `… and exile that card.` / `… Exile that card.`
        // `You may choose …. If you do, that player discards that card.`: choosing is always worth it.
        // Inquisition of Kozilek: `You choose a nonland card from it with mana value 3 or less.`
        if (! preg_match("/^(Target opponent|Target player) reveals (?:their|his or her) hand\. You (?:may )?choose an? ([^.]+?) from it( with mana value \d+ or (?:less|greater))?(\. (?:If you do, that|That) player discards that card| and exile that card|\. Exile that card)\.?\s*(.*)$/s", ucfirst(trim($text)), $m)
            || ($filter = ($m[3] === '' ? $filters[$m[2]] ?? null : null) ?? self::revealFilter($m[2].$m[3])) === null) {
            return null;
        }

        return [['type' => 'discard', 'amount' => 1, 'target' => self::TARGETS[strtolower($m[1])], 'chooser' => 'you', 'filter' => $filter] + (str_contains($m[4], 'xile') ? ['exile' => true] : []), trim($m[5])];
    }

    /**
     * The card a hand-reveal discard picks, beyond the common ones:
     * `nonland card with mana value 3 or less`, `nonartifact, nonland card`
     * or `artifact or enchantment card`, as a filter for Game::matchesFilter()
     * (`&` joins filters that must all hold).
     *
     * @param string $words
     *
     * @return string|null
     */
    private static function revealFilter(string $words): ?string
    {
        $all = [];
        if (preg_match('/^(.+?) with mana value (\d+) or (less|greater)$/', $words, $m)) {
            [$words, $all[]] = [$m[1], 'mv_'.($m[3] === 'less' ? 'le' : 'ge').'_'.$m[2]];
        }
        if (! preg_match('/^(.+?) cards?$/', $words, $m)) {
            return null;
        }
        // `noncreature, nonland`: every one of them; `noncreature, nonland permanent`: and a permanent.
        if (preg_match('/^non[a-z]+(?:, non[a-z]+)+(?: permanent)?$/', $m[1])) {
            foreach (explode(', ', $m[1]) as $piece) {
                $all[] = str_contains($piece, ' ') ? self::cardFilter($piece) : 'not:'.substr($piece, 3);
            }
        } elseif (($filter = self::cardFilter($m[1])) !== null) {
            $all[] = $filter;
        } else {
            return null;
        }

        return implode('&', $all);
    }

    /**
     * One sentence as effects: one, or two joined by `, then` (`Draw two
     * cards, then discard a card`).
     *
     * @param string $sentence
     *
     * @return array[]|null
     */
    private static function sentenceEffects(string $sentence): ?array
    {
        if (($effect = self::effect($sentence)) !== null) {
            return [$effect];
        }
        // Fire // Ice: `CARDNAME deals 2 damage divided as you choose among one or two targets.`; split evenly, the rest to the first (see Game::runSteps()).
        if (preg_match('/^CARDNAME deals (\w+) damage divided as you choose among (one or two|one, two, or three|any number of) targets$/i', $sentence, $m) && is_int($n = self::amount($m[1])) && $n >= 1) {
            $slots = min($n, match (strtolower($m[2])) {
                'one or two' => 2,
                'one, two, or three' => 3,
                default => 5,
            });

            return array_map(fn (int $i) => ['type' => 'divided', 'total' => $n, 'slot' => $i, 'target' => $i === 0 ? 'any' : '?any'], range(0, $slots - 1));
        }
        // Peppersmoke: `If you control a Faerie, draw a card.`; checked as it resolves (see Game::runSteps()).
        if (preg_match('/^If you control an? ([A-Z][a-z]+|artifact|enchantment|creature|planeswalker), (.+)$/', $sentence, $m) && ($then = self::sentenceEffects($m[2])) !== null
            && array_filter($then, fn (array $effect) => isset($effect['target'])) === []) {
            return array_map(fn (array $effect) => $effect + ['ifCondition' => 'controls '.ucfirst($m[1])], $then);
        }
        // Tarkir: `If you cast CARDNAME during your main phase, …`; checked as it resolves.
        if (preg_match('/^If you cast CARDNAME during your main phase, (.+)$/', $sentence, $m) && ($then = self::sentenceEffects($m[1])) !== null) {
            return array_map(fn (array $effect) => $effect + ['ifCondition' => 'main_phase'], $then);
        }
        // `Whenever you cast an instant or sorcery spell, copy that spell.`: the spell that triggered it; see Game::trigger().
        if (preg_match('/^copy that spell$/i', $sentence)) {
            return [['type' => 'copy_spell', 'thatSpell' => true]];
        }
        // Support N (rule 701.41): a +1/+1 counter on each of up to N other target creatures.
        if (preg_match('/^support (\w+)$/i', $sentence, $m) && is_int($n = self::amount($m[1])) && $n >= 1 && $n <= 5) {
            return array_fill(0, $n, ['type' => 'counters', 'amount' => 1, 'target' => '?creature_other']);
        }
        // A bounce land's `When this land enters, return a land you control to its owner's hand.`
        if (preg_match("/^return an? (land|creature|nonland permanent) you control to its owner's hand$/i", $sentence, $m)) {
            return [['type' => 'return_own', 'filter' => str_replace(' ', '_', strtolower($m[1]))]];
        }
        // Stampeding Wildebeests: `return a green creature you control to its owner's hand`.
        if (preg_match("/^return an? (white|blue|black|red|green) creature you control to its owner's hand$/i", $sentence, $m)) {
            return [['type' => 'return_own', 'filter' => 'creature', 'color' => self::COLOR_WORDS[strtolower($m[1])]]];
        }
        // Dwarven Shortsword: `create a 2/2 red Dwarf creature token, then attach CARDNAME to it` (an Equipment carried in).
        if (preg_match('/^(create an? .+ creature token), then attach CARDNAME to it$/i', $sentence, $m) && ($found = self::sentenceEffects($m[1])) !== null
            && count($found) === 1 && $found[0]['type'] === 'token' && ($found[0]['amount'] ?? 1) === 1) {
            return [$found[0] + ['attachSelf' => true]];
        }
        // Strive: `any number of target creatures`, read as up to three (a choice the game offers as optional targets).
        if (preg_match('/\bany number of target /i', $sentence)) {
            return self::sentenceEffects(preg_replace_callback('/\b(a)ny number of target /i', fn (array $m) => ($m[1] === 'A' ? 'U' : 'u').'p to three target ', $sentence));
        }
        // "Tap up to two target creatures": optional targets, `?kind` (see Game::isLegalTarget()).
        if (preg_match('/^(tap|untap) up to (\w+) target (.+)$/i', $sentence, $m) && is_int($n = self::amount($m[2])) && $n >= 1 && $n <= 5
            && isset(self::TARGETS[$phrase = 'target '.preg_replace('/s\b/', '', strtolower($m[3]))]) && self::isPermanentTarget($phrase)) {
            return array_fill(0, $n, ['type' => strtolower($m[1]), 'target' => '?'.self::TARGETS[$phrase]]);
        }
        // `Up to two target creatures each get +2/+2 until end of turn.`: as `… target creatures gets …`.
        if (preg_match('/^(up to \w+ target creatures) each get (.+)$/i', $sentence, $m)) {
            return self::sentenceEffects($m[1].' gets '.preg_replace('/^(\S+ and) gain /', '$1 gains ', $m[2]));
        }
        // "Return up to two target creature cards from your graveyard to your hand": that many optional targets.
        if (preg_match('/^(.*)\bup to (two|three|four|five) target (.+)$/i', $sentence, $m) && is_int($n = self::amount($m[2]))
            && ($found = self::sentenceEffects($m[1].'target '.str_replace("to their owners' hands", "to its owner's hand", preg_replace('/^((?:\w+ )*?)(\w+)s\b/', '$1$2', $m[3])))) !== null
            && count($withTarget = array_keys(array_filter($found, fn (array $effect) => isset($effect['target'])))) === 1) {
            $effect = $found[$withTarget[0]];
            $effect['target'] = '?'.$effect['target'];
            array_splice($found, $withTarget[0], 1, array_fill(0, $n, $effect));

            return $found;
        }
        // "… to up to one target creature": the same effect with its one target optional.
        if (str_contains($sentence, 'up to one target ') && ($found = self::sentenceEffects(preg_replace('/\bup to one target /', 'target ', $sentence, 1))) !== null
            && count($withTarget = array_keys(array_filter($found, fn (array $effect) => isset($effect['target'])))) === 1) {
            $found[$withTarget[0]]['target'] = '?'.$found[$withTarget[0]]['target'];

            return $found;
        }
        $targets = implode('|', array_map(fn ($phrase) => preg_quote($phrase, '/'), array_keys(self::TARGETS)));
        // Fight (rule 701.14), or one-sided: "… deals damage equal to its power to …". The second effect uses the first one's target too.
        if (preg_match("/^target creature you control (fights|deals damage equal to its power to) ({$targets})$/i", $sentence, $m)
            && in_array($kind = self::TARGETS[strtolower($m[2])], ['creature', 'creature_opponent', 'creature_or_planeswalker', 'creature_or_planeswalker_opponent'], true)
            && ($m[1] === 'deals damage equal to its power to' || ! str_contains($kind, 'planeswalker'))) {
            return [['type' => 'chosen', 'target' => 'creature_you_control'], ['type' => 'fight', 'target' => $kind, 'mutual' => strtolower($m[1]) === 'fights']];
        }
        if (preg_match('/^you draw (\w+) cards? and (?:you )?(gain|lose) (\w+) life$/i', $sentence, $m) && ($draw = self::amount($m[1])) !== null && ($life = self::amount($m[3])) !== null) {
            return [['type' => 'draw', 'amount' => $draw], strtolower($m[2]) === 'gain' ? ['type' => 'gain_life', 'amount' => $life] : ['type' => 'lose_life', 'amount' => $life, 'you' => true]];
        }
        if (preg_match('/^(target player|target opponent) draws (\w+) cards? and loses (\w+) life$/i', $sentence, $m) && ($draw = self::amount($m[2])) !== null && ($life = self::amount($m[3])) !== null) {
            return [['type' => 'draw', 'amount' => $draw, 'target' => self::TARGETS[strtolower($m[1])]], ['type' => 'lose_life', 'amount' => $life, 'sameTarget' => true]];
        }
        if (preg_match('/^(.+?), then (.+)$/', $sentence, $match) && ($first = self::effect($match[1])) !== null && ($second = self::effect($match[2])) !== null) {
            return [$first, $second];
        }
        // Two whole effects joined by "and": "untap CARDNAME and put a +1/+1 counter on CARDNAME".
        $parts = explode(' and ', $sentence);
        for ($i = 1; $i < count($parts); $i++) {
            $first = self::effect(implode(' and ', array_slice($parts, 0, $i)));
            $second = $first === null ? null : self::effect(implode(' and ', array_slice($parts, $i)));
            if ($second !== null && ! isset($second['target'])) {
                return [$first, $second];
            }
        }

        return null;
    }

    /**
     * `that creature gets an additional +2/+2 …` after an effect on target
     * creature: the same effect on that creature again; `CARDNAME deals 3
     * damage to that creature's controller`.
     *
     * @param string  $text
     * @param array[] $effects So far.
     *
     * @return array[]|null
     */
    private static function thatCreature(string $text, array $effects): ?array
    {
        if (array_intersect(array_column($effects, 'target'), self::CREATURE_KINDS) === []) {
            return null;
        }
        if (preg_match("/^CARDNAME deals (\\w+) damage to that creature's controller$/", $text, $m) && is_int($n = self::amount($m[1]))) {
            return [['type' => 'damage', 'amount' => $n, 'sameTarget' => true, 'toController' => true]];
        }
        if (! preg_match('/^that creature (.+)$/', $text, $m)) {
            return null;
        }
        $found = self::effects('target creature '.preg_replace('/^gets an additional /', 'gets ', $m[1]));
        if ($found === null || count($found) !== 1 || ($found[0]['target'] ?? null) !== 'creature') {
            return null;
        }
        unset($found[0]['target']);

        return [$found[0] + ['sameTarget' => true]];
    }

    /**
     * A sentence that changes the effect before it: `It can't be
     * regenerated.` after destroy, `It gains haste until end of turn.`
     * after creating a token.
     *
     * @param string  $sentence
     * @param array[] $effects  So far; the last one may change.
     *
     * @return bool
     */
    private static function modifies(string $sentence, array &$effects): bool
    {
        $last = array_key_last($effects);
        if ($last === null) {
            return false;
        }
        // A manland: `… becomes a 3/3 … creature until end of turn. It's still a land.`
        if (preg_match("/^It's still (?:a land|an artifact)$/", $sentence) && ($effects[$last]['animate'] ?? false)) {
            return true;
        }
        // Tenacity: `Creatures you control get +1/+1 … until end of turn. Untap those creatures.`
        if ($sentence === 'Untap those creatures' && $effects[$last]['type'] === 'pump' && ($effects[$last]['each'] ?? null) === 'yours') {
            $effects[] = ['type' => 'untap', 'each' => 'yours'];

            return true;
        }
        // `Gain control of up to two target creatures until end of turn. Untap those creatures. They gain haste until end of turn.`:
        // the follow-up for each of them, right after it (see Game::resolve(), which reads `sameTarget` as the previous target).
        if (preg_match('/^(?:Untap those creatures|They gain (.+) until end of turn)$/', $sentence, $m)) {
            $follow = isset($m[1]) ? (($keywords = self::keywordList($m[1])) === null ? null : ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'sameTarget' => true])
                : ['type' => 'untap', 'sameTarget' => true];
            $targeted = [];
            for ($i = $last; $i >= 0 && (isset($effects[$i]['target']) || ($effects[$i]['sameTarget'] ?? false)); $i--) {
                if (isset($effects[$i]['target'])) {
                    $targeted[] = $i;
                }
            }
            if ($follow !== null && count($targeted) >= 2 && count(array_unique(array_map(fn (int $i) => $effects[$i]['type'], $targeted))) === 1) {
                $rebuilt = [];
                foreach ($effects as $i => $effect) {
                    $rebuilt[] = $effect;
                    $next = $effects[$i + 1] ?? null;
                    // After a targeted effect's own follow-ups.
                    if ($i >= min($targeted) && ($next === null || isset($next['target']))) {
                        $rebuilt[] = $follow;
                    }
                }
                $effects = $rebuilt;

                return true;
            }
        }
        // "If that creature would die this turn, exile it instead." after damage.
        if (preg_match('/^If (?:that creature(?: or planeswalker)?|a creature dealt damage this way|it) would die this turn, exile it instead$/', $sentence)) {
            $damages = array_keys(array_filter($effects, fn (array $effect) => $effect['type'] === 'damage'));
            foreach ($damages as $i) {
                $effects[$i]['exileIfDies'] = true;
            }

            return $damages !== [];
        }
        if ($sentence === "If that spell is countered this way, exile it instead of putting it into its owner's graveyard" && $effects[$last]['type'] === 'counter') {
            $effects[$last]['exileCountered'] = true;

            return true;
        }
        if ($effects[$last]['type'] === 'copy_spell' && $sentence === 'You may choose new targets for the copy') {
            return true;
        }
        // "Destroy target creature. Its controller loses 2 life.", also after a permanent or a spell.
        if (isset($effects[$last]['target']) && ! in_array(ltrim($effects[$last]['target'], '?'), ['any', 'player', 'opponent', 'player_or_planeswalker'], true) && ! str_contains($effects[$last]['target'], 'card')
            && preg_match('/^Its controller (loses (\w+) life|mills (\w+) cards?)$/', $sentence, $m) && is_int($n = self::amount($m[2] !== '' ? $m[2] : $m[3]))) {
            $effects[] = ['type' => $m[2] !== '' ? 'lose_life' : 'mill', 'amount' => $n, 'sameTarget' => true, 'toController' => true];

            return true;
        }
        // Swords to Plowshares: `Exile target creature. Its controller gains life equal to its power.`
        if (isset($effects[$last]['target']) && in_array(ltrim($effects[$last]['target'], '?'), self::CREATURE_KINDS, true)
            && preg_match('/^(Its controller gains|You gain) life equal to (?:its|that creature\'s) (power|toughness)$/', $sentence, $m)) {
            $effects[] = ['type' => 'gain_life', 'amount' => 'target_'.$m[2], 'sameTarget' => true] + ($m[1] === 'You gain' ? [] : ['toController' => true]);

            return true;
        }
        // Beast Within: `Destroy target permanent. Its controller creates a 3/3 green Beast creature token.`
        if (isset($effects[$last]['target']) && ! str_contains($effects[$last]['target'], 'card') && ! in_array(ltrim($effects[$last]['target'], '?'), ['any', 'player', 'opponent', 'player_or_planeswalker'], true)
            && preg_match('/^Its controller creates (.+)$/', $sentence, $m) && ($token = self::token('create '.$m[1])) !== null) {
            $effects[] = $token + ['sameTarget' => true, 'toController' => true];

            return true;
        }
        // `Search target opponent's graveyard, hand, and library for any number of cards with that name and exile them.`
        if ($effects[$last]['type'] === 'choose_name'
            && preg_match("/^Search (target opponent|target player)'s graveyard, hand, and library for (any number of|up to (\\w+)) cards with (?:that|the chosen) name and exile them$/", $sentence, $m)
            && ($m[2] === 'any number of' || is_int(self::amount($m[3])))) {
            $effects[$last] = ['type' => 'name_exile', 'target' => self::TARGETS[strtolower($m[1])], 'filter' => $effects[$last]['filter'], 'amount' => $m[2] === 'any number of' ? 99 : self::amount($m[3])];

            return true;
        }
        // `Return target creature card from your graveyard to the battlefield. It gains haste.`
        if ($effects[$last]['type'] === 'reanimate' && preg_match('/^(?:It|That creature) gains haste$/', $sentence)) {
            $effects[] = ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => ['haste'], 'sameTarget' => true];

            return true;
        }
        // Kiki-Jiki: `Create a token that's a copy of target creature you control. It gains haste.`
        if ($effects[$last]['type'] === 'copy_token' && preg_match('/^(?:It|That token) gains haste(?: until end of turn)?$/', $sentence)) {
            $effects[$last]['haste'] = true;

            return true;
        }
        // "It gains haste. Exile it at the beginning of the next end step." after a token or a creature returned to the battlefield.
        for ($made = $last; $made > 0 && $effects[$made]['type'] === 'pump' && ($effects[$made]['sameTarget'] ?? false); $made--);
        if (in_array($effects[$made]['type'], ['token', 'reanimate', 'copy_token'], true)
            && preg_match('/^(Exile|Sacrifice) (?:it|them|that token|those tokens|that creature) at the beginning of the next end step$/', $sentence, $m)) {
            $effects[$made]['endStep'] = strtolower($m[1]);

            return true;
        }
        if (preg_match('/^If you do, draw (\w+) cards?$/', $sentence, $m) && is_int($n = self::amount($m[1])) && $effects[$last]['type'] === 'discard' && ($effects[$last]['may'] ?? false)) {
            $effects[$last]['draw'] = $n;

            return true;
        }
        // Ruthless Predation: `Target creature you control gets +1/+1 until end of turn. It fights target creature you don't control.`
        if (in_array($effects[$last]['target'] ?? null, ['creature_you_control', 'creature'], true)
            && preg_match("/^(?:Then )?(?:[Ii]t|[Tt]hat creature) fights (target creature you don't control|target creature an opponent controls|another target creature)$/", $sentence, $m)) {
            $effects[] = ['type' => 'fight', 'target' => $m[1] === 'another target creature' ? 'creature' : 'creature_opponent', 'mutual' => true];

            return true;
        }
        // `Clash with an opponent. If you win, …`: those effects only when the clash is won.
        if (preg_match('/^If you win, (.+)$/', $sentence, $m) && in_array('clash', array_column($effects, 'type'), true)
            && (($won = self::effects($m[1])) !== null || ($won = self::thatCreature($m[1], $effects)) !== null)) {
            foreach ($won as $effect) {
                $effects[] = $effect + ['ifWon' => true];
            }

            return true;
        }
        // Fire Prophecy: `You may put a card from your hand on the bottom of your library. If you do, draw a card.`
        if ($sentence === 'You may put a card from your hand on the bottom of your library') {
            $effects[] = ['type' => 'bottom_draw', 'draw' => false];

            return true;
        }
        if ($sentence === 'If you do, draw a card' && ($effects[$last]['type'] ?? '') === 'bottom_draw') {
            $effects[$last]['draw'] = true;

            return true;
        }
        // The Pacts: `At the beginning of your next upkeep, pay {2}{G}{G}. If you don't, you lose the game.`; see Game::$pacts.
        if (preg_match("/^If you don't, you lose the game$/", $sentence) && $effects[$last]['type'] === 'pact') {
            $effects[$last]['lose'] = true;

            return true;
        }
        // `Prevent the next 3 damage … You gain life equal to the damage prevented this way.`: see Game::dealDamage().
        if ($sentence === 'You gain life equal to the damage prevented this way' && $effects[$last]['type'] === 'prevent') {
            $effects[$last]['gain'] = true;

            return true;
        }
        if ($sentence === 'Then shuffle' || $sentence === 'Then that player shuffles') {
            $effects[] = ['type' => 'shuffle'] + ($sentence === 'Then shuffle' ? [] : ['that' => true, 'sameTarget' => true]);

            return true;
        }
        if (preg_match("/^(?:It|They|CARDNAME) can't be regenerated$/", $sentence) && in_array($effects[$last]['type'], ['destroy', 'damage'], true)) {
            $effects[$last]['noRegen'] = true;

            return true;
        }
        // "Those creatures don't untap during their controller's next untap step." / "Put a stun counter on each of them." after tapping.
        $tapped = [];
        for ($i = $last; isset($effects[$i]) && $effects[$i]['type'] === 'tap' && isset($effects[$i]['target']); $i--) {
            $tapped[] = $i;
        }
        $freeze = preg_match("/^(?:It|They|That creature|Those creatures|That permanent|Those permanents) (?:doesn't|don't) untap during (?:its|their) controller's next untap step$/i", $sentence);
        if ($tapped !== [] && ($freeze || preg_match('/^Put a stun counter on (?:it|that creature|that permanent|each of them)$/i', $sentence))) {
            foreach ($tapped as $i) {
                $effects[$i][$freeze ? 'freeze' : 'stun'] = $freeze ? true : 1;
            }

            return true;
        }
        if (preg_match('/^(?:It|They) gains? haste until end of turn$/', $sentence) && $effects[$last]['type'] === 'token') {
            $effects[$last]['haste'] = true;

            return true;
        }
        // "It gains haste until end of turn." after an effect with a target: the same creature.
        if ((isset($effects[$last]['target']) || ($effects[$last]['sameTarget'] ?? false))
            && preg_match('/^(?:It|That creature) (?:gets ([+-]\d+)\/([+-]\d+)(?: and gains (.+?))?|gains (.+?)) until end of turn$/', $sentence, $m)
            && ($keywords = self::keywordList(($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''))) !== null) {
            $effects[] = ['type' => 'pump', 'power' => (int) ($m[1] ?? 0), 'toughness' => (int) ($m[2] ?? 0), 'keywords' => $keywords, 'sameTarget' => true];

            return true;
        }

        return false;
    }

    /**
     * `flying and first strike` => keywords, or null when one is not a keyword.
     *
     * @param string $text
     *
     * @return string[]|null
     */
    private static function keywordList(string $text, bool $untilEndOfTurn = false): ?array
    {
        if (trim($text) === '') {
            return [];
        }
        // The Swords: `protection from black and from green`.
        $text = (string) preg_replace('/protection from (\w+) and from (\w+)/', 'protection from $1, protection from $2', $text);
        $words = array_map(fn ($word) => strtolower(trim($word)), preg_split('/,\s*(?:and\s+)?|\s+and\s+/', $text));
        foreach ($words as $word) {
            // See Game::protectedFrom().
            if (preg_match('/^protection from (white|blue|black|red|green|artifacts|creatures|enchantments|instants|sorceries|planeswalkers)$/', $word)) {
                continue;
            }
            // `gains protection from the color of your choice until end of turn`: the color is picked as it resolves (see Game::applyEffect()).
            if (! in_array($word, CardDefinition::KEYWORDS, true) && ! ($untilEndOfTurn && $word === 'protection from the color of your choice')) {
                return null;
            }
        }

        return $words;
    }

    /**
     * An amount: `3`, `three`, `a` or `X`.
     *
     * @param string $text
     *
     * @return int|string|null An int, `X`, or null when it is not an amount.
     */
    private static function amount(string $text): int|string|null
    {
        $text = strtolower($text);
        if (ctype_digit($text)) {
            return (int) $text;
        }
        if ($text === 'x') {
            return 'X';
        }

        return self::NUMBERS[$text] ?? null;
    }

    /**
     * One sentence of an instant or sorcery as an effect.
     *
     * @param string $sentence Without its final period.
     *
     * @return array|null
     */
    public static function effect(string $sentence): ?array
    {
        $targets = implode('|', array_map(fn ($phrase) => preg_quote($phrase, '/'), array_keys(self::TARGETS)));
        $s = $sentence;

        // `you may draw a card`, `you may mill two cards`: the game always does.
        // Leonin Snarecaster: `you may tap target creature`; tapping is always worth it.
        if (preg_match('/^you may ((?:tap|untap) target .+)$/i', $s, $m) && ($effect = self::effect($m[1])) !== null && in_array($effect['type'], ['tap', 'untap'], true)) {
            return $effect;
        }
        if (preg_match('/^you may ((?:draw|mill) .+)$/i', $s, $m) && ($effect = self::effect($m[1])) !== null && in_array($effect['type'], ['draw', 'mill'], true)) {
            return $effect;
        }

        // Adapt and monstrosity (rules 701.46 and 701.37).
        if (preg_match('/^(adapt|monstrosity) (\d+)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'amount' => (int) $m[2], 'self' => true];
        }
        if ($s === 'sacrifice CARDNAME' || $s === 'Sacrifice CARDNAME' || $s === 'sacrifice it' || $s === 'Sacrifice it') {
            return ['type' => 'sacrifice', 'self' => true];
        }
        // Explore (rule 701.44).
        if ($s === 'CARDNAME explores') {
            return ['type' => 'explore', 'self' => true];
        }
        if (preg_match('/^target creature you control explores$/i', $s)) {
            return ['type' => 'explore', 'target' => 'creature_you_control'];
        }
        if (preg_match("/^CARDNAME deals (\\w+) damage to ({$targets})$/i", $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[2])]];
        }
        // Explore (the sorcery): `You may play an additional land this turn.`
        if (preg_match('/^you may play an additional land this turn$/i', $s)) {
            return ['type' => 'extra_land'];
        }
        // Infernal Phantom: `When CARDNAME dies, it deals damage equal to its power to any target.` (its power as it last was).
        if (preg_match("/^(?:CARDNAME|it) deals damage equal to its power to ({$targets})$/i", $s, $m)) {
            return ['type' => 'damage', 'amount' => 'self_power', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Rumbling Rockslide: `CARDNAME deals damage to target creature equal to the number of lands you control.`
        if (preg_match("/^CARDNAME deals damage to ({$targets}) equal to the number of (lands|creatures|artifacts|enchantments|Forests|Islands|Mountains|Plains|Swamps) you control$/i", $s, $m)) {
            return ['type' => 'damage', 'amount' => 'yours:'.$m[2], 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^CARDNAME deals (\w+) damage to each opponent$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'each' => 'opponent'];
        }
        if (preg_match('/^CARDNAME deals (\w+) damage to each creature( without flying| with flying| your opponents control)?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'each' => match ($m[2] ?? '') {
                ' without flying' => 'creature_without_flying',
                ' with flying' => 'creature_with_flying',
                ' your opponents control' => 'creature_opponents',
                default => 'creature',
            }];
        }
        if (preg_match('/^(?:you )?draw (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'draw', 'amount' => $n];
        }
        if (preg_match('/^target player draws (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'draw', 'amount' => $n, 'target' => 'player'];
        }
        // Baleful Mastery: `an opponent draws a card` (the next opponent; see Game::opponent()).
        if (preg_match('/^an opponent draws (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'draw', 'amount' => $n, 'opponent' => true];
        }
        if (preg_match('/^you gain (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'gain_life', 'amount' => $n];
        }
        if (preg_match('/^target player gains (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'gain_life', 'amount' => $n, 'target' => 'player'];
        }
        if (preg_match('/^(target player|target opponent) loses (\w+) life$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^each opponent loses (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'each' => 'opponent'];
        }
        if (preg_match('/^each player loses (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'each' => 'player'];
        }
        if (preg_match("/^destroy ({$targets})$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'destroy', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^exile ({$targets})( until CARDNAME leaves the battlefield)?$/i", $s, $m) && (self::isPermanentTarget($m[1]) || (in_array(self::TARGETS[strtolower($m[1])], ['card_graveyard', 'creature_card_graveyard', 'card_graveyard_nonbasic'], true) && ($m[2] ?? '') === ''))) {
            return ['type' => 'exile', 'target' => self::TARGETS[strtolower($m[1])]] + (($m[2] ?? '') !== '' ? ['until' => true] : []);
        }
        // Hideaway's payoff: `You may play the exiled card without paying its mana cost if each player has no cards in hand.`
        if (preg_match('/^(?:you may )?play the exiled card without paying its mana cost(?: if (.+))?$/i', $s, $m)) {
            $if = match (strtolower($m[1] ?? '')) {
                '' => '',
                'your library has twenty or fewer cards in it' => 'library_le 20',
                'each player has no cards in hand' => 'no_hands',
                'creatures you control have total power 10 or greater' => 'total_power 10',
                'you attacked with three or more creatures this turn' => 'attackers 3',
                default => null,
            };
            if ($if !== null) {
                return ['type' => 'play_hidden'] + ($if === '' ? [] : ['if' => $if]);
            }
        }
        if (preg_match('/^at the beginning of your next upkeep, pay '.self::COST.'$/i', $s, $m) && ! str_contains($m[1], 'X')) {
            return ['type' => 'pact', 'cost' => $m[1]];
        }
        if (preg_match('/^prevent all combat damage that would be dealt this turn$/i', $s)) {
            return ['type' => 'fog'];
        }
        // The Ring tempts you (rule 701.54): see Game::temptWithRing().
        if (preg_match('/^the ring tempts you$/i', $s)) {
            return ['type' => 'ring'];
        }
        if (preg_match("/^damage can't be prevented this turn$/i", $s)) {
            return ['type' => 'no_prevention'];
        }
        if (preg_match("/^prevent (the next (\\d+|one|two|three|four|five)|all) damage that would be dealt to ({$targets}|CARDNAME|you) this turn$/i", $s, $m)
            && (in_array(strtolower($m[3]), ['cardname', 'you'], true) || in_array(self::TARGETS[strtolower($m[3])] ?? '', ['any', ...self::CREATURE_KINDS], true))) {
            $amount = strtolower($m[1]) === 'all' ? 'all' : self::amount($m[2]);
            $who = strtolower($m[3]);

            return ['type' => 'prevent', 'amount' => $amount] + match ($who) {
                'cardname' => ['self' => true],
                'you' => ['you' => true],
                default => ['target' => self::TARGETS[$who]],
            };
        }
        if (preg_match('/^transform CARDNAME$/i', $s)) {
            return ['type' => 'transform', 'self' => true];
        }
        // A preparation card's later way to become prepared again; see CardDefinition::$prepare.
        if (preg_match('/^(?:CARDNAME|it) becomes prepared$/i', $s)) {
            return ['type' => 'prepare', 'self' => true];
        }
        // A Saga's last chapter: `Exile CARDNAME, then return it to the battlefield transformed under your control.`
        if (preg_match('/^exile CARDNAME, then return it to the battlefield transformed under (?:your|its owner\'s) control$/i', $s)) {
            return ['type' => 'exile_transformed', 'self' => true];
        }
        if (preg_match("/^(?:you may )?shuffle (?:CARDNAME|it) into its owner's library$/i", $s)) {
            return ['type' => 'shuffle_self', 'self' => true];
        }
        // Haru-Onna: `you may return CARDNAME to its owner's hand` (it always does).
        if (preg_match("/^(?:you may )?return CARDNAME to its owner's hand$/i", $s)) {
            return ['type' => 'bounce', 'self' => true];
        }
        if (preg_match("/^return ({$targets}) to its owner's hand$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'bounce', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^counter (target spell|target creature spell|target noncreature spell)$/i', $s, $m)) {
            return ['type' => 'counter', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Manlands and animated artifacts: `Until end of turn, CARDNAME becomes a 4/4 white and blue Elemental creature with flying.`; see Game::isCreature().
        if (preg_match('/^(until end of turn, )?CARDNAME becomes an? (\d+)\/(\d+) [A-Za-z ]+? creature(?: with ([a-z ,]+?))?( until end of turn)?$/i', $s, $m)
            && ($m[1] !== '') !== (($m[5] ?? '') !== '') && ($keywords = self::keywordList($m[4] ?? '')) !== null) {
            return ['type' => 'base_pt', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'base' => [(int) $m[2], (int) $m[3]], 'self' => true, 'animate' => true];
        }
        // Signed X: `Target creature gets -X/-X until end of turn.`
        if (preg_match("/^({$targets}) gets ([+-])X\/([+-])(X|0) until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => [], 'xPump' => [$m[2] === '-' ? -1 : 1, $m[4] === '0' ? 0 : ($m[3] === '-' ? -1 : 1)], 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // `Target creature has base power and toughness 0/1 until end of turn.` (rule 613.4b); see Game::power().
        if (preg_match("/^({$targets}) has base power and toughness (\\d+)\\/(\\d+) until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'base_pt', 'power' => 0, 'toughness' => 0, 'keywords' => [], 'base' => [(int) $m[2], (int) $m[3]], 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^({$targets}) gets ([+-]\\w+)\\/([+-]\\w+)(?: and gains (.+?))? until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            $power = self::signed($m[2]);
            $toughness = self::signed($m[3]);
            $keywords = self::keywordList($m[4] ?? '');
            if ($power !== null && $toughness !== null && $keywords !== null) {
                return ['type' => 'pump', 'power' => $power, 'toughness' => $toughness, 'keywords' => $keywords, 'target' => self::TARGETS[strtolower($m[1])]];
            }
        }
        if (preg_match('/^CARDNAME gets ([+-]\d+)\/([+-]\d+)(?: and gains (.+?))? until end of turn$/i', $s, $m)
            && ($keywords = self::keywordList($m[3] ?? '')) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => $keywords, 'self' => true];
        }
        if (preg_match('/^CARDNAME gains (.+?) until end of turn$/i', $s, $m) && ($keywords = self::keywordList($m[1], true)) !== null) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'self' => true];
        }
        // Incubate (rule 701.53): an Incubator token with N +1/+1 counters and `{2}: Transform this artifact.`
        // `Put target creature on top of its owner's library.` / `… on the bottom …`; the owner's choice of top or bottom is top.
        if (preg_match("/^put ({$targets}) on (top|the bottom) of its owner's library$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'tuck', 'target' => self::TARGETS[strtolower($m[1])], 'bottom' => strtolower($m[2]) !== 'top'];
        }
        // Flicker: `Exile target creature you control, then return that card to the battlefield under its owner's control.`
        if (preg_match("/^exile ({$targets}), then return (?:that card|it) to the battlefield under (?:its owner's|your) control$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'flicker', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Scaled Herbalist: `You may put a land card from your hand onto the battlefield.`
        if (preg_match('/^(?:you may )?put a land card from your hand onto the battlefield( tapped)?$/i', $s, $m)) {
            return ['type' => 'land_from_hand', 'tapped' => ($m[1] ?? '') !== ''];
        }
        // Cackling Counterpart: `Create a token that's a copy of target creature you control.`
        // Kiki-style: `…, except it has haste`.
        if (preg_match("/^create a token that's a copy of ({$targets})(, except it has haste)?$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'copy_token', 'target' => self::TARGETS[strtolower($m[1])]] + (isset($m[2]) ? ['haste' => true] : []);
        }
        // Justice Strike: `Target creature deals damage to itself equal to its power.`
        if (preg_match("/^({$targets}) deals damage to itself equal to its power$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])] ?? '', self::CREATURE_KINDS, true)) {
            return ['type' => 'self_damage', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // `Put target card from a graveyard on the bottom of its owner's library.` (Junktroller)
        if (preg_match("/^put (target card from a graveyard|target card from your graveyard) on (top|the bottom) of (?:its owner's|your) library$/i", $s, $m)) {
            return ['type' => 'tuck', 'target' => self::TARGETS[strtolower($m[1])], 'bottom' => strtolower($m[2]) !== 'top'];
        }
        if (preg_match("/^({$targets})'s owner puts it on (?:their choice of )?the top or bottom of their library$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'tuck', 'target' => self::TARGETS[strtolower($m[1])], 'bottom' => false];
        }
        // Amass (rule 701.47): `Amass Orcs 2`, or the older `Amass 2` (Zombies).
        if (preg_match('/^amass (?:([A-Z][a-z]+) )?(\w+)$/i', $s, $m) && is_int($n = self::amount($m[2])) && $n > 0) {
            return ['type' => 'amass', 'amount' => $n, 'subtype' => $m[1] !== '' ? ucfirst(preg_replace('/s$/', '', $m[1])) : 'Zombie'];
        }
        // Earthbend (TLA): see Game's `earthbend` effect.
        if (preg_match('/^earthbend (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1])) && $n > 0) {
            return ['type' => 'earthbend', 'amount' => $n];
        }
        if (preg_match('/^incubate (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'incubate', 'amount' => $n];
        }
        if (preg_match('/^take an extra turn after this one$/i', $s)) {
            return ['type' => 'extra_turn'];
        }
        // Connive (rule 701.50): draw a card, then discard a card; a nonland discard puts a +1/+1 counter on it.
        if (preg_match('/^(?:CARDNAME|it) connives$/i', $s)) {
            return ['type' => 'connive', 'self' => true];
        }
        if (preg_match('/^you lose (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'you' => true];
        }
        if (preg_match('/^(?:you may )?put (\w+) (spore|charge|age|time|ki|oil|verse|fade|quest|storage|page|lore) counters? on CARDNAME$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'counters', 'amount' => $n, 'self' => true, 'kind' => strtolower($m[2])];
        }
        if (preg_match("/^(?:you may )?put (\w+) \+1\/\+1 counters? on ({$targets}|CARDNAME)$/i", $s, $m) && ($n = self::amount($m[1])) !== null) {
            if ($m[2] === 'CARDNAME') {
                return ['type' => 'counters', 'amount' => $n, 'self' => true];
            }
            if (in_array(self::TARGETS[strtolower($m[2])], self::CREATURE_KINDS, true)) {
                return ['type' => 'counters', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[2])]];
            }
        }
        // `Put a +1/+1 counter on each creature you control.` / `… each other creature you control.`
        if (preg_match('/^put (\w+) \+1\/\+1 counters? on each (other )?creature you control$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'counters', 'amount' => $n, 'each' => 'yours'] + (($m[2] ?? '') !== '' ? ['other' => true] : []);
        }
        // A ritual: `Add {R}{R}{R}.`
        if (preg_match('/^add ((\{[WUBRGC]\})\2*)$/i', $s, $m)) {
            return ['type' => 'add_mana', 'color' => strtoupper($m[2][1]), 'amount' => intdiv(strlen($m[1]), 3)];
        }
        if (preg_match("/^(tap|untap) ({$targets})$/i", $s, $m) && self::isPermanentTarget($m[2])) {
            return ['type' => strtolower($m[1]), 'target' => self::TARGETS[strtolower($m[2])]];
        }
        if (preg_match('/^(scry|surveil) (\d+)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'amount' => (int) $m[2]];
        }
        if (preg_match('/^regenerate (?:enchanted|equipped) creature$/i', $s)) {
            return ['type' => 'regenerate', 'enchanted' => true];
        }
        if (preg_match('/^regenerate CARDNAME$/i', $s)) {
            return ['type' => 'regenerate', 'self' => true];
        }
        if (preg_match("/^regenerate ({$targets})$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'regenerate', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // `Target opponent reveals their hand.` on its own: everyone sees it (see Game::log()).
        if (preg_match('/^(target opponent|target player) reveals (?:their|his or her) hand$/i', $s, $m)) {
            return ['type' => 'reveal_hand', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^(tap|untap) CARDNAME$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'self' => true];
        }
        // `When CARDNAME enters, enchanted creature gains first strike until end of turn.`
        if (preg_match('/^enchanted creature (?:gets ([+-]\d+)\/([+-]\d+)(?: and gains (.+?))?|gains (.+?)) until end of turn$/i', $s, $m)
            && ($keywords = self::keywordList(($m[4] ?? '') !== '' ? $m[4] : ($m[3] ?? ''))) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => $keywords, 'enchanted' => true];
        }
        if (preg_match('/^(tap|untap) enchanted (?:creature|permanent|artifact|land)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'enchanted' => true];
        }
        if (preg_match('/^turn CARDNAME face up$/i', $s)) {
            return ['type' => 'face_up', 'self' => true];
        }
        if (($token = self::token($s)) !== null) {
            return $token;
        }
        if (preg_match('/^counter (target spell|target creature spell|target noncreature spell) unless its controller pays \{(\d+|X)\}$/i', $s, $m)) {
            return ['type' => 'counter', 'target' => self::TARGETS[strtolower($m[1])], 'unless' => '{'.$m[2].'}'];
        }
        $each = ['creatures you control' => 'yours', 'creatures your opponents control' => 'opponents', 'all creatures' => 'all', 'creatures without flying' => 'without_flying', 'attacking creatures' => 'attacking', 'attacking creatures you control' => 'attacking'];
        $group = implode('|', array_keys($each));
        if (preg_match('/^(destroy|exile) (all creatures|all creatures you don\'t control|all creatures your opponents control|all artifacts|all enchantments|all nonland permanents|all artifacts and enchantments)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'all' => strtolower($m[2])];
        }
        // Time Stop, Ultima: `End the turn.`; see Game::endTheTurn().
        if (preg_match('/^end the turn$/i', $s)) {
            return ['type' => 'end_turn'];
        }
        // `Choose a creature type.` for a spell: the game picks the type most of your creatures share; see Game::choiceFor().
        if (preg_match('/^choose a creature type$/i', $s)) {
            return ['type' => 'choose_type'];
        }
        // `Creatures (you control) of the chosen type get +1/+1 until end of turn.` and `Elves you control get +2/+2 until end of turn.`
        if (preg_match('/^creatures( you control)? of the chosen type get ([+-]\d+)\/([+-]\d+)(?: and gain (.+?))? until end of turn$/i', $s, $m) && ($keywords = self::keywordList($m[4] ?? '')) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[2], 'toughness' => (int) $m[3], 'keywords' => $keywords, 'each' => $m[1] !== '' ? 'yours' : 'all', 'subtype' => 'chosen'];
        }
        if (preg_match('/^([A-Z][a-z]+s?) you control get ([+-]\d+)\/([+-]\d+)(?: and gain (.+?))? until end of turn$/', $s, $m) && ($type = self::subtypeOf($m[1])) !== null
            && ($keywords = self::keywordList($m[4] ?? '')) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[2], 'toughness' => (int) $m[3], 'keywords' => $keywords, 'each' => 'yours', 'subtype' => $type];
        }
        if (preg_match("/^({$group}) get ([+-]\\d+)\\/([+-]\\d+)(?: and gain (.+?))? until end of turn$/i", $s, $m)
            && ($keywords = self::keywordList($m[4] ?? '')) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[2], 'toughness' => (int) $m[3], 'keywords' => $keywords, 'each' => $each[strtolower($m[1])]];
        }
        if (preg_match("/^({$group}) gain (.+?) until end of turn$/i", $s, $m) && ($keywords = self::keywordList($m[2])) !== null) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'each' => $each[strtolower($m[1])]];
        }
        // Shrewd Hatchling: `Target creature can't block CARDNAME this turn.`
        if (preg_match("/^({$targets}) can't block CARDNAME this turn$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'cant_block_source', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Vortex Elemental: `Target creature blocks CARDNAME this turn if able.`
        if (preg_match("/^({$targets}) blocks CARDNAME this turn if able$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'must_block_source', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // "Target creature can't block this turn": a restriction until end of turn.
        if (preg_match("/^({$targets}|CARDNAME|{$group}) (can't block|can't be blocked) this turn$/i", $s, $m)) {
            $restriction = [strtolower($m[2])];
            if ($m[1] === 'CARDNAME') {
                return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $restriction, 'self' => true];
            }
            if (isset($each[strtolower($m[1])])) {
                return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $restriction, 'each' => $each[strtolower($m[1])]];
            }
            if (in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
                return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $restriction, 'target' => self::TARGETS[strtolower($m[1])]];
            }
        }
        // Memory's Journey: `Target player shuffles up to three target cards from their graveyard into their library.` The caster picks themself.
        if (preg_match('/^(?:target player shuffles|shuffle) up to (\w+) target cards? from (?:their|your) graveyard into (?:their|your) library$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'gy_shuffle', 'amount' => $n];
        }
        if (preg_match('/^(?:(?:then )?shuffle your graveyard into your library|target player shuffles their graveyard into their library)$/i', $s)) {
            return ['type' => 'gy_shuffle', 'amount' => null];
        }
        if (preg_match('/^(target player|target opponent) mills (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'mill', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^mill (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'mill', 'amount' => $n];
        }
        if (preg_match('/^each opponent mills (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'mill', 'amount' => $n, 'each' => 'opponent'];
        }
        if (preg_match('/^discover (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'discover', 'amount' => $n];
        }
        // Goblin Piledriver: `it gets +2/+0 until end of turn for each other attacking Goblin`.
        if (preg_match('/^(?:CARDNAME|it) gets \+(\d+)\/\+(\d+) until end of turn for each other attacking ([A-Z][a-z]+)$/', $s, $m) && ($type = self::subtypeOf($m[3])) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => [], 'self' => true, 'perAttacking' => $type];
        }
        // Drifter il-Dal: `At the beginning of your upkeep, sacrifice CARDNAME unless you pay {U}.`
        if (preg_match('/^sacrifice (?:CARDNAME|it) unless you pay ((?:\{[0-9WUBRGC]\})+)$/i', $s, $m)) {
            return ['type' => 'sac_unless', 'cost' => $m[1], 'self' => true];
        }
        // `When CARDNAME enters, it endures 2.`
        if (preg_match('/^(?:CARDNAME|it) endures (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'endure', 'amount' => $n, 'self' => true];
        }
        if (preg_match('/^you get ((?:\{E\})+)$/i', $s, $m)) {
            return ['type' => 'energy', 'amount' => substr_count($m[1], '{E}')];
        }
        if (preg_match('/^investigate$/i', $s)) {
            return ['type' => 'token', 'amount' => 1, 'token' => self::ARTIFACT_TOKENS['Clue']];
        }
        if (preg_match('/^create (\w+) (Food|Clue|Treasure|Blood|Lander|Map|Junk) tokens?$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'token', 'amount' => $n, 'token' => self::ARTIFACT_TOKENS[ucfirst(strtolower($m[2]))]];
        }
        if (preg_match("/^gain control of ({$targets}) until end of turn$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'control', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^attach (?:it|CARDNAME) to target creature you control$/', $s)) {
            return ['type' => 'attach', 'target' => 'creature_you_control'];
        }
        if (preg_match('/^untap (that creature|that permanent|it)$/i', $s)) {
            return ['type' => 'untap', 'sameTarget' => true];
        }
        if (preg_match("/^return ({$targets}) to (your hand|the battlefield)$/i", $s, $m)
            && in_array($kind = self::TARGETS[strtolower($m[1])], ['creature_card_yours', 'legendary_creature_card_yours', 'card_yours', 'instant_sorcery_card_yours', 'artifact_card_yours', 'land_card_yours', 'enchantment_card_yours', 'permanent_card_yours'], true)
            && ($m[2] === 'your hand' || str_ends_with($kind, 'creature_card_yours'))) {
            return ['type' => $m[2] === 'your hand' ? 'bounce' : 'reanimate', 'target' => $kind];
        }
        // `Return target creature card with mana value 3 or less from your graveyard to the battlefield.`
        if (preg_match("/^return ({$targets}) to (your hand|the battlefield)$/i", $s, $m) && str_starts_with($kind = self::TARGETS[strtolower($m[1])], 'creature_card_yours_mv_le_')) {
            return ['type' => $m[2] === 'your hand' ? 'bounce' : 'reanimate', 'target' => $kind];
        }
        // Reanimate: `Put target creature card from a graveyard onto the battlefield under your control.`
        if (preg_match("/^put ({$targets}) onto the battlefield under your control$/i", $s, $m) && self::TARGETS[strtolower($m[1])] === 'creature_card_graveyard') {
            return ['type' => 'reanimate', 'target' => 'creature_card_graveyard'];
        }
        if (preg_match('/^empower jace (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'empower', 'amount' => $n];
        }
        // `Copy target instant or sorcery spell. You may choose new targets for the copy.`: the copy keeps its targets.
        if (preg_match("/^copy ({$targets})$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])] ?? null, ['instant_sorcery_spell', 'instant_sorcery_spell_yours'], true)) {
            return ['type' => 'copy_spell', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Clash (rule 701.23); `If you win, …` follows (see TextParser::modifies()).
        if (preg_match('/^clash with an opponent$/i', $s)) {
            return ['type' => 'clash'];
        }
        // `Choose a nonland card name.`: the search after it uses the name; see TextParser::modifies().
        if (preg_match('/^choose an? (?:(nonland|creature|artifact) )?card name$/i', $s, $m)) {
            return ['type' => 'choose_name', 'filter' => ($m[1] ?? '') === '' ? 'any' : strtolower($m[1])];
        }
        // `When CARDNAME dies, put its counters on target creature you control.`: its +1/+1 counters, as modular moves them.
        if (preg_match('/^put its counters on target creature you control$/i', $s)) {
            return ['type' => 'counters', 'amount' => 'counters', 'target' => 'creature_you_control'];
        }
        if (preg_match('/^(tap|untap) all creatures (you control|your opponents control)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'each' => strtolower($m[2]) === 'you control' ? 'yours' : 'theirs'];
        }
        // Bolster (rule 701.39): counters on your creature with the least toughness.
        if (preg_match('/^bolster (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1])) && $n > 0) {
            return ['type' => 'bolster', 'amount' => $n];
        }
        if (preg_match('/^proliferate$/i', $s)) {
            return ['type' => 'proliferate'];
        }
        if (preg_match("/^return CARDNAME to its owner's hand$/i", $s)) {
            return ['type' => 'bounce', 'self' => true];
        }
        // Surgical Extraction: `Choose target card in a graveyard other than a basic land card.`
        if (preg_match("/^choose ({$targets})$/i", $s, $m) && (self::TARGETS[strtolower($m[1])] ?? null) === 'card_graveyard_nonbasic') {
            return ['type' => 'choose_target', 'target' => 'card_graveyard_nonbasic'];
        }
        // `Search its owner's graveyard, hand, and library for any number of cards with the same name as that card and exile them.`
        if (preg_match("/^search its (?:owner|controller)'s graveyard, hand, and library for (?:any number of|all|up to four) cards with the same name as that (?:card|land|creature|permanent) and exile them$/i", $s)) {
            return ['type' => 'exile_named', 'sameTarget' => true];
        }
        // Bribery: `Search target opponent's library for a creature card and put that card onto the battlefield under your control.`
        if (preg_match("/^search target opponent's library for an? (.+?) card and put (?:that card|it) onto the battlefield under your control$/i", $s, $m) && ($filter = self::cardFilter(strtolower($m[1]))) !== null) {
            return ['type' => 'steal_search', 'filter' => $filter, 'target' => 'opponent'];
        }
        // Path to Exile: `Its controller may search their library for a basic land card, put that card onto the battlefield tapped, then shuffle.`
        if (preg_match('/^its controller may search their library for a basic land card, put (?:it|that card) (onto the battlefield tapped|onto the battlefield), then shuffle$/i', $s, $m)) {
            return ['type' => 'search', 'find' => 'basic land', 'to' => strtolower($m[1]) === 'onto the battlefield' ? 'battlefield' : 'tapped', 'theirs' => true, 'sameTarget' => true];
        }
        // `Search your library for up to two basic land cards, put them onto the battlefield tapped, then shuffle.`
        if (preg_match('/^(?:you may )?search your library for up to (\w+) basic land cards, (?:reveal them, )?put them (into your hand|onto the battlefield tapped|onto the battlefield), then shuffle$/i', $s, $m) && is_int($n = self::amount($m[1])) && $n > 0) {
            return ['type' => 'search', 'find' => 'basic land', 'count' => $n, 'to' => match (strtolower($m[2])) {
                'into your hand' => 'hand',
                'onto the battlefield tapped' => 'tapped',
                default => 'battlefield',
            }];
        }
        // Spider-Bot: `… search your library for a basic land card, reveal it, then shuffle and put that card on top.`
        if (preg_match('/^(?:you may )?search your library for an? (basic land|plains|island|swamp|mountain|forest) card, reveal it, then shuffle and put that card on top$/i', $s, $m)) {
            return ['type' => 'search', 'find' => strtolower($m[1]) === 'basic land' ? 'basic land' : ucfirst(strtolower($m[1])), 'to' => 'top'];
        }
        // Cultivate: `… for up to two basic land cards, reveal those cards, put one onto the battlefield tapped and the other into your hand, then shuffle.`
        if (preg_match('/^(?:you may )?search your library for up to two basic land cards, reveal those cards, put one onto the battlefield tapped and the other into your hand, then shuffle$/i', $s)) {
            return ['type' => 'search', 'find' => 'basic land', 'count' => 2, 'to' => 'split'];
        }
        if (preg_match('/^(?:you may )?search your library for an? (basic land|plains|island|swamp|mountain|forest) card, (?:reveal it, )?put it (into your hand|onto the battlefield tapped|onto the battlefield), then shuffle$/i', $s, $m)) {
            return ['type' => 'search', 'find' => strtolower($m[1]) === 'basic land' ? 'basic land' : ucfirst(strtolower($m[1])), 'to' => match (strtolower($m[2])) {
                'into your hand' => 'hand',
                'onto the battlefield tapped' => 'tapped',
                default => 'battlefield',
            }];
        }
        // The Harbingers: `… search your library for a Goblin card, reveal it, then shuffle and put that card on top.`
        if (preg_match('/^(?:you may )?search your library for an? (.+?) card, reveal it, then shuffle and put that card on top$/i', $s, $m) && ($filter = self::cardFilter($m[1])) !== null) {
            return ['type' => 'tutor', 'filter' => $filter, 'to' => 'top'];
        }
        // A tutor: `Search your library for a creature card, reveal it, put it into your hand, then shuffle.`
        if (preg_match('/^(?:you may )?search your library for an? (?:(.+?) )?card, (?:reveal it, )?put (?:it|that card) (into your hand|onto the battlefield tapped|onto the battlefield), then shuffle$/i', $s, $m)
            && ($filter = ($m[1] ?? '') === '' ? 'any' : self::cardFilter($m[1])) !== null) {
            return ['type' => 'tutor', 'filter' => $filter, 'to' => match (strtolower($m[2])) {
                'into your hand' => 'hand',
                'onto the battlefield tapped' => 'tapped',
                default => 'battlefield',
            }];
        }
        if (preg_match('/^discard (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'discard', 'amount' => $n];
        }
        // An edict: `Target player sacrifices a creature of their choice.`, `Each opponent sacrifices a creature or planeswalker.`
        if (preg_match("/^(target player|target opponent|each opponent|each player) sacrifices (a|an|one|two|three) (creature|artifact|enchantment|land|permanent|nonland permanent|creature or planeswalker|artifact or creature|attacking creature)s?(?: of their choice)?$/i", $s, $m)
            && is_int($n = self::amount($m[2] === 'an' ? 'a' : $m[2]))) {
            $who = strtolower($m[1]);

            return ['type' => 'edict', 'amount' => $n, 'kind' => strtolower($m[3])] + (str_starts_with($who, 'target') ? ['target' => self::TARGETS[$who]] : ['each' => substr($who, 5)]);
        }
        // Graveyard hate: `Exile target player's graveyard.`, `Exile each opponent's graveyard.`, `Exile all graveyards.`
        if (preg_match("/^exile (target player|target opponent)'s graveyard$/i", $s, $m)) {
            return ['type' => 'exile_graveyard', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^exile (each opponent's graveyard|all graveyards)$/i", $s, $m)) {
            return ['type' => 'exile_graveyard', 'each' => str_starts_with(strtolower($m[1]), 'each') ? 'opponent' : 'player'];
        }
        // Learn (rule 701.48): without Lessons outside the game, the choice is to discard a card and draw one, or not.
        if (preg_match('/^learn$/i', $s)) {
            return ['type' => 'discard', 'amount' => 1, 'may' => true, 'draw' => 1];
        }
        // `You may discard a card. If you do, draw a card.`: see TextParser::modifies() for the draw.
        if (preg_match('/^you may discard a card$/i', $s)) {
            return ['type' => 'discard', 'amount' => 1, 'may' => true];
        }
        if (preg_match('/^(target player|target opponent) discards (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'discard', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^each (opponent|player) discards (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'discard', 'amount' => $n, 'each' => strtolower($m[1])];
        }
        if (preg_match("/^({$targets}) gains (.+?) until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)
            && ($keywords = self::keywordList($m[2], true)) !== null) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'target' => self::TARGETS[strtolower($m[1])]];
        }

        return null;
    }

    /**
     * `Create two 1/1 white Soldier creature tokens with flying` as an effect.
     *
     * @param string $sentence
     *
     * @return array|null
     */
    private static function token(string $sentence): ?array
    {
        $colors = ['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G', 'colorless' => null];
        $color = implode('|', array_keys($colors));
        if (! preg_match("/^create (\\w+) (\\d+)\\/(\\d+) ((?:{$color})(?:(?:, | and )(?:{$color}))*) ([A-Z][A-Za-z' ]*?) (artifact )?creature tokens?(?: with (.+))?$/i", $sentence, $m)
            || ($count = self::amount($m[1])) === null) {
            return null;
        }
        $keywords = self::keywordList($m[7] ?? '');
        if ($keywords === null) {
            return null;
        }
        $tokenColors = array_values(array_filter(array_map(fn ($word) => $colors[strtolower($word)] ?? null, preg_split('/, | and /', $m[4]))));
        $subtypes = preg_split('/\s+/', trim($m[5]));
        $artifact = ($m[6] ?? '') !== '';

        return [
            'type' => 'token',
            'amount' => $count,
            'token' => [
                'name' => implode(' ', $subtypes).' Token',
                'type' => 'Token '.($artifact ? 'Artifact ' : '').'Creature — '.implode(' ', $subtypes),
                'types' => $artifact ? ['Artifact', 'Creature'] : ['Creature'],
                'subtypes' => $subtypes,
                'colors' => $tokenColors,
                'power' => $m[2],
                'toughness' => $m[3],
                'text' => implode(', ', array_map('ucfirst', $keywords)),
                'manaCost' => null,
            ],
        ];
    }

    /**
     * `+3` or `-X` as a number; X is not supported here.
     *
     * @param string $text
     *
     * @return int|null
     */
    private static function signed(string $text): ?int
    {
        return preg_match('/^[+-]\d+$/', $text) ? (int) $text : null;
    }

    private static function isPermanentTarget(string $phrase): bool
    {
        return ! in_array(self::TARGETS[strtolower($phrase)], ['any', 'player', 'opponent', 'player_or_planeswalker', 'spell', 'creature_spell', 'noncreature_spell', 'instant_sorcery_spell', 'instant_sorcery_spell_yours', 'creature_card_yours', 'legendary_creature_card_yours', 'card_yours', 'card_graveyard_nonbasic', 'instant_sorcery_card_yours', 'artifact_card_yours', 'land_card_yours', 'enchantment_card_yours', 'permanent_card_yours', 'creature_card_graveyard', 'card_graveyard'], true)
            && ! str_starts_with(self::TARGETS[strtolower($phrase)], 'creature_card_yours_mv_le_');
    }
}
