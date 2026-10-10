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

namespace MTGPocket\Tutorial;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Mana\ManaCost;
use MTGPocket\Game\Step;

/**
 * The opponent in a practice game. It plays its starter deck the plain
 * way a new player would: a land each turn, then the biggest spell it can
 * afford; it attacks when no blocker would kill its creature for free and
 * blocks when its creature survives, trades evenly or the damage would be
 * lethal. It makes the same public moves a person makes in their panel.
 *
 * @since 0.7.0
 */
final class PracticeBot
{
    /** The bot's player id; Discord ids are numbers, so it never clashes. */
    public const string ID = 'practice-bot';

    public const string NAME = 'Practice Bot';

    /** Effects aimed at the opponent's side; anything else helps its own. */
    private const array HARMFUL = ['damage', 'destroy', 'exile', 'bounce', 'tap', 'counter', 'lose_life', 'discard', 'control', 'mill'];

    /** Moves in a row before the bot gives up and concedes, so a game never hangs on it. */
    private const int MOVE_LIMIT = 500;

    /**
     * Makes the bot's moves until the game waits on the other player or ends.
     * A bot that cannot move concedes.
     *
     * @param Game $game
     * @param int  $seat The bot's seat.
     *
     * @return void
     */
    public static function play(Game $game, int $seat): void
    {
        for ($moves = 0; $game->stage !== Game::OVER && $game->decision($seat) !== null; $moves++) {
            if ($moves >= self::MOVE_LIMIT) {
                $game->concede($seat);

                return;
            }
            try {
                $moved = self::act($game, $seat);
            } catch (GameException) {
                $moved = false;
            }
            if (! $moved) {
                // Passing is always allowed with priority; anything else it cannot do ends the game.
                if ($game->decision($seat) === 'priority') {
                    $game->pass($seat);
                } else {
                    $game->concede($seat);
                }
            }
        }
    }

    /**
     * Makes the bot's next move, if the game is waiting on it.
     *
     * @param Game $game
     * @param int  $seat
     *
     * @return bool Whether it made a move.
     */
    public static function act(Game $game, int $seat): bool
    {
        switch ($game->decision($seat)) {
            case 'mulligan':
                $lands = count(array_filter($game->players[$seat]->hand, fn (int $id) => $game->objects[$id]->definition()->isLand()));
                $game->players[$seat]->mulligans === 0 && ($lands < 2 || $lands > 5) ? $game->mulligan($seat) : $game->keep($seat);

                return true;
            case 'bottom':
                $game->bottom($seat, array_slice(self::worstFirst($game, $game->players[$seat]->hand), 0, $game->players[$seat]->toBottom));

                return true;
            case 'trigger':
                $trigger = $game->triggerAwaitingTargets();
                $game->chooseTriggerTargets($seat, self::targets($game, $seat, $trigger['kinds'], $trigger['effects'], $trigger['source'], true) ?? []);

                return true;
            case 'scry':
            case 'surveil':
                $game->arrange($seat, []);

                return true;
            case 'look':
                $choice = $game->choiceAwaiting();
                $game->take($seat, array_slice(array_reverse(self::worstFirst($game, $choice['eligible'])), 0, $choice['take']));

                return true;
            case 'mode':
                $game->chooseMode($seat, 0);

                return true;
            case 'discard':
                $choice = $game->choiceAwaiting();
                if (($choice['from'] ?? $seat) !== $seat) {
                    $game->discard($seat, [array_reverse(self::worstFirst($game, $choice['cards']))[0]]);
                } else {
                    $game->discard($seat, array_slice(self::worstFirst($game, $game->players[$seat]->hand), 0, $game->discardCount()));
                }

                return true;
            case 'attack':
                return self::attack($game, $seat);
            case 'block':
                return self::block($game, $seat);
            case 'priority':
                return self::priority($game, $seat);
            default:
                return false;
        }
    }

