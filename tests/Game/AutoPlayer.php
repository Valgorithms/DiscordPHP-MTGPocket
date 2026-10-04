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

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Mana\ManaCost;
use MTGPocket\Game\Step;

/**
 * A simple automated player for simulating whole games through the same
 * public moves a person makes in their panel. It is not clever: it plays a
 * land, then its most expensive spell it can afford, attacks when no
 * blocker would kill its creature for free, and blocks when it survives,
 * trades evenly or would otherwise take lethal damage. It casts modal
 * spells with the first modes it can, kicks what it can afford, levels up
 * and crews, and keeps every card it scries on top.
 */
final class AutoPlayer
{
    /** Effects aimed at the opponent's side; anything else helps your own. */
    private const array HARMFUL = ['damage', 'destroy', 'exile', 'bounce', 'tap', 'counter', 'lose_life', 'discard', 'control', 'mill'];

    /**
     * Makes this seat's next move, if the game is waiting on it.
     *
     * @param Game $game
     * @param int  $seat
     *
     * @return bool Whether it made a move.
     */
    public static function act(Game $game, int $seat): bool
    {
        return match ($game->decision($seat)) {
            'mulligan' => self::mulligan($game, $seat),
            'bottom' => self::bottom($game, $seat),
            'trigger' => self::trigger($game, $seat),
            'scry', 'surveil' => self::arrange($game, $seat),
            'look' => self::look($game, $seat),
            'mode' => self::mode($game, $seat),
            'attack' => self::attack($game, $seat),
            'block' => self::block($game, $seat),
            'discard' => self::discard($game, $seat),
            'priority' => self::priority($game, $seat),
            default => false,
        };
    }

    private static function mulligan(Game $game, int $seat): bool
    {
        $player = $game->players[$seat];
        $lands = count(array_filter($player->hand, fn (int $id) => $game->objects[$id]->definition()->isLand()));
        if ($player->mulligans === 0 && ($lands < 2 || $lands > 5)) {
            $game->mulligan($seat);
        } else {
            $game->keep($seat);
        }

        return true;
    }

    private static function bottom(Game $game, int $seat): bool
    {
        $player = $game->players[$seat];
        $game->bottom($seat, array_slice(self::worstFirst($game, $player->hand), 0, $player->toBottom));

        return true;
    }

    private static function look(Game $game, int $seat): bool
    {
        $choice = $game->choiceAwaiting();
        // The most expensive eligible cards.
        $game->take($seat, array_slice(array_reverse(self::worstFirst($game, $choice['eligible'])), 0, $choice['take']));

        return true;
    }

    private static function mode(Game $game, int $seat): bool
    {
        $game->chooseMode($seat, 0);

        return true;
    }

    private static function discard(Game $game, int $seat): bool
    {
        $choice = $game->choiceAwaiting();
        if (($choice['from'] ?? $seat) !== $seat) {
            // Their best card: the most expensive.
            $game->discard($seat, [array_reverse(self::worstFirst($game, $choice['cards']))[0]]);

            return true;
        }
        $hand = $game->players[$seat]->hand;
        $game->discard($seat, array_slice(self::worstFirst($game, $hand), 0, $game->discardCount()));

        return true;
    }

    /**
     * Cards to give up first: lands beyond the third, then the most expensive.
     *
     * @param Game  $game
     * @param int[] $ids
     *
     * @return int[]
     */
    private static function worstFirst(Game $game, array $ids): array
    {
        $lands = array_values(array_filter($ids, fn (int $id) => $game->objects[$id]->definition()->isLand()));
        $spells = array_values(array_filter($ids, fn (int $id) => ! $game->objects[$id]->definition()->isLand()));
        usort($spells, fn (int $a, int $b) => self::cost($game, $b) <=> self::cost($game, $a));

        return [...array_slice($lands, 3), ...$spells, ...array_slice($lands, 0, 3)];
    }

    private static function cost(Game $game, int $id): int
    {
        return $game->objects[$id]->definition()->cost->manaValue();
    }

    /**
     * The cards that can be played or cast the plain way, without a mode,
     * kicker, flashback, morph or cycling.
     *
     * @param Game $game
     * @param int  $seat
     *
     * @return int[]
     */
    private static function plainPlays(Game $game, int $seat): array
    {
        return array_column(array_filter($game->plays($seat), fn (array $play) => $play['how'] === ''), 'id');
    }

    private static function arrange(Game $game, int $seat): bool
    {
        $game->arrange($seat, []);

        return true;
    }

