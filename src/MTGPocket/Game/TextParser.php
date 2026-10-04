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
 * - keyword lines, e.g. `Flying, vigilance` ({@see CardDefinition::KEYWORDS})
 * - simple mana abilities: `{T}: Add {G}.`, `{T}: Add {W} or {U}.`, `{T}: Add {C}{C}.`, `{T}: Add one mana of any color.`
 * - `This land enters tapped.`
 * - an Aura's `Enchant …` line and `Enchanted creature gets +N/+N` / `has …`,
 *   and the same for Equipment (`Equipped creature …`) with `Equip {N}`
 * - the most common effects: damage, card draw, life gain and loss,
 *   destroy, exile, return to hand, counter, tap and untap, +N/+N until end
 *   of turn, +1/+1 counters and creature tokens
 * - those effects as triggered abilities (`When CARDNAME enters, …`,
 *   `… dies, …`, `Whenever CARDNAME attacks, …`, `… deals combat damage to a
 *   player, …`, `At the beginning of your upkeep / end step, …`), as
 *   activated abilities (`{2}, {T}, Sacrifice CARDNAME: …`) and as a
 *   planeswalker's loyalty abilities (`+1: …`)
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
     * @param CardDefinition $card With its name, types and text set.
     *
     * @return array{keywords: string[], mana: array|null, entersTapped: bool, effects: array[], aura: array|null, equipment: array|null, triggered: array[], activated: array[], unsupported: string[]}
     */
    public static function parse(CardDefinition $card): array
    {
        $result = ['keywords' => [], 'mana' => null, 'entersTapped' => false, 'effects' => [], 'aura' => null, 'equipment' => null, 'triggered' => [], 'activated' => [], 'unsupported' => []];
        $spell = ! $card->isPermanentCard();

        foreach (self::lines($card) as $line) {
            if (self::keywords($line, $result)
                || self::manaAbility($line, $result)
                || self::entersTapped($line, $result)
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
                $effect = self::effect($sentence);
                if ($effect === null) {
                    $result['unsupported'][] = $sentence.'.';
                } else {
                    $result['effects'][] = $effect;
                }
            }
        }

        if ($card->isAura() && $result['aura'] === null) {
            $result['unsupported'][] = 'Enchant …';
        }

        return $result;
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

    private static function keywords(string $line, array &$result): bool
    {
        $words = array_map(fn ($word) => strtolower(trim($word)), preg_split('/[,;]/', $line));
        foreach ($words as $word) {
            if (! in_array($word, CardDefinition::KEYWORDS, true)) {
                return false;
            }
        }
        array_push($result['keywords'], ...$words);
        $result['keywords'] = array_values(array_unique($result['keywords']));

        return true;
    }

    private static function manaAbility(string $line, array &$result): bool
    {
        if (! preg_match('/^\{T\}: Add (.+?)\.?$/', $line, $match)) {
            return false;
        }
        $what = $match[1];
        if (preg_match('/^one mana of any colou?r$/i', $what)) {
            $result['mana'] = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];

            return true;
        }
        if (preg_match('/^(\{[WUBRGC]\})+$/', $what)) {
            preg_match_all('/\{([WUBRGC])\}/', $what, $symbols);
            if (count(array_unique($symbols[1])) !== 1) {
                return false;
            }
            $result['mana'] = ['count' => count($symbols[1]), 'colors' => [$symbols[1][0]]];

            return true;
        }
        if (preg_match('/^\{[WUBRGC]\}(?:,? (?:or )?\{[WUBRGC]\})+$/', $what)) {
            preg_match_all('/\{([WUBRGC])\}/', $what, $symbols);
            $result['mana'] = ['count' => 1, 'colors' => array_values(array_unique($symbols[1]))];

            return true;
        }

        return false;
    }

    private static function entersTapped(string $line, array &$result): bool
    {
        if (preg_match('/^CARDNAME enters(?: the battlefield)? tapped\.?$/', $line)) {
            $result['entersTapped'] = true;

            return true;
        }

        return false;
    }

    private static function aura(string $line, array &$result): bool
    {
        if (preg_match('/^Enchant (.+)$/', $line, $match) && isset(self::ENCHANT[strtolower($match[1])])) {
            $result['aura'] ??= ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['aura']['enchant'] = self::ENCHANT[strtolower($match[1])];

            return true;
        }
        if (preg_match('/^Enchanted creature gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))?\.?$/', $line, $match)
            || preg_match('/^Enchanted creature (has) (.+?)\.?$/', $line, $match)) {
            $keywords = self::keywordList($match[1] === 'has' ? $match[2] : ($match[3] ?? ''));
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

        return false;
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
            'deals combat damage to a player' => 'combat_damage',
        ];
        if (preg_match('/^(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks|deals combat damage to a player), (.+)$/', $line, $match)) {
            $event = $events[$match[1]];
            $text = $match[2];
        } elseif (preg_match('/^At the beginning of your (upkeep|end step), (.+)$/', $line, $match)) {
            $event = $match[1] === 'upkeep' ? 'upkeep' : 'end_step';
            $text = $match[2];
        } else {
            return false;
        }
        $effects = self::effects($text);
        if ($effects === null) {
            return false;
        }
        $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects];

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
