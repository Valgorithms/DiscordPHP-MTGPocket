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

namespace MTGPocket\Matches;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;

/**
 * What each control of a player's action panel does. A spell is cast in
 * steps (pick the card and how to play it, then X, then each target), so the picks so far are
 * kept on the match until the last one casts it. Abilities are activated
 * the same way (pick the ability, then each target), and so are targets
 * picked for a triggered ability. Attackers and blockers are picked first
 * and declared with a button.
 *
 * Kept apart from Discord so the flow can be tested without it.
 *
 * @since 0.3.0
 */
final class PanelActions
{
    public function __construct(private readonly MatchService $matches)
    {
    }

    /**
     * Runs a panel action.
     *
     * @param string   $matchId
     * @param string   $playerId
     * @param string   $action   From the custom id: `keep`, `mull`, `bottom`, `play`, `x`, `tgt`, `ability`, `atgt`, `trig`, `cancel`, `pass`, `auto`, `atk`, `atkgo`, `noatk`, `blk`, `blkgo`, `noblk`, `disc`, `away`, `keepall` or `panel`.
     * @param string[] $args     The rest of the custom id.
     * @param string[] $values   What was picked, for select menus.
     *
     * @throws \InvalidArgumentException When the rules do not allow it; nothing changes.
     *
     * @return array{0: MatchRecord, 1: bool} The match, and whether the game changed (so the board should be shown again).
     */
    public function run(string $matchId, string $playerId, string $action, array $args = [], array $values = []): array
    {
        $ints = array_map('intval', $values);
        $act = fn (callable $do) => [$this->matches->act($matchId, $playerId, function (Game $game, int $seat, MatchRecord $match) use ($do, $playerId): void {
            $do($game, $seat, $match);
            unset($match->choices[$playerId]);
        }), true];
        $choose = fn (callable $change) => [$this->matches->choose($matchId, $playerId, $change), false];

        return match ($action) {
            'panel' => [$this->matches->find($matchId) ?? throw new \OutOfBoundsException('That match no longer exists.'), false],
            'keep' => $act(fn (Game $game, int $seat) => $game->keep($seat)),
            'mull' => $act(fn (Game $game, int $seat) => $game->mulligan($seat)),
            'bottom' => $act(fn (Game $game, int $seat) => $game->bottom($seat, $ints)),
            'disc' => $act(fn (Game $game, int $seat) => $game->discard($seat, $ints)),
            'away' => $act(fn (Game $game, int $seat) => $game->arrange($seat, $ints)),
            'keepall' => $act(fn (Game $game, int $seat) => $game->arrange($seat, [])),
            'take' => $act(fn (Game $game, int $seat) => $game->take($seat, $ints)),
            'takenone' => $act(fn (Game $game, int $seat) => $game->take($seat, [])),
            'mode' => $act(fn (Game $game, int $seat) => $game->chooseMode($seat, (int) ($values[0] ?? 0))),
            'pass' => $act(fn (Game $game, int $seat) => $game->pass($seat)),
            'auto' => [$this->matches->act($matchId, $playerId, fn (Game $game, int $seat) => $game->setAutoPass($seat, ! ($game->autoPass[$seat] ?? true))), false],
            'play' => $this->play($matchId, $playerId, ...array_pad(explode(':', (string) ($values[0] ?? '0'), 2), 2, '')),
            'x' => $this->castStep($matchId, $playerId, fn (array $cast) => ['x' => max(0, (int) ($values[0] ?? 0))] + $cast),
            'tgt' => $this->castStep($matchId, $playerId, fn (array $cast) => ['targets' => [...(array) ($cast['targets'] ?? []), (string) ($values[0] ?? '')]] + $cast),
            'ability' => $this->ability($matchId, $playerId, (string) ($values[0] ?? '')),
            'atgt' => $this->activateStep($matchId, $playerId, (string) ($values[0] ?? '')),
            'trig' => $this->triggerStep($matchId, $playerId, (string) ($values[0] ?? '')),
            'cancel' => $choose(fn (array $choice) => array_diff_key($choice, ['cast' => true, 'activate' => true, 'trigger' => true])),
            'atk' => $choose(fn (array $choice) => ['attack' => $ints] + $choice),
            'atkgo' => $act(fn (Game $game, int $seat, MatchRecord $match) => $game->declareAttackers($seat, array_map('intval', (array) ($match->choice($playerId)['attack'] ?? [])))),
            'noatk' => $act(fn (Game $game, int $seat) => $game->declareAttackers($seat, [])),
            'blk' => $choose(function (array $choice) use ($args, $ints) {
                $choice['blocks'] = (array) ($choice['blocks'] ?? []);
                $choice['blocks'][(int) ($args[0] ?? 0)] = $ints;

                return $choice;
            }),
            'blkgo' => $act(fn (Game $game, int $seat, MatchRecord $match) => $game->declareBlockers($seat, self::blocks($game, (array) ($match->choice($playerId)['blocks'] ?? [])))),
            'noblk' => $act(fn (Game $game, int $seat) => $game->declareBlockers($seat, [])),
            default => throw new \InvalidArgumentException('That button does nothing any more.'),
        };
    }