    private static function trigger(Game $game, int $seat): bool
    {
        $trigger = $game->triggerAwaitingTargets();
        $game->chooseTriggerTargets($seat, self::targets($game, $seat, $trigger['kinds'], $trigger['effects'], $trigger['source'], true));

        return true;
    }

    private static function priority(Game $game, int $seat): bool
    {
        $mine = $game->active === $seat;

        // Counter the opponent's spell on top of the stack.
        $top = end($game->stack) ?: null;
        if ($top !== null && $top['controller'] !== $seat && ! Game::isAbility($top)) {
            foreach ($game->plays($seat) as $play) {
                $card = $game->objects[$play['id']]->printed();
                if ($play['how'] === '' && $card->targetCount() === 1 && in_array('counter', array_column($card->effects, 'type'), true)
                    && $game->isLegalTarget($card->targetKinds()[0], "s:{$top['id']}", $seat)) {
                    $game->cast($seat, $play['id'], 0, ["s:{$top['id']}"]);

                    return true;
                }
            }
        }

        // Cast a card exiled with rebound, for free.
        if ($mine && $game->step === Step::Upkeep && $game->stack === []) {
            foreach ($game->plays($seat) as $play) {
                if (! str_starts_with($play['how'], 'rb')) {
                    continue;
                }
                $card = $game->objects[$play['id']]->printed();
                $options = Game::castOptions($play['how']);
                $targets = self::targets($game, $seat, $card->targetKinds($options['modes']), $card->spellEffects($options['modes']));
                if ($targets !== null) {
                    $game->cast($seat, $play['id'], 0, $targets, $play['how']);

                    return true;
                }
            }
        }

        if ($mine && $game->step->isMain() && $game->stack === []) {
            // A land first, then the most expensive spell that has good targets.
            // Only plays as cast or played: a land with cycling can still be cycled after the land drop.
            foreach (self::plainPlays($game, $seat) as $id) {
                if ($game->objects[$id]->definition()->isLand()) {
                    $game->playLand($seat, $id);

                    return true;
                }
            }
            // Landcycle when there is no land to play.
            $lands = array_filter($game->players[$seat]->hand, fn (int $id) => $game->objects[$id]->definition()->isLand());
            foreach ($game->plays($seat) as $play) {
                if ($lands === [] && $play['how'] === 'cycle' && $game->objects[$play['id']]->printed()->cyclingFinds !== null) {
                    $game->cycle($seat, $play['id']);

                    return true;
                }
            }
            $plays = array_values(array_filter($game->plays($seat), fn (array $play) => ! in_array($play['how'], Game::SPECIAL_PLAYS, true) && ! in_array('counter', array_column($game->objects[$play['id']]->printed()->effects, 'type'), true)));
            $cost = fn (array $play) => ManaCost::parse(Game::castCost($game->objects[$play['id']]->printed(), $play['how']))->manaValue();
            usort($plays, fn (array $a, array $b) => $cost($b) <=> $cost($a));
            foreach ($plays as $play) {
                $id = $play['id'];
                $card = $game->objects[$id]->printed();
                $options = Game::castOptions($play['how']);
                $effects = $options['faceDown'] ? [] : $card->spellEffects($options['modes'], $options['kicked']);
                // Pump spells are for combat; the main phase is too early.
                if (! $card->isPermanentCard() && in_array('pump', array_column($effects, 'type'), true) && array_intersect(array_column($effects, 'type'), self::HARMFUL) === []) {
                    continue;
                }
                $kinds = Game::castTargetKinds($card, $options);
                if ($options['bestowed']) {
                    $effects = [['type' => 'pump']];
                } elseif ($card->aura !== null) {
                    // Pacifism and the like go on the opponent's creatures.
                    $harmful = array_intersect($card->aura['keywords'], ["can't attack", "can't block", "doesn't untap", "abilities can't be activated"]) !== [] || $card->aura['power'] < 0
                        || in_array($card->aura['enchant'], ['player', 'opponent'], true) || ($card->aura['control'] ?? false);
                    $effects = [['type' => $harmful ? 'destroy' : 'pump']];
                }
                $targets = self::targets($game, $seat, $kinds, $effects);
                if ($targets === null) {
                    continue;
                }
                try {
                    $game->cast($seat, $id, ! $options['faceDown'] && ! $options['flashback'] && $card->cost->xCount > 0 ? $game->maxX($seat, $id, 20, $play['how']) : 0, $targets, $play['how']);
                } catch (GameException) {
                    continue; // Ward it cannot pay on top.
                }

                return true;
            }
            foreach ($game->plays($seat) as $play) {
                if (in_array($play['how'], ['unearth', 'plot', 'suspend', 'ninjutsu', 'regrow', 'foretell', 'eternalize', 'embalm'], true)) {
                    match ($play['how']) {
                        'unearth' => $game->unearth($seat, $play['id']),
                        'plot' => $game->plot($seat, $play['id']),
                        'foretell' => $game->foretell($seat, $play['id']),
                        'eternalize', 'embalm' => $game->embalm($seat, $play['id'], $play['how']),
                        'suspend' => $game->suspend($seat, $play['id']),
                        'ninjutsu' => $game->ninjutsu($seat, $play['id']),
                        'regrow' => $game->regrow($seat, $play['id']),
                    };

                    return true;
                }
            }
            foreach ($game->activatableAbilities($seat) as [$id, $index]) {
                $object = $game->objects[$id];
                $ability = $object->definition()->activated[$index];
                $types = array_column($ability['effects'], 'type');
                // Ping, equip something unequipped, or use a planeswalker; never sacrifice.
                $useful = ! ($ability['cost']['sacrifice'] ?? false) && (
                    in_array('damage', $types, true)
                    || (in_array('attach', $types, true) && $object->attachedTo === null)
                    || isset($ability['cost']['loyalty'])
                    || in_array('level', $types, true)
                    || in_array('class_level', $types, true) || array_intersect(['adapt', 'monstrosity'], $types) !== []
                    || (in_array('charge', $types, true) && $game->step === Step::PrecombatMain)
                    || (isset($ability['cost']['energy']) && in_array('counters', $types, true))
                    || (in_array('saddled', $types, true) && $game->step === Step::PrecombatMain && ! $object->sick && ! $game->hasKeyword($object, 'saddled'))
                    || in_array('face_up', $types, true)
                    || (in_array('crewed', $types, true) && $game->step === Step::PrecombatMain && ! $game->isCreature($object))
                );
                if (! $useful || ($object->used[$index] ?? null) === $game->turn) {
                    continue;
                }
                $targets = self::targets($game, $seat, array_values(array_filter(array_column($ability['effects'], 'target'))), $ability['effects'], $id);
                if ($targets === null) {
                    continue;
                }
                $game->activate($seat, $id, $index, $targets);

                return true;
            }
        }

        // A pump spell on a blocked attacker or a blocker in the declare blockers step.
        if ($game->step === Step::DeclareBlockers && $game->stack === []) {
            foreach (self::plainPlays($game, $seat) as $id) {
                $card = $game->objects[$id]->definition();
                if (! in_array('pump', array_column($card->effects, 'type'), true) || $card->isPermanentCard() || $card->targetCount() !== 1) {
                    continue;
                }
                $fighting = $mine ? array_keys(array_filter($game->blocked)) : array_keys($game->blockers);
                $fighting = array_values(array_filter($fighting, fn (int $id) => $game->objects[$id]->zone === GameObject::BATTLEFIELD));
                if ($fighting !== [] && $game->isLegalTarget($card->targetKinds()[0], "o:{$fighting[0]}", $seat)) {
                    $game->cast($seat, $id, 0, ["o:{$fighting[0]}"]);

                    return true;
                }
            }
        }

        $game->pass($seat);

        return true;
    }

