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
use MTGPocket\Game\Mana\ManaPayer;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * A two-player game of Magic under the Comprehensive Rules: the opening
 * hands and London mulligan, the turn structure, priority and the stack,
 * playing lands, casting spells with automatic mana payment, combat,
 * triggered and activated abilities (loyalty and Equip included), tokens
 * and state-based actions; and in Commander games, the command zone,
 * commander tax and commander damage (rule 903).
 *
 * Every action checks that the rules allow it and throws a
 * {@see GameException} (changing nothing) when they do not. After each
 * action the game runs on by itself until a player has a choice to make:
 * state-based actions are checked whenever a player would get priority,
 * and a player who has nothing they could do passes priority
 * automatically, except in their own first main phase.
 *
 * The whole game is plain data ({@see toArray()}), so it can be saved
 * between actions, and shuffles come from a seed, so it can be replayed.
 *
 * @since 0.3.0
 */
final class Game
{
    public const string MULLIGAN = 'mulligan';
    public const string PLAYING = 'playing';
    public const string OVER = 'over';

    public const int OPENING_HAND = 7;

    /** Combat damage from one commander that loses the game (rule 903.10a). */
    public const int COMMANDER_DAMAGE = 21;
    public const int MAX_LOG = 50;

    /** @var GamePlayer[] By seat. */
    public array $players = [];

    /** @var array<int, GameObject> By id. */
    public array $objects = [];

    /** @var int[] Permanents, in the order they entered. */
    public array $battlefield = [];

    /**
     * Spells and abilities; the last is the top. An ability also has `kind`
     * (`ability`), its `effects`, its target `kinds` and a `label`; its
     * `object` is its source.
     *
     * @var array<int, array{id: int, object: int, incarnation: int, controller: int, x: int, targets: string[], kind?: string, effects?: array[], kinds?: string[], label?: string}>
     */
    public array $stack = [];

    /**
     * Abilities that have triggered and wait to be put on the stack the next
     * time a player would receive priority (rule 603.3).
     *
     * @var array<int, array{source: int, incarnation: int, controller: int, effects: array[], kinds: string[], label: string, text: string}>
     */
    public array $pendingTriggers = [];

    /**
     * A choice a player makes in the middle of a spell or ability resolving:
     * the cards they look at to scry or surveil, top first, and what is left
     * to do once they have chosen (`resume`).
     *
     * @var array{type: string, seat: int, cards: int[], resume?: array}|null
     */
    public ?array $pendingChoice = null;

    /** @var int[] */
    public array $exile = [];

    /** @var int[] The command zone. */
    public array $command = [];

    /** @var array<int, int> Each commander, by object id, with how many times it has been cast from the command zone. */
    public array $commanders = [];

    public string $stage = self::MULLIGAN;
    public int $turn = 1;
    public int $startingPlayer = 0;
    public int $active = 0;
    public Step $step = Step::Untap;
    public ?int $priority = null;

    /** Players who have passed in succession with nothing happening since. */
    public int $passes = 0;

    /** @var array<int, true> Attacking creatures. */
    public array $attackers = [];

    /** @var array<int, int> Blocking creature => the attacker it blocks, in declaration order. */
    public array $blockers = [];

    /** @var array<int, true> Attackers that were blocked, even if their blockers have left. */
    public array $blocked = [];

    /** @var array<int, true> Creatures that dealt first-strike damage this combat. */
    public array $struckFirst = [];

    /** @var array<string, true> Turn-based choices already made this step: `attack`, `block`. */
    public array $declared = [];

    /** @var array<int, bool> Seat => whether priority is passed for them when they have nothing to do. */
    public array $autoPass = [];

    public ?int $winner = null;

    /** @var string[] What happened, newest last; only the latest {@see MAX_LOG} lines. */
    public array $log = [];

    /**
     * The whole game, kept for review once it is over: every line of the
     * log with the turn and step it happened in, and at the end of each
     * turn the position (life, card counts and the battlefield). Moves a
     * player made, and their outcomes, also have `m`, a short notation that
     * {@see GameRecord} lays out turn by turn like a chess score sheet.
     * Nothing hidden is recorded: no card drawn or put on the bottom is named.
     *
     * @var list<array{t: int, s: string, p?: int, m?: string, x?: string, pos?: list<array{life: int, hand: int, library: int, graveyard: int, board: string[]}>}>
     */
    public array $record = [];

    private int $nextId = 1;
    private int $nextStackId = 1;
    private int $shuffles = 0;

    /**
     * @param string $id
     * @param string $seed Shuffles and the coin flip come from it.
     */
    public function __construct(public readonly string $id, public readonly string $seed)
    {
    }

    /**
     * Sets up a game: decks shuffled, a coin flip for who plays first, and
     * opening hands drawn for the mulligan.
     *
     * @param string $id
     * @param array<int, array{id: string, name: string, cards: array[], commander?: array|null}> $players Two players, each with their deck as a list of card data, and in Commander their commander's.
     * @param string $seed
     * @param int    $life  Each player's starting life.
     *
     * @return self
     */
    public static function start(string $id, array $players, string $seed, int $life = GamePlayer::STARTING_LIFE): self
    {
        if (count($players) !== 2) {
            throw new GameException('A game needs two players.');
        }

        $game = new self($id, $seed);
        foreach (array_values($players) as $seat => $player) {
            $game->players[$seat] = new GamePlayer($seat, (string) $player['id'], (string) $player['name']);
            $game->players[$seat]->life = $life;
            $game->autoPass[$seat] = true;
            foreach ($player['cards'] as $card) {
                $object = new GameObject($game->nextId++, $seat, $card, GameObject::LIBRARY);
                $game->objects[$object->id] = $object;
                $game->players[$seat]->library[] = $object->id;
            }
            if (! empty($player['commander'])) {
                $object = new GameObject($game->nextId++, $seat, (array) $player['commander'], GameObject::COMMAND);
                $game->objects[$object->id] = $object;
                $game->command[] = $object->id;
                $game->commanders[$object->id] = 0;
            }
            $game->shuffle($seat);
        }

        $game->startingPlayer = $game->random()->getInt(0, 1);
        $game->active = $game->startingPlayer;
        $game->log("{$game->players[$game->startingPlayer]->name} won the coin flip and plays first.", $game->startingPlayer);
        foreach ($game->players as $seat => $player) {
            $game->draw($seat, self::OPENING_HAND);
        }

        return $game;
    }

    /**
     * Puts a new card straight into a zone, for setting up puzzles and
     * tests. A permanent enters untapped and without summoning sickness,
     * and its "enters" abilities do not trigger.
     *
     * @param int    $owner
     * @param array  $card  Card data.
     * @param string $zone  A {@see GameObject} zone other than the stack.
     *
     * @return GameObject
     */
    public function addCard(int $owner, array $card, string $zone): GameObject
    {
        $object = new GameObject($this->nextId++, $owner, $card, GameObject::EXILE);
        $this->objects[$object->id] = $object;
        $this->exile[] = $object->id;
        if ($zone === GameObject::BATTLEFIELD) {
            $this->putOntoBattlefield($object, $owner, false);
            $object->sick = false;
            $object->tapped = false;
        } else {
            $this->moveTo($object, $zone);
        }

        return $object;
    }

    // ----------------------------------------------------------------------
    // What the game is waiting for
    // ----------------------------------------------------------------------

    /**
     * The choice a player has to make now, if any: `mulligan`, `bottom`,
     * `trigger` (targets for a triggered ability), `scry` or `surveil`,
     * `attack`, `block`, `discard` or `priority`.
     *
     * @param int $seat
     *
     * @return string|null
     */
    public function decision(int $seat): ?string
    {
        $player = $this->players[$seat];
        if ($this->stage === self::OVER) {
            return null;
        }
        if ($this->stage === self::MULLIGAN) {
            return ! $player->kept ? 'mulligan' : ($player->toBottom > 0 ? 'bottom' : null);
        }
        if ($this->pendingChoice !== null) {
            return $seat === $this->pendingChoice['seat'] ? $this->pendingChoice['type'] : null;
        }
        if ($this->pendingTriggers !== [] && $this->priority !== null) {
            return $seat === $this->pendingTriggers[0]['controller'] ? 'trigger' : null;
        }
        if ($this->step === Step::DeclareAttackers && ! isset($this->declared['attack'])) {
            return $seat === $this->active ? 'attack' : null;
        }
        if ($this->step === Step::DeclareBlockers && ! isset($this->declared['block'])) {
            return $seat === $this->defender() ? 'block' : null;
        }
        if ($this->step === Step::Cleanup) {
            return $seat === $this->active && $this->discardCount() > 0 ? 'discard' : null;
        }

        return $this->priority === $seat ? 'priority' : null;
    }

    /**
     * The seats that have a choice to make.
     *
     * @return int[]
     */
    public function waitingOn(): array
    {
        return array_values(array_filter(array_keys($this->players), fn (int $seat) => $this->decision($seat) !== null));
    }

    public function defender(): int
    {
        return $this->opponent($this->active);
    }

    public function opponent(int $seat): int
    {
        return $seat === 0 ? 1 : 0;
    }

    /**
     * The seat of a Discord user in this game.
     *
     * @param string $playerId
     *
     * @return int|null
     */
    public function seatOf(string $playerId): ?int
    {
        foreach ($this->players as $seat => $player) {
            if ($player->id === $playerId) {
                return $seat;
            }
        }

        return null;
    }

    public function object(int $id): GameObject
    {
        return $this->objects[$id] ?? throw new GameException('There is no such card in this game.');
    }

    // ----------------------------------------------------------------------
    // Opening hands
    // ----------------------------------------------------------------------

    /**
     * Keeps the opening hand. After N mulligans, N cards then go on the
     * bottom of the library (London mulligan, rule 103.5).
     *
     * @param int $seat
     *
     * @return void
     */
    public function keep(int $seat): void
    {
        $this->expect($seat, 'mulligan');
        $player = $this->players[$seat];
        $player->kept = true;
        $player->toBottom = min($player->mulligans, count($player->hand));
        $this->log("{$player->name} keeps ".($player->mulligans === 0 ? 'their opening hand.' : "after {$player->mulligans} mulligan".($player->mulligans === 1 ? '' : 's').'.'), $seat, 'keep');
        $this->beginIfReady();
    }

    /**
     * Shuffles the hand back and draws a new one.
     *
     * @param int $seat
     *
     * @return void
     */
    public function mulligan(int $seat): void
    {
        $this->expect($seat, 'mulligan');
        $player = $this->players[$seat];
        if ($player->mulligans >= self::OPENING_HAND) {
            throw new GameException('You have no cards left to mulligan.');
        }
        foreach ($player->hand as $id) {
            $this->objects[$id]->moveTo(GameObject::LIBRARY);
            $player->library[] = $id;
        }
        $player->hand = [];
        $player->mulligans++;
        $this->shuffle($seat);
        $this->draw($seat, self::OPENING_HAND);
        $this->log("{$player->name} takes a mulligan.", $seat, 'mull');
    }