    /**
     * Picks a card to play and how (see {@see Game::plays()}), as
     * `id:how`: a land is played, a card cycled, and a spell cast at once
     * unless it needs X or targets.
     *
     * @param string $matchId
     * @param string $playerId
     * @param string $id
     * @param string $how
     *
     * @return array{0: MatchRecord, 1: bool}
     */
    private function play(string $matchId, string $playerId, string $id, string $how): array
    {
        $id = (int) $id;
        $match = $this->matches->find($matchId) ?? throw new \OutOfBoundsException('That match no longer exists.');
        $card = ($match->game?->objects[$id] ?? throw new GameException('That card is not in your hand.'))->printed();
        $options = in_array($how, Game::SPECIAL_PLAYS, true) ? null : Game::castOptions($how);

        if ($options !== null && ! $card->isLand() && (self::xCount($card, $options) > 0 || count(Game::castTargetKinds($card, $options)) > 0)) {
            $seat = $match->game->seatOf($playerId);
            if ($seat === null || ! $match->game->canCast($seat, $id, $how)) {
                throw new GameException("You cannot cast {$card->name} that way now.");
            }

            return $this->castStep($matchId, $playerId, fn () => ['id' => $id, 'how' => $how, 'targets' => []] + (self::xCount($card, $options) > 0 ? [] : ['x' => 0]));
        }

        return [$this->matches->act($matchId, $playerId, function (Game $game, int $seat, MatchRecord $match) use ($id, $how, $card, $playerId): void {
            match (true) {
                $how === 'cycle' => $game->cycle($seat, $id),
                $how === 'unearth' => $game->unearth($seat, $id),
                $how === 'plot' => $game->plot($seat, $id),
                $how === 'foretell' => $game->foretell($seat, $id),
                $how === 'suspend' => $game->suspend($seat, $id),
                $how === 'ninjutsu' => $game->ninjutsu($seat, $id),
                $how === 'regrow' => $game->regrow($seat, $id),
                $card->isLand() => $game->playLand($seat, $id),
                default => $game->cast($seat, $id, 0, [], $how),
            };
            unset($match->choices[$playerId]);
        }), true];
    }

    /**
     * How many X a spell's cost has, cast a given way.
     *
     * @param CardDefinition $card
     * @param array                           $options
     *
     * @return int
     */
    public static function xCount(CardDefinition $card, array $options): int
    {
        return $options['faceDown'] ? 0 : ($options['flashback'] ? 0 : $card->cost->xCount);
    }

    /**
     * Records one more pick for the spell being cast, and casts it once
     * everything is picked.
     *
     * @param string                  $matchId
     * @param string                  $playerId
     * @param callable(array): array  $change   Gets the picks so far and returns them with the new one.
     *
     * @return array{0: MatchRecord, 1: bool}
     */
    private function castStep(string $matchId, string $playerId, callable $change): array
    {
        $match = $this->matches->choose($matchId, $playerId, function (array $choice) use ($change) {
            $choice['cast'] = $change((array) ($choice['cast'] ?? []));

            return $choice;
        });
        $cast = $match->choice($playerId)['cast'];
        if (! isset($cast['id'])) {
            throw new GameException('Pick the spell to cast first.');
        }
        $card = $match->game->objects[(int) $cast['id']]->printed();
        $options = Game::castOptions((string) ($cast['how'] ?? ''));
        if (! isset($cast['x']) || count($cast['targets']) < count(Game::castTargetKinds($card, $options))) {
            return [$match, false];
        }

        try {
            return [$this->matches->act($matchId, $playerId, function (Game $game, int $seat, MatchRecord $match) use ($cast, $playerId): void {
                $game->cast($seat, (int) $cast['id'], (int) $cast['x'], array_values($cast['targets']), (string) ($cast['how'] ?? ''));
                unset($match->choices[$playerId]);
            }), true];
        } catch (\InvalidArgumentException $e) {
            // Start over on that spell rather than leave a bad pick in place.
            $this->matches->choose($matchId, $playerId, fn (array $choice) => array_diff_key($choice, ['cast' => true]));

            throw $e;
        }
    }

