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
        'target land' => 'land',
        'target planeswalker' => 'planeswalker',
        'target nonland permanent' => 'nonland_permanent',
        'target permanent' => 'permanent',
        'target creature you control' => 'creature_you_control',
        'target creature an opponent controls' => 'creature_opponent',
        'target creature you don\'t control' => 'creature_opponent',
        'target spell' => 'spell',
        'target creature spell' => 'creature_spell',
        'target noncreature spell' => 'noncreature_spell',
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
            'ward' => null, 'kicker' => null, 'kickerCounters' => 0, 'flashback' => null, 'cycling' => null, 'morph' => null, 'levels' => [],
            'unsupported' => [],
        ];
        $spell = ! $card->isPermanentCard();
        $lines = self::lines($card);

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // A modal spell's `Choose one —` and its `•` modes.
            if (preg_match('/^Choose (one|two|three|one or both|one or more) —$/u', $line, $match)) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    $bullets[] = trim(mb_substr($lines[++$i], 1));
                }
                if (! self::modes($spell, $match[1], $bullets, $result)) {
                    $result['unsupported'][] = implode("\n", [$line, ...array_map(fn ($bullet) => "• {$bullet}", $bullets)]);
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

            if (self::keywords($line, $result, $spell)
                || self::manaAbility($line, $result)
                || self::entersTapped($line, $result)
                || (! $spell && self::restriction($line, $result))
                || ($card->isAura() && self::aura($line, $result))
                || ($card->isEquipment() && self::equipment($line, $result))
                || ($card->isPlaneswalker() && self::loyalty($line, $result))
                || (! $spell && self::triggered($line, $result))
                || (! $spell && self::activated($line, $result))) {
                continue;
            }

            if (! $spell) {
                $result['unsupported'][] = $line;

                continue;
            }

            foreach (self::sentences($line) as $sentence) {
                if (! self::kickedSentence($sentence, $result)) {
                    $effect = self::effect($sentence);
                    if ($effect === null) {
                        $result['unsupported'][] = $sentence.'.';
                    } else {
                        $result['effects'][] = $effect;
                    }
                }
            }
        }

        if ($card->isAura() && $result['aura'] === null) {
            $result['unsupported'][] = 'Enchant …';
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
        if ($result['kicker'] === null || ! preg_match('/^If CARDNAME was kicked, (.+)$/', $sentence, $match)) {
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

        $names = array_filter([$card->name, explode(',', $card->name)[0]], fn ($name) => strlen(trim($name)) > 2);
        foreach (array_unique($names) as $name) {
            $text = str_replace($name, 'CARDNAME', $text);
        }
        $text = preg_replace('/\b[Tt]his (spell|creature|land|artifact|enchantment|card|permanent|aura|equipment|planeswalker)\b/', 'CARDNAME', $text);
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
        foreach (array_map('trim', preg_split('/[,;]\s*/', $line)) as $part) {
            $word = strtolower($part);
            if (in_array($word, CardDefinition::KEYWORDS, true)) {
                $found['keywords'][] = $word;
            } elseif (preg_match('/^ward '.self::COST.'$/i', $part, $m)) {
                $found['ward'] = ['mana' => $m[1]];
            } elseif (preg_match('/^ward—pay (\d+) life\.?$/iu', $part, $m)) {
                $found['ward'] = ['life' => (int) $m[1]];
            } elseif (preg_match('/^kicker '.self::COST.'$/i', $part, $m)) {
                $found['kicker'] = $m[1];
            } elseif (preg_match('/^flashback '.self::COST.'$/i', $part, $m) && $spell) {
                $found['flashback'] = $m[1];
            } elseif (preg_match('/^cycling '.self::COST.'$/i', $part, $m)) {
                $found['cycling'] = $m[1];
            } elseif (preg_match('/^(morph|megamorph|disguise) '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['morph'] = ['kind' => strtolower($m[1]), 'cost' => $m[2]];
            } elseif (preg_match('/^crew (\d+)$/i', $part, $m) && ! $spell) {
                $found['activated'][] = ['text' => $part, 'cost' => ['crew' => (int) $m[1]], 'effects' => [['type' => 'crewed', 'self' => true]], 'sorcery' => false, 'once' => false];
            } elseif (preg_match('/^level up '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['activated'][] = ['text' => $part, 'cost' => ['mana' => $m[1]], 'effects' => [['type' => 'level', 'self' => true]], 'sorcery' => true, 'once' => false];
            } else {
                return false;
            }
        }
        foreach (['kicker', 'flashback', 'cycling', 'morph'] as $cost) {
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
        if (! preg_match('/^\{T\}(, Pay 1 life)?: Add (.+?)\.?(?: CARDNAME deals 1 damage to you\.)?$/', $line, $match)) {
            return false;
        }
        $pain = ($match[1] ?? '') !== '' || str_ends_with($line, 'deals 1 damage to you.');
        $what = $match[2];
        $ability = null;
        if (preg_match('/^one mana of any colou?r$/i', $what)) {
            $ability = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];
        } elseif (preg_match('/^(\{[WUBRGC]\})+$/', $what)) {
            preg_match_all('/\{([WUBRGC])\}/', $what, $symbols);
            if (count(array_unique($symbols[1])) !== 1) {
                return false;
            }
            $ability = ['count' => count($symbols[1]), 'colors' => [$symbols[1][0]]];
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

        $existing = $result['mana'];
        if ($existing === null) {
            $result['mana'] = $ability;

            return true;
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
        $result['mana'] = ['count' => 1, 'colors' => $colors] + ($pain === [] ? [] : ['pain' => $pain]);

        return true;
    }

    private static function entersTapped(string $line, array &$result): bool
    {
        if (preg_match('/^CARDNAME enters(?: the battlefield)? tapped\.?$/', $line)) {
            $result['entersTapped'] = true;

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with (\w+) \+1\/\+1 counters? on it\.?$/', $line, $match) && is_int($n = self::amount($match[1]))) {
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
        $found = [...$found, ...match ($text) {
            "can't attack" => ["can't attack"],
            "can't block" => ["can't block"],
            "can't attack or block", "can't attack, block, or crew Vehicles" => ["can't attack", "can't block"],
            "doesn't untap during its controller's untap step" => ["doesn't untap"],
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
            'enters' => 'enters', 'enters the battlefield' => 'enters', 'dies' => 'dies', 'attacks' => 'attacks',
            'deals combat damage to a player' => 'combat_damage', 'is turned face up' => 'turned_face_up',
        ];
        $casts = [
            'a noncreature spell' => 'cast_noncreature', 'an instant or sorcery spell' => 'cast_instant_sorcery', 'a spell' => 'cast_spell',
        ];
        if (preg_match('/^(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks|deals combat damage to a player|is turned face up), (.+)$/', $line, $match)) {
            $event = $events[$match[1]];
            $text = $match[2];
        } elseif (preg_match('/^At the beginning of your (upkeep|end step), (.+)$/', $line, $match)) {
            $event = $match[1] === 'upkeep' ? 'upkeep' : 'end_step';
            $text = $match[2];
        } elseif (preg_match('/^Whenever you cast (a noncreature spell|an instant or sorcery spell|a spell), (.+)$/', $line, $match)) {
            $event = $casts[$match[1]];
            $text = $match[2];
        } else {
            return false;
        }
        $kicked = false;
        if ($event === 'enters' && $result['kicker'] !== null && preg_match('/^if it was kicked, (.+)$/', $text, $match)) {
            [$kicked, $text] = [true, $match[1]];
        }
        $effects = self::effects($text);
        if ($effects === null) {
            return false;
        }
        $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects] + ($kicked ? ['kicked' => true] : []);

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
        $effects = [];
        foreach (self::sentences($text) as $sentence) {
            // In an ability, "it" at the start means the card itself: "When CARDNAME enters, it deals 4 damage …".
            $effect = self::effect(preg_replace('/^it /', 'CARDNAME ', $sentence));
            if ($effect === null) {
                return null;
            }
            $effects[] = $effect;
        }

        return $effects === [] ? null : $effects;
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
        if (preg_match("/^exile ({$targets})$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'exile', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^return ({$targets}) to its owner's hand$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'bounce', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^counter (target spell|target creature spell|target noncreature spell)$/i', $s, $m)) {
            return ['type' => 'counter', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^({$targets}) gets ([+-]\\w+)\\/([+-]\\w+)(?: and gains (.+?))? until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], ['creature', 'creature_you_control', 'creature_opponent'], true)) {
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
        if (preg_match('/^you lose (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'you' => true];
        }
        if (preg_match("/^put (\w+) \+1\/\+1 counters? on ({$targets}|CARDNAME)$/i", $s, $m) && ($n = self::amount($m[1])) !== null) {
            if ($m[2] === 'CARDNAME') {
                return ['type' => 'counters', 'amount' => $n, 'self' => true];
            }
            if (in_array(self::TARGETS[strtolower($m[2])], ['creature', 'creature_you_control', 'creature_opponent'], true)) {
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
        if (preg_match("/^regenerate ({$targets})$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], ['creature', 'creature_you_control', 'creature_opponent'], true)) {
            return ['type' => 'regenerate', 'target' => self::TARGETS[strtolower($m[1])]];
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
        if (preg_match("/^({$targets}) gains (.+?) until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], ['creature', 'creature_you_control', 'creature_opponent'], true)
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
        return ! in_array(self::TARGETS[strtolower($phrase)], ['any', 'player', 'opponent', 'player_or_planeswalker', 'spell', 'creature_spell', 'noncreature_spell'], true);
    }
}