    /**
     * Targets for a spell or ability: harmful effects at the opponent (their
     * biggest creature, or them), helpful ones at your own biggest creature.
     *
     * @param Game     $game
     * @param int      $seat
     * @param string[] $kinds
     * @param array[]  $effects
     * @param int|null $self    The source, which should not target itself.
     * @param bool     $forced  A triggered ability has to target something, even on the wrong side.
     *
     * @return string[]|null Null when some target only lands on the wrong side.
     */
    private static function targets(Game $game, int $seat, array $kinds, array $effects, ?int $self = null, bool $forced = false): ?array
    {
        $harmful = array_intersect(array_column($effects, 'type'), self::HARMFUL) !== [];
        $targets = [];
        $spellHarmful = $harmful;
        foreach ($kinds as $kind) {
            // A fight picks one of your creatures and one of theirs.
            $harmful = str_ends_with($kind, '_you_control') ? false : (str_ends_with($kind, '_opponent') ? true : $spellHarmful);
            $best = null;
            $bestScore = PHP_INT_MIN;
            foreach ($game->targetOptions($seat, $kind) as $option) {
                [$type, $id] = explode(':', $option) + [1 => ''];
                $score = null;
                if ($type === 'p') {
                    if ($harmful && (int) $id !== $seat) {
                        $score = 1;
                    }
                } elseif ($type === 'o' && (int) $id !== $self) {
                    $object = $game->objects[(int) $id];
                    $theirs = $object->controller !== $seat;
                    if ($harmful === $theirs) {
                        $score = $game->isCreature($object) ? 10 + $game->power($object) + $game->toughness($object) : 2;
                        // Damage is best spent on a creature it kills; otherwise go face.
                        $damage = array_values(array_filter($effects, fn (array $effect) => $effect['type'] === 'damage'))[0]['amount'] ?? null;
                        if (is_int($damage) && $game->isCreature($object) && $game->toughness($object) - $object->damage > $damage) {
                            $score = 0;
                        }
                    }
                }
                if ($score !== null && $score > $bestScore) {
                    $best = $option;
                    $bestScore = $score;
                }
            }
            if ($best === null && str_starts_with($kind, '?')) {
                $best = '-';
            }
            if ($best === null && $forced) {
                $best = $game->targetOptions($seat, $kind)[0] ?? null;
            }
            if ($best === null) {
                return null;
            }
            $targets[] = $best;
        }

        return $targets;
    }