    /**
     * Picks an ability to activate, as `objectId.abilityIndex`: it is
     * activated at once unless it needs targets.
     *
     * @param string $matchId
     * @param string $playerId
     * @param string $value
     *
     * @return array{0: MatchRecord, 1: bool}
     */
    private function ability(string $matchId, string $playerId, string $value): array
    {
        [$id, $index] = array_map('intval', array_pad(explode('.', $value, 2), 2, '0'));
        $match = $this->matches->find($matchId) ?? throw new \OutOfBoundsException('That match no longer exists.');
        $seat = $match->game?->seatOf($playerId);
        if ($seat === null || ! $match->game->canActivate($seat, $id, $index)) {
            throw new GameException('You cannot activate that ability now.');
        }
        return $this->activateStep($matchId, $playerId, null, ['id' => $id, 'index' => $index, 'targets' => []]);
    }

    /**
     * Records one more target for the ability being activated, and
     * activates it once every target is picked.
     *
     * @param string      $matchId
     * @param string      $playerId
     * @param string|null $target
     * @param array|null  $start    A new ability to start on.
     *
     * @return array{0: MatchRecord, 1: bool}
     */
    private function activateStep(string $matchId, string $playerId, ?string $target, ?array $start = null): array
    {
        $match = $this->matches->choose($matchId, $playerId, function (array $choice) use ($target, $start) {
            $activate = $start ?? (array) ($choice['activate'] ?? []);
            if ($target !== null) {
                $activate['targets'] = [...(array) ($activate['targets'] ?? []), $target];
            }
            $choice['activate'] = $activate;

            return $choice;
        });
        $activate = $match->choice($playerId)['activate'];
        if (! isset($activate['id'])) {
            throw new GameException('Pick the ability to activate first.');
        }
        $ability = $match->game->objects[(int) $activate['id']]->definition()->activated[(int) $activate['index']] ?? [];
        $needed = count(array_filter((array) ($ability['effects'] ?? []), fn (array $effect) => isset($effect['target'])));
        if (count($activate['targets']) < $needed) {
            return [$match, false];
        }

        return $this->finish($matchId, $playerId, 'activate', fn (Game $game, int $seat) => $game->activate($seat, (int) $activate['id'], (int) $activate['index'], array_values($activate['targets'])));
    }

    /**
     * Records one more target for the triggered ability waiting on them,
     * and puts it on the stack once every target is picked.
     *
     * @param string $matchId
     * @param string $playerId
     * @param string $target
     *
     * @return array{0: MatchRecord, 1: bool}
     */
    private function triggerStep(string $matchId, string $playerId, string $target): array
    {
        $match = $this->matches->choose($matchId, $playerId, function (array $choice) use ($target) {
            $choice['trigger'] = [...(array) ($choice['trigger'] ?? []), $target];

            return $choice;
        });
        $trigger = $match->game?->triggerAwaitingTargets();
        if ($trigger === null || $match->game->seatOf($playerId) !== $trigger['controller']) {
            $this->matches->choose($matchId, $playerId, fn (array $choice) => array_diff_key($choice, ['trigger' => true]));

            throw new GameException('No ability of yours is waiting for targets.');
        }
        $targets = array_values((array) $match->choice($playerId)['trigger']);
        if (count($targets) < count($trigger['kinds'])) {
            return [$match, false];
        }

        return $this->finish($matchId, $playerId, 'trigger', fn (Game $game, int $seat) => $game->chooseTriggerTargets($seat, $targets));
    }

    /**
     * Does the action the picks were for; on failure the picks are
     * dropped, so the player starts that over rather than keep a bad pick.
     *
     * @param string   $matchId
     * @param string   $playerId
     * @param string   $key      The picks: `activate` or `trigger`.
     * @param callable $do
     *
     * @return array{0: MatchRecord, 1: bool}
     */
    private function finish(string $matchId, string $playerId, string $key, callable $do): array
    {
        try {
            return [$this->matches->act($matchId, $playerId, function (Game $game, int $seat, MatchRecord $match) use ($do, $playerId): void {
                $do($game, $seat);
                unset($match->choices[$playerId]);
            }), true];
        } catch (\InvalidArgumentException $e) {
            $this->matches->choose($matchId, $playerId, fn (array $choice) => array_diff_key($choice, [$key => true]));

            throw $e;
        }
    }

    /**
     * Picks per attacker => the block declaration, blocker => attacker.
     *
     * @param Game                    $game
     * @param array<int, int[]>       $picks
     *
     * @return array<int, int>
     */
    private static function blocks(Game $game, array $picks): array
    {
        $blocks = [];
        foreach ($picks as $attacker => $blockers) {
            foreach ((array) $blockers as $blocker) {
                $blocker = (int) $blocker;
                if (isset($blocks[$blocker])) {
                    throw new GameException(($game->objects[$blocker] ?? null)?->name().' can block only one attacker.');
                }
                $blocks[$blocker] = (int) $attacker;
            }
        }

        return $blocks;
    }
}