    /**
     * Puts cards from the kept hand on the bottom of the library, in the
     * order given (the last ends up at the very bottom).
     *
     * @param int   $seat
     * @param int[] $ids
     *
     * @return void
     */
    public function bottom(int $seat, array $ids): void
    {
        $this->expect($seat, 'bottom');
        $player = $this->players[$seat];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) !== $player->toBottom) {
            throw new GameException("Choose exactly {$player->toBottom} card".($player->toBottom === 1 ? '' : 's').' to put on the bottom.');
        }
        foreach ($ids as $id) {
            if (! in_array($id, $player->hand, true)) {
                throw new GameException('You can only put cards from your hand on the bottom.');
            }
        }
        foreach ($ids as $id) {
            $this->removeFromZone($this->objects[$id]);
            $this->objects[$id]->moveTo(GameObject::LIBRARY);
            array_unshift($player->library, $id);
        }
        $player->toBottom = 0;
        $this->log("{$player->name} puts ".count($ids).' card'.(count($ids) === 1 ? '' : 's').' on the bottom of their library.', $seat, 'bottom '.count($ids));
        $this->beginIfReady();
    }

    private function beginIfReady(): void
    {
        foreach ($this->players as $player) {
            if (! $player->kept || $player->toBottom > 0) {
                return;
            }
        }

        $this->stage = self::PLAYING;
        $this->log("Turn 1: {$this->players[$this->active]->name}.", $this->active);
        $this->enterStep(Step::Untap);
        $this->settle();
    }

    // ----------------------------------------------------------------------
    // Priority and the stack
    // ----------------------------------------------------------------------

    /**
     * Passes priority. When every player passes in succession, the top of
     * the stack resolves or, with an empty stack, the step ends (rule 117.4).
     *
     * @param int $seat
     *
     * @return void
     */
    public function pass(int $seat): void
    {
        $this->expect($seat, 'priority');
        $this->passPriority();
        $this->settle();
    }

    private function passPriority(): void
    {
        $this->passes++;
        if ($this->passes < count($this->players)) {
            $this->priority = $this->opponent((int) $this->priority);

            return;
        }

        $this->passes = 0;
        if ($this->stack !== []) {
            $this->resolveTop();
            $this->priority = $this->active;
        } else {
            $this->advance();
        }
    }

    /**
     * Turns automatic passing on or off for a player ("full control" when off).
     *
     * @param int  $seat
     * @param bool $on
     *
     * @return void
     */
    public function setAutoPass(int $seat, bool $on): void
    {
        $this->autoPass[$seat] = $on;
    }

    /**
     * Checks state-based actions, puts triggered abilities on the stack and
     * passes for players who have nothing to do, until someone has a real
     * choice or the game is over.
     *
     * @return void
     */
    private function settle(): void
    {
        for ($guard = 0; $guard < 1000 && $this->stage === self::PLAYING && $this->pendingChoice === null; $guard++) {
            $this->checkStateBasedActions();
            if ($this->stage !== self::PLAYING || $this->priority === null) {
                return;
            }
            if ($this->flushTriggers() || ! $this->shouldAutoPass($this->priority)) {
                return;
            }
            $this->passPriority();
        }
    }

    private function shouldAutoPass(int $seat): bool
    {
        if (! ($this->autoPass[$seat] ?? true)) {
            return false;
        }
        // Like MTG Arena, always stop in your own first main phase.
        if ($seat === $this->active && $this->step === Step::PrecombatMain && $this->stack === []) {
            return false;
        }
        if ($this->playableCards($seat) !== []) {
            return false;
        }

        // Abilities you could activate stop play only when there is something
        // to respond to or it is your own main phase; otherwise a creature
        // with a {T} ability would stop every step of every turn.
        return ! (($this->stack !== [] || ($seat === $this->active && $this->step->isMain())) && $this->activatableAbilities($seat) !== []);
    }

    // ----------------------------------------------------------------------
    // Turn structure
    // ----------------------------------------------------------------------

    private function advance(): void
    {
        foreach ($this->players as $player) {
            $player->manaPool->empty(); // Rule 500.4.
        }

        if ($this->step === Step::Cleanup) {
            $this->nextTurn();

            return;
        }
        if ($this->step === Step::EndCombat) {
            $this->attackers = $this->blockers = $this->blocked = $this->struckFirst = [];
        }
        $this->enterStep($this->step->next());
    }

    private function nextTurn(): void
    {
        $this->recordPosition();
        $this->turn++;
        $this->active = $this->opponent($this->active);
        foreach ($this->players as $player) {
            $player->landsPlayed = 0;
        }
        $this->step = Step::Untap;
        $this->log("Turn {$this->turn}: {$this->players[$this->active]->name}.", $this->active);
        $this->enterStep(Step::Untap);
    }

    /**
     * Starts a step and does its turn-based actions (rules 502 to 514).
     *
     * @param Step $step
     *
     * @return void
     */
    private function enterStep(Step $step): void
    {
        $this->step = $step;
        $this->passes = 0;
        $this->priority = null;
        $this->declared = [];

        switch ($step) {
            case Step::Untap:
                foreach ($this->permanents($this->active) as $object) {
                    $object->sick = false;
                    if (! $this->hasKeyword($object, "doesn't untap")) {
                        $object->tapped = false;
                    }
                }
                $this->enterStep(Step::Upkeep);

                return;

            case Step::Upkeep:
            case Step::End:
                foreach ($this->permanents($this->active) as $object) {
                    $this->trigger($object, $step === Step::Upkeep ? 'upkeep' : 'end_step', $object->controller);
                }
                break;

            case Step::Draw:
                // The player who plays first skips their first draw (rule 103.8a).
                if (! ($this->turn === 1 && $this->active === $this->startingPlayer)) {
                    $this->draw($this->active, 1);
                }
                break;

            case Step::DeclareAttackers:
                if ($this->attackCandidates() === []) {
                    $this->declared['attack'] = true;
                    $this->enterStep(Step::EndCombat);

                    return;
                }

                return; // Waits for the attack.

            case Step::DeclareBlockers:
                if ($this->blockCandidates() === []) {
                    $this->declared['block'] = true;
                    break;
                }

                return; // Waits for blocks.

            case Step::FirstStrikeDamage:
                if (! $this->anyFirstStrike()) {
                    $this->enterStep(Step::CombatDamage);

                    return;
                }
                $this->combatDamage(true);
                break;

            case Step::CombatDamage:
                $this->combatDamage(false);
                break;

            case Step::Cleanup:
                if ($this->discardCount() > 0) {
                    return; // Waits for the discard.
                }
                $this->cleanup();

                return;

            default:
                break;
        }

        $this->priority = $this->active;
    }

    /**
     * Rule 514.2: damage wears off and "until end of turn" effects end.
     * Then the next turn begins.
     *
     * @return void
     */
    private function cleanup(): void
    {
        foreach ($this->battlefield as $id) {
            $object = $this->objects[$id];
            $object->damage = 0;
            $object->deathtouched = false;
            $object->untilEndOfTurn = [];
            $object->shields = 0;
        }
        $this->advance();
    }

    private function discardCount(): int
    {
        return max(0, count($this->players[$this->active]->hand) - GamePlayer::HAND_SIZE);
    }

    /**
     * Discards down to the maximum hand size in the cleanup step (rule 514.1).
     *
     * @param int   $seat
     * @param int[] $ids
     *
     * @return void
     */
    public function discard(int $seat, array $ids): void
    {
        $this->expect($seat, 'discard');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $count = $this->discardCount();
        if (count($ids) !== $count) {
            throw new GameException("Choose exactly {$count} card".($count === 1 ? '' : 's').' to discard.');
        }
        $player = $this->players[$seat];
        foreach ($ids as $id) {
            if (! in_array($id, $player->hand, true)) {
                throw new GameException('You can only discard cards from your hand.');
            }
        }
        foreach ($ids as $id) {
            $this->moveTo($this->objects[$id], GameObject::GRAVEYARD);
        }
        $discarded = implode(', ', array_map(fn ($id) => $this->objects[$id]->name(), $ids));
        $this->log("{$player->name} discards {$discarded}.", $seat, "discard {$discarded}");
        $this->cleanup();
        $this->settle();
    }

    // ----------------------------------------------------------------------
    // Playing lands and casting spells
    // ----------------------------------------------------------------------

    /**
     * The cards a player could play right now: from their hand and command
     * zone, and from their graveyard with flashback.
     *
     * @param int $seat
     *
     * @return int[]
     */
    public function playableCards(int $seat): array
    {
        return array_values(array_unique(array_column($this->plays($seat), 'id')));
    }

    /**
     * Every way a player could play a card right now, as the card and how:
     * `''` (play the land or cast the spell), `m0+2` (choosing those modes),
     * `kick` (paying its kicker), `fb` (with flashback, from the graveyard),
     * `morph` (face down) or `cycle`; combined with commas, e.g. `fb,m1`.
     *
     * @param int $seat
     *
     * @return array<int, array{id: int, how: string}>
     */
    public function plays(int $seat): array
    {
        if ($this->stage !== self::PLAYING || $this->priority !== $seat || $this->pendingChoice !== null) {
            return [];
        }
        $plays = [];
        foreach ([...$this->players[$seat]->hand, ...$this->commandCards($seat)] as $id) {
            $card = $this->objects[$id]->printed();
            $ways = $card->isLand() ? ($this->canPlayLand($seat, $id) ? [''] : []) : array_filter(self::castWays($card, false), fn (string $how) => $this->canCast($seat, $id, $how));
            foreach ($ways as $how) {
                $plays[] = ['id' => $id, 'how' => $how];
            }
            if ($card->cycling !== null && $this->canCycle($seat, $id)) {
                $plays[] = ['id' => $id, 'how' => 'cycle'];
            }
        }
        foreach ($this->players[$seat]->graveyard as $id) {
            $card = $this->objects[$id]->printed();
            if ($card->flashback !== null) {
                foreach (self::castWays($card, true) as $how) {
                    if ($this->canCast($seat, $id, $how)) {
                        $plays[] = ['id' => $id, 'how' => $how];
                    }
                }
            }
        }

        return $plays;
    }

    /**
     * The ways a card could be cast, legal now or not.
     *
     * @param CardDefinition $card
     * @param bool           $flashback From the graveyard.
     *
     * @return string[]
     */
    private static function castWays(CardDefinition $card, bool $flashback): array
    {
        $ways = [];
        foreach ($card->modeChoices() as $modes) {
            $base = array_values(array_filter([$flashback ? 'fb' : '', $modes === [] ? '' : 'm'.implode('+', $modes)]));
            $ways[] = implode(',', $base);
            if ($card->kicker !== null) {
                $ways[] = implode(',', [...$base, 'kick']);
            }
        }
        if (! $flashback && $card->morph !== null) {
            $ways[] = 'morph';
        }

        return $ways;
    }

    /**
     * How to cast a spell, from the `how` of one of {@see plays()}.
     *
     * @param string|array $how A `how`, or options already read.
     *
     * @throws GameException When it is not a way to cast a spell.
     *
     * @return array{modes: int[], kicked: bool, flashback: bool, faceDown: bool}
     */
    public static function castOptions(string|array $how): array
    {
        $options = ['modes' => [], 'kicked' => false, 'flashback' => false, 'faceDown' => false];
        if (is_array($how)) {
            return array_intersect_key($how, $options) + $options;
        }
        foreach (array_filter(explode(',', $how)) as $part) {
            if ($part === 'kick') {
                $options['kicked'] = true;
            } elseif ($part === 'fb') {
                $options['flashback'] = true;
            } elseif ($part === 'morph') {
                $options['faceDown'] = true;
            } elseif (preg_match('/^m\d+(?:\+\d+)*$/', $part)) {
                $options['modes'] = array_map('intval', explode('+', substr($part, 1)));
            } else {
                throw new GameException('That is not a way to cast a spell.');
            }
        }

        return $options;
    }

    /**
     * A player's commanders in the command zone.
     *
     * @param int $seat
     *
     * @return int[]
     */
    public function commandCards(int $seat): array
    {
        return array_values(array_filter($this->command, fn (int $id) => $this->objects[$id]->owner === $seat));
    }

    /**
     * Whether an object is a commander.
     *
     * @param GameObject $object
     *
     * @return bool
     */
    public function isCommander(GameObject $object): bool
    {
        return isset($this->commanders[$object->id]);
    }

    /**
     * The extra {2} a commander costs for each time it was cast from the
     * command zone before (rule 903.8).
     *
     * @param int $id
     *
     * @return int Generic mana.
     */
    public function commanderTax(int $id): int
    {
        return 2 * ($this->commanders[$id] ?? 0);
    }

    private function sorcerySpeed(int $seat): bool
    {
        return $this->priority === $seat && $this->active === $seat && $this->step->isMain() && $this->stack === [];
    }

    /**
     * Whether a player may play a land from their hand now (rule 305.2).
     *
     * @param int $seat
     * @param int $id
     *
     * @return bool
     */
    public function canPlayLand(int $seat, int $id): bool
    {
        return $this->whyNotPlayLand($seat, $id) === null;
    }

    private function whyNotPlayLand(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || ! in_array($id, $this->players[$seat]->hand, true)) {
            return 'That card is not in your hand.';
        }
        if (! $object->definition()->isLand()) {
            return 'That is not a land.';
        }
        if (! $this->sorcerySpeed($seat)) {
            return 'You can play a land only in your own main phase, when the stack is empty.';
        }
        if ($this->players[$seat]->landsPlayed >= 1) {
            return 'You have already played a land this turn.';
        }

        return null;
    }

    /**
     * Plays a land (rule 305.1). It does not use the stack.
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function playLand(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotPlayLand($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $this->putOntoBattlefield($object, $seat);
        $this->players[$seat]->landsPlayed++;
        $this->passes = 0;
        $this->log("{$this->players[$seat]->name} plays {$object->name()}.", $seat, '+'.$object->name());
        $this->settle();
    }

    /**
     * Whether a player could cast a card now, with X = 0: the timing is
     * right, it has legal targets and they can pay for it.
     *
     * @param int          $seat
     * @param int          $id
     * @param string|array $how  See {@see plays()}.
     *
     * @return bool
     */
    public function canCast(int $seat, int $id, string|array $how = ''): bool
    {
        $options = self::castOptions($how);
        if ($this->whyNotCast($seat, $id, $options) !== null) {
            return false;
        }
        foreach ($this->castKinds($this->objects[$id]->printed(), $options) as $kind) {
            if ($this->targetOptions($seat, $kind) === []) {
                return false;
            }
        }

        return $this->paymentFor($seat, $id, 0, $options) !== null;
    }

    private function whyNotCast(int $seat, int $id, array $options): ?string
    {
        $object = $this->objects[$id] ?? null;
        $player = $this->players[$seat];
        if ($options['flashback']) {
            if ($object === null || ! in_array($id, $player->graveyard, true)) {
                return 'That card is not in your graveyard.';
            }
        } elseif ($object === null || ! (in_array($id, $player->hand, true) || in_array($id, $this->commandCards($seat), true))) {
            return 'That card is not in your hand.';
        }
        $card = $object->printed();
        if ($card->isLand()) {
            return 'Lands are played, not cast.';
        }
        if ($options['flashback'] && $card->flashback === null) {
            return "{$card->name} has no flashback.";
        }
        if ($options['kicked'] && $card->kicker === null) {
            return "{$card->name} has no kicker.";
        }
        if ($options['faceDown'] && $card->morph === null) {
            return "{$card->name} cannot be cast face down.";
        }
        if (! $options['faceDown'] && ! in_array(array_values(array_unique(array_map('intval', $options['modes']))), $card->modeChoices(), true)) {
            return $card->choose === null ? "{$card->name} has no modes." : "Choose {$card->choose['min']}".($card->choose['max'] > $card->choose['min'] ? " to {$card->choose['max']}" : '')." of {$card->name}'s modes.";
        }
        // A card with no mana cost at all cannot be cast for mana (rule 202.1b).
        if (! $options['faceDown'] && ! $options['flashback'] && $card->cost->isEmpty() && array_key_exists('manaCost', $card->card)) {
            return "{$card->name} has no mana cost, so it cannot be cast.";
        }
        if ($this->priority !== $seat) {
            return 'You do not have priority.';
        }
        // Face down it is a 2/2 creature spell with no abilities, flash included.
        if (($options['faceDown'] || ! $card->hasInstantSpeed()) && ! $this->sorcerySpeed($seat)) {
            return "{$card->name} can be cast only in your own main phase, when the stack is empty.";
        }

        return null;
    }

    /**
     * The targets a spell needs, cast a given way.
     *
     * @param CardDefinition $card
     * @param array          $options
     *
     * @return string[]
     */
    private function castKinds(CardDefinition $card, array $options): array
    {
        return $options['faceDown'] ? [] : $card->targetKinds($options['modes'], $options['kicked']);
    }

    /**
     * The mana cost of casting a card a given way: its own, its flashback
     * cost or {3} face down, plus its kicker.
     *
     * @param CardDefinition $card
     * @param array          $options
     *
     * @return string
     */
    private static function castCost(CardDefinition $card, array $options): string
    {
        $cost = $options['faceDown'] ? '{3}' : ($options['flashback'] ? (string) $card->flashback : (string) $card->cost);

        return $cost.($options['kicked'] ? (string) $card->kicker : '');
    }

    /**
     * The largest X a player could pay for a card, up to `$limit`.
     *
     * @param int          $seat
     * @param int          $id
     * @param int          $limit
     * @param string|array $how
     *
     * @return int
     */
    public function maxX(int $seat, int $id, int $limit = 20, string|array $how = ''): int
    {
        $best = 0;
        for ($x = 1; $x <= $limit; $x++) {
            if ($this->paymentFor($seat, $id, $x, self::castOptions($how)) === null) {
                break;
            }
            $best = $x;
        }

        return $best;
    }

    /**
     * How a player would pay for a card, or null when they cannot.
     *
     * @param int    $seat
     * @param int    $id
     * @param int    $x
     * @param array  $options  See {@see castOptions()}.
     * @param string $wardMana What ward makes them pay on top.
     * @param int    $wardLife
     *
     * @return array{life: int, pool: array<string, int>, tap: int[], float: array<string, int>, made: array<int, string>}|null
     */
    private function paymentFor(int $seat, int $id, int $x, array $options = [], string $wardMana = '', int $wardLife = 0): ?array
    {
        $options = self::castOptions($options);
        $tax = $this->objects[$id]->zone === GameObject::COMMAND ? $this->commanderTax($id) : 0;

        return $this->payFor($seat, self::castCost($this->objects[$id]->printed(), $options).$wardMana, $wardLife, [], $x, $tax);
    }

    /**
     * How a player would pay a cost from their mana pool and mana sources,
     * or null when they cannot.
     *
     * @param int    $seat
     * @param string $mana    A mana cost such as `{2}{R}`; may be empty.
     * @param int    $life    Life paid on top, e.g. for `Pay 2 life`.
     * @param int[]  $without Sources that cannot be tapped for it.
     * @param int    $x
     * @param int    $tax     Generic mana added, for commander tax.
     *
     * @return array{life: int, pool: array<string, int>, tap: int[], float: array<string, int>, made: array<int, string>}|null
     */
    private function payFor(int $seat, string $mana, int $life = 0, array $without = [], int $x = 0, int $tax = 0): ?array
    {
        $player = $this->players[$seat];
        $sources = array_diff_key($this->manaSources($seat), array_flip($without));
        foreach (ManaCost::parse($mana)->payments($x) as $payment) {
            if ($tax > 0) {
                $payment['mana']['generic'] = ($payment['mana']['generic'] ?? 0) + $tax;
            }
            $total = $payment['life'] + $life;
            if ($total > 0 && $total > $player->life) {
                continue;
            }
            $plan = ManaPayer::plan($payment, $player->manaPool, $sources);
            if ($plan !== null) {
                return $plan + ['life' => $total];
            }
        }

        return null;
    }

    /**
     * Pays a cost found by {@see payFor()}: sources are tapped, mana leaves
     * the pool, unused mana floats and life is paid. A painful mana ability,
     * such as a painland's colors, deals 1 damage to its controller.
     *
     * @param int   $seat
     * @param array $payment
     *
     * @return void
     */
    private function pay(int $seat, array $payment): void
    {
        $player = $this->players[$seat];
        foreach ($payment['tap'] as $source) {
            $this->objects[$source]->tapped = true;
        }
        foreach ($payment['pool'] as $type => $amount) {
            $player->manaPool->remove($type, $amount);
        }
        foreach ($payment['float'] as $type => $amount) {
            $player->manaPool->add($type, $amount);
        }
        $player->life -= $payment['life'];
        foreach ($payment['made'] ?? [] as $source => $type) {
            $object = $this->objects[$source];
            if (in_array($type, $object->definition()->manaAbility['pain'] ?? [], true)) {
                $this->dealDamage($object, "p:{$seat}", 1);
                $this->note("{$object->name()} deals 1 damage to {$player->name} for {{$type}}.", $seat);
            }
        }
    }

    /**
     * What ward makes a player pay to target permanents an opponent controls.
     *
     * @param string[] $targets
     * @param int      $seat
     *
     * @return array{0: string, 1: int} Mana, and life.
     */
    private function wardCost(array $targets, int $seat): array
    {
        $mana = '';
        $life = 0;
        foreach ($targets as $target) {
            $object = $this->targetObject($target);
            if ($object === null || $object->zone !== GameObject::BATTLEFIELD || $object->controller === $seat || ($ward = $object->definition()->ward) === null) {
                continue;
            }
            $mana .= $ward['mana'] ?? '';
            $life += $ward['life'] ?? 0;
        }

        return [$mana, $life];
    }

    /**
     * A player's untapped permanents with a mana ability they can use.
     *
     * @param int $seat
     *
     * @return array<int, array{count: int, colors: string[], pain?: string[]}>
     */
    public function manaSources(int $seat): array
    {
        $sources = [];
        foreach ($this->permanents($seat) as $object) {
            $ability = $object->definition()->manaAbility;
            if ($ability === null || $object->tapped || $this->hasKeyword($object, "abilities can't be activated")) {
                continue;
            }
            // A creature's {T} abilities need it to have been under your control since your turn began (rule 302.6).
            if ($this->isCreature($object) && $object->sick && ! $this->hasKeyword($object, 'haste')) {
                continue;
            }
            $sources[$object->id] = $ability;
        }

        return $sources;
    }

    /**
     * Casts a spell (rule 601.2): it moves to the stack with its modes, X
     * and targets, and its cost is paid from floating mana and by tapping
     * mana sources, with ward's for opponents' permanents it targets on
     * top. The caster keeps priority.
     *
     * @param int          $seat
     * @param int          $id      A card in their hand, or their graveyard with flashback.
     * @param int          $x       The value of X, for costs with {X}.
     * @param string[]     $targets One per target the spell needs: `p:{seat}`, `o:{objectId}` or `s:{stackId}`.
     * @param string|array $how     How it is cast; see {@see plays()}.
     *
     * @return void
     */
    public function cast(int $seat, int $id, int $x = 0, array $targets = [], string|array $how = ''): void
    {
        $this->expect($seat, 'priority');
        $options = self::castOptions($how);
        if (($reason = $this->whyNotCast($seat, $id, $options)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $card = $object->printed();
        $xCount = ManaCost::parse(self::castCost($card, $options))->xCount;
        if ($x < 0 || ($x > 0 && $xCount === 0)) {
            throw new GameException("{$card->name} has no X to choose.");
        }

        $kinds = $this->castKinds($card, $options);
        $targets = array_values($targets);
        if (count($targets) !== count($kinds)) {
            throw new GameException("{$card->name} needs ".count($kinds).' target'.(count($kinds) === 1 ? '' : 's').'.');
        }
        foreach ($kinds as $slot => $kind) {
            if (! $this->isLegalTarget($kind, $targets[$slot], $seat)) {
                throw new GameException('That is not a legal target for '.$card->name.'.');
            }
            $targets[$slot] = $this->pinTarget($targets[$slot]);
        }

        [$wardMana, $wardLife] = $this->wardCost($targets, $seat);
        $payment = $this->paymentFor($seat, $id, $x, $options, $wardMana, $wardLife);
        if ($payment === null) {
            $cost = ManaCost::parse(self::castCost($card, $options));
            throw new GameException("You cannot pay {$cost}".($xCount > 0 ? " with X = {$x}" : '').($wardMana !== '' || $wardLife > 0 ? ' and ward' : '').'.');
        }
        $this->pay($seat, $payment);

        $player = $this->players[$seat];
        $fromCommand = $object->zone === GameObject::COMMAND;
        if ($fromCommand) {
            $tax = $this->commanderTax($id);
            $this->commanders[$id]++;
        }
        $this->removeFromZone($object);
        $object->moveTo(GameObject::STACK);
        $object->controller = $seat;
        $object->faceDown = $options['faceDown'];
        $this->stack[] = [
            'id' => $this->nextStackId++,
            'object' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'x' => $x,
            'targets' => $targets,
        ] + array_filter(['modes' => $options['modes'], 'kicked' => $options['kicked'], 'flashback' => $options['flashback'], 'faceDown' => $options['faceDown']]);
        $this->passes = 0;

        $named = array_map(fn (string $target) => $this->describeTarget($target), $targets);
        $ways = array_filter([
            $fromCommand ? 'from the command zone'.($tax > 0 ? " (tax {{$tax}})" : '') : '',
            $options['flashback'] ? 'with flashback' : '',
            $options['kicked'] ? 'kicked' : '',
            $options['faceDown'] ? 'face down' : '',
            $options['modes'] === [] ? '' : 'choosing '.implode(' and ', array_map(fn (int $mode) => '"'.rtrim(str_replace('CARDNAME', $card->name, $card->modes[$mode]['text']), '.').'"', $options['modes'])),
            $xCount > 0 ? "(X = {$x})" : '',
        ]);
        $this->log(
            "{$player->name} casts {$object->name()}".($ways === [] ? '' : ' '.implode(' ', $ways)).($named === [] ? '' : ' targeting '.implode(', ', $named)).'.',
            $seat,
            $object->name().($options['kicked'] ? ' kicked' : '').($options['flashback'] ? ' fb' : '').($options['modes'] === [] ? '' : ' m'.implode('+', array_map(fn (int $mode) => $mode + 1, $options['modes']))).($xCount > 0 ? " X={$x}" : '').self::targetNotation($named),
        );
        $this->castTriggers($seat, $object);
        $this->settle();
    }

    /**
     * Abilities that trigger on a player casting a spell, such as prowess.
     *
     * @param int        $seat
     * @param GameObject $spell
     *
     * @return void
     */
    private function castTriggers(int $seat, GameObject $spell): void
    {
        $card = $spell->definition();
        $events = ['cast_spell', ...($card->isCreature() ? [] : ['cast_noncreature']), ...($card->isPermanentCard() ? [] : ['cast_instant_sorcery'])];
        foreach ($this->permanents($seat) as $permanent) {
            foreach ($events as $event) {
                $this->trigger($permanent, $event, $seat);
            }
        }
    }

    /**
     * Whether a player could cycle a card from their hand now.
     *
     * @param int $seat
     * @param int $id
     *
     * @return bool
     */
    public function canCycle(int $seat, int $id): bool
    {
        return $this->whyNotCycle($seat, $id) === null && $this->payFor($seat, (string) $this->objects[$id]->printed()->cycling) !== null;
    }

    private function whyNotCycle(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || ! in_array($id, $this->players[$seat]->hand, true)) {
            return 'That card is not in your hand.';
        }
        if ($object->printed()->cycling === null) {
            return "{$object->name()} has no cycling.";
        }

        return $this->priority === $seat ? null : 'You do not have priority.';
    }

    /**
     * Cycles a card (rule 702.29): pay its cycling cost and discard it, and
     * an ability that draws a card goes on the stack.
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function cycle(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotCycle($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $card = $object->printed();
        $payment = $this->payFor($seat, (string) $card->cycling) ?? throw new GameException("You cannot pay {$card->cycling}.");
        $this->pay($seat, $payment);
        $this->moveTo($object, GameObject::GRAVEYARD);
        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => [['type' => 'draw', 'amount' => 1]],
            'kinds' => [],
            'label' => "{$card->name}'s cycling",
        ], [], 'is activated', "cycle {$card->name}");
        $this->settle();
    }

    /**
     * Resolves the top of the stack (rule 608).
     *
     * @return void
     */
    private function resolveTop(): void
    {
        $item = array_pop($this->stack);
        $object = $this->objects[$item['object']];
        $ability = self::isAbility($item);
        if (! $ability && ($object->zone !== GameObject::STACK || $object->incarnation !== $item['incarnation'])) {
            return;
        }
        $card = $object->definition();
        $effects = $ability ? $item['effects'] : ($card->isPermanentCard() ? [] : $card->spellEffects($item['modes'] ?? [], $item['kicked'] ?? false));
        $kinds = $ability ? $item['kinds'] : ($card->aura !== null ? [$card->aura['enchant']] : self::targetKindsOf($effects));
        $name = $ability ? $item['label'] : $object->name();

        // A spell or ability whose targets are all illegal does not resolve (rule 608.2b).
        $legal = [];
        foreach ($item['targets'] as $slot => $target) {
            $legal[$slot] = $this->isLegalTarget($kinds[$slot], $target, $item['controller'], $item['id']);
        }
        if ($legal !== [] && ! in_array(true, $legal, true)) {
            $this->log("{$name} has no legal targets left and does nothing.");
            if (! $ability) {
                $this->spellLeavesStack($object, $item);
            }

            return;
        }

        if (! $ability && $card->isPermanentCard()) {
            $this->putOntoBattlefield($object, $item['controller'], true, $item['faceDown'] ?? false, $item['kicked'] ?? false);
            if ($card->aura !== null) {
                $object->attachedTo = $this->targetObject($item['targets'][0])?->id;
            }
            $this->log("{$object->name()} enters the battlefield".($object->attachedTo !== null ? ' attached to '.$this->objects[$object->attachedTo]->name() : '').'.');

            return;
        }

        // "CARDNAME" effects of an ability only apply while the source is still the same permanent.
        $self = $ability && $object->zone === GameObject::BATTLEFIELD && $object->incarnation === $item['incarnation'] ? [$object->id, $object->incarnation] : null;
        $steps = [];
        $slot = 0;
        foreach ($effects as $effect) {
            $target = null;
            if (isset($effect['target'])) {
                $target = $item['targets'][$slot];
                // Effects whose target has become illegal are skipped.
                if (! $legal[$slot++]) {
                    continue;
                }
            }
            $steps[] = [$effect, $target];
        }
        $this->runSteps($item, $steps, $self);
    }

    /**
     * Does a resolving spell's or ability's effects in order. One that needs
     * a player's choice (scry, surveil) waits for it with the rest, which
     * {@see arrange()} then does.
     *
     * @param array                   $item  The stack item.
     * @param array<int, array>       $steps Each an effect and its target.
     * @param array{0: int, 1: int}|null $self The permanent "CARDNAME" refers to, as id and incarnation.
     *
     * @return void
     */
    private function runSteps(array $item, array $steps, ?array $self): void
    {
        $source = $this->objects[$item['object']];
        $permanent = $self === null ? null : $this->objects[$self[0]];
        if ($permanent !== null && ($permanent->zone !== GameObject::BATTLEFIELD || $permanent->incarnation !== $self[1])) {
            $permanent = null;
        }
        while ($steps !== []) {
            [$effect, $target] = array_shift($steps);
            if ($this->applyEffect($effect, $source, $permanent, $item['controller'], $item['x'], $target)) {
                $this->pendingChoice['resume'] = ['item' => $item, 'steps' => $steps, 'self' => $self];

                return;
            }
        }

        $this->log((self::isAbility($item) ? $item['label'] : $source->name()).' resolves.');
        if (! self::isAbility($item) && $source->zone === GameObject::STACK) {
            $this->spellLeavesStack($source, $item);
        }
    }

    /**
     * Puts a spell that has resolved or been countered into its owner's
     * graveyard, or exile when it was cast with flashback (rule 702.34a).
     *
     * @param GameObject $object
     * @param array      $item
     *
     * @return void
     */
    private function spellLeavesStack(GameObject $object, array $item): void
    {
        $this->moveTo($object, ($item['flashback'] ?? false) ? GameObject::EXILE : GameObject::GRAVEYARD);
    }

    /**
     * The cards a player is looking at to scry or surveil, top first.
     *
     * @return array{type: string, seat: int, cards: int[]}|null
     */
    public function choiceAwaiting(): ?array
    {
        return $this->pendingChoice;
    }

    /**
     * Finishes a scry or surveil (rules 701.22 and 701.25): the chosen cards
     * go to the bottom of the library (scry) or into the graveyard
     * (surveil), and the rest stay on top in the same order. Then the spell
     * or ability goes on resolving.
     *
     * @param int   $seat
     * @param int[] $away
     *
     * @return void
     */
    public function arrange(int $seat, array $away): void
    {
        $choice = $this->pendingChoice;
        if ($choice === null || $choice['seat'] !== $seat || $this->stage !== self::PLAYING) {
            throw new GameException('You have nothing to scry or surveil.');
        }
        $away = array_values(array_unique(array_map('intval', $away)));
        foreach ($away as $id) {
            if (! in_array($id, $choice['cards'], true)) {
                throw new GameException('Choose only among the cards you are looking at.');
            }
        }
        $player = $this->players[$seat];
        foreach ($away as $id) {
            $object = $this->objects[$id];
            if ($choice['type'] === 'scry') {
                $this->removeFromZone($object);
                $object->moveTo(GameObject::LIBRARY);
                array_unshift($player->library, $id);
            } else {
                $this->moveTo($object, GameObject::GRAVEYARD);
            }
        }
        $count = count($choice['cards']);
        $kept = $count - count($away);
        $this->pendingChoice = null;
        $this->log(
            "{$player->name} ".($choice['type'] === 'scry' ? 'scries' : 'surveils')." {$count}: {$kept} on top, ".count($away).($choice['type'] === 'scry' ? ' on the bottom' : ' into the graveyard').'.',
            $seat,
            "{$choice['type']} {$kept}/{$count}",
        );
        $resume = $choice['resume'];
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /**
     * Does one effect of a resolving spell or ability.
     *
     * @param array           $effect     See {@see TextParser::effect()}.
     * @param GameObject      $source
     * @param GameObject|null $self
     * @param int             $controller
     * @param int             $x
     * @param string|null     $target
     *
     * @return bool Whether it waits for a player's choice.
     */
    private function applyEffect(array $effect, GameObject $source, ?GameObject $self, int $controller, int $x, ?string $target): bool
    {
        $amount = ($effect['amount'] ?? 0) === 'X' ? $x : (int) ($effect['amount'] ?? 0);
        $opponents = array_filter(array_keys($this->players), fn (int $seat) => $seat !== $controller);
        $affected = match (true) {
            (bool) ($effect['self'] ?? false) => $self,
            (bool) ($effect['enchanted'] ?? false) => $self?->attachedTo === null ? null : ($this->objects[$self->attachedTo] ?? null),
            default => $target === null ? null : $this->targetObject($target),
        };

        switch ($effect['type']) {
            case 'damage':
                $hits = match ($effect['each'] ?? null) {
                    'opponent' => array_map(fn (int $seat) => "p:{$seat}", $opponents),
                    'creature' => array_map(fn (GameObject $o) => "o:{$o->id}", array_filter($this->permanents(), fn (GameObject $o) => $this->isCreature($o))),
                    default => [$target],
                };
                foreach ($hits as $hit) {
                    $this->dealDamage($source, (string) $hit, $amount);
                }
                break;

            case 'draw':
                $this->draw($target !== null ? (int) substr($target, 2) : $controller, $amount);
                break;

            case 'gain_life':
                $this->players[$controller]->life += $amount;
                break;

            case 'lose_life':
                $seats = match (true) {
                    isset($effect['each']) => $opponents,
                    isset($effect['you']) => [$controller],
                    default => [(int) substr((string) $target, 2)],
                };
                foreach ($seats as $seat) {
                    $this->players[$seat]->life -= $amount;
                }
                break;

            case 'destroy':
                $this->destroy($affected);
                break;

            case 'exile':
                $this->moveTo($affected, GameObject::EXILE);
                break;

            case 'bounce':
                $this->moveTo($affected, GameObject::HAND);
                break;

            case 'pump':
                if ($affected !== null) {
                    $affected->untilEndOfTurn[] = ['power' => $effect['power'], 'toughness' => $effect['toughness'], 'keywords' => $effect['keywords']];
                }
                break;

            case 'counters':
                $affected?->addCounters('+1/+1', $amount);
                break;

            case 'tap':
            case 'untap':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->tapped = $effect['type'] === 'tap';
                }
                break;

            case 'token':
                for ($i = 0; $i < $amount; $i++) {
                    $this->createToken((array) $effect['token'], $controller);
                }
                $this->log("{$this->players[$controller]->name} creates {$amount} {$effect['token']['name']}".($amount === 1 ? '' : 's').'.');
                break;

            case 'attach':
                // Equip (rule 702.6): only while the Equipment is still on the battlefield.
                if ($self !== null && $affected !== null && $affected->id !== $self->id) {
                    $self->attachedTo = $affected->id;
                    $this->log("{$self->name()} is attached to {$affected->name()}.");
                }
                break;

            case 'counter':
                $stackId = (int) substr((string) $target, 2);
                foreach ($this->stack as $index => $item) {
                    if ($item['id'] === $stackId) {
                        array_splice($this->stack, $index, 1);
                        $countered = $this->objects[$item['object']];
                        $this->log("{$countered->name()} is countered.", null, "{$countered->name()} countered");
                        $this->spellLeavesStack($countered, $item);
                        break;
                    }
                }
                break;

            case 'scry':
            case 'surveil':
                $cards = array_reverse(array_slice($this->players[$controller]->library, -$amount));
                if ($amount > 0 && $cards !== []) {
                    $this->pendingChoice = ['type' => $effect['type'], 'seat' => $controller, 'cards' => $cards];

                    return true;
                }
                break;

            case 'regenerate':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->shields++;
                }
                break;

            case 'crewed':
                if ($self !== null) {
                    $self->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => [], 'creature' => true];
                    $this->log("{$self->name()} becomes an artifact creature until end of turn.");
                }
                break;

            case 'level':
                $self?->addCounters('level', 1);
                break;
        }

        return false;
    }

    /**
     * Creates a creature token (rule 111): it is owned by the player who
     * created it and enters the battlefield like any permanent.
     *
     * @param array $token      Card data from {@see TextParser}.
     * @param int   $controller
     *
     * @return GameObject
     */
    private function createToken(array $token, int $controller): GameObject
    {
        $object = new GameObject($this->nextId++, $controller, ['token' => true] + $token, GameObject::GONE);
        $this->objects[$object->id] = $object;
        $this->putOntoBattlefield($object, $controller);

        return $object;
    }

    // ----------------------------------------------------------------------
    // Triggered and activated abilities
    // ----------------------------------------------------------------------

    /**
     * Whether a stack item is an ability rather than a spell.
     *
     * @param array $item
     *
     * @return bool
     */
    public static function isAbility(array $item): bool
    {
        return ($item['kind'] ?? 'spell') === 'ability';
    }

    /**
     * The kinds of target effects need, in order.
     *
     * @param array[] $effects
     *
     * @return string[]
     */
    private static function targetKindsOf(array $effects): array
    {
        return array_values(array_map(fn (array $effect) => $effect['target'], array_filter($effects, fn (array $effect) => isset($effect['target']))));
    }

    /**
     * Queues a permanent's abilities that trigger on an event (rule 603.2).
     *
     * @param GameObject $source
     * @param string     $event       See {@see CardDefinition::triggersOn()}.
     * @param int        $controller  Who controlled the source when the event happened.
     * @param int|null   $incarnation The source's, if it has since changed zones.
     *
     * @return void
     */
    private function trigger(GameObject $source, string $event, int $controller, ?int $incarnation = null): void
    {
        foreach ($source->definition()->triggersOn($event) as $ability) {
            // "When this creature enters, if it was kicked, …"
            if (($ability['kicked'] ?? false) && ! $source->kicked) {
                continue;
            }
            $this->pendingTriggers[] = [
                'source' => $source->id,
                'incarnation' => $incarnation ?? $source->incarnation,
                'controller' => $controller,
                'effects' => $ability['effects'],
                'kinds' => self::targetKindsOf($ability['effects']),
                'label' => "{$source->name()}'s ability",
                'text' => str_replace('CARDNAME', $source->name(), $ability['text']),
            ];
        }
    }

    /**
     * Puts waiting triggered abilities on the stack, the active player's
     * first so the other player's resolve first (rule 603.3b). Targets are
     * chosen for the controller when there is only one choice; an ability
     * with no legal targets is removed (rule 603.3d).
     *
     * @return bool Whether a player must now choose targets for one.
     */
    private function flushTriggers(): bool
    {
        usort($this->pendingTriggers, fn (array $a, array $b) => ($a['controller'] !== $this->active) <=> ($b['controller'] !== $this->active));
        while ($this->pendingTriggers !== []) {
            $trigger = $this->pendingTriggers[0];
            $targets = [];
            foreach ($trigger['kinds'] as $kind) {
                $options = $this->targetOptions($trigger['controller'], $kind);
                if ($options === []) {
                    array_shift($this->pendingTriggers);
                    $this->log("{$trigger['label']} has no legal targets and is removed.");

                    continue 2;
                }
                if (count($options) > 1) {
                    return true;
                }
                $targets[] = $options[0];
            }
            array_shift($this->pendingTriggers);
            $this->pushTriggered($trigger, $targets);
        }

        return false;
    }

    /**
     * Choose targets for the triggered ability waiting on them.
     *
     * @param int      $seat
     * @param string[] $targets One per target, as for {@see cast()}.
     *
     * @return void
     */
    public function chooseTriggerTargets(int $seat, array $targets): void
    {
        $this->expect($seat, 'trigger');
        $trigger = $this->pendingTriggers[0];
        $this->checkTargets($trigger['kinds'], array_values($targets), $seat, $trigger['label']);
        array_shift($this->pendingTriggers);
        $this->pushTriggered($trigger, array_values($targets));
        $this->settle();
    }

    /**
     * Puts a triggered ability on the stack. When it targets an opponent's
     * permanent with ward, its controller pays the ward cost if they can,
     * and otherwise it is countered.
     *
     * @param array    $trigger
     * @param string[] $targets
     *
     * @return void
     */
    private function pushTriggered(array $trigger, array $targets): void
    {
        [$wardMana, $wardLife] = $this->wardCost(array_map(fn (string $target) => $this->pinTarget($target), $targets), $trigger['controller']);
        if ($wardMana !== '' || $wardLife > 0) {
            $payment = $this->payFor($trigger['controller'], $wardMana, $wardLife);
            if ($payment === null) {
                $this->log("{$trigger['label']} is countered by ward.", $trigger['controller']);

                return;
            }
            $this->pay($trigger['controller'], $payment);
        }
        $this->pushAbility($trigger, $targets, 'triggers');
    }

    /**
     * The triggered ability waiting for its controller to choose targets.
     *
     * @return array{source: int, incarnation: int, controller: int, effects: array[], kinds: string[], label: string, text: string}|null
     */
    public function triggerAwaitingTargets(): ?array
    {
        return $this->pendingTriggers !== [] && $this->priority !== null ? $this->pendingTriggers[0] : null;
    }

    /**
     * Puts an ability on the stack with its targets.
     *
     * @param array{source: int, incarnation: int, controller: int, effects: array[], kinds: string[], label: string, text?: string} $ability
     * @param string[] $targets Already checked.
     * @param string   $verb    For the log: `triggers` or `is activated`.
     *
     * @return void
     */
    private function pushAbility(array $ability, array $targets, string $verb, ?string $move = null): void
    {
        $targets = array_map(fn (string $target) => $this->pinTarget($target), $targets);
        $this->stack[] = [
            'id' => $this->nextStackId++,
            'object' => $ability['source'],
            'incarnation' => $ability['incarnation'],
            'controller' => $ability['controller'],
            'x' => 0,
            'targets' => $targets,
            'kind' => 'ability',
            'effects' => $ability['effects'],
            'kinds' => $ability['kinds'],
            'label' => $ability['label'],
        ];
        $this->passes = 0;
        $named = array_map(fn (string $target) => $this->describeTarget($target), $targets);
        $line = "{$ability['label']} {$verb}".($named === [] ? '' : ' targeting '.implode(', ', $named));
        // The record also says what a triggered ability does, since one card can have several.
        $this->log("{$line}.", $ability['controller'], $move === null ? null : $move.self::targetNotation($named), ($ability['text'] ?? '') === '' ? null : "{$line}: {$ability['text']}");
    }

    /**
     * Throws unless targets fit the kinds a spell or ability needs.
     *
     * @param string[] $kinds
     * @param string[] $targets
     * @param int      $seat
     * @param string   $name
     *
     * @return void
     */
    private function checkTargets(array $kinds, array $targets, int $seat, string $name): void
    {
        if (count($targets) !== count($kinds)) {
            throw new GameException("{$name} needs ".count($kinds).' target'.(count($kinds) === 1 ? '' : 's').'.');
        }
        foreach ($kinds as $slot => $kind) {
            if (! $this->isLegalTarget($kind, (string) $targets[$slot], $seat)) {
                throw new GameException("That is not a legal target for {$name}.");
            }
        }
    }

    /**
     * The activated abilities a player could activate right now, as
     * `[objectId, abilityIndex]` pairs.
     *
     * @param int $seat
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function activatableAbilities(int $seat): array
    {
        if ($this->stage !== self::PLAYING || $this->priority !== $seat) {
            return [];
        }
        $abilities = [];
        foreach ($this->permanents($seat) as $object) {
            foreach (array_keys($object->definition()->activated) as $index) {
                if ($this->canActivate($seat, $object->id, $index)) {
                    $abilities[] = [$object->id, $index];
                }
            }
        }

        return $abilities;
    }

    /**
     * Whether a player could activate an ability now: the timing and its
     * limits allow it, it has legal targets and they can pay its cost.
     *
     * @param int $seat
     * @param int $id
     * @param int $index Among the card's {@see CardDefinition::$activated}.
     *
     * @return bool
     */
    public function canActivate(int $seat, int $id, int $index): bool
    {
        if ($this->whyNotActivate($seat, $id, $index) !== null) {
            return false;
        }
        $object = $this->objects[$id];
        $ability = $object->definition()->activated[$index];
        foreach (self::targetKindsOf($ability['effects']) as $kind) {
            if ($this->targetOptions($seat, $kind) === []) {
                return false;
            }
        }

        return $this->abilityPayment($seat, $object, $ability) !== null;
    }

    /**
     * Whether an ability is turning a face-down permanent face up, a special
     * action that does not use the stack (rule 702.37e).
     *
     * @param array $ability
     *
     * @return bool
     */
    private static function isSpecialAction(array $ability): bool
    {
        return ($ability['effects'][0]['type'] ?? null) === 'face_up';
    }

    private function whyNotActivate(int $seat, int $id, int $index): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::BATTLEFIELD || $object->controller !== $seat) {
            return 'You do not control that permanent.';
        }
        $card = $object->definition();
        $ability = $card->activated[$index] ?? null;
        if ($ability === null) {
            return "{$card->name} has no such ability.";
        }
        if ($this->priority !== $seat || $this->pendingChoice !== null) {
            return 'You do not have priority.';
        }
        if (! self::isSpecialAction($ability) && $this->hasKeyword($object, "abilities can't be activated")) {
            return "{$card->name}'s activated abilities can't be activated.";
        }
        if ($ability['sorcery'] && ! $this->sorcerySpeed($seat)) {
            return "That ability of {$card->name} can be activated only in your own main phase, when the stack is empty.";
        }
        if ($ability['once'] && ($object->used[$index] ?? 0) === $this->turn) {
            return "You have already activated that ability of {$card->name} this turn.";
        }
        $cost = $ability['cost'];
        if (isset($cost['loyalty'])) {
            // One loyalty ability per planeswalker per turn (rule 606.3).
            foreach ($object->used as $used => $turn) {
                if ($turn === $this->turn && isset($card->activated[$used]['cost']['loyalty'])) {
                    return "You have already activated a loyalty ability of {$card->name} this turn.";
                }
            }
            if ($cost['loyalty'] < 0 && $object->counter('loyalty') < -$cost['loyalty']) {
                return "{$card->name} does not have enough loyalty.";
            }
        }
        if ($cost['tap'] ?? false) {
            if ($object->tapped) {
                return "{$card->name} is tapped.";
            }
            if ($this->isCreature($object) && $object->sick && ! $this->hasKeyword($object, 'haste')) {
                return "{$card->name} has not been under your control since your turn began.";
            }
        }
        if (isset($cost['crew']) && $this->crew($seat, $object, $cost['crew']) === null) {
            return "Crewing {$card->name} needs untapped creatures with total power {$cost['crew']} or more.";
        }
        if (($cost['life'] ?? 0) > $this->players[$seat]->life) {
            return 'You do not have enough life.';
        }

        return null;
    }

    /**
     * The creatures tapped to crew a Vehicle (rule 702.122): untapped
     * creatures other than it with enough total power, those that could not
     * attack this turn anyway first, then the weakest.
     *
     * @param int        $seat
     * @param GameObject $vehicle
     * @param int        $power
     *
     * @return int[]|null Null when there are not enough.
     */
    private function crew(int $seat, GameObject $vehicle, int $power): ?array
    {
        $crew = array_values(array_filter($this->permanents($seat), fn (GameObject $o) => $o->id !== $vehicle->id && $this->isCreature($o) && ! $o->tapped && $this->power($o) > 0));
        usort($crew, fn (GameObject $a, GameObject $b) => [! ($a->sick && ! $this->hasKeyword($a, 'haste')), $this->power($a)] <=> [! ($b->sick && ! $this->hasKeyword($b, 'haste')), $this->power($b)]);
        $chosen = [];
        $total = 0;
        foreach ($crew as $creature) {
            if ($total >= $power) {
                break;
            }
            $chosen[] = $creature->id;
            $total += $this->power($creature);
        }

        return $total >= $power ? $chosen : null;
    }

    /**
     * How a player would pay an ability's costs, or null when they cannot.
     *
     * @param int        $seat
     * @param GameObject $object
     * @param array      $ability
     * @param string     $wardMana
     * @param int        $wardLife
     *
     * @return array{life: int, pool: array<string, int>, tap: int[], float: array<string, int>, made: array<int, string>}|null
     */
    private function abilityPayment(int $seat, GameObject $object, array $ability, string $wardMana = '', int $wardLife = 0): ?array
    {
        // A source tapped for the cost itself cannot also make mana for it.
        return $this->payFor($seat, ($ability['cost']['mana'] ?? '').$wardMana, ($ability['cost']['life'] ?? 0) + $wardLife, ($ability['cost']['tap'] ?? false) ? [$object->id] : []);
    }

    /**
     * Activates an ability (rule 602): it goes on the stack and its costs
     * are paid, mana automatically, with ward's for opponents' permanents it
     * targets on top. The player keeps priority. Turning a face-down
     * permanent face up happens at once instead.
     *
     * @param int      $seat
     * @param int      $id      A permanent they control.
     * @param int      $index   Among its card's {@see CardDefinition::$activated}.
     * @param string[] $targets One per target, as for {@see cast()}.
     *
     * @return void
     */
    public function activate(int $seat, int $id, int $index, array $targets = []): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotActivate($seat, $id, $index)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $ability = $object->definition()->activated[$index];
        $kinds = self::targetKindsOf($ability['effects']);
        $targets = array_values($targets);
        $label = "{$object->name()}'s ability";
        $this->checkTargets($kinds, $targets, $seat, $label);
        [$wardMana, $wardLife] = $this->wardCost(array_map(fn (string $target) => $this->pinTarget($target), $targets), $seat);
        $payment = $this->abilityPayment($seat, $object, $ability, $wardMana, $wardLife);
        if ($payment === null) {
            throw new GameException('You cannot pay '.(($ability['cost']['mana'] ?? '').$wardMana ?: 'that cost').'.');
        }

        $player = $this->players[$seat];
        $cost = $ability['cost'];
        $crew = isset($cost['crew']) ? (array) $this->crew($seat, $object, $cost['crew']) : [];
        $this->pay($seat, $payment);
        if ($cost['tap'] ?? false) {
            $object->tapped = true;
        }
        foreach ($crew as $creature) {
            $this->objects[$creature]->tapped = true;
        }
        if (isset($cost['loyalty'])) {
            $object->addCounters('loyalty', $cost['loyalty']);
        }

        if (self::isSpecialAction($ability)) {
            $this->turnFaceUp($object);
            $this->settle();

            return;
        }
        $object->used[$index] = $this->turn;

        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => $ability['effects'],
            'kinds' => $kinds,
            'label' => $label,
        ], $targets, 'is activated', $object->name().'*'.($crew === [] ? '' : ' (crew '.implode(', ', array_map(fn (int $c) => $this->objects[$c]->name(), $crew)).')'));
        if ($cost['sacrifice'] ?? false) {
            $this->moveTo($object, GameObject::GRAVEYARD);
            $this->log("{$player->name} sacrifices {$object->name()}.", $seat, "sac {$object->name()}");
        }
        $this->settle();
    }

    /**
     * Turns a face-down permanent face up (rule 708.8): it becomes the card
     * it is, with a +1/+1 counter for megamorph, and its "is turned face up"
     * abilities trigger.
     *
     * @param GameObject $object
     *
     * @return void
     */
    private function turnFaceUp(GameObject $object): void
    {
        $object->faceDown = false;
        if (($object->printed()->morph['kind'] ?? null) === 'megamorph') {
            $object->addCounters('+1/+1', 1);
        }
        $this->passes = 0;
        $this->log("{$this->players[$object->controller]->name} turns a face-down creature face up: it is {$object->name()}.", $object->controller, "unmorph {$object->name()}");
        $this->trigger($object, 'turned_face_up', $object->controller);
    }

    // ----------------------------------------------------------------------
    // Targets
    // ----------------------------------------------------------------------

    /**
     * Whether something is a legal target of a kind for a spell a player
     * controls (rule 115). Hexproof and shroud are checked here.
     *
     * @param string   $kind       A kind from {@see TextParser::TARGETS} or an Aura's enchant restriction.
     * @param string   $target     `p:{seat}`, `o:{id}` (optionally `:{incarnation}`) or `s:{stackId}`.
     * @param int      $controller
     * @param int|null $self       The stack id of the spell itself, which cannot target itself.
     *
     * @return bool
     */
    public function isLegalTarget(string $kind, string $target, int $controller, ?int $self = null): bool
    {
        $parts = explode(':', $target);
        switch ($parts[0]) {
            case 'p':
                $seat = (int) ($parts[1] ?? -1);
                if (! isset($this->players[$seat]) || $this->players[$seat]->lost) {
                    return false;
                }

                return match ($kind) {
                    'any', 'player', 'player_or_planeswalker' => true,
                    'opponent' => $seat !== $controller,
                    default => false,
                };

            case 'o':
                $object = $this->targetObject($target);
                if ($object === null || $object->zone !== GameObject::BATTLEFIELD) {
                    return false;
                }
                if ($this->hasKeyword($object, 'shroud') || ($object->controller !== $controller && $this->hasKeyword($object, 'hexproof'))) {
                    return false;
                }

                return $this->matchesKind($object, $kind, $controller);

            case 's':
                $stackId = (int) ($parts[1] ?? 0);
                if ($stackId === $self) {
                    return false;
                }
                foreach ($this->stack as $item) {
                    if ($item['id'] === $stackId && ! self::isAbility($item)) {
                        $card = $this->objects[$item['object']]->definition();

                        return match ($kind) {
                            'spell' => true,
                            'creature_spell' => $card->isCreature(),
                            'noncreature_spell' => ! $card->isCreature(),
                            default => false,
                        };
                    }
                }

                return false;
        }

        return false;
    }

    private function matchesKind(GameObject $object, string $kind, int $controller): bool
    {
        $card = $object->definition();
        $creature = $this->isCreature($object);

        return match ($kind) {
            'any' => $creature || $card->isPlaneswalker() || $card->is('Battle'),
            'creature' => $creature,
            'creature_you_control' => $creature && $object->controller === $controller,
            'creature_opponent' => $creature && $object->controller !== $controller,
            'creature_or_planeswalker' => $creature || $card->isPlaneswalker(),
            'player_or_planeswalker', 'planeswalker' => $card->isPlaneswalker(),
            'artifact' => $card->is('Artifact'),
            'enchantment' => $card->is('Enchantment'),
            'artifact_or_enchantment' => $card->is('Artifact') || $card->is('Enchantment'),
            'land' => $card->isLand(),
            'nonland_permanent' => ! $card->isLand(),
            'permanent' => true,
            default => false,
        };
    }

    /**
     * Everything a player's spell could target for one kind of target.
     *
     * @param int      $seat
     * @param string   $kind
     * @param int|null $self
     *
     * @return string[]
     */
    public function targetOptions(int $seat, string $kind, ?int $self = null): array
    {
        $options = [];
        foreach (array_keys($this->players) as $player) {
            $options[] = "p:{$player}";
        }
        foreach ($this->battlefield as $id) {
            $options[] = "o:{$id}";
        }
        foreach ($this->stack as $item) {
            $options[] = "s:{$item['id']}";
        }

        return array_values(array_filter($options, fn (string $target) => $this->isLegalTarget($kind, $target, $seat, $self)));
    }

    /**
     * Pins an object target to the object it is now (rule 400.7).
     *
     * @param string $target
     *
     * @return string
     */
    private function pinTarget(string $target): string
    {
        $parts = explode(':', $target);
        if ($parts[0] !== 'o') {
            return $target;
        }

        return "o:{$parts[1]}:".$this->objects[(int) $parts[1]]->incarnation;
    }

    /**
     * The object a target names, if it is still that object.
     *
     * @param string $target
     *
     * @return GameObject|null
     */
    private function targetObject(string $target): ?GameObject
    {
        $parts = explode(':', $target);
        if ($parts[0] !== 'o' || ! isset($this->objects[(int) ($parts[1] ?? 0)])) {
            return null;
        }
        $object = $this->objects[(int) $parts[1]];
        if (isset($parts[2]) && (int) $parts[2] !== $object->incarnation) {
            return null;
        }

        return $object;
    }

    /**
     * A target's name, for the log and menus.
     *
     * @param string $target
     *
     * @return string
     */
    public function describeTarget(string $target): string
    {
        $parts = explode(':', $target);

        return match ($parts[0]) {
            'p' => $this->players[(int) $parts[1]]->name ?? 'a player',
            'o' => isset($this->objects[(int) $parts[1]]) ? $this->objects[(int) $parts[1]]->name() : 'a permanent',
            's' => $this->describeStackItem((int) $parts[1]),
            default => $target,
        };
    }

    private function describeStackItem(int $stackId): string
    {
        foreach ($this->stack as $item) {
            if ($item['id'] === $stackId) {
                return self::isAbility($item) ? $item['label'] : $this->objects[$item['object']]->name();
            }
        }

        return 'a spell';
    }

    // ----------------------------------------------------------------------
    // Combat
    // ----------------------------------------------------------------------

    /**
     * The active player's creatures that could attack (rule 508.1a).
     *
     * @return int[]
     */
    public function attackCandidates(): array
    {
        return array_values(array_map(fn (GameObject $o) => $o->id, array_filter(
            $this->permanents($this->active),
            fn (GameObject $o) => $this->isCreature($o) && ! $o->tapped && (! $o->sick || $this->hasKeyword($o, 'haste')) && ! $this->hasKeyword($o, 'defender') && ! $this->hasKeyword($o, "can't attack")
        )));
    }

    /**
     * Declares attackers; they all attack the defending player. Attacking
     * taps a creature unless it has vigilance.
     *
     * @param int   $seat
     * @param int[] $ids  May be empty: no attack.
     *
     * @return void
     */
    public function declareAttackers(int $seat, array $ids): void
    {
        $this->expect($seat, 'attack');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $candidates = $this->attackCandidates();
        foreach ($ids as $id) {
            if (! in_array($id, $candidates, true)) {
                throw new GameException(($this->objects[$id] ?? null)?->name().' cannot attack.');
            }
        }

        $this->declared['attack'] = true;
        foreach ($ids as $id) {
            $this->attackers[$id] = true;
            if (! $this->hasKeyword($this->objects[$id], 'vigilance')) {
                $this->objects[$id]->tapped = true;
            }
            $this->trigger($this->objects[$id], 'attacks', $seat);
        }

        if ($ids === []) {
            $this->log("{$this->players[$seat]->name} does not attack.", $seat);
            // No attackers: the blockers and damage steps are skipped (rule 508.8).
            $this->enterStep(Step::EndCombat);
        } else {
            $attacking = implode(', ', array_map(fn ($id) => $this->objects[$id]->name(), $ids));
            $this->log("{$this->players[$seat]->name} attacks with {$attacking}.", $seat, "atk {$attacking}");
            $this->priority = $this->active;
        }
        $this->settle();
    }

    /**
     * The defending player's creatures that could block at least one attacker.
     *
     * @return int[]
     */
    public function blockCandidates(): array
    {
        $candidates = [];
        foreach ($this->permanents($this->defender()) as $object) {
            foreach (array_keys($this->attackers) as $attacker) {
                if ($this->canBlock($object, $this->objects[$attacker])) {
                    $candidates[] = $object->id;
                    break;
                }
            }
        }

        return $candidates;
    }

    /**
     * Whether a creature could block an attacker, on its own terms
     * (menace is checked for the whole block).
     *
     * @param GameObject $blocker
     * @param GameObject $attacker
     *
     * @return bool
     */
    public function canBlock(GameObject $blocker, GameObject $attacker): bool
    {
        if (! $this->isCreature($blocker) || $blocker->tapped || $blocker->zone !== GameObject::BATTLEFIELD || $blocker->controller !== $this->defender() || $this->hasKeyword($blocker, "can't block")) {
            return false;
        }
        if (! isset($this->attackers[$attacker->id]) || $attacker->zone !== GameObject::BATTLEFIELD) {
            return false;
        }
        if ($this->hasKeyword($attacker, 'flying') && ! $this->hasKeyword($blocker, 'flying') && ! $this->hasKeyword($blocker, 'reach')) {
            return false;
        }

        return true;
    }

    /**
     * Declares blockers: each blocker blocks one attacker (rule 509.1).
     *
     * @param int             $seat
     * @param array<int, int> $blocks Blocker id => attacker id. May be empty.
     *
     * @return void
     */
    public function declareBlockers(int $seat, array $blocks): void
    {
        $this->expect($seat, 'block');
        $count = [];
        foreach ($blocks as $blocker => $attacker) {
            $blockerObject = $this->objects[(int) $blocker] ?? null;
            $attackerObject = $this->objects[(int) $attacker] ?? null;
            if ($blockerObject === null || $attackerObject === null || ! $this->canBlock($blockerObject, $attackerObject)) {
                throw new GameException(($blockerObject?->name() ?? 'That creature').' cannot block '.($attackerObject?->name() ?? 'that').'.');
            }
            $count[(int) $attacker] = ($count[(int) $attacker] ?? 0) + 1;
        }
        foreach ($count as $attacker => $n) {
            if ($n < 2 && $this->hasKeyword($this->objects[$attacker], 'menace')) {
                throw new GameException($this->objects[$attacker]->name().' has menace and can be blocked only by two or more creatures.');
            }
        }

        $this->declared['block'] = true;
        $lines = $moves = [];
        foreach ($blocks as $blocker => $attacker) {
            $this->blockers[(int) $blocker] = (int) $attacker;
            $this->blocked[(int) $attacker] = true;
            $lines[] = $this->objects[(int) $blocker]->name().' blocks '.$this->objects[(int) $attacker]->name();
            $moves[] = $this->objects[(int) $blocker]->name().':'.$this->objects[(int) $attacker]->name();
        }
        $this->log($lines === [] ? "{$this->players[$seat]->name} does not block." : implode('; ', $lines).'.', $seat, $moves === [] ? null : 'blk '.implode(', ', $moves));
        $this->priority = $this->active;
        $this->settle();
    }

    private function anyFirstStrike(): bool
    {
        foreach ([...array_keys($this->attackers), ...array_keys($this->blockers)] as $id) {
            $object = $this->objects[$id];
            if ($object->zone === GameObject::BATTLEFIELD && ($this->hasKeyword($object, 'first strike') || $this->hasKeyword($object, 'double strike'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assigns and deals combat damage (rule 510). Each attacker assigns
     * lethal damage to its blockers in the order they were declared, the
     * rest to the last one, or with trample to the player.
     *
     * @param bool $first The first-strike damage step.
     *
     * @return void
     */
    private function combatDamage(bool $first): void
    {
        $deals = function (GameObject $object) use ($first): bool {
            if ($object->zone !== GameObject::BATTLEFIELD || ! $this->isCreature($object)) {
                return false;
            }
            $firstStrike = $this->hasKeyword($object, 'first strike');
            $double = $this->hasKeyword($object, 'double strike');

            return $first ? ($firstStrike || $double) : ($double || ! isset($this->struckFirst[$object->id]));
        };

        $assignments = [];
        foreach (array_keys($this->attackers) as $id) {
            $attacker = $this->objects[$id];
            if (! $deals($attacker) || ($power = $this->power($attacker)) <= 0) {
                continue;
            }
            $defender = 'p:'.$this->defender();
            if (! isset($this->blocked[$id])) {
                $assignments[] = [$attacker, $defender, $power];

                continue;
            }

            $blockers = array_values(array_filter(
                array_keys($this->blockers, $id, true),
                fn (int $blocker) => $this->objects[$blocker]->zone === GameObject::BATTLEFIELD
            ));
            $trample = $this->hasKeyword($attacker, 'trample');
            if ($blockers === []) {
                if ($trample) {
                    $assignments[] = [$attacker, $defender, $power];
                }

                continue;
            }
            $deathtouch = $this->hasKeyword($attacker, 'deathtouch');
            foreach ($blockers as $index => $blocker) {
                $object = $this->objects[$blocker];
                $lethal = $deathtouch ? 1 : max(0, $this->toughness($object) - $object->damage);
                $last = $index === count($blockers) - 1;
                $amount = $last && ! $trample ? $power : min($power, $lethal);
                if ($amount > 0) {
                    $assignments[] = [$attacker, "o:{$blocker}", $amount];
                }
                $power -= $amount;
            }
            if ($power > 0) {
                $assignments[] = [$attacker, $defender, $power];
            }
        }
        foreach ($this->blockers as $blocker => $attacker) {
            $object = $this->objects[$blocker];
            if ($deals($object) && ($power = $this->power($object)) > 0 && $this->objects[$attacker]->zone === GameObject::BATTLEFIELD) {
                $assignments[] = [$object, "o:{$attacker}", $power];
            }
        }

        if ($first) {
            foreach ([...array_keys($this->attackers), ...array_keys($this->blockers)] as $id) {
                if ($deals($this->objects[$id])) {
                    $this->struckFirst[$id] = true;
                }
            }
        }

        // All combat damage is dealt at once (rule 510.2).
        $lines = $hitPlayer = $toPlayers = [];
        foreach ($assignments as [$source, $target, $amount]) {
            $this->dealDamage($source, $target, $amount);
            $lines[] = "{$source->name()} deals {$amount} to ".$this->describeTarget($target);
            if ($target[0] === 'p') {
                $hitPlayer[$source->id] = $source;
                $toPlayers[(int) substr($target, 2)] = ($toPlayers[(int) substr($target, 2)] ?? 0) + $amount;
                if ($this->isCommander($source)) {
                    $hit = $this->players[(int) substr($target, 2)];
                    $hit->commanderDamage[$source->id] = ($hit->commanderDamage[$source->id] ?? 0) + $amount;
                }
            }
        }
        foreach ($hitPlayer as $source) {
            $this->trigger($source, 'combat_damage', $source->controller);
        }
        if ($lines !== []) {
            $hits = array_map(fn (int $seat, int $amount) => "{$this->players[$seat]->name} -{$amount}", array_keys($toPlayers), $toPlayers);
            $this->log(implode('; ', $lines).'.', null, $hits === [] ? null : implode(', ', $hits));
        }
    }

    // ----------------------------------------------------------------------
    // Damage, zones and state-based actions
    // ----------------------------------------------------------------------

    /**
     * Deals damage from a source (rule 120.3): life loss for players,
     * marked damage for creatures, loyalty loss for planeswalkers.
     *
     * @param GameObject $source
     * @param string     $target `p:{seat}` or `o:{id}`.
     * @param int        $amount
     *
     * @return void
     */
    private function dealDamage(GameObject $source, string $target, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $parts = explode(':', $target);
        if ($parts[0] === 'p') {
            $this->players[(int) $parts[1]]->life -= $amount;
        } else {
            $object = $this->targetObject($target);
            if ($object === null || $object->zone !== GameObject::BATTLEFIELD) {
                return;
            }
            if ($object->definition()->isPlaneswalker() && ! $this->isCreature($object)) {
                $object->addCounters('loyalty', -$amount);
            } else {
                $object->damage += $amount;
                if ($this->hasKeyword($source, 'deathtouch')) {
                    $object->deathtouched = true;
                }
            }
        }
        if ($this->hasKeyword($source, 'lifelink')) {
            $this->players[$source->controller]->life += $amount;
        }
    }

    private function destroy(?GameObject $object): void
    {
        if ($object === null || $object->zone !== GameObject::BATTLEFIELD || $this->hasKeyword($object, 'indestructible') || $this->regenerate($object)) {
            return;
        }
        $this->moveTo($object, GameObject::GRAVEYARD);
    }

    /**
     * Uses a regeneration shield instead of a permanent being destroyed
     * (rule 701.15): it is tapped, its damage is removed and it is removed
     * from combat.
     *
     * @param GameObject $object
     *
     * @return bool Whether it had a shield.
     */
    private function regenerate(GameObject $object): bool
    {
        if ($object->shields <= 0) {
            return false;
        }
        $object->shields--;
        $object->tapped = true;
        $object->damage = 0;
        $object->deathtouched = false;
        unset($this->attackers[$object->id], $this->blockers[$object->id]);
        $this->log("{$object->name()} regenerates.");

        return true;
    }

    /**
     * Draws cards; drawing from an empty library loses the game the next
     * time state-based actions are checked (rule 704.5b).
     *
     * @param int $seat
     * @param int $count
     *
     * @return void
     */
    private function draw(int $seat, int $count): void
    {
        $player = $this->players[$seat];
        for ($i = 0; $i < $count; $i++) {
            $id = array_pop($player->library);
            if ($id === null) {
                $player->drewFromEmpty = true;

                return;
            }
            $this->objects[$id]->moveTo(GameObject::HAND);
            $player->hand[] = $id;
        }
        if ($this->stage === self::PLAYING) {
            $this->note("{$player->name} draws ".($count === 1 ? 'a card' : "{$count} cards").'.', $seat);
        }
    }

    /**
     * @param GameObject $object
     * @param int        $controller
     * @param bool       $triggers   Whether its "enters" abilities trigger.
     * @param bool       $faceDown
     * @param bool       $kicked
     *
     * @return void
     */
    private function putOntoBattlefield(GameObject $object, int $controller, bool $triggers = true, bool $faceDown = false, bool $kicked = false): void
    {
        $this->removeFromZone($object);
        $object->moveTo(GameObject::BATTLEFIELD);
        $object->faceDown = $faceDown;
        $object->kicked = $kicked;
        $card = $object->definition();
        $object->controller = $controller;
        $object->sick = true;
        $object->tapped = $card->entersTapped;
        if ($card->isPlaneswalker() && $card->loyalty !== null) {
            $object->addCounters('loyalty', $card->loyalty);
        }
        $counters = $card->entersWithCounters + ($kicked ? $card->kickerCounters : 0);
        if ($counters > 0) {
            $object->addCounters('+1/+1', $counters);
        }
        $this->battlefield[] = $object->id;
        if ($triggers) {
            $this->trigger($object, 'enters', $controller);
        }
    }

    /**
     * Moves an object to its owner's hand, library top, graveyard, or exile.
     * A token that leaves the battlefield ceases to exist (rules 111.7 and
     * 111.8), though it still "dies" on its way to the graveyard.
     *
     * @param GameObject|null $object
     * @param string          $zone
     *
     * @return void
     */
    private function moveTo(?GameObject $object, string $zone): void
    {
        if ($object === null) {
            return;
        }
        $controller = $object->controller;
        $incarnation = $object->incarnation;
        $dies = $object->zone === GameObject::BATTLEFIELD && $zone === GameObject::GRAVEYARD && $this->isCreature($object);
        if ($object->definition()->isToken()) {
            $zone = GameObject::GONE;
        }

        $this->removeFromZone($object);
        $object->moveTo($zone);
        $owner = $this->players[$object->owner];
        match ($zone) {
            GameObject::HAND => $owner->hand[] = $object->id,
            GameObject::GRAVEYARD => $owner->graveyard[] = $object->id,
            GameObject::LIBRARY => $owner->library[] = $object->id,
            GameObject::EXILE => $this->exile[] = $object->id,
            GameObject::COMMAND => $this->command[] = $object->id,
            GameObject::GONE => null,
        };
        if ($dies) {
            $this->trigger($object, 'dies', $controller, $incarnation);
        }
    }

    private function removeFromZone(GameObject $object): void
    {
        $remove = fn (array $ids) => array_values(array_filter($ids, fn (int $id) => $id !== $object->id));
        $owner = $this->players[$object->owner];
        switch ($object->zone) {
            case GameObject::BATTLEFIELD:
                $this->battlefield = $remove($this->battlefield);
                unset($this->attackers[$object->id], $this->blockers[$object->id]);
                // What was attached to it is no longer attached (state-based actions deal with it).
                foreach ($this->battlefield as $id) {
                    if ($this->objects[$id]->attachedTo === $object->id) {
                        $this->objects[$id]->attachedTo = null;
                    }
                }
                break;
            case GameObject::HAND:
                $owner->hand = $remove($owner->hand);
                break;
            case GameObject::LIBRARY:
                $owner->library = $remove($owner->library);
                break;
            case GameObject::GRAVEYARD:
                $owner->graveyard = $remove($owner->graveyard);
                break;
            case GameObject::EXILE:
                $this->exile = $remove($this->exile);
                break;
            case GameObject::COMMAND:
                $this->command = $remove($this->command);
                break;
            case GameObject::STACK:
                $this->stack = array_values(array_filter($this->stack, fn (array $item) => self::isAbility($item) || $item['object'] !== $object->id));
                break;
        }
    }

    /**
     * Rule 704: checks state-based actions until none apply, then ends the
     * game if a player has lost.
     *
     * @return void
     */
    private function checkStateBasedActions(): void
    {
        for ($guard = 0; $guard < 100; $guard++) {
            $changed = false;

            foreach ($this->players as $player) {
                if ($player->lost) {
                    continue;
                }
                $reason = match (true) {
                    $player->life <= 0 => 'has no life left',
                    max([0, ...$player->commanderDamage]) >= self::COMMANDER_DAMAGE => 'has taken '.self::COMMANDER_DAMAGE.' combat damage from one commander',
                    $player->drewFromEmpty => 'tried to draw from an empty library',
                    $player->poison >= 10 => 'has ten poison counters',
                    default => null,
                };
                if ($reason !== null) {
                    $this->lose($player, $reason);
                    $changed = true;
                }
            }

            $toGraveyard = [];
            $legends = [];
            foreach ($this->battlefield as $id) {
                $object = $this->objects[$id];
                $card = $object->definition();

                // +1/+1 and -1/-1 counters cancel out (rule 704.5q).
                $both = min($object->counter('+1/+1'), $object->counter('-1/-1'));
                if ($both > 0) {
                    $object->addCounters('+1/+1', -$both);
                    $object->addCounters('-1/-1', -$both);
                }

                if ($this->isCreature($object)) {
                    $toughness = $this->toughness($object);
                    if ($toughness <= 0) {
                        $toGraveyard[$id] = "{$object->name()} has 0 toughness";
                    } elseif (($object->damage >= $toughness || ($object->deathtouched && $object->damage > 0)) && ! $this->hasKeyword($object, 'indestructible')) {
                        if ($this->regenerate($object)) {
                            $changed = true;
                        } else {
                            $toGraveyard[$id] = "{$object->name()} dies";
                        }
                    }
                }
                if ($card->isPlaneswalker() && $object->counter('loyalty') <= 0) {
                    $toGraveyard[$id] = "{$object->name()} has no loyalty left";
                }
                if ($card->isAura()) {
                    $host = $object->attachedTo === null ? null : ($this->objects[$object->attachedTo] ?? null);
                    if ($host === null || $host->zone !== GameObject::BATTLEFIELD || ! $this->matchesKind($host, $card->aura['enchant'] ?? 'permanent', $object->controller)) {
                        $toGraveyard[$id] = "{$object->name()} is not attached to anything";
                    }
                }
                // Equipment attached to something that is not a creature becomes unattached (rule 704.5n).
                if ($card->isEquipment() && $object->attachedTo !== null) {
                    $host = $this->objects[$object->attachedTo] ?? null;
                    if ($host === null || $host->zone !== GameObject::BATTLEFIELD || ! $this->isCreature($host)) {
                        $object->attachedTo = null;
                        $changed = true;
                    }
                }
                if ($card->isLegendary()) {
                    $legends[$object->controller.':'.$card->name][] = $id;
                }
            }
            // The legend rule (704.5j): the newest one stays.
            foreach ($legends as $ids) {
                foreach (array_slice($ids, 0, -1) as $id) {
                    $toGraveyard[$id] = "{$this->objects[$id]->name()} goes to the graveyard under the legend rule";
                }
            }

            foreach ($toGraveyard as $id => $reason) {
                $this->log("{$reason}.", null, '†'.$this->objects[$id]->name());
                $this->moveTo($this->objects[$id], GameObject::GRAVEYARD);
                $changed = true;
            }

            // A commander put into a graveyard or exile goes back to the
            // command zone (rule 903.9a; the game always chooses to).
            foreach ($this->commanders as $id => $casts) {
                $object = $this->objects[$id];
                if (in_array($object->zone, [GameObject::GRAVEYARD, GameObject::EXILE], true)) {
                    $this->moveTo($object, GameObject::COMMAND);
                    $this->log("{$object->name()} returns to the command zone.");
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        $alive = array_filter($this->players, fn (GamePlayer $player) => ! $player->lost);
        if (count($alive) <= 1 && $this->stage !== self::OVER) {
            $this->stage = self::OVER;
            $this->priority = null;
            $this->winner = $alive === [] ? null : array_key_first($alive);
            $this->log($this->winner === null ? 'The game is a draw.' : "{$this->players[$this->winner]->name} wins the game!");
            $this->recordPosition();
        }
    }

    private function lose(GamePlayer $player, string $reason): void
    {
        $player->lost = true;
        $player->lossReason = $reason;
        $this->log("{$player->name} {$reason} and loses the game.", $player->seat, $reason === 'concedes' ? 'resign' : null);
    }

    /**
     * Concedes (rule 104.3a). Allowed at any time, even before the game starts.
     *
     * @param int $seat
     *
     * @return void
     */
    public function concede(int $seat): void
    {
        if ($this->stage === self::OVER) {
            throw new GameException('The game is already over.');
        }
        $this->lose($this->players[$seat], 'concedes');
        $this->checkStateBasedActions();
    }

    // ----------------------------------------------------------------------
    // Characteristics
    // ----------------------------------------------------------------------

    /**
     * A player's permanents, or everyone's.
     *
     * @param int|null $controller
     *
     * @return GameObject[]
     */
    public function permanents(?int $controller = null): array
    {
        $objects = array_map(fn (int $id) => $this->objects[$id], $this->battlefield);

        return $controller === null ? $objects : array_values(array_filter($objects, fn (GameObject $object) => $object->controller === $controller));
    }

    /**
     * Whether it is a creature: by its card, or a Vehicle crewed this turn.
     *
     * @param GameObject $object
     *
     * @return bool
     */
    public function isCreature(GameObject $object): bool
    {
        if ($object->definition()->isCreature()) {
            return true;
        }
        foreach ($object->untilEndOfTurn as $effect) {
            if ($effect['creature'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A level up creature's band for its level counters, if it has one.
     *
     * @param GameObject $object
     *
     * @return array{min: int, max: int|null, power: int|null, toughness: int|null, keywords: string[]}|null
     */
    private function levelBand(GameObject $object): ?array
    {
        $card = $object->definition();

        return $card->levels === [] ? null : $card->levelBand($object->counter('level'));
    }

    /**
     * The Auras and Equipment attached to a permanent.
     *
     * @param GameObject $object
     *
     * @return GameObject[]
     */
    public function attachments(GameObject $object): array
    {
        return array_values(array_filter($this->permanents(), fn (GameObject $attached) => $attached->attachedTo === $object->id && $attached->definition()->attachmentBonus() !== null));
    }

    public function power(GameObject $object): int
    {
        $power = ($this->levelBand($object)['power'] ?? $object->definition()->power ?? 0) + $object->counter('+1/+1') - $object->counter('-1/-1');
        foreach ($this->attachments($object) as $attached) {
            $power += $attached->definition()->attachmentBonus()['power'];
        }
        foreach ($object->untilEndOfTurn as $effect) {
            $power += $effect['power'];
        }

        return $power;
    }

    public function toughness(GameObject $object): int
    {
        $toughness = ($this->levelBand($object)['toughness'] ?? $object->definition()->toughness ?? 0) + $object->counter('+1/+1') - $object->counter('-1/-1');
        foreach ($this->attachments($object) as $attached) {
            $toughness += $attached->definition()->attachmentBonus()['toughness'];
        }
        foreach ($object->untilEndOfTurn as $effect) {
            $toughness += $effect['toughness'];
        }

        return $toughness;
    }

    /**
     * Its keywords: printed, from its level, from Auras and Equipment, and
     * until end of turn; with the {@see CardDefinition::RESTRICTIONS} on it.
     *
     * @param GameObject $object
     *
     * @return string[]
     */
    public function keywords(GameObject $object): array
    {
        $keywords = $object->definition()->keywords;
        if ($object->zone === GameObject::BATTLEFIELD) {
            array_push($keywords, ...($this->levelBand($object)['keywords'] ?? []));
            foreach ($this->attachments($object) as $attached) {
                array_push($keywords, ...$attached->definition()->attachmentBonus()['keywords']);
            }
            foreach ($object->untilEndOfTurn as $effect) {
                array_push($keywords, ...$effect['keywords']);
            }
        }

        return array_values(array_unique($keywords));
    }

    public function hasKeyword(GameObject $object, string $keyword): bool
    {
        return in_array($keyword, $this->keywords($object), true);
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    /**
     * Throws unless a player has a given choice to make now.
     *
     * @param int    $seat
     * @param string $decision
     *
     * @return void
     */
    private function expect(int $seat, string $decision): void
    {
        if (! isset($this->players[$seat])) {
            throw new GameException('You are not playing in this game.');
        }
        if ($this->stage === self::OVER) {
            throw new GameException('The game is over.');
        }
        $actual = $this->decision($seat);
        if ($actual !== $decision) {
            throw new GameException(match ($decision) {
                'priority' => 'You do not have priority right now.',
                'mulligan' => 'You have already kept your hand.',
                'trigger' => 'No ability of yours is waiting for targets.',
                'scry', 'surveil' => 'You have nothing to scry or surveil.',
                'bottom' => 'You have no cards to put on the bottom.',
                'attack' => 'You cannot declare attackers now.',
                'block' => 'You cannot declare blockers now.',
                'discard' => 'You have nothing to discard now.',
                default => 'You cannot do that now.',
            });
        }
    }

    private function shuffle(int $seat): void
    {
        $this->players[$seat]->library = $this->random()->shuffleArray($this->players[$seat]->library);
    }

    /**
     * A random source for the next shuffle, from the game's seed.
     *
     * @return Randomizer
     */
    private function random(): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash('sha256', $this->seed.':'.$this->shuffles++, true)));
    }

    /**
     * Adds a line to the log and the record.
     *
     * @param string      $line
     * @param int|null    $seat The player who made the move or whose turn begins; null for what follows from the rules.
     * @param string|null $move The move in the record's notation, when it belongs on the score sheet.
     * @param string|null $full The line for the record, when it says more than the board has room for.
     *
     * @return void
     */
    private function log(string $line, ?int $seat = null, ?string $move = null, ?string $full = null): void
    {
        $this->log[] = $line;
        if (count($this->log) > self::MAX_LOG) {
            $this->log = array_slice($this->log, -self::MAX_LOG);
        }
        $this->note($full ?? $line, $seat, $move);
    }

    /**
     * Adds a line to the record only, for what the board does not need to show.
     *
     * @param string      $line
     * @param int|null    $seat
     * @param string|null $move
     *
     * @return void
     */
    private function note(string $line, ?int $seat = null, ?string $move = null): void
    {
        $this->record[] = ['t' => $this->stage === self::MULLIGAN ? 0 : $this->turn, 's' => $this->stage === self::MULLIGAN ? 'mulligan' : $this->step->value]
            + ($seat === null ? [] : ['p' => $seat])
            + ($move === null ? [] : ['m' => $move])
            + ['x' => $line];
    }

    /**
     * Records the position at the end of a turn or of the game: each
     * player's life, how many cards are in their hidden zones, and their
     * permanents (creatures with their power and toughness).
     *
     * @return void
     */
    private function recordPosition(): void
    {
        $position = [];
        foreach ($this->players as $seat => $player) {
            $board = [];
            foreach ($this->permanents($seat) as $object) {
                $board[] = $object->name()
                    .($this->isCreature($object) ? ' '.$this->power($object).'/'.$this->toughness($object) : '')
                    .($object->definition()->isPlaneswalker() ? ' ['.$object->counter('loyalty').']' : '')
                    .($object->attachedTo !== null && isset($this->objects[$object->attachedTo]) ? ' (on '.$this->objects[$object->attachedTo]->name().')' : '')
                    .($object->tapped ? ' (tapped)' : '');
            }
            $position[$seat] = ['life' => $player->life, 'hand' => count($player->hand), 'library' => count($player->library), 'graveyard' => count($player->graveyard), 'board' => $board];
        }
        $this->record[] = ['t' => $this->turn, 's' => $this->step->value, 'pos' => $position];
    }

    /**
     * Targets in move notation: ` > Bob, Grizzly Bears`.
     *
     * @param string[] $named
     *
     * @return string
     */
    private static function targetNotation(array $named): string
    {
        return $named === [] ? '' : ' > '.implode(', ', $named);
    }

    // ----------------------------------------------------------------------
    // Saving
    // ----------------------------------------------------------------------

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seed' => $this->seed,
            'stage' => $this->stage,
            'turn' => $this->turn,
            'startingPlayer' => $this->startingPlayer,
            'active' => $this->active,
            'step' => $this->step->value,
            'priority' => $this->priority,
            'passes' => $this->passes,
            'players' => array_map(fn (GamePlayer $player) => $player->toArray(), $this->players),
            'objects' => array_values(array_map(fn (GameObject $object) => $object->toArray(), $this->objects)),
            'battlefield' => $this->battlefield,
            'stack' => $this->stack,
            'pendingTriggers' => $this->pendingTriggers,
            'pendingChoice' => $this->pendingChoice,
            'exile' => $this->exile,
            'command' => $this->command,
            'commanders' => array_map(fn ($id, $casts) => [$id, $casts], array_keys($this->commanders), $this->commanders),
            'attackers' => array_keys($this->attackers),
            'blockers' => array_map(fn ($blocker, $attacker) => [$blocker, $attacker], array_keys($this->blockers), $this->blockers),
            'blocked' => array_keys($this->blocked),
            'struckFirst' => array_keys($this->struckFirst),
            'declared' => array_keys($this->declared),
            'autoPass' => $this->autoPass,
            'winner' => $this->winner,
            'log' => $this->log,
            'record' => $this->record,
            'nextId' => $this->nextId,
            'nextStackId' => $this->nextStackId,
            'shuffles' => $this->shuffles,
        ];
    }

    public static function fromArray(array $data): self
    {
        $game = new self((string) $data['id'], (string) $data['seed']);
        $game->stage = (string) $data['stage'];
        $game->turn = (int) $data['turn'];
        $game->startingPlayer = (int) $data['startingPlayer'];
        $game->active = (int) $data['active'];
        $game->step = Step::from((string) $data['step']);
        $game->priority = isset($data['priority']) ? (int) $data['priority'] : null;
        $game->passes = (int) ($data['passes'] ?? 0);
        foreach ($data['players'] as $player) {
            $player = GamePlayer::fromArray((array) $player);
            $game->players[$player->seat] = $player;
        }
        ksort($game->players);
        foreach ($data['objects'] as $object) {
            $object = GameObject::fromArray((array) $object);
            $game->objects[$object->id] = $object;
        }
        $game->battlefield = array_map('intval', (array) $data['battlefield']);
        $game->stack = array_values(array_map(fn ($item) => [
            'id' => (int) $item['id'],
            'object' => (int) $item['object'],
            'incarnation' => (int) $item['incarnation'],
            'controller' => (int) $item['controller'],
            'x' => (int) ($item['x'] ?? 0),
            'targets' => array_values(array_map('strval', (array) ($item['targets'] ?? []))),
        ] + (self::isAbility((array) $item) ? [
            'kind' => 'ability',
            'effects' => array_values((array) $item['effects']),
            'kinds' => array_values(array_map('strval', (array) $item['kinds'])),
            'label' => (string) $item['label'],
        ] : array_filter([
            'modes' => array_values(array_map('intval', (array) ($item['modes'] ?? []))),
            'kicked' => (bool) ($item['kicked'] ?? false),
            'flashback' => (bool) ($item['flashback'] ?? false),
            'faceDown' => (bool) ($item['faceDown'] ?? false),
        ])), (array) $data['stack']));
        $game->pendingTriggers = array_values(array_map(fn ($trigger) => [
            'source' => (int) $trigger['source'],
            'incarnation' => (int) $trigger['incarnation'],
            'controller' => (int) $trigger['controller'],
            'effects' => array_values((array) $trigger['effects']),
            'kinds' => array_values(array_map('strval', (array) $trigger['kinds'])),
            'label' => (string) $trigger['label'],
            'text' => (string) ($trigger['text'] ?? ''),
        ], (array) ($data['pendingTriggers'] ?? [])));
        $game->pendingChoice = isset($data['pendingChoice']) ? (array) $data['pendingChoice'] : null;
        $game->exile = array_map('intval', (array) $data['exile']);
        $game->command = array_map('intval', (array) ($data['command'] ?? []));
        foreach ((array) ($data['commanders'] ?? []) as [$id, $casts]) {
            $game->commanders[(int) $id] = (int) $casts;
        }
        $game->attackers = array_fill_keys(array_map('intval', (array) ($data['attackers'] ?? [])), true);
        foreach ((array) ($data['blockers'] ?? []) as [$blocker, $attacker]) {
            $game->blockers[(int) $blocker] = (int) $attacker;
        }
        $game->blocked = array_fill_keys(array_map('intval', (array) ($data['blocked'] ?? [])), true);
        $game->struckFirst = array_fill_keys(array_map('intval', (array) ($data['struckFirst'] ?? [])), true);
        $game->declared = array_fill_keys(array_map('strval', (array) ($data['declared'] ?? [])), true);
        foreach ((array) ($data['autoPass'] ?? []) as $seat => $on) {
            $game->autoPass[(int) $seat] = (bool) $on;
        }
        $game->winner = isset($data['winner']) ? (int) $data['winner'] : null;
        $game->log = array_values(array_map('strval', (array) ($data['log'] ?? [])));
        // Games from before the record have none.
        $game->record = array_values(array_map(fn ($entry) => (array) $entry, (array) ($data['record'] ?? [])));
        $game->nextId = (int) $data['nextId'];
        $game->nextStackId = (int) $data['nextStackId'];
        $game->shuffles = (int) $data['shuffles'];

        return $game;
    }
}