    private static function attack(Game $game, int $seat): bool
    {
        $opponent = $game->opponent($seat);
        $blockers = array_filter($game->permanents($opponent), fn (GameObject $o) => $game->isCreature($o) && ! $o->tapped);
        $attackers = array_map(fn (int $id) => $game->objects[$id], $game->attackCandidates());
        $total = array_sum(array_map(fn (GameObject $o) => $game->power($o), $attackers));
        $lethal = $total >= $game->players[$opponent]->life;

        $chosen = [];
        foreach ($attackers as $attacker) {
            if ($game->power($attacker) <= 0) {
                continue;
            }
            $safe = true;
            foreach ($blockers as $blocker) {
                $canBlock = ! ($game->hasKeyword($attacker, 'flying') && ! $game->hasKeyword($blocker, 'flying') && ! $game->hasKeyword($blocker, 'reach'));
                $kills = $game->power($blocker) >= $game->toughness($attacker) || $game->hasKeyword($blocker, 'deathtouch');
                $dies = $game->power($attacker) >= $game->toughness($blocker) || $game->hasKeyword($attacker, 'deathtouch');
                if ($canBlock && $kills && ! $dies) {
                    $safe = false;
                }
            }
            if ($safe || $lethal) {
                $chosen[] = $attacker->id;
            }
        }
        $game->declareAttackers($seat, $chosen);

        return true;
    }

    private static function block(Game $game, int $seat): bool
    {
        $incoming = 0;
        foreach (array_keys($game->attackers) as $id) {
            $incoming += $game->power($game->objects[$id]);
        }
        $lethal = $incoming >= $game->players[$seat]->life;

        $blocks = [];
        $used = [];
        $attackers = array_map(fn (int $id) => $game->objects[$id], array_keys($game->attackers));
        usort($attackers, fn (GameObject $a, GameObject $b) => $game->power($b) <=> $game->power($a));
        foreach ($attackers as $attacker) {
            // Menace needs two blockers; keep it simple and let it through.
            if ($game->hasKeyword($attacker, 'menace')) {
                continue;
            }
            $choice = null;
            foreach ($game->blockCandidates() as $blockerId) {
                $blocker = $game->objects[$blockerId];
                if (isset($used[$blockerId]) || ! $game->canBlock($blocker, $attacker)) {
                    continue;
                }
                $survives = $game->toughness($blocker) > $game->power($attacker) && ! $game->hasKeyword($attacker, 'deathtouch');
                $kills = $game->power($blocker) >= $game->toughness($attacker) || $game->hasKeyword($blocker, 'deathtouch');
                $trade = $kills && $blocker->definition()->cost->manaValue() <= $attacker->definition()->cost->manaValue();
                if ($survives || $trade || $lethal) {
                    $choice = $blockerId;
                    if ($survives) {
                        break;
                    }
                }
            }
            if ($choice !== null) {
                $blocks[$choice] = $attacker->id;
                $used[$choice] = true;
                $incoming -= $game->power($attacker);
                $lethal = $incoming >= $game->players[$seat]->life;
            }
        }
        $game->declareBlockers($seat, $blocks);

        return true;
    }
}