    private static function priority(Game $game, int $seat): bool
    {
        if ($game->active === $seat && $game->step->isMain() && $game->stack === []) {
            $plain = array_values(array_filter($game->plays($seat), fn (array $play) => $play['how'] === ''));
            foreach ($plain as $play) {
                if ($game->objects[$play['id']]->definition()->isLand()) {
                    $game->playLand($seat, $play['id']);

                    return true;
                }
            }
            $cost = fn (array $play) => ManaCost::parse(Game::castCost($game->objects[$play['id']]->printed(), ''))->manaValue();
            usort($plain, fn (array $a, array $b) => $cost($b) <=> $cost($a));
            foreach ($plain as $play) {
                $card = $game->objects[$play['id']]->printed();
                $effects = $card->spellEffects();
                // Pump spells are for combat.
                if (! $card->isPermanentCard() && in_array('pump', array_column($effects, 'type'), true) && array_intersect(array_column($effects, 'type'), self::HARMFUL) === []) {
                    continue;
                }
                $targets = self::targets($game, $seat, Game::castTargetKinds($card, ''), $effects);
                if ($targets === null) {
                    continue;
                }
                try {
                    $game->cast($seat, $play['id'], 0, $targets);
                } catch (GameException) {
                    continue;
                }

                return true;
            }
        }

        // A pump spell on its own creature in a fight.
        if ($game->step === Step::DeclareBlockers && $game->stack === []) {
            $fighting = $game->active === $seat ? array_keys(array_filter($game->blocked)) : array_keys($game->blockers);
            $fighting = array_values(array_filter($fighting, fn (int $id) => $game->objects[$id]->zone === GameObject::BATTLEFIELD));
            foreach ($game->plays($seat) as $play) {
                $card = $game->objects[$play['id']]->definition();
                if ($play['how'] !== '' || $fighting === [] || $card->isPermanentCard() || $card->targetCount() !== 1 || ! in_array('pump', array_column($card->effects, 'type'), true)) {
                    continue;
                }
                if ($game->isLegalTarget($card->targetKinds()[0], "o:{$fighting[0]}", $seat)) {
                    $game->cast($seat, $play['id'], 0, ["o:{$fighting[0]}"]);

                    return true;
                }
            }
        }

        $game->pass($seat);

        return true;
    }

    /**
     * Targets for a spell or ability: harmful effects at the opponent (their
     * biggest creature, or them), helpful ones at its own biggest creature.
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
        $damage = array_values(array_filter($effects, fn (array $effect) => $effect['type'] === 'damage'))[0]['amount'] ?? null;
        $targets = [];
        foreach ($kinds as $kind) {
            $best = null;
            $bestScore = PHP_INT_MIN;
            foreach ($game->targetOptions($seat, $kind) as $option) {
                [$type, $id] = explode(':', $option) + [1 => ''];
                $score = null;
                if ($type === 'p' && $harmful && (int) $id !== $seat) {
                    $score = 1;
                } elseif ($type === 'o' && (int) $id !== $self) {
                    $object = $game->objects[(int) $id];
                    if ($harmful === ($object->controller !== $seat)) {
                        $score = $game->isCreature($object) ? 10 + $game->power($object) + $game->toughness($object) : 2;
                        // Damage is best spent on a creature it kills; otherwise go face.
                        if (is_int($damage) && $game->isCreature($object) && $game->toughness($object) - $object->damage > $damage) {
                            $score = 0;
                        }
                    }
                }
                if ($score !== null && $score > $bestScore) {
                    [$best, $bestScore] = [$option, $score];
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
        $lethal = array_sum(array_map(fn (GameObject $o) => $game->power($o), $attackers)) >= $game->players[$opponent]->life;

        $chosen = [];
        foreach ($attackers as $attacker) {
            if ($game->power($attacker) <= 0) {
                continue;
            }
            $safe = true;
            foreach ($blockers as $blocker) {
                $canBlock = $game->canBlock($blocker, $attacker);
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
        $incoming = array_sum(array_map(fn (int $id) => $game->power($game->objects[$id]), array_keys($game->attackers)));
        $lethal = $incoming >= $game->players[$seat]->life;

        $blocks = [];
        $attackers = array_map(fn (int $id) => $game->objects[$id], array_keys($game->attackers));
        usort($attackers, fn (GameObject $a, GameObject $b) => $game->power($b) <=> $game->power($a));
        foreach ($attackers as $attacker) {
            // Menace needs two blockers (or more); keep it simple and let it through.
            if ($game->minBlockers($attacker) > 1) {
                continue;
            }
            $choice = null;
            foreach ($game->blockCandidates() as $blockerId) {
                $blocker = $game->objects[$blockerId];
                if (isset($blocks[$blockerId]) || ! $game->canBlock($blocker, $attacker)) {
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
                $incoming -= $game->power($attacker);
                $lethal = $incoming >= $game->players[$seat]->life;
            }
        }
        $game->declareBlockers($seat, $blocks);

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
        usort($spells, fn (int $a, int $b) => $game->objects[$b]->definition()->cost->manaValue() <=> $game->objects[$a]->definition()->cost->manaValue());

        return [...array_slice($lands, 3), ...$spells, ...array_slice($lands, 0, 3)];
    }
}
