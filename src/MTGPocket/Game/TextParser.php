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
        'target creature card from your graveyard' => 'creature_card_yours',
        'target card from your graveyard' => 'card_yours',
        'target instant or sorcery card from your graveyard' => 'instant_sorcery_card_yours',
        'target artifact card from your graveyard' => 'artifact_card_yours',
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
    ];

    /** Predefined artifact tokens (rule 111.10). */
    private const array ARTIFACT_TOKENS = [
        'Food' => ['name' => 'Food', 'type' => 'Token Artifact — Food', 'types' => ['Artifact'], 'subtypes' => ['Food'], 'colors' => [], 'text' => '{2}, {T}, Sacrifice this token: You gain 3 life.', 'manaCost' => null],
        'Clue' => ['name' => 'Clue', 'type' => 'Token Artifact — Clue', 'types' => ['Artifact'], 'subtypes' => ['Clue'], 'colors' => [], 'text' => '{2}, Sacrifice this token: Draw a card.', 'manaCost' => null],
        'Treasure' => ['name' => 'Treasure', 'type' => 'Token Artifact — Treasure', 'types' => ['Artifact'], 'subtypes' => ['Treasure'], 'colors' => [], 'text' => '{T}, Sacrifice this token: Add one mana of any color.', 'manaCost' => null],
    ];

    /** Target kinds that are always a creature. */
    private const array CREATURE_KINDS = [
        'creature', 'creature_you_control', 'creature_opponent', 'attacking_or_blocking', 'attacking', 'blocking',
        'creature_flying', 'creature_no_flying', 'tapped_creature', 'untapped_creature',
        'creature_power_ge_2', 'creature_power_ge_3', 'creature_power_ge_4', 'creature_power_ge_5', 'creature_power_ge_6', 'creature_power_le_1', 'creature_power_le_2', 'creature_power_le_3', 'creature_power_le_4',
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
            if ($spell && preg_match('/^Spree\.?$/', $line)) {
                [$modes, $read] = [[], true];
                while (isset($lines[$i + 1]) && preg_match('/^\+ '.self::COST.' — (.+)$/u', $lines[$i + 1], $match)) {
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
                    $result['choose'] = ['min' => 1, 'max' => count($modes)];
                } else {
                    $result['unsupported'][] = 'Spree';
                }

                continue;
            }
            // `{2}{B}: Return CARDNAME from your graveyard to your hand.`: see Game::regrow().
            if (preg_match('/^'.self::COST.': Return CARDNAME from your graveyard to your hand\.$/', $line, $match) && ! str_contains($match[1], 'X')) {
                $result['altCosts']['regrow'] = $match[1];

                continue;
            }
            // Static lines that work like keywords.
            $statics = [
                'You have no maximum hand size.' => 'no maximum hand size', 'You may play an additional land on each of your turns.' => 'additional land', 'You may play lands from your graveyard.' => 'lands from graveyard',
                'If CARDNAME is in your opening hand, you may begin the game with it on the battlefield.' => 'leyline',
                'A deck can have any number of cards named CARDNAME.' => 'any number',
                'You have hexproof.' => 'you have hexproof',
                'You may look at the top card of your library any time.' => 'look at top any time',
                // Daybound sets it to day as it enters when it is neither (rule 702.145); see Game::dayNight().
                "If it's neither day nor night, it becomes day as CARDNAME enters." => 'daybound',
                'If CARDNAME would be put into a graveyard from anywhere, exile it instead.' => 'exile instead of graveyard',
                'CARDNAME escapes with a +1/+1 counter on it.' => 'escapes with 1',
                'CARDNAME escapes with two +1/+1 counters on it.' => 'escapes with 2',
                'CARDNAME escapes with three +1/+1 counters on it.' => 'escapes with 3',
                'CARDNAME escapes with four +1/+1 counters on it.' => 'escapes with 4',
                // Read by Game::canBlock().
                "Creatures with power less than CARDNAME's power can't block it." => "can't be blocked by lesser power",
                // Read ahead (rule 714.3a): the controller may start a Saga on a later chapter; Game always starts it on chapter I, which is one of the legal choices.
                'Read ahead' => 'read ahead',
            ];
            if (! $spell && isset($statics[$line])) {
                $result['keywords'][] = $statics[$line];

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
            // `CARDNAME costs {1} less to cast for each creature in your party.`: read by Game::paymentFor().
            if (preg_match('/^(?:This spell|CARDNAME) costs \{(\d+)\} less to cast for each (creature in your party|artifact you control|creature you control|land you control|card in your hand|opponent you have)\.$/', $line, $match)) {
                $result['keywords'][] = "costs {$match[1]} less for each ".match ($match[2]) {
                    'creature in your party' => 'party',
                    'opponent you have' => 'opponent',
                    'card in your hand' => 'card',
                    default => strtok($match[2], ' '),
                };

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
                || (! $spell && self::activated($line, $result))) {
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
            if (($reveal = self::revealDiscard($line)) !== null) {
                [$result['effects'][], $line] = $reveal;
            }
            foreach (self::sentences($line) as $sentence) {
                if ($sentence === "CARDNAME can't be countered") {
                    $result['keywords'][] = "can't be countered";
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
        if (($result['kicker'] === null || ! preg_match('/^If CARDNAME was kicked, (.+)$/', $sentence, $match))
            && (! in_array('bargain', $result['keywords'], true) || ! preg_match('/^If CARDNAME was bargained, (.+)$/', $sentence, $match))) {
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
        $effects = self::effects($match[1]);
        if ($effects === null) {
            return false;
        }
        foreach ($effects as $effect) {
            $result['effects'][] = $effect + ['kicked' => true];
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
            } elseif ($word === 'enlist' && ! $spell) {
                $found['keywords'][] = 'enlist';
            } elseif (preg_match('/^protection from (white|blue|black|red|green)$/', $word) && ! $spell) {
                // Protection from a color (rule 702.16): see Game::protectedFrom().
                $found['keywords'][] = $word;
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
            } elseif ($word === 'ingest' && ! $spell) {
                // Ingest (rule 702.115): the damaged player exiles the top card of their library.
                $found['triggered'][] = ['text' => 'Ingest', 'event' => 'combat_damage', 'effects' => [['type' => 'ingest']]];
            } elseif (preg_match('/^(dash|evoke|warp|plot|blitz) '.self::COST.'$/i', $part, $m)) {
                // Other ways to cast it: see Game::castOptions().
                $found['altCosts'][strtolower($m[1])] = $m[2];
            } elseif (preg_match('/^echo '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Echo (rule 702.30): see Game::applyEffect().
                $found['altCosts']['echo'] = $m[1];
                $found['triggered'][] = ['text' => $part, 'event' => 'upkeep', 'effects' => [['type' => 'echo', 'self' => true]]];
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
        if (! preg_match('/^\{T\}(, Pay 1 life|, Sacrifice CARDNAME)?: Add (.+?)\.?(?: CARDNAME deals 1 damage to you\.)?$/', $line, $match)) {
            return false;
        }
        $sacrifice = ($match[1] ?? '') === ', Sacrifice CARDNAME';
        $pain = (($match[1] ?? '') !== '' && ! $sacrifice) || str_ends_with($line, 'deals 1 damage to you.');
        $what = $match[2];
        $ability = null;
        if (preg_match('/^one mana of any colou?r$/i', $what)) {
            $ability = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];
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
        if ($sacrifice) {
            // A Treasure: one use.
            if ($result['mana'] !== null) {
                return false;
            }
            $result['mana'] = $ability + ['sacrifice' => true];

            return true;
        }

        $existing = $result['mana'];
        if ($existing !== null && $existing['count'] === 0) {
            // The filter ability came first.
            $ability += array_intersect_key($existing, ['filter' => true, 'filterAs' => true, 'filterPays' => true]);
            $existing = null;
        }
        if ($existing === null) {
            $result['mana'] = $ability;

            return true;
        }
        if (isset($existing['fixed']) || isset($ability['fixed'])) {
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
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with (\w+) (oil|charge|time|lore|loyalty|verse|fade|ice|age|quest|study|storage|page) counters? on it\.?$/', $line, $match) && is_int($n = self::amount($match[1]))) {
            $result['otherCounters'][$match[2]] = $n;

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with (\w+) \+1\/\+1 counters? on it\.?$/', $line, $match) && ($n = self::amount($match[1])) !== null) {
            $result['counters'] = $n;

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
        // `Each creature you control with a +1/+1 counter on it has trample.`
        if (preg_match('/^(?:Each (other )?creature you control with a \+1\/\+1 counter on it has|(Other )?[Cc]reatures you control with \+1\/\+1 counters on them have) (.+?)\.?$/', $line, $m)) {
            if (($keywords = self::keywordList($m[3])) === null) {
                return false;
            }
            $result['anthem'][] = ['power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'other' => $m[1] !== '' || $m[2] !== '', 'withCounter' => true];

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
        if (! preg_match('/^As an additional cost to cast CARDNAME, (sacrifice a creature|sacrifice an artifact or creature|discard a card|exile a creature card from your graveyard|exile a card from your graveyard)\.?$/', $line, $m)) {
            return false;
        }
        $result['additionalCost'] = match ($m[1]) {
            'sacrifice a creature' => 'sacrifice_creature',
            'sacrifice an artifact or creature' => 'sacrifice_artifact_or_creature',
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
            "attacks each combat if able" => ["attacks each combat if able"],
            // Read by Game::declareBlockers().
            'must be blocked if able' => ['must be blocked if able'],
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
        if (! preg_match('/^CARDNAME (.+)$/', $line, $match) || ($found = self::restrictions($match[1])) === null) {
            return false;
        }
        $result['keywords'] = array_values(array_unique([...$result['keywords'], ...$found]));

        return true;
    }

    private static function aura(string $line, array &$result): bool
    {
        if (preg_match('/^Enchant (.+)$/', $line, $match) && isset(self::ENCHANT[strtolower($match[1])])) {
            $result['aura'] ??= ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['aura']['enchant'] = self::ENCHANT[strtolower($match[1])];

            return true;
        }
        // "You control enchanted creature.": see Game::updateControl().
        if (preg_match('/^You control enchanted (?:creature|permanent|artifact|land|planeswalker)\.?$/', $line)) {
            $result['aura'] ??= ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['aura']['control'] = true;

            return true;
        }
        $keywords = null;
        if (preg_match('/^Enchanted creature gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))?\.?$/', $line, $match)
            || preg_match('/^Enchanted creature (has) (.+?)\.?$/', $line, $match)) {
            $keywords = self::keywordList($match[1] === 'has' ? $match[2] : ($match[3] ?? ''));
        } elseif (preg_match('/^Enchanted creature (.+)$/', $line, $match)) {
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
        if (! preg_match('/^([+−-]?)(\d+): (.+)$/u', $line, $match) || ($effects = self::effects($match[3])) === null) {
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
            'deals combat damage to a player' => 'combat_damage', 'is turned face up' => 'turned_face_up',
            'exploits a creature' => 'exploits', 'becomes monstrous' => 'monstrous',
            'is put into a graveyard from the battlefield' => 'to_graveyard', 'becomes blocked' => 'blocked',
        ];
        $casts = [
            'a noncreature spell' => 'cast_noncreature', 'an instant or sorcery spell' => 'cast_instant_sorcery', 'a spell' => 'cast_spell', 'a Spirit or Arcane spell' => 'cast_spirit_arcane',
        ];
        $saddled = false;
        if (preg_match('/^When CARDNAME becomes level (\d+), (.+)$/', $line, $match)) {
            $event = "class_level_{$match[1]}";
            $text = $match[2];
        } elseif (preg_match('/^(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks|deals combat damage to a player|is turned face up|exploits a creature|becomes monstrous|is put into a graveyard from the battlefield|becomes the target of a spell or ability|becomes blocked)( while saddled)?, (.+)$/', $line, $match)) {
            $event = $events[$match[1]];
            $saddled = $match[2] !== '';
            $text = $match[3];
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
        } elseif (preg_match('/^At the beginning of the end step, (.+)$/', $line, $match)) {
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
        } elseif (preg_match('/^Whenever you draw your second card each turn, (.+)$/', $line, $match)) {
            $event = 'second_draw';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you gain life, (.+)$/', $line, $match)) {
            $event = 'gain_life';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you cast (a noncreature spell|an instant or sorcery spell|a spell|a Spirit or Arcane spell), (.+)$/', $line, $match)) {
            $event = $casts[$match[1]];
            $text = $match[2];
        } else {
            return false;
        }
        if ($event === 'enters' && preg_match("/^if tribute wasn't paid, (.+)$/", $text, $match) && preg_grep('/^tribute \d+$/', $result['keywords']) !== []) {
            $text = $match[1];
        }
        $kicked = false;
        if ($event === 'enters' && $result['kicker'] !== null && preg_match('/^if it was kicked, (.+)$/', $text, $match)) {
            [$kicked, $text] = [true, $match[1]];
        }
        $effects = self::effects($text);
        // "Whenever CARDNAME attacks, put a +1/+1 counter on it.": with no target, "it" is CARDNAME.
        if ($effects === null && ! str_contains($text, 'target')) {
            $effects = self::effects(preg_replace('/\bit\b/', 'CARDNAME', $text));
        }
        if ($effects === null) {
            return false;
        }
        $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects] + ($kicked ? ['kicked' => true] : []) + ($saddled ? ['saddled' => true] : []);

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
        if ($cost['sacrifice'] ?? false) {
            // `Sacrifice CARDNAME: It deals 2 damage to target creature.`: "it" is the sacrificed permanent.
            $text = preg_replace('/^It deals /', 'CARDNAME deals ', $text);
        }
        $effects = self::effects($text);
        if ($effects === null) {
            return false;
        }
        $result['activated'][] = ['text' => $line, 'cost' => $cost, 'effects' => $effects, 'sorcery' => $sorcery, 'once' => $once];

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
        $where = '(on the bottom of your library(?: in (?:a random|any) order)?|into your graveyard)';
        $to = '(into your hand|onto the battlefield tapped|onto the battlefield)';
        $after = '(?:\.|$)\s*(.*)$/s';
        $dest = 'hand';
        if ($head[1] === 'Look at' && preg_match("/^, then put them back in any order{$after}", $head[3], $m)) {
            [$take, $may, $filter, $rest, $tail] = [0, true, 'any', 'top', $m[1]];
        } elseif (preg_match("/^\\. Put (up to )?(\\w+) of (?:them|those cards) into your hand and the (?:rest|other|others) {$where}{$after}", $head[3], $m) && is_int($take = self::amount($m[2]))) {
            [$may, $filter, $rest, $tail] = [$m[1] !== '', 'any', $m[3], $m[4]];
        } elseif (preg_match("/^\\. (?:You may (?:reveal|put|choose) (an?|up to \\w+|any number of) (?:(.+?) )?cards? from among them(?: and put (?:it|that card|them|those cards|the revealed cards) {$to}| {$to})?|Put all (.+?) cards revealed this way {$to})(?:\\. Put the rest| and the rest) {$where}{$after}", $head[3], $m)) {
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
            $filter = $words === '' ? 'any' : self::cardFilter($words);
            if ($filter === null || ! is_int($take)) {
                return null;
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
        if (! preg_match('/^Exile the top (card|(\w+) cards) of your library\. (?:Until the end of your next turn, you may play (?:that card|those cards|them)|You may play (?:that card|those cards|them) (this turn|until the end of your next turn))\.?$/', trim($text), $m)) {
            return null;
        }
        $n = $m[1] === 'card' ? 1 : self::amount($m[2]);
        if (! is_int($n) || $n < 1) {
            return null;
        }

        return ['type' => 'impulse', 'amount' => $n, 'until' => ($m[3] ?? '') === 'this turn' ? 'this' : 'next'];
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
            'noncreature' => 'noncreature', 'nonland permanent' => 'nonland_permanent', 'battle' => 'battle',
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
        $filter = implode('|', array_map(fn ($f) => preg_quote($f, '/'), array_keys($filters)));
        // `… That player discards that card.`, or `… and exile that card.` / `… Exile that card.`
        if (! preg_match("/^(Target opponent|Target player) reveals (?:their|his or her) hand\. You choose an? ({$filter}) from it(\. That player discards that card| and exile that card|\. Exile that card)\.?\s*(.*)$/s", ucfirst(trim($text)), $m)) {
            return null;
        }

        return [['type' => 'discard', 'amount' => 1, 'target' => self::TARGETS[strtolower($m[1])], 'chooser' => 'you', 'filter' => $filters[$m[2]]] + (str_contains($m[3], 'xile') ? ['exile' => true] : []), trim($m[4])];
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
        // A bounce land's `When this land enters, return a land you control to its owner's hand.`
        if (preg_match("/^return an? (land|creature|nonland permanent) you control to its owner's hand$/i", $sentence, $m)) {
            return [['type' => 'return_own', 'filter' => str_replace(' ', '_', strtolower($m[1]))]];
        }
        // "Tap up to two target creatures": optional targets, `?kind` (see Game::isLegalTarget()).
        if (preg_match('/^(tap|untap) up to (\w+) target (.+)$/i', $sentence, $m) && is_int($n = self::amount($m[2])) && $n >= 1 && $n <= 5
            && isset(self::TARGETS[$phrase = 'target '.preg_replace('/s\b/', '', strtolower($m[3]))]) && self::isPermanentTarget($phrase)) {
            return array_fill(0, $n, ['type' => strtolower($m[1]), 'target' => '?'.self::TARGETS[$phrase]]);
        }
        // "Return up to two target creature cards from your graveyard to your hand": that many optional targets.
        if (preg_match('/^(.*)\bup to (two|three|four|five) target (.+)$/i', $sentence, $m) && is_int($n = self::amount($m[2]))
            && ($found = self::sentenceEffects($m[1].'target '.preg_replace('/^((?:\w+ )*?)(\w+)s\b/', '$1$2', $m[3]))) !== null
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
        // "If that creature would die this turn, exile it instead." after damage.
        if (preg_match('/^If (?:that creature|a creature dealt damage this way|it) would die this turn, exile it instead$/', $sentence)) {
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
        // "Destroy target creature. Its controller loses 2 life."
        if (isset($effects[$last]['target']) && in_array($effects[$last]['target'], self::CREATURE_KINDS, true)
            && preg_match('/^Its controller loses (\w+) life$/', $sentence, $m) && is_int($n = self::amount($m[1]))) {
            $effects[] = ['type' => 'lose_life', 'amount' => $n, 'sameTarget' => true, 'toController' => true];

            return true;
        }
        // "It gains haste. Exile it at the beginning of the next end step." after a token or a creature returned to the battlefield.
        for ($made = $last; $made > 0 && $effects[$made]['type'] === 'pump' && ($effects[$made]['sameTarget'] ?? false); $made--);
        if (in_array($effects[$made]['type'], ['token', 'reanimate'], true)
            && preg_match('/^(Exile|Sacrifice) (?:it|them|that token|those tokens|that creature) at the beginning of the next end step$/', $sentence, $m)) {
            $effects[$made]['endStep'] = strtolower($m[1]);

            return true;
        }
        if (preg_match('/^If you do, draw (\w+) cards?$/', $sentence, $m) && is_int($n = self::amount($m[1])) && $effects[$last]['type'] === 'discard' && ($effects[$last]['may'] ?? false)) {
            $effects[$last]['draw'] = $n;

            return true;
        }
        if ($sentence === 'Then shuffle' || $sentence === 'Then that player shuffles') {
            $effects[] = ['type' => 'shuffle'] + ($sentence === 'Then shuffle' ? [] : ['that' => true, 'sameTarget' => true]);

            return true;
        }
        if (preg_match("/^(?:It|They|CARDNAME) can't be regenerated$/", $sentence) && $effects[$last]['type'] === 'destroy') {
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
    private static function keywordList(string $text): ?array
    {
        if (trim($text) === '') {
            return [];
        }
        $words = array_map(fn ($word) => strtolower(trim($word)), preg_split('/,\s*(?:and\s+)?|\s+and\s+/', $text));
        foreach ($words as $word) {
            if (! in_array($word, CardDefinition::KEYWORDS, true)) {
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
        if (preg_match("/^CARDNAME deals (\\w+) damage to ({$targets})$/i", $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[2])]];
        }
        if (preg_match('/^CARDNAME deals (\w+) damage to each opponent$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'each' => 'opponent'];
        }
        if (preg_match('/^CARDNAME deals (\w+) damage to each creature$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'each' => 'creature'];
        }
        if (preg_match('/^draw (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'draw', 'amount' => $n];
        }
        if (preg_match('/^target player draws (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'draw', 'amount' => $n, 'target' => 'player'];
        }
        if (preg_match('/^you gain (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'gain_life', 'amount' => $n];
        }
        if (preg_match('/^(target player|target opponent) loses (\w+) life$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^each opponent loses (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'each' => 'opponent'];
        }
        if (preg_match("/^destroy ({$targets})$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'destroy', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^exile ({$targets})( until CARDNAME leaves the battlefield)?$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'exile', 'target' => self::TARGETS[strtolower($m[1])]] + (($m[2] ?? '') !== '' ? ['until' => true] : []);
        }
        if (preg_match('/^prevent all combat damage that would be dealt this turn$/i', $s)) {
            return ['type' => 'fog'];
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
        // A Saga's last chapter: `Exile CARDNAME, then return it to the battlefield transformed under your control.`
        if (preg_match('/^exile CARDNAME, then return it to the battlefield transformed under (?:your|its owner\'s) control$/i', $s)) {
            return ['type' => 'exile_transformed', 'self' => true];
        }
        if (preg_match("/^(?:you may )?shuffle CARDNAME into its owner's library$/i", $s)) {
            return ['type' => 'shuffle_self', 'self' => true];
        }
        if (preg_match("/^return ({$targets}) to its owner's hand$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'bounce', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^counter (target spell|target creature spell|target noncreature spell)$/i', $s, $m)) {
            return ['type' => 'counter', 'target' => self::TARGETS[strtolower($m[1])]];
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
        if (preg_match('/^CARDNAME gains (.+?) until end of turn$/i', $s, $m) && ($keywords = self::keywordList($m[1])) !== null) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'self' => true];
        }
        // Incubate (rule 701.53): an Incubator token with N +1/+1 counters and `{2}: Transform this artifact.`
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
        if (preg_match("/^(tap|untap) ({$targets})$/i", $s, $m) && self::isPermanentTarget($m[2])) {
            return ['type' => strtolower($m[1]), 'target' => self::TARGETS[strtolower($m[2])]];
        }
        if (preg_match('/^(scry|surveil) (\d+)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'amount' => (int) $m[2]];
        }
        if (preg_match('/^regenerate CARDNAME$/i', $s)) {
            return ['type' => 'regenerate', 'self' => true];
        }
        if (preg_match("/^regenerate ({$targets})$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'regenerate', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^(tap|untap) CARDNAME$/', $s, $m)) {
            return ['type' => strtolower($m[1]), 'self' => true];
        }
        if (preg_match('/^(tap|untap) enchanted creature$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'enchanted' => true];
        }
        if (preg_match('/^turn CARDNAME face up$/i', $s)) {
            return ['type' => 'face_up', 'self' => true];
        }
        if (($token = self::token($s)) !== null) {
            return $token;
        }
        if (preg_match('/^counter (target spell|target creature spell|target noncreature spell) unless its controller pays \{(\d+)\}$/i', $s, $m)) {
            return ['type' => 'counter', 'target' => self::TARGETS[strtolower($m[1])], 'unless' => '{'.$m[2].'}'];
        }
        $each = ['creatures you control' => 'yours', 'creatures your opponents control' => 'opponents', 'all creatures' => 'all'];
        $group = implode('|', array_keys($each));
        if (preg_match('/^destroy (all creatures|all creatures you don\'t control|all creatures your opponents control|all artifacts|all enchantments|all nonland permanents|all artifacts and enchantments)$/i', $s, $m)) {
            return ['type' => 'destroy', 'all' => strtolower($m[1])];
        }
        if (preg_match("/^({$group}) get ([+-]\\d+)\\/([+-]\\d+)(?: and gain (.+?))? until end of turn$/i", $s, $m)
            && ($keywords = self::keywordList($m[4] ?? '')) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[2], 'toughness' => (int) $m[3], 'keywords' => $keywords, 'each' => $each[strtolower($m[1])]];
        }
        if (preg_match("/^({$group}) gain (.+?) until end of turn$/i", $s, $m) && ($keywords = self::keywordList($m[2])) !== null) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'each' => $each[strtolower($m[1])]];
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
        if (preg_match('/^(target player|target opponent) mills (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'mill', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^mill (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'mill', 'amount' => $n];
        }
        if (preg_match('/^each opponent mills (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'mill', 'amount' => $n, 'each' => 'opponent'];
        }
        if (preg_match('/^you get ((?:\{E\})+)$/', $s, $m)) {
            return ['type' => 'energy', 'amount' => substr_count($m[1], '{E}')];
        }
        if (preg_match('/^investigate$/i', $s)) {
            return ['type' => 'token', 'amount' => 1, 'token' => self::ARTIFACT_TOKENS['Clue']];
        }
        if (preg_match('/^create (\w+) (Food|Clue|Treasure) tokens?$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
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
            && in_array($kind = self::TARGETS[strtolower($m[1])], ['creature_card_yours', 'card_yours', 'instant_sorcery_card_yours', 'artifact_card_yours', 'land_card_yours', 'enchantment_card_yours'], true)
            && ($m[2] === 'your hand' || $kind === 'creature_card_yours')) {
            return ['type' => $m[2] === 'your hand' ? 'bounce' : 'reanimate', 'target' => $kind];
        }
        if (preg_match('/^empower jace (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'empower', 'amount' => $n];
        }
        // `Copy target instant or sorcery spell. You may choose new targets for the copy.`: the copy keeps its targets.
        if (preg_match("/^copy ({$targets})$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])] ?? null, ['instant_sorcery_spell', 'instant_sorcery_spell_yours'], true)) {
            return ['type' => 'copy_spell', 'target' => self::TARGETS[strtolower($m[1])]];
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
            && ($keywords = self::keywordList($m[2])) !== null) {
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
            || ($count = self::amount($m[1])) === null || $count === 'X') {
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
        return ! in_array(self::TARGETS[strtolower($phrase)], ['any', 'player', 'opponent', 'player_or_planeswalker', 'spell', 'creature_spell', 'noncreature_spell', 'instant_sorcery_spell', 'instant_sorcery_spell_yours', 'creature_card_yours', 'card_yours', 'card_graveyard_nonbasic', 'instant_sorcery_card_yours', 'artifact_card_yours', 'land_card_yours', 'enchantment_card_yours'], true);
    }
}
