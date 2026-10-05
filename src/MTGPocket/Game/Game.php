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

    /** Target kinds for cards in a graveyard. */
    private const array GRAVEYARD_KINDS = ['creature_card_yours', 'card_yours', 'card_graveyard_nonbasic'];

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

    /** Ways in {@see plays()} that are not casting a spell. */
    public const array SPECIAL_PLAYS = ['cycle', 'unearth', 'plot', 'suspend', 'ninjutsu', 'regrow', 'foretell', 'eternalize', 'embalm'];

    /** @var array<int, true> Attacking creatures. */
    public array $attackers = [];

    /** @var array<int, int> Blocking creature => the attacker it blocks, in declaration order. */
    public array $blockers = [];

    /** @var array<int, true> Attackers that were blocked, even if their blockers have left. */
    public array $blocked = [];

    /** The turn in which all combat damage is prevented (`Prevent all combat damage that would be dealt this turn.`). */
    public int $fogTurn = 0;

    /** @var array<string, string> A card returned to the battlefield, by its old target, for `It gains haste …` after it. */
    private array $followed = [];

    /** @var array<string, int> Damage to prevent this turn, by target (`p:SEAT` or `o:ID`) (rule 615). */
    public array $prevent = [];

    /** Spells cast in turn `$spellsTurn`, by anyone, for storm (rule 702.40). */
    public int $spellsCast = 0;

    public int $spellsTurn = 0;

    /** Spells cast in the turn before `$spellsTurn`, when that was the turn before it. */
    public int $spellsBefore = 0;

    /** `day`, `night`, or null while it is neither (rule 726). */
    public ?string $dayNight = null;

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
        // Leylines (`If this card is in your opening hand, you may begin the game with it on the battlefield.`).
        foreach ($this->players as $seat => $player) {
            foreach ($player->hand as $id) {
                if (in_array('leyline', $this->objects[$id]->definition()->keywords, true)) {
                    $this->putOntoBattlefield($this->objects[$id], $seat, false);
                    $this->log("{$player->name} begins the game with {$this->objects[$id]->name()} on the battlefield.");
                }
            }
        }
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
                $this->updateControl();
                foreach ($this->players as $player) {
                    $player->lifeMark = $player->life;
                }
                foreach ($this->permanents($this->active) as $object) {
                    $object->sick = false;
                    if ($object->frozen) {
                        $object->frozen = false;
                    } elseif ($object->tapped && $object->counter('stun') > 0) {
                        // Stun counters (rule 122.1d): one is removed instead.
                        $object->addCounters('stun', -1);
                    } elseif (! $this->hasKeyword($object, "doesn't untap")) {
                        $object->tapped = false;
                    }
                }
                $this->enterStep(Step::Upkeep);

                return;

            case Step::Upkeep:
            case Step::End:
                $this->updateControl();
                // Suspend: a time counter off each of the active player's suspended cards; with the last, it can be cast free this upkeep.
                foreach ($step === Step::Upkeep ? $this->exile : [] as $id) {
                    $object = $this->objects[$id];
                    if ($object->exiledBy === 'suspend' && $object->owner === $this->active) {
                        $object->addCounters('time', -1);
                        if ($object->counter('time') === 0) {
                            $object->exiledBy = 'suspended';
                            $object->exiledOn = $this->turn;
                            $this->log("The last time counter is removed from {$object->name()}; it can be cast without paying its mana cost.");
                        }
                    }
                }
                if ($step === Step::End) {
                    foreach ($this->permanents() as $object) {
                        if ($object->unearthed) {
                            $this->moveTo($object, GameObject::EXILE);
                            $this->log("{$object->name()} is exiled (unearth).");
                        }
                        // Dash returns it to its owner's hand, warp exiles it to be cast later, and Mobilize's tokens are sacrificed.
                        $alt = $object->alt;
                        match ($alt) {
                            'dash' => $this->moveTo($object, GameObject::HAND),
                            'warp' => $this->moveTo($object, GameObject::EXILE),
                            'temporary' => $this->moveTo($object, GameObject::GRAVEYARD),
                            'end_exile' => $this->moveTo($object, GameObject::EXILE),
                            default => null,
                        };
                        if ($alt === 'warp' && $object->zone === GameObject::EXILE) {
                            $object->exiledBy = 'warp';
                            $object->exiledOn = $this->turn;
                            $this->log("{$object->name()} is exiled (warp); it can be cast from exile on a later turn.");
                        }
                    }
                }
                if ($step === Step::Upkeep) {
                    $this->dayNightCheck();
                }
                foreach ($this->permanents($this->active) as $object) {
                    $this->trigger($object, $step === Step::Upkeep ? 'upkeep' : 'end_step', $object->controller);
                }
                // "At the beginning of each upkeep, …": every turn's.
                foreach ($step === Step::Upkeep ? $this->permanents() : [] as $object) {
                    $this->trigger($object, 'each_upkeep', $object->controller);
                }
                // "At the beginning of the end step, …": every turn's.
                foreach ($step === Step::End ? $this->permanents() : [] as $object) {
                    $this->trigger($object, 'each_end_step', $object->controller);
                }
                // Curses: "At the beginning of enchanted player's upkeep, …"
                foreach ($step === Step::Upkeep ? $this->permanents() : [] as $object) {
                    if ($object->enchantedPlayer === $this->active) {
                        $this->trigger($object, 'enchanted_upkeep', $object->controller);
                    }
                }
                break;

            case Step::Draw:
                // The player who plays first skips their first draw (rule 103.8a).
                if (! ($this->turn === 1 && $this->active === $this->startingPlayer)) {
                    $this->draw($this->active, 1);
                }
                break;

            case Step::PrecombatMain:
                // After your draw step, each of your Sagas gets a lore counter (rule 714.3b).
                foreach ($this->permanents($this->active) as $object) {
                    if ($this->keywordAmount($object, 'saga') > 0) {
                        $this->addLore($object);
                    }
                }
                // "At the beginning of your first main phase, …"
                foreach ($this->permanents($this->active) as $object) {
                    $this->trigger($object, 'first_main', $object->controller);
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
        $this->prevent = [];
        foreach ($this->battlefield as $id) {
            $object = $this->objects[$id];
            $object->damage = 0;
            $object->deathtouched = false;
            $object->untilEndOfTurn = [];
            $object->shields = 0;
            if ($object->borrowedFrom !== null) {
                $object->controller = $object->borrowedFrom;
                $object->borrowedFrom = null;
                $this->log("{$object->name()} returns to {$this->players[$object->controller]->name}'s control.");
            }
        }
        $this->advance();
    }

    /**
     * How many cards the player discarding now must discard: for a spell or
     * ability, or down to the maximum hand size in the cleanup step.
     *
     * @return int
     */
    public function discardCount(): int
    {
        if (($this->pendingChoice['type'] ?? null) === 'discard') {
            return $this->pendingChoice['count'];
        }

        if (array_filter($this->permanents($this->active), fn (GameObject $o) => $this->hasKeyword($o, 'no maximum hand size')) !== []) {
            return 0;
        }

        return max(0, count($this->players[$this->active]->hand) - GamePlayer::HAND_SIZE);
    }

    /**
     * Discards the chosen cards: for a resolving spell or ability, which then
     * goes on resolving, or down to the maximum hand size in the cleanup
     * step (rule 514.1).
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
        $from = $this->pendingChoice['from'] ?? $seat;
        $allowed = $this->pendingChoice['cards'] ?? $this->players[$from]->hand;
        foreach ($ids as $id) {
            if (! in_array($id, $allowed, true)) {
                throw new GameException($from === $seat ? 'You can only discard cards from your hand.' : 'Choose one of the cards you may choose.');
            }
        }
        if ($this->pendingChoice['exile'] ?? false) {
            $this->exileFromHand($from, $ids);
        } else {
            $this->discardCards($from, $ids);
        }
        $choice = $this->pendingChoice;
        if ($choice === null) {
            $this->cleanup();
        } elseif ($choice['next'] !== []) {
            // The next opponent chooses what they discard.
            $this->pendingChoice['seat'] = array_shift($this->pendingChoice['next']);
        } else {
            $this->pendingChoice = null;
            $this->runSteps($choice['resume']['item'], $choice['resume']['steps'], $choice['resume']['self']);
        }
        $this->settle();
    }

    /**
     * Puts cards from a player's hand into their graveyard.
     *
     * @param int   $seat
     * @param int[] $ids
     *
     * @return void
     */
    private function exileFromHand(int $seat, array $ids): void
    {
        foreach ($ids as $id) {
            $this->moveTo($this->objects[$id], GameObject::EXILE);
        }
        $this->log("{$this->players[$seat]->name}'s ".implode(', ', array_map(fn ($id) => $this->objects[$id]->name(), $ids)).' is exiled.');
    }

    private function discardCards(int $seat, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $discarded = implode(', ', array_map(fn ($id) => $this->objects[$id]->name(), $ids));
        $this->log("{$this->players[$seat]->name} discards {$discarded}.", $seat, "discard {$discarded}");
        foreach ($ids as $id) {
            $object = $this->objects[$id];
            if (isset($object->printed()->altCosts['madness'])) {
                // Madness: exiled instead, castable while its trigger waits.
                $this->moveTo($object, GameObject::EXILE);
                $object->exiledBy = 'madness';
                $object->exiledOn = $this->turn;
                $this->trigger($object, 'madness', $object->owner);
                $this->log("{$object->name()} is exiled (madness).");
            } else {
                $this->moveTo($object, GameObject::GRAVEYARD);
            }
        }
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
     * `rb` (free from exile, with rebound), `morph` (face down), `cycle` or
     * `unearth`; combined with commas, e.g. `fb,m1`.
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
            if (isset($card->altCosts['plot']) && $this->whyNotPlot($seat, $id) === null) {
                $plays[] = ['id' => $id, 'how' => 'plot'];
            }
            if (isset($card->altCosts['foretell']) && $this->whyNotForetell($seat, $id) === null) {
                $plays[] = ['id' => $id, 'how' => 'foretell'];
            }
            if (isset($card->altCosts['suspend']) && $this->whyNotSuspend($seat, $id) === null) {
                $plays[] = ['id' => $id, 'how' => 'suspend'];
            }
            if (isset($card->altCosts['ninjutsu']) && $this->whyNotNinjutsu($seat, $id) === null) {
                $plays[] = ['id' => $id, 'how' => 'ninjutsu'];
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
            $disturb = isset($card->altCosts['disturb']) && $card->back !== null && ! $card->back->isAura();
            foreach (['es' => isset($card->altCosts['escape']), 'js' => in_array('jump-start', $card->keywords, true), 'rt' => in_array('retrace', $card->keywords, true), 'db' => $disturb] as $code => $has) {
                foreach ($has ? self::castWays($card, false) : [] as $how) {
                    if (! preg_match('/kick|dash|evoke|warp|morph|bestow|bargain/', $how) && $this->canCast($seat, $id, $how = implode(',', array_filter([$code, $how])))) {
                        $plays[] = ['id' => $id, 'how' => $how];
                    }
                }
            }
            if ($card->unearth !== null && $this->whyNotUnearth($seat, $id) === null) {
                $plays[] = ['id' => $id, 'how' => 'unearth'];
            }
            if (isset($card->altCosts['regrow']) && $this->whyNotRegrow($seat, $id) === null) {
                $plays[] = ['id' => $id, 'how' => 'regrow'];
            }
            foreach (['eternalize', 'embalm'] as $way) {
                if (isset($card->altCosts[$way]) && $this->whyNotEmbalm($seat, $id, $way) === null) {
                    $plays[] = ['id' => $id, 'how' => $way];
                }
            }
        }
        foreach ($this->exile as $id) {
            $exiled = $this->objects[$id];
            if ($exiled->printed()->isLand()) {
                if ($this->impulsePlayable($seat, $id) && $this->canPlayLand($seat, $id)) {
                    $plays[] = ['id' => $id, 'how' => ''];
                }
            } elseif (in_array($exiled->exiledBy, ['plot', 'warp', 'madness', 'suspended', 'impulse', 'foretell', 'cascade'], true) && $exiled->owner === $seat) {
                foreach (self::castWays($exiled->printed(), false) as $how) {
                    if (preg_match('/kick|dash|evoke|warp|morph|bestow/', $how)) {
                        continue;
                    }
                    $how = implode(',', array_filter([['plot' => 'pl', 'warp' => 'wx', 'madness' => 'md', 'suspended' => 'sp', 'impulse' => 'ix', 'foretell' => 'ft', 'cascade' => 'cc'][$exiled->exiledBy] ?? null, $how]));
                    if ($this->canCast($seat, $id, $how)) {
                        $plays[] = ['id' => $id, 'how' => $how];
                    }
                }
            }
            if ($this->objects[$id]->rebound && $this->objects[$id]->owner === $seat) {
                foreach (self::castWays($this->objects[$id]->printed(), false) as $how) {
                    $how = implode(',', array_filter(['rb', $how]));
                    if (! str_contains($how, 'kick') && $this->canCast($seat, $id, $how)) {
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
            if (in_array('bargain', $card->keywords, true)) {
                $ways[] = implode(',', [...$base, 'bargain']);
            }
            if (self::casualty($card) > 0) {
                $ways[] = implode(',', [...$base, 'casualty']);
            }
        }
        if ($card->entwine !== null && $card->modes !== []) {
            $ways[] = implode(',', array_filter([$flashback ? 'fb' : '', 'm'.implode('+', array_keys($card->modes)), 'entwine']));
        }
        if (! $flashback && $card->morph !== null) {
            $ways[] = 'morph';
        }
        if (! $flashback && $card->bestow !== null) {
            $ways[] = 'bestow';
        }
        foreach (['dash', 'evoke', 'warp', 'overload', 'prototype'] as $alt) {
            if (! $flashback && isset($card->altCosts[$alt])) {
                $ways[] = $alt;
            }
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
     * @return array{modes: int[], kicked: bool, flashback: bool, faceDown: bool, rebound: bool, bestowed: bool, alt: string, exiled: string, entwined: bool, grave: string, bargained: bool, casualty: bool}
     */
    public static function castOptions(string|array $how): array
    {
        $options = ['modes' => [], 'kicked' => false, 'flashback' => false, 'faceDown' => false, 'rebound' => false, 'bestowed' => false, 'alt' => '', 'exiled' => '', 'entwined' => false, 'grave' => '', 'bargained' => false, 'casualty' => false];
        if (is_array($how)) {
            return array_intersect_key($how, $options) + $options;
        }
        foreach (array_filter(explode(',', $how)) as $part) {
            if ($part === 'kick') {
                $options['kicked'] = true;
            } elseif ($part === 'fb') {
                $options['flashback'] = true;
            } elseif ($part === 'rb') {
                $options['rebound'] = true;
            } elseif ($part === 'bestow') {
                $options['bestowed'] = true;
            } elseif ($part === 'entwine') {
                $options['entwined'] = true;
            } elseif ($part === 'casualty') {
                // Casualty (rule 702.153): sacrifice a creature with enough power, and the spell is copied.
                $options['casualty'] = true;
            } elseif ($part === 'bargain') {
                // Bargain (rule 702.166): like kicker, paid by sacrificing an artifact, enchantment or token.
                $options['kicked'] = $options['bargained'] = true;
            } elseif (in_array($part, ['es', 'js', 'rt', 'db'], true)) {
                // From the graveyard: escape, jump-start, retrace, or disturb (transformed, rule 702.146).
                $options['grave'] = ['es' => 'escape', 'js' => 'jumpstart', 'rt' => 'retrace', 'db' => 'disturb'][$part];
            } elseif ($part === 'morph') {
                $options['faceDown'] = true;
            } elseif (in_array($part, ['dash', 'evoke', 'warp', 'overload', 'prototype'], true)) {
                $options['alt'] = $part;
            } elseif (in_array($part, ['pl', 'wx', 'md', 'sp', 'ix', 'ft', 'cc'], true)) {
                // Cast from exile after plotting it, after warp exiled it, discarded with madness, its last time counter removed, foretold, or cascaded into.
                $options['exiled'] = ['pl' => 'plot', 'wx' => 'warp', 'md' => 'madness', 'sp' => 'suspended', 'ix' => 'impulse', 'ft' => 'foretell', 'cc' => 'cascade'][$part];
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
    /**
     * Whether a card exiled by `Exile the top card … you may play it` can still be played.
     *
     * @param int $seat
     * @param int $id
     *
     * @return bool
     */
    private function impulsePlayable(int $seat, int $id): bool
    {
        $object = $this->objects[$id] ?? null;

        return $object !== null && $object->zone === GameObject::EXILE && $object->exiledBy === 'impulse' && $object->owner === $seat && $this->turn <= $object->exiledOn;
    }

    public function canPlayLand(int $seat, int $id): bool
    {
        return $this->whyNotPlayLand($seat, $id) === null;
    }

    private function whyNotPlayLand(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || (! in_array($id, $this->players[$seat]->hand, true) && ! $this->impulsePlayable($seat, $id))) {
            return 'That card is not in your hand.';
        }
        if (! $object->definition()->isLand()) {
            return 'That is not a land.';
        }
        if (! $this->sorcerySpeed($seat)) {
            return 'You can play a land only in your own main phase, when the stack is empty.';
        }
        if ($this->players[$seat]->landsPlayed >= 1 + count(array_filter($this->permanents($seat), fn (GameObject $o) => $this->hasKeyword($o, 'additional land')))) {
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
        if ($options['flashback'] || $options['grave'] !== '') {
            if ($object === null || ! in_array($id, $player->graveyard, true)) {
                return 'That card is not in your graveyard.';
            }
            if (($reason = $this->whyNotFromGraveyard($seat, $id, $options['grave'])) !== null) {
                return $reason;
            }
        } elseif ($options['rebound']) {
            if ($object === null || ! $object->rebound || $object->zone !== GameObject::EXILE || $object->owner !== $seat) {
                return 'That card was not exiled with rebound.';
            }
            if ($this->step !== Step::Upkeep || $this->active !== $seat) {
                return 'A card exiled with rebound is cast in your upkeep.';
            }
        } elseif ($options['exiled'] !== '') {
            if ($object === null || $object->exiledBy !== $options['exiled'] || $object->zone !== GameObject::EXILE || $object->owner !== $seat) {
                return "That card was not exiled with {$options['exiled']}.";
            }
            if ($options['exiled'] === 'suspended' && ($this->step !== Step::Upkeep || $this->active !== $seat || $object->exiledOn !== $this->turn)) {
                return 'A suspended card is cast in the upkeep its last time counter is removed.';
            }
            if ($options['exiled'] === 'impulse' && $this->turn > $object->exiledOn) {
                return 'The time to play it has passed.';
            }
            if (! in_array($options['exiled'], ['madness', 'suspended', 'impulse', 'cascade'], true) && $object->exiledOn >= $this->turn) {
                return 'It can be cast from exile on a later turn.';
            }
        } elseif ($object === null || ! (in_array($id, $player->hand, true) || in_array($id, $this->commandCards($seat), true))) {
            return 'That card is not in your hand.';
        }
        $card = $object->printed();
        if ($options['alt'] !== '' && (! isset($card->altCosts[$options['alt']]) || $options['flashback'] || $options['rebound'] || $options['exiled'] !== '')) {
            return "{$card->name} cannot be cast for a {$options['alt']} cost that way.";
        }
        if ($card->isLand()) {
            return 'Lands are played, not cast.';
        }
        if ($options['flashback'] && $card->flashback === null) {
            return "{$card->name} has no flashback.";
        }
        if ($options['kicked'] && ! $options['bargained'] && $card->kicker === null) {
            return "{$card->name} has no kicker.";
        }
        if ($options['bargained'] && (! in_array('bargain', $card->keywords, true) || $this->bargainFodder($seat) === null)) {
            return "{$card->name} cannot be bargained now.";
        }
        if ($options['casualty'] && (self::casualty($card) === 0 || $this->casualtyFodder($seat, self::casualty($card)) === null)) {
            return "{$card->name} needs a creature with power ".self::casualty($card).' or greater to sacrifice.';
        }
        if ($options['faceDown'] && $card->morph === null) {
            return "{$card->name} cannot be cast face down.";
        }
        if ($options['bestowed'] && $card->bestow === null) {
            return "{$card->name} has no bestow.";
        }
        if ($options['entwined'] && ($card->entwine === null || array_values(array_unique(array_map('intval', $options['modes']))) !== array_keys($card->modes))) {
            return "{$card->name} has no entwine.";
        }
        if (! $options['faceDown'] && ! $options['entwined'] && ! in_array(array_values(array_unique(array_map('intval', $options['modes']))), $card->modeChoices(), true)) {
            return $card->choose === null ? "{$card->name} has no modes." : "Choose {$card->choose['min']}".($card->choose['max'] > $card->choose['min'] ? " to {$card->choose['max']}" : '')." of {$card->name}'s modes.";
        }
        // A card with no mana cost at all cannot be cast for mana (rule 202.1b).
        if (! $options['faceDown'] && ! $options['flashback'] && ! $options['rebound'] && $options['alt'] === '' && ! in_array($options['exiled'], ['plot', 'madness', 'suspended', 'cascade'], true) && $card->cost->isEmpty() && array_key_exists('manaCost', $card->card)) {
            return "{$card->name} has no mana cost, so it cannot be cast.";
        }
        if ($this->priority !== $seat) {
            return 'You do not have priority.';
        }
        if (($split = $this->splitSecond()) !== null) {
            return "{$split} has split second: nothing can be cast while it is on the stack.";
        }
        if (! $options['faceDown'] && $card->additionalCost === 'sacrifice_creature' && array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o)) === []) {
            return "{$card->name} needs a creature to sacrifice.";
        }
        if (! $options['faceDown'] && $card->additionalCost === 'sacrifice_artifact_or_creature'
            && array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o) || $o->definition()->is('Artifact')) === []) {
            return "{$card->name} needs an artifact or creature to sacrifice.";
        }
        if (! $options['faceDown'] && $card->additionalCost === 'discard' && count($player->hand) < 2) {
            return "{$card->name} needs another card in your hand to discard.";
        }
        // Face down it is a 2/2 creature spell with no abilities, flash included.
        // A plotted card is cast as a sorcery (rule 702.170d).
        // Madness casts it whenever its trigger waits (rule 702.35c).
        // Cascade casts it while its trigger waits (rule 702.85a).
        if (! $options['rebound'] && ! in_array($options['exiled'], ['madness', 'suspended', 'cascade'], true) && ($options['faceDown'] || ! $card->hasInstantSpeed() || $options['exiled'] === 'plot') && ! $this->sorcerySpeed($seat)) {
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
        return self::castTargetKinds($card, $options);
    }

    /**
     * The targets a spell needs, cast a given way: none face down, the
     * creature it enchants when bestowed.
     *
     * @param CardDefinition $card
     * @param string|array   $how
     *
     * @return string[]
     */
    public static function castTargetKinds(CardDefinition $card, string|array $how): array
    {
        $options = self::castOptions($how);

        return match (true) {
            $options['faceDown'], $options['alt'] === 'overload' => [],
            $options['bestowed'] => [$card->bestow['enchant']],
            default => $card->targetKinds($options['modes'], $options['kicked']),
        };
    }

    /**
     * The mana cost of casting a card a given way: its own, its flashback
     * cost or {3} face down, plus its kicker.
     *
     * @param CardDefinition $card
     * @param string|array   $how  See {@see plays()}.
     *
     * @return string
     */
    public static function castCost(CardDefinition $card, string|array $how): string
    {
        $options = self::castOptions($how);
        // Additional costs: kicker, entwine and each Spree mode's.
        $more = ($options['kicked'] && ! $options['bargained'] ? (string) $card->kicker : '').($options['entwined'] ? (string) $card->entwine : '')
            .implode('', array_map(fn ($mode) => $card->modes[(int) $mode]['cost'] ?? '', $options['modes']));
        if ($options['rebound'] || in_array($options['exiled'], ['plot', 'suspended', 'cascade'], true)) {
            // Without paying its mana cost (rule 118.9), but still its additional costs: X is 0.
            return $more;
        }
        if ($options['alt'] !== '') {
            return $card->altCosts[$options['alt']].$more;
        }
        if (in_array($options['exiled'], ['madness', 'foretell'], true)) {
            return $card->altCosts[$options['exiled']].$more;
        }
        if (in_array($options['grave'], ['escape', 'disturb'], true)) {
            return $card->altCosts[$options['grave']].$more;
        }
        if ($options['bestowed']) {
            return $card->bestow['cost'];
        }
        $cost = $options['faceDown'] ? '{3}' : ($options['flashback'] ? (string) $card->flashback : (string) $card->cost);

        return $cost.$more;
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

        $card = $this->objects[$id]->printed();
        // Affinity for artifacts (rule 702.41): {1} less for each artifact you control.
        if (! $options['faceDown'] && in_array('affinity for artifacts', $card->keywords, true)) {
            $tax -= count(array_filter($this->permanents($seat), fn (GameObject $o) => $o->definition()->is('Artifact')));
        }
        // `This spell costs {1} less to cast for each creature in your party.` (or artifact you control …)
        foreach ($options['faceDown'] ? [] : $card->keywords as $keyword) {
            if (preg_match('/^costs (\d+) less for each (\w+)$/', $keyword, $m)) {
                $tax -= (int) $m[1] * match ($m[2]) {
                    'party' => $this->partySize($seat),
                    'creature' => $this->countYours($seat, 'creature'),
                    'artifact' => $this->countYours($seat, 'artifact'),
                    'land' => $this->countYours($seat, 'land'),
                    'card' => count(array_diff($this->players[$seat]->hand, [$id])),
                    'opponent' => count($this->players) - 1,
                    default => 0,
                };
            }
        }
        // `Instant and sorcery spells you cast cost {1} less to cast.`
        foreach ($this->permanents($seat) as $permanent) {
            foreach ($permanent->definition()->costReductions as $reduction) {
                if (match ($reduction['kind']) {
                    'instant_sorcery' => $card->is('Instant') || $card->is('Sorcery'),
                    'creature' => $card->isCreature(),
                    'noncreature' => ! $card->isCreature(),
                    'artifact' => $card->is('Artifact'),
                    'enchantment' => $card->is('Enchantment'),
                    default => true,
                }) {
                    $tax -= $reduction['amount'];
                }
            }
        }
        $cost = self::castCost($card, $options).$wardMana;
        $convoke = ! $options['faceDown'] && in_array('convoke', $card->keywords, true);
        $improvise = ! $options['faceDown'] && in_array('improvise', $card->keywords, true);
        // Delve (rule 702.66): cards exiled from your graveyard pay for generic mana, as many as can help.
        if (! $options['faceDown'] && in_array('delve', $card->keywords, true)) {
            $generic = max(0, (ManaCost::parse($cost)->payments($x)[0]['mana']['generic'] ?? 0) + $tax);
            $cards = count(array_diff($this->players[$seat]->graveyard, [$id]));
            for ($delve = min($generic, $cards); $delve > 0; $delve--) {
                $payment = $this->payFor($seat, $cost, $wardLife, [], $x, $tax - $delve, $convoke, $improvise);
                if ($payment !== null) {
                    return $payment + ['delve' => $delve];
                }
            }
        }

        return $this->payFor($seat, $cost, $wardLife, [], $x, $tax, $convoke, $improvise);
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
     * @param int    $tax     Generic mana added, for commander tax, or taken off for affinity.
     * @param bool   $convoke   Untapped creatures can be tapped for {1} or one mana of their color (rule 702.51).
     * @param bool   $improvise Untapped artifacts can be tapped for {1} (rule 702.126).
     *
     * @return array{life: int, pool: array<string, int>, tap: int[], float: array<string, int>, made: array<int, string>}|null
     */
    private function payFor(int $seat, string $mana, int $life = 0, array $without = [], int $x = 0, int $tax = 0, bool $convoke = false, bool $improvise = false): ?array
    {
        $player = $this->players[$seat];
        $sources = array_diff_key($this->manaSources($seat), array_flip($without));
        if ($convoke) {
            foreach ($this->permanents($seat) as $object) {
                if ($this->isCreature($object) && ! $object->tapped && ! isset($sources[$object->id])) {
                    $sources[$object->id] = ['count' => 1, 'colors' => [...$object->definition()->colors, 'C'], 'convoke' => true];
                }
            }
        }
        if ($improvise) {
            foreach ($this->permanents($seat) as $object) {
                if ($object->definition()->is('Artifact') && ! $object->tapped && ! isset($sources[$object->id])) {
                    $sources[$object->id] = ['count' => 1, 'colors' => ['C'], 'convoke' => true];
                }
            }
        }
        foreach (ManaCost::parse($mana)->payments($x) as $payment) {
            if ($tax !== 0) {
                $payment['mana']['generic'] = max(0, ($payment['mana']['generic'] ?? 0) + $tax);
            }
            $total = $payment['life'] + $life;
            if ($total > 0 && $total > $player->life) {
                continue;
            }
            $plan = ManaPayer::plan($payment, $player->manaPool, $sources);
            // `{1}, {T}: Add one mana of any color.`: each one used that way costs {1} more.
            // A Signet's `{1}, {T}: Add {W}{U}.` works the same way, its {1} paid by other mana.
            $filters = array_keys(array_filter($sources, fn (array $source) => $source['filter'] ?? false));
            $others = array_sum(array_map(fn (array $source) => ($source['filter'] ?? false) ? 0 : $source['count'], $sources)) + $player->manaPool->total();
            for ($k = 1; $plan === null && $k <= count($filters) && $k <= $others; $k++) {
                $filtered = $sources;
                foreach (array_slice($filters, 0, $k) as $id) {
                    $filtered[$id] = $sources[$id]['filterAs'] ?? ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];
                }
                $plan = ManaPayer::plan(['mana' => ['generic' => ($payment['mana']['generic'] ?? 0) + $k] + $payment['mana']] + $payment, $player->manaPool, $filtered);
            }
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
        // A Treasure is sacrificed for its mana.
        foreach ($payment['tap'] as $source) {
            if ($this->objects[$source]->definition()->manaAbility['sacrifice'] ?? false) {
                $this->moveTo($this->objects[$source], GameObject::GRAVEYARD);
            }
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
        $life = $discard = 0;
        foreach ($targets as $target) {
            $object = $this->targetObject($target);
            if ($object === null || $object->zone !== GameObject::BATTLEFIELD || $object->controller === $seat || ($ward = $object->definition()->ward) === null) {
                continue;
            }
            $mana .= $ward['mana'] ?? '';
            $life += $ward['life'] ?? 0;
            $discard += $ward['discard'] ?? 0;
        }

        return [$mana, $life, $discard];
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
            if (($ability['chosen'] ?? false) && $object->chosen !== null) {
                $ability['colors'] = [$object->chosen];
            }
            $sources[$object->id] = $ability;
        }
        // Lands and creatures before Treasures, which are used up.
        uasort($sources, fn (array $a, array $b) => ($a['sacrifice'] ?? false) <=> ($b['sacrifice'] ?? false));

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
            if (! $this->isLegalTarget($kind, $targets[$slot], $seat) || $this->protectedFrom($this->targetObject($targets[$slot]), $object)) {
                throw new GameException('That is not a legal target for '.$card->name.'.');
            }
            $targets[$slot] = $this->pinTarget($targets[$slot]);
        }

        [$wardMana, $wardLife, $wardDiscard] = $this->wardCost($targets, $seat);
        if ($wardDiscard > count($this->players[$seat]->hand) - (in_array($id, $this->players[$seat]->hand, true) ? 1 : 0)) {
            throw new GameException('You need a card in your hand to discard for ward.');
        }
        $payment = $this->paymentFor($seat, $id, $x, $options, $wardMana, $wardLife);
        if ($payment === null) {
            $cost = ManaCost::parse(self::castCost($card, $options));
            throw new GameException("You cannot pay {$cost}".($xCount > 0 ? " with X = {$x}" : '').($wardMana !== '' || $wardLife > 0 ? ' and ward' : '').'.');
        }
        $this->pay($seat, $payment);
        // Sunburst (rule 702.44): how many colors of mana were spent.
        $colorsSpent = count(array_intersect(['W', 'U', 'B', 'R', 'G'], [...array_values($payment['made'] ?? []), ...array_keys(array_filter($payment['pool'] ?? []))]));
        if (($payment['delve'] ?? 0) > 0) {
            $delved = array_slice(array_values(array_diff($this->players[$seat]->graveyard, [$id])), 0, $payment['delve']);
            foreach ($delved as $delvedId) {
                $this->moveTo($this->objects[$delvedId], GameObject::EXILE);
            }
            $this->log("{$this->players[$seat]->name} exiles {$payment['delve']} card".($payment['delve'] === 1 ? '' : 's').' from their graveyard (delve).');
        }

        $this->payGraveyardCost($seat, $id, $options);
        if ($options['bargained'] && ($fodder = $this->bargainFodder($seat)) !== null) {
            $this->log("{$this->players[$seat]->name} sacrifices {$fodder->name()} to bargain.");
            $this->moveTo($fodder, GameObject::GRAVEYARD);
        }
        $casualty = $options['casualty'] ? $this->casualtyFodder($seat, self::casualty($card)) : null;
        if ($casualty !== null) {
            $this->log("{$this->players[$seat]->name} sacrifices {$casualty->name()} (casualty).");
            $this->moveTo($casualty, GameObject::GRAVEYARD);
        }
        // Ward—Discard a card: the cheapest other cards in hand.
        $this->discardCheapest($seat, $wardDiscard, $id);

        $player = $this->players[$seat];
        $fromCommand = $object->zone === GameObject::COMMAND;
        $fromHand = $object->zone === GameObject::HAND;
        if ($fromCommand) {
            $tax = $this->commanderTax($id);
            $this->commanders[$id]++;
        }
        $this->removeFromZone($object);
        $object->moveTo(GameObject::STACK);
        $object->controller = $seat;
        $object->faceDown = $options['faceDown'];
        $object->transformed = $options['grave'] === 'disturb';
        if (! $options['faceDown'] && $card->additionalCost !== null) {
            $this->payAdditionalCost($seat, $card);
        }
        $this->stack[] = [
            'id' => $this->nextStackId++,
            'object' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'x' => $x,
            'targets' => $targets,
        ] + array_filter(['modes' => $options['modes'], 'kicked' => $options['kicked'], 'flashback' => $options['flashback'] || in_array($options['grave'], ['escape', 'jumpstart'], true), 'escaped' => $options['grave'] === 'escape', 'disturbed' => $options['grave'] === 'disturb', 'faceDown' => $options['faceDown'], 'fromHand' => $fromHand, 'bestowed' => $options['bestowed'], 'alt' => $options['exiled'] === 'suspended' ? 'suspend' : $options['alt'],
            'sunburst' => in_array('sunburst', $card->keywords, true) && ! $options['faceDown'] ? $colorsSpent : 0]);
        $this->passes = 0;

        $named = array_map(fn (string $target) => $this->describeTarget($target), $targets);
        $ways = array_filter([
            $fromCommand ? 'from the command zone'.($tax > 0 ? " (tax {{$tax}})" : '') : '',
            $options['flashback'] ? 'with flashback' : '',
            $options['grave'] !== '' ? "from the graveyard ({$options['grave']})" : '',
            $options['bargained'] ? 'bargained' : '',
            $options['rebound'] ? 'from exile with rebound' : '',
            $options['bestowed'] ? 'bestowed' : '',
            $options['alt'] !== '' ? "for its {$options['alt']} cost" : '',
            $options['exiled'] !== '' ? "from exile ({$options['exiled']})" : '',
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
        if ($casualty !== null) {
            $this->copySpell(end($this->stack), $seat, 'casualty');
        }
        $this->castTriggers($seat, $object);
        $this->targetedTriggers($targets);
        $this->settle();
    }

    /**
     * `When this creature becomes the target of a spell or ability, …`
     *
     * @param string[] $targets
     *
     * @return void
     */
    private function targetedTriggers(array $targets): void
    {
        foreach (array_unique($targets) as $target) {
            $object = str_starts_with($target, 'o:') ? $this->targetObject($target) : null;
            if ($object !== null && $object->zone === GameObject::BATTLEFIELD) {
                $this->trigger($object, 'targeted', $object->controller);
            }
        }
    }

    /**
     * Why a card can't be cast from a graveyard with escape, jump-start or retrace.
     *
     * @param int    $seat
     * @param int    $id
     * @param string $grave
     *
     * @return string|null
     */
    private function whyNotFromGraveyard(int $seat, int $id, string $grave): ?string
    {
        $card = $this->objects[$id]->printed();
        $hand = $this->players[$seat]->hand;

        return match ($grave) {
            'escape' => ! isset($card->altCosts['escape']) ? "{$card->name} has no escape."
                : (count($this->players[$seat]->graveyard) - 1 < self::escapeExile($card) ? 'Not enough other cards in your graveyard to escape.' : null),
            'jumpstart' => ! in_array('jump-start', $card->keywords, true) ? "{$card->name} has no jump-start." : ($hand === [] ? 'Jump-start needs a card to discard.' : null),
            'disturb' => ! isset($card->altCosts['disturb']) || $card->back === null || $card->back->isAura() ? "{$card->name} can't be cast with disturb." : null,
            'retrace' => ! in_array('retrace', $card->keywords, true) ? "{$card->name} has no retrace."
                : (array_filter($hand, fn (int $card) => $this->objects[$card]->printed()->isLand()) === [] ? 'Retrace needs a land card to discard.' : null),
            default => null,
        };
    }

    private static function escapeExile(CardDefinition $card): int
    {
        foreach ($card->keywords as $keyword) {
            if (preg_match('/^escape (\d+)$/', $keyword, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Escape exiles the oldest other cards in the graveyard; jump-start discards
     * the cheapest card, retrace a land card.
     *
     * @param int   $seat
     * @param int   $id
     * @param array $options
     *
     * @return void
     */
    private function payGraveyardCost(int $seat, int $id, array $options): void
    {
        $player = $this->players[$seat];
        if ($options['grave'] === 'escape') {
            $exiled = array_slice(array_values(array_diff($player->graveyard, [$id])), 0, self::escapeExile($this->objects[$id]->printed()));
            foreach ($exiled as $other) {
                $this->moveTo($this->objects[$other], GameObject::EXILE);
            }
            $this->log("{$player->name} exiles ".count($exiled).' other cards from their graveyard (escape).');
        } elseif ($options['grave'] === 'jumpstart') {
            $hand = $player->hand;
            usort($hand, fn (int $a, int $b) => $this->objects[$a]->definition()->cost->manaValue() <=> $this->objects[$b]->definition()->cost->manaValue());
            $this->discardCards($seat, [$hand[0]]);
        } elseif ($options['grave'] === 'retrace') {
            $lands = array_values(array_filter($player->hand, fn (int $card) => $this->objects[$card]->printed()->isLand()));
            $this->discardCards($seat, [$lands[0]]);
        }
    }

    /**
     * What bargain sacrifices: a token first, else the weakest artifact or enchantment.
     *
     * @param int $seat
     *
     * @return GameObject|null
     */
    private function casualtyFodder(int $seat, int $power): ?GameObject
    {
        return $this->weakest($seat, fn (GameObject $o) => $this->isCreature($o) && $this->power($o) >= $power);
    }

    /** The N of `casualty N`, or 0. */
    private static function casualty(CardDefinition $card): int
    {
        foreach ($card->keywords as $keyword) {
            if (preg_match('/^casualty (\d+)$/', $keyword, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Discards a player's cheapest cards, such as for `Ward—Discard a card`.
     *
     * @param int $seat
     * @param int $count
     * @param int $except A card that is not discarded: the spell being cast.
     *
     * @return void
     */
    private function discardCheapest(int $seat, int $count, int $except = 0): void
    {
        if ($count <= 0) {
            return;
        }
        $hand = array_values(array_diff($this->players[$seat]->hand, [$except]));
        usort($hand, fn (int $a, int $b) => $this->objects[$a]->definition()->cost->manaValue() <=> $this->objects[$b]->definition()->cost->manaValue());
        $this->discardCards($seat, array_slice($hand, 0, $count));
    }

    /**
     * Puts a copy of a spell on the stack, with the same modes and targets
     * (storm, casualty, `Copy target instant or sorcery spell`).
     *
     * @param array|false $item       The spell's stack item.
     * @param int         $controller
     * @param string      $why
     *
     * @return void
     */
    private function copySpell(array|false $item, int $controller, string $why): void
    {
        if ($item === false || self::isAbility($item)) {
            return;
        }
        $spell = $this->objects[$item['object']];
        $card = $spell->definition();
        $effects = $card->isPermanentCard() ? [] : $card->spellEffects($item['modes'] ?? [], $item['kicked'] ?? false);
        if ($effects === []) {
            return;
        }
        $this->pushAbility([
            'source' => $spell->id,
            'incarnation' => $spell->incarnation,
            'controller' => $controller,
            'effects' => $effects,
            'kinds' => self::targetKindsOf($effects),
            'label' => "A copy of {$card->name}",
        ], $item['targets'], "is put on the stack ({$why})");
    }

    private function bargainFodder(int $seat): ?GameObject
    {
        return $this->weakest($seat, fn (GameObject $o) => $o->definition()->isToken() || $o->definition()->is('Artifact') || $o->definition()->is('Enchantment'));
    }

    /**
     * Pays a spell's additional cost: the game sacrifices the weakest
     * creature (tokens first), or discards the card with the lowest mana
     * value.
     *
     * @param int            $seat
     * @param CardDefinition $card
     *
     * @return void
     */
    private function payAdditionalCost(int $seat, CardDefinition $card): void
    {
        if ($card->additionalCost === 'sacrifice_creature' || $card->additionalCost === 'sacrifice_artifact_or_creature') {
            $artifacts = $card->additionalCost === 'sacrifice_artifact_or_creature';
            $weakest = $this->weakest($seat, fn (GameObject $o) => $this->isCreature($o) || ($artifacts && $o->definition()->is('Artifact')));
            $this->log("{$this->players[$seat]->name} sacrifices {$weakest->name()} to cast {$card->name}.");
            $this->moveTo($weakest, GameObject::GRAVEYARD);
        } else {
            $hand = $this->players[$seat]->hand;
            usort($hand, fn (int $a, int $b) => $this->objects[$a]->definition()->cost->manaValue() <=> $this->objects[$b]->definition()->cost->manaValue());
            $this->discardCards($seat, [$hand[0]]);
        }
    }

    /**
     * The name of a spell with split second on the stack (rule 702.61), if any.
     *
     * @return string|null
     */
    private function splitSecond(): ?string
    {
        foreach ($this->stack as $item) {
            if (! self::isAbility($item) && in_array('split second', $this->objects[$item['object']]->definition()->keywords, true)) {
                return $this->objects[$item['object']]->name();
            }
        }

        return null;
    }

    /**
     * A player's weakest permanent of some kind: tokens first, then the
     * lowest power and toughness.
     *
     * @param int      $seat
     * @param callable $filter
     *
     * @return GameObject|null
     */
    private function weakest(int $seat, callable $filter): ?GameObject
    {
        $permanents = array_values(array_filter($this->permanents($seat), $filter));
        $score = fn (GameObject $o) => [! $o->definition()->isToken(), $this->isCreature($o) ? $this->power($o) + $this->toughness($o) : 0];
        usort($permanents, fn (GameObject $a, GameObject $b) => $score($a) <=> $score($b));

        return $permanents[0] ?? null;
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
        if ($this->spellsTurn !== $this->turn) {
            $this->spellsBefore = $this->spellsTurn === $this->turn - 1 ? $this->spellsCast : 0;
            [$this->spellsTurn, $this->spellsCast] = [$this->turn, 0];
        }
        // Storm (rule 702.40): a copy for each spell cast before it this turn, with the same targets.
        $before = $this->spellsCast++;
        $item = end($this->stack);
        for ($i = 0; $i < $before && in_array('storm', $card->keywords, true) && $item !== false && $item['object'] === $spell->id; $i++) {
            $this->copySpell($item, $seat, 'storm');
        }
        if (in_array('cascade', $card->keywords, true)) {
            $this->cascade($seat, $spell);
        }
        $events = ['cast_spell', ...($card->isCreature() ? [] : ['cast_noncreature']), ...($card->isPermanentCard() ? [] : ['cast_instant_sorcery']),
            ...(array_intersect(['Spirit', 'Arcane'], $card->subtypes) !== [] ? ['cast_spirit_arcane'] : [])];
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
            'effects' => [$card->cyclingFinds === null ? ['type' => 'draw', 'amount' => 1] : ['type' => 'search', 'find' => $card->cyclingFinds, 'to' => 'hand']],
            'kinds' => [],
            'label' => "{$card->name}'s ".($card->cyclingFinds === null ? 'cycling' : 'landcycling'),
        ], [], 'is activated', "cycle {$card->name}");
        $this->settle();
    }

    /**
     * Unearths a creature card (rule 702.84): pays the cost and puts the
     * ability on the stack. It returns with haste and is exiled at the end
     * step, or whenever it would leave the battlefield.
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function unearth(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotUnearth($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $card = $object->printed();
        $this->pay($seat, $this->payFor($seat, (string) $card->unearth));
        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => [['type' => 'unearth']],
            'kinds' => [],
            'label' => "{$card->name}'s unearth",
        ], [], 'is activated', "unearth {$card->name}");
        $this->settle();
    }

    /**
     * Plots a card (rule 702.170): a special action that exiles it from your
     * hand, paying its plot cost as a sorcery; it can be cast free on a later turn.
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function plot(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotPlot($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $this->pay($seat, $this->payFor($seat, $object->printed()->altCosts['plot']));
        $this->moveTo($object, GameObject::EXILE);
        $object->exiledBy = 'plot';
        $object->exiledOn = $this->turn;
        $this->log("{$this->players[$seat]->name} plots {$object->name()}.", $seat, "plot {$object->name()}");
        $this->settle();
    }

    /**
     * Cascade (rule 702.85): exiles cards from the top of the library until a
     * nonland card with lesser mana value, which may be cast free while the
     * trigger waits; the rest go on the bottom in a random order.
     *
     * @param int        $seat
     * @param GameObject $spell
     *
     * @return void
     */
    private function cascade(int $seat, GameObject $spell): void
    {
        $player = $this->players[$seat];
        $value = $spell->definition()->cost->manaValue();
        $missed = [];
        $hit = null;
        while ($hit === null && ($id = array_pop($player->library)) !== null) {
            $card = $this->objects[$id]->printed();
            if (! $card->isLand() && $card->cost->manaValue() < $value) {
                $hit = $this->objects[$id];
            } else {
                $missed[] = $id;
            }
        }
        shuffle($missed);
        foreach ($missed as $id) {
            array_unshift($player->library, $id);
        }
        if ($hit === null) {
            $this->log("{$player->name} cascades and finds nothing.");

            return;
        }
        $hit->moveTo(GameObject::EXILE);
        $this->exile[] = $hit->id;
        $hit->exiledBy = 'cascade';
        $hit->exiledOn = $this->turn;
        $this->log("{$player->name} cascades into {$hit->name()}.");
        $this->trigger($spell, 'cascade', $seat, null, $hit->id);
    }

    /**
     * Foretells a card (rule 702.143): a special action on its owner's turn that
     * pays {2} and exiles it face down; it can be cast for its foretell cost on a later turn.
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function foretell(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotForetell($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $this->pay($seat, $this->payFor($seat, '{2}'));
        $this->moveTo($object, GameObject::EXILE);
        $object->exiledBy = 'foretell';
        $object->exiledOn = $this->turn;
        $this->log("{$this->players[$seat]->name} foretells a card.", $seat, 'foretell');
        $this->settle();
    }

    private function whyNotForetell(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::HAND || $object->owner !== $seat) {
            return 'That card is not in your hand.';
        }
        if (! isset($object->printed()->altCosts['foretell'])) {
            return "{$object->name()} has no foretell.";
        }
        if ($this->active !== $seat || $this->priority !== $seat) {
            return 'Foretell only on your own turn, while you have priority.';
        }
        if ($this->payFor($seat, '{2}') === null) {
            return 'You cannot pay {2}.';
        }

        return null;
    }

    private function whyNotPlot(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::HAND || $object->owner !== $seat) {
            return 'That card is not in your hand.';
        }
        if (! isset($object->printed()->altCosts['plot'])) {
            return "{$object->name()} has no plot.";
        }
        if (! $this->sorcerySpeed($seat)) {
            return 'Plot only as a sorcery.';
        }
        if ($this->payFor($seat, $object->printed()->altCosts['plot']) === null) {
            return "You cannot pay {$object->printed()->altCosts['plot']}.";
        }

        return null;
    }

    private function whyNotUnearth(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::GRAVEYARD || $object->owner !== $seat) {
            return 'That card is not in your graveyard.';
        }
        if ($object->printed()->unearth === null) {
            return "{$object->name()} has no unearth.";
        }
        if (! $this->sorcerySpeed($seat)) {
            return 'Unearth only as a sorcery.';
        }
        if ($this->payFor($seat, (string) $object->printed()->unearth) === null) {
            return "You cannot pay {$object->printed()->unearth}.";
        }

        return null;
    }

    /**
     * Ninjutsu (rule 702.49): pays the cost and returns an unblocked attacker
     * to its owner's hand, the weakest unless one is named; the ability puts
     * this card onto the battlefield tapped and attacking.
     *
     * @param int      $seat
     * @param int      $id
     * @param int|null $returned The unblocked attacker to return.
     *
     * @return void
     */
    public function ninjutsu(int $seat, int $id, ?int $returned = null): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotNinjutsu($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $unblocked = $this->unblockedAttackers($seat);
        if ($returned !== null && ! in_array($returned, array_map(fn (GameObject $o) => $o->id, $unblocked), true)) {
            throw new GameException('Return an unblocked attacker you control.');
        }
        usort($unblocked, fn (GameObject $a, GameObject $b) => [! $a->definition()->isToken(), $this->power($a) + $this->toughness($a), $a->id] <=> [! $b->definition()->isToken(), $this->power($b) + $this->toughness($b), $b->id]);
        $back = $returned === null ? $unblocked[0] : $this->objects[$returned];
        $object = $this->objects[$id];
        $card = $object->printed();
        $this->pay($seat, $this->payFor($seat, $card->altCosts['ninjutsu']));
        $this->moveTo($back, GameObject::HAND);
        $this->log("{$this->players[$seat]->name} returns {$back->name()} to hand for ninjutsu.");
        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => [['type' => 'ninjutsu']],
            'kinds' => [],
            'label' => "{$card->name}'s ninjutsu",
        ], [], 'is activated', "ninjutsu {$card->name}");
        $this->settle();
    }

    /**
     * @param int $seat
     *
     * @return GameObject[] A player's attackers that no creature blocked.
     */
    private function unblockedAttackers(int $seat): array
    {
        return array_values(array_filter(
            array_map(fn (int $id) => $this->objects[$id], array_keys($this->attackers)),
            fn (GameObject $o) => $o->controller === $seat && $o->zone === GameObject::BATTLEFIELD && ! isset($this->blocked[$o->id]) && ! in_array($o->id, $this->blockers, true)
        ));
    }

    private function whyNotNinjutsu(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::HAND || $object->owner !== $seat) {
            return 'That card is not in your hand.';
        }
        if (! isset($object->printed()->altCosts['ninjutsu'])) {
            return "{$object->name()} has no ninjutsu.";
        }
        if ($this->priority !== $seat || $this->step !== Step::DeclareBlockers) {
            return 'Ninjutsu once blockers are declared.';
        }
        if ($this->splitSecond() !== null) {
            return 'Nothing can be activated while a spell with split second is on the stack.';
        }
        if ($this->unblockedAttackers($seat) === []) {
            return 'You have no unblocked attacker to return.';
        }
        if ($this->payFor($seat, $object->printed()->altCosts['ninjutsu']) === null) {
            return "You cannot pay {$object->printed()->altCosts['ninjutsu']}.";
        }

        return null;
    }

    /**
     * Suspends a card (rule 702.62): a special action that exiles it from
     * your hand with N time counters, any time you could cast it.
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function suspend(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotSuspend($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $card = $object->printed();
        $this->pay($seat, $this->payFor($seat, $card->altCosts['suspend']));
        $this->moveTo($object, GameObject::EXILE);
        $object->exiledBy = 'suspend';
        $object->exiledOn = $this->turn;
        $object->addCounters('time', self::suspendTime($card));
        $this->log("{$this->players[$seat]->name} suspends {$object->name()} with {$object->counter('time')} time counters.", $seat, "suspend {$object->name()}");
        $this->settle();
    }

    private static function suspendTime(CardDefinition $card): int
    {
        foreach ($card->keywords as $keyword) {
            if (preg_match('/^suspend (\d+)$/', $keyword, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    private function whyNotSuspend(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::HAND || $object->owner !== $seat) {
            return 'That card is not in your hand.';
        }
        $card = $object->printed();
        if (! isset($card->altCosts['suspend'])) {
            return "{$object->name()} has no suspend.";
        }
        if ($this->priority !== $seat || $this->splitSecond() !== null) {
            return 'You cannot suspend a card now.';
        }
        if (! $card->hasInstantSpeed() && ! $this->sorcerySpeed($seat)) {
            return 'Suspend it when you could cast it.';
        }
        if ($this->payFor($seat, $card->altCosts['suspend']) === null) {
            return "You cannot pay {$card->altCosts['suspend']}.";
        }

        return null;
    }

    /**
     * `{2}{B}: Return this card from your graveyard to your hand.`
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function regrow(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotRegrow($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $card = $object->printed();
        $this->pay($seat, $this->payFor($seat, $card->altCosts['regrow']));
        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => [['type' => 'regrow']],
            'kinds' => [],
            'label' => "{$card->name}'s ability",
        ], [], 'is activated', "return {$card->name}");
        $this->settle();
    }

    /**
     * Embalm (rule 702.128) or eternalize (rule 702.129): exiles the card from
     * your graveyard, as a sorcery, for a token copy that's a Zombie (4/4 and
     * black when eternalized, white when embalmed) with no mana cost.
     *
     * @param int    $seat
     * @param int    $id
     * @param string $way  `embalm` or `eternalize`.
     *
     * @return void
     */
    public function embalm(int $seat, int $id, string $way): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotEmbalm($seat, $id, $way)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $card = $object->printed();
        $this->pay($seat, $this->payFor($seat, $card->altCosts[$way]));
        $this->moveTo($object, GameObject::EXILE);
        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => [['type' => 'embalm', 'way' => $way]],
            'kinds' => [],
            'label' => "{$card->name}'s {$way}",
        ], [], 'is activated', "{$way} {$card->name}");
        $this->settle();
    }

    private function whyNotEmbalm(int $seat, int $id, string $way): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::GRAVEYARD || $object->owner !== $seat) {
            return 'That card is not in your graveyard.';
        }
        if (! isset($object->printed()->altCosts[$way])) {
            return "{$object->name()} has no {$way}.";
        }
        if (! $this->sorcerySpeed($seat)) {
            return ucfirst($way).' only as a sorcery.';
        }
        if ($this->payFor($seat, $object->printed()->altCosts[$way]) === null) {
            return "You cannot pay {$object->printed()->altCosts[$way]}.";
        }

        return null;
    }

    private function whyNotRegrow(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::GRAVEYARD || $object->owner !== $seat) {
            return 'That card is not in your graveyard.';
        }
        if (! isset($object->printed()->altCosts['regrow'])) {
            return "{$object->name()} cannot return from your graveyard.";
        }
        if ($this->priority !== $seat) {
            return 'You do not have priority.';
        }
        if ($this->splitSecond() !== null) {
            return 'Nothing can be activated while a spell with split second is on the stack.';
        }
        if ($this->payFor($seat, $object->printed()->altCosts['regrow']) === null) {
            return "You cannot pay {$object->printed()->altCosts['regrow']}.";
        }

        return null;
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
        $kinds = $ability ? $item['kinds'] : match (true) {
            (bool) ($item['bestowed'] ?? false) => [$card->bestow['enchant']],
            $card->aura !== null => [$card->aura['enchant']],
            default => self::targetKindsOf($effects),
        };
        $name = $ability ? $item['label'] : $object->name();

        // A spell or ability whose targets are all illegal does not resolve (rule 608.2b).
        $legal = [];
        $chosen = [];
        foreach ($item['targets'] as $slot => $target) {
            $legal[$slot] = $target !== '-' && $this->isLegalTarget($kinds[$slot], $target, $item['controller'], $item['id']) && ! $this->protectedFrom($this->targetObject($target), $object);
            if ($target !== '-') {
                $chosen[] = $legal[$slot];
            }
        }
        if ($chosen !== [] && ! in_array(true, $chosen, true) && ! $ability && ($item['bestowed'] ?? false)) {
            // A bestowed Aura with nothing to enchant resolves as a creature (rule 702.103e).
            $item['bestowed'] = false;
            $item['targets'] = [];
            $legal = $chosen = [];
        }
        if ($chosen !== [] && ! in_array(true, $chosen, true)) {
            $this->log("{$name} has no legal targets left and does nothing.");
            if (! $ability) {
                $this->spellLeavesStack($object, $item);
            }

            return;
        }

        if (! $ability && $card->isPermanentCard()) {
            $this->putOntoBattlefield($object, $item['controller'], true, $item['faceDown'] ?? false, $item['kicked'] ?? false, (int) ($item['x'] ?? 0), (bool) ($item['disturbed'] ?? false));
            $object->alt = ($item['alt'] ?? '') === '' ? null : $item['alt'];
            foreach (($item['escaped'] ?? false) ? $card->keywords : [] as $keyword) {
                if (preg_match('/^escapes with (\d+)$/', $keyword, $m)) {
                    $object->addCounters('+1/+1', (int) $m[1]);
                }
            }
            if (($item['sunburst'] ?? 0) > 0) {
                $object->addCounters($this->isCreature($object) ? '+1/+1' : 'charge', (int) $item['sunburst']);
            }
            // Evoke (rule 702.74): sacrificed as it enters; its own enters abilities resolve first.
            if ($object->alt === 'evoke') {
                array_unshift($this->pendingTriggers, [
                    'source' => $object->id, 'incarnation' => $object->incarnation, 'controller' => $item['controller'],
                    'effects' => [['type' => 'sacrifice', 'self' => true]], 'kinds' => [], 'label' => "{$object->name()}'s evoke", 'text' => 'Evoke: sacrifice it.',
                ]);
            }
            if ($card->aura !== null && str_starts_with($item['targets'][0] ?? '', 'p:')) {
                $object->enchantedPlayer = (int) substr($item['targets'][0], 2);
            } elseif ($card->aura !== null || ($item['bestowed'] ?? false)) {
                $object->attachedTo = $this->targetObject($item['targets'][0])?->id;
                $object->bestowed = (bool) ($item['bestowed'] ?? false);
            }
            $this->log("{$object->name()} enters the battlefield".match (true) {
                $object->attachedTo !== null => ' attached to '.$this->objects[$object->attachedTo]->name(),
                $object->enchantedPlayer !== null => ' attached to '.$this->players[$object->enchantedPlayer]->name,
                default => '',
            }.'.');

            return;
        }

        // "CARDNAME" effects of an ability only apply while the source is still the same permanent.
        $self = $ability && $object->zone === GameObject::BATTLEFIELD && $object->incarnation === $item['incarnation'] ? [$object->id, $object->incarnation] : null;
        $steps = [];
        $slot = 0;
        $previous = null;
        foreach ($effects as $effect) {
            $target = null;
            // Overload (rule 702.96): "target" becomes "each".
            if (! $ability && ($item['alt'] ?? '') === 'overload' && isset($effect['target'])) {
                foreach ($this->permanents() as $each) {
                    if ($this->matchesKind($each, $effect['target'], $item['controller'])) {
                        $steps[] = [$effect, "o:{$each->id}"];
                    }
                }

                continue;
            }
            if (isset($effect['target'])) {
                $target = $item['targets'][$slot];
                // A fight needs both its creatures; it keeps the first one's target, legal or not.
                if ($effect['type'] === 'fight') {
                    $effect['with'] = $previous;
                }
                $previous = $legal[$slot] ? $target : null;
                // Effects whose target has become illegal are skipped.
                if (! $legal[$slot++]) {
                    continue;
                }
            } elseif ($effect['sameTarget'] ?? false) {
                // "Target player draws two cards and loses 2 life": the same player.
                if ($previous === null) {
                    continue;
                }
                $target = $previous;
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
            $this->spellLeavesStack($source, $item, true);
        }
    }

    /**
     * Puts a spell that has resolved or been countered into its owner's
     * graveyard, or exile when it was cast with flashback (rule 702.34a), or
     * resolved after being cast from its owner's hand with rebound (rule 702.88a).
     *
     * @param GameObject $object
     * @param array      $item
     * @param bool       $resolved
     *
     * @return void
     */
    private function spellLeavesStack(GameObject $object, array $item, bool $resolved = false): void
    {
        if ($resolved && ($item['fromHand'] ?? false) && in_array('rebound', $object->printed()->keywords, true)) {
            $this->moveTo($object, GameObject::EXILE);
            $object->rebound = true;
            $this->log("{$object->name()} is exiled with rebound.");

            return;
        }
        // `Shuffle CARDNAME into its owner's library.` as the spell's last instruction.
        if ($resolved && ! ($item['flashback'] ?? false) && in_array('shuffle_self', array_column($object->definition()->effects, 'type'), true)) {
            $this->moveTo($object, GameObject::LIBRARY);
            $this->shuffle($object->owner);
            $this->log("{$object->name()} is shuffled into {$this->players[$object->owner]->name}'s library.");

            return;
        }
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
        if ($choice === null || $choice['seat'] !== $seat || ! in_array($choice['type'], ['scry', 'surveil'], true) || $this->stage !== self::PLAYING) {
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
     * Finishes looking at the top cards of a library: the chosen cards go to
     * their owner's hand, and the rest go to the bottom in a random order
     * or into the graveyard. Then the spell or ability goes on resolving.
     *
     * @param int   $seat
     * @param int[] $ids
     *
     * @return void
     */
    public function take(int $seat, array $ids): void
    {
        $choice = $this->pendingChoice;
        if ($choice === null || $choice['type'] !== 'look' || $choice['seat'] !== $seat || $this->stage !== self::PLAYING) {
            throw new GameException('You are not looking at any cards.');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $least = $choice['may'] ? 0 : $choice['take'];
        if (count($ids) < $least || count($ids) > $choice['take']) {
            throw new GameException($least === $choice['take'] ? "Choose {$choice['take']} of the cards." : "Choose up to {$choice['take']} of the cards.");
        }
        foreach ($ids as $id) {
            if (! in_array($id, $choice['eligible'], true)) {
                throw new GameException('You can\'t take that card.');
            }
        }
        $player = $this->players[$seat];
        $to = $choice['to'] ?? 'hand';
        foreach ($ids as $id) {
            if ($to === 'hand') {
                $this->moveTo($this->objects[$id], GameObject::HAND);
            } else {
                // `You may put a land card from among them onto the battlefield tapped.`
                $this->putOntoBattlefield($this->objects[$id], $seat);
                $this->objects[$id]->tapped = $this->objects[$id]->tapped || $to === 'tapped';
            }
        }
        $rest = array_values(array_diff($choice['cards'], $ids));
        shuffle($rest);
        foreach ($choice['rest'] === 'top' ? [] : $rest as $id) {
            $object = $this->objects[$id];
            if ($choice['rest'] === 'graveyard') {
                $this->moveTo($object, GameObject::GRAVEYARD);
            } else {
                $this->removeFromZone($object);
                $object->moveTo(GameObject::LIBRARY);
                array_unshift($player->library, $id);
            }
        }
        $this->pendingChoice = null;
        $this->log(
            "{$player->name} looks at the top ".count($choice['cards']).' cards and puts '.($ids === [] ? 'none' : count($ids)).($to === 'hand' ? ' into their hand.' : ' onto the battlefield.'),
            $seat,
            'took '.count($ids).'/'.count($choice['cards']),
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
        if ($effect['toEnchanted'] ?? false) {
            $target = $self?->enchantedPlayer === null ? null : "p:{$self->enchantedPlayer}";
            if ($target === null) {
                return false;
            }
        }
        if (($effect['sameTarget'] ?? false) && $target !== null && isset($this->followed[$target])) {
            $target = $this->followed[$target];
        }
        $amount = match ($effect['amount'] ?? 0) {
            'X' => $x,
            // `Look at the top X cards of your library, where X is the number of lands you control.`
            'lands' => count(array_filter($this->permanents($controller), fn (GameObject $o) => $o->definition()->isLand())),
            default => (int) ($effect['amount'] ?? 0),
        };
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
                    if (($effect['exileIfDies'] ?? false) && ($hurt = $this->targetObject((string) $hit)) !== null) {
                        $hurt->exileIfDies = $this->turn;
                    }
                }
                break;

            case 'draw':
                $this->draw($target !== null ? (int) substr($target, 2) : $controller, $amount);
                break;

            case 'gain_life':
                $this->gainLife($controller, $amount);
                break;

            case 'sacrifice':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $this->log("{$this->players[$affected->controller]->name} sacrifices {$affected->name()}.");
                    $this->moveTo($affected, GameObject::GRAVEYARD);
                }
                break;

            case 'mobilize':
                for ($i = 0; $i < $amount; $i++) {
                    $token = $this->createToken(['name' => 'Warrior Token', 'type' => 'Token Creature — Warrior', 'types' => ['Creature'], 'subtypes' => ['Warrior'], 'colors' => ['R'], 'power' => '1', 'toughness' => '1', 'text' => '', 'manaCost' => null], $controller);
                    $token->tapped = true;
                    $token->alt = 'temporary';
                    if ($this->step === Step::DeclareAttackers || $this->step === Step::DeclareBlockers) {
                        $this->attackers[$token->id] = true;
                    }
                }
                $this->log("{$this->players[$controller]->name} creates {$amount} attacking Warrior token".($amount === 1 ? '' : 's').'.');
                break;

            case 'adapt':
                if ($affected !== null && $affected->counter('+1/+1') === 0) {
                    $affected->addCounters('+1/+1', $amount);
                }
                break;

            case 'monstrosity':
                if ($affected !== null && ! $affected->monstrous) {
                    $affected->addCounters('+1/+1', $amount);
                    $affected->monstrous = true;
                    $this->log("{$affected->name()} becomes monstrous.");
                    $this->trigger($affected, 'monstrous', $affected->controller);
                }
                break;

            case 'explore':
                // A land goes to hand; otherwise a +1/+1 counter, and the card stays on top.
                $top = end($this->players[$controller]->library);
                if ($top !== false && $this->objects[$top]->definition()->isLand()) {
                    $this->moveTo($this->objects[$top], GameObject::HAND);
                    $this->log("{$this->players[$controller]->name} explores and puts {$this->objects[$top]->name()} into their hand.");
                } elseif ($affected !== null) {
                    $affected->addCounters('+1/+1', 1);
                    $this->log("{$affected->name()} explores and gets a +1/+1 counter.");
                }
                break;

            case 'vanishing':
                if ($affected !== null && $affected->counter('time') > 0) {
                    $affected->addCounters('time', -1);
                    if ($affected->counter('time') === 0) {
                        $this->log("The last time counter leaves {$affected->name()}.");
                        $this->moveTo($affected, GameObject::GRAVEYARD);
                    }
                }
                break;

            case 'training':
                // Training: another attacking creature has greater power.
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD && array_filter(array_keys($this->attackers), fn (int $id) => $id !== $affected->id && $this->power($this->objects[$id]) > $this->power($affected)) !== []) {
                    $affected->addCounters('+1/+1', 1);
                    $this->log("Training: {$affected->name()} gets a +1/+1 counter.");
                }
                break;

            case 'exploit':
                // The game exploits a token or a creature with total power and toughness 2 or less.
                $fodder = $affected === null ? null : $this->weakest($controller, fn (GameObject $o) => $o->id !== $affected->id && $this->isCreature($o));
                if ($fodder !== null && ($fodder->definition()->isToken() || $this->power($fodder) + $this->toughness($fodder) <= 2)) {
                    $this->log("{$affected->name()} exploits {$fodder->name()}.");
                    $this->moveTo($fodder, GameObject::GRAVEYARD);
                    $this->trigger($affected, 'exploits', $controller);
                }
                break;

            case 'renown':
                if ($affected !== null && ! $affected->renowned) {
                    $affected->addCounters('+1/+1', $amount);
                    $affected->renowned = true;
                    $this->log("{$affected->name()} becomes renowned.");
                }
                break;

            case 'copy_spell':
                foreach ($this->stack as $copied) {
                    if ($copied['id'] === (int) substr((string) $target, 2)) {
                        $this->copySpell($copied, $controller, $source->name());
                        break;
                    }
                }
                break;

            case 'for_mirrodin':
                // For Mirrodin! (rule 702.163): a 2/2 red Rebel token to carry it.
                if ($self !== null && $self->zone === GameObject::BATTLEFIELD) {
                    $rebel = $this->createToken(['name' => 'Rebel Token', 'type' => 'Token Creature — Rebel', 'types' => ['Creature'], 'subtypes' => ['Rebel'], 'colors' => ['R'], 'power' => '2', 'toughness' => '2', 'text' => '', 'manaCost' => null], $controller);
                    $self->attachedTo = $rebel->id;
                    $this->log("{$self->name()} is attached to a Rebel token.");
                }
                break;

            case 'living_weapon':
                if ($self !== null && $self->zone === GameObject::BATTLEFIELD) {
                    $germ = $this->createToken(['name' => 'Phyrexian Germ Token', 'type' => 'Token Creature — Phyrexian Germ', 'types' => ['Creature'], 'subtypes' => ['Phyrexian', 'Germ'], 'colors' => ['B'], 'power' => '0', 'toughness' => '0', 'text' => '', 'manaCost' => null], $controller);
                    $self->attachedTo = $germ->id;
                    $this->log("{$self->name()} is attached to a Germ token.");
                }
                break;

            case 'extort':
                if (($payment = $this->payFor($controller, '{W/B}')) !== null) {
                    $this->pay($controller, $payment);
                    foreach ($opponents as $seat) {
                        $this->players[$seat]->life--;
                    }
                    $this->gainLife($controller, count($opponents));
                    $this->log("{$this->players[$controller]->name} extorts.");
                }
                break;

            case 'lose_life':
                $seats = match (true) {
                    isset($effect['each']) => $opponents,
                    isset($effect['you']) => [$controller],
                    // "Its controller loses 2 life": whoever controlled the target.
                    isset($effect['toController']) => [$this->objects[(int) explode(':', (string) $target)[1]]->controller],
                    default => [(int) substr((string) $target, 2)],
                };
                foreach ($seats as $seat) {
                    $this->players[$seat]->life -= $amount;
                }
                break;

            case 'destroy':
                if (isset($effect['all'])) {
                    $doomed = array_filter($this->permanents(), fn (GameObject $o) => match ($effect['all']) {
                        'all creatures' => $this->isCreature($o),
                        "all creatures you don't control", 'all creatures your opponents control' => $this->isCreature($o) && $o->controller !== $controller,
                        'all artifacts' => $o->definition()->is('Artifact'),
                        'all enchantments' => $o->definition()->is('Enchantment'),
                        'all artifacts and enchantments' => $o->definition()->is('Artifact') || $o->definition()->is('Enchantment'),
                        default => ! $o->definition()->isLand(),
                    });
                    foreach ($doomed as $object) {
                        $this->destroy($object, ! ($effect['noRegen'] ?? false));
                    }
                    break;
                }
                $this->destroy($affected, ! ($effect['noRegen'] ?? false));
                break;

            case 'exile':
                // "… until this leaves the battlefield": nothing is exiled if it already has (rule 610.3c).
                if ($effect['until'] ?? false) {
                    if ($self === null || $self->zone !== GameObject::BATTLEFIELD || $affected === null) {
                        break;
                    }
                    $this->moveTo($affected, GameObject::EXILE);
                    $self->holding[] = $affected->id;
                    break;
                }
                $this->moveTo($affected, GameObject::EXILE);
                break;

            case 'fog':
                $this->fogTurn = $this->turn;
                $this->log('Combat damage is prevented this turn.');
                break;

            case 'add_mana':
                $this->players[$controller]->manaPool->add((string) $effect['color'], $amount);
                $this->log("{$this->players[$controller]->name} adds ".str_repeat('{'.$effect['color'].'}', $amount).'.');
                break;

            case 'graft':
                $to = $this->objects[(int) ($effect['to'] ?? -1)] ?? null;
                if ($affected !== null && $to !== null && $to->zone === GameObject::BATTLEFIELD && $affected->counter('+1/+1') > 0) {
                    $affected->addCounters('+1/+1', -1);
                    $to->addCounters('+1/+1', 1);
                    $this->log("{$affected->name()} moves a +1/+1 counter onto {$to->name()}.");
                }
                break;

            case 'bounce':
                $this->moveTo($affected, GameObject::HAND);
                break;

            case 'pump':
                $pumped = match ($effect['each'] ?? null) {
                    null => $affected === null ? [] : [$affected],
                    'yours' => $this->permanents($controller),
                    'opponents' => array_filter($this->permanents(), fn (GameObject $o) => $o->controller !== $controller),
                    default => $this->permanents(),
                };
                foreach ($pumped as $object) {
                    if (isset($effect['each']) && ! $this->isCreature($object)) {
                        continue;
                    }
                    $object->untilEndOfTurn[] = ['power' => $effect['power'], 'toughness' => $effect['toughness'], 'keywords' => $effect['keywords']];
                }
                break;

            case 'counters':
                $affected?->addCounters($effect['kind'] ?? '+1/+1', $amount);
                break;

            case 'cumulative_upkeep':
                // Cumulative upkeep: an age counter, then its cost once for each, paid when its controller can, or sacrificed (rule 702.24a).
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->addCounters('age', 1);
                    $cost = str_repeat($affected->definition()->altCosts['cumulative'], $affected->counter('age'));
                    if (($payment = $this->payFor($controller, $cost)) !== null) {
                        $this->pay($controller, $payment);
                        $this->log("{$this->players[$controller]->name} pays {$cost} for {$affected->name()}'s cumulative upkeep.");
                    } else {
                        $this->log("{$this->players[$controller]->name} sacrifices {$affected->name()} (cumulative upkeep).");
                        $this->moveTo($affected, GameObject::GRAVEYARD);
                    }
                }
                break;

            case 'tap':
            case 'untap':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->tapped = $effect['type'] === 'tap';
                    $affected->frozen = $affected->frozen || ($effect['freeze'] ?? false);
                    $affected->addCounters('stun', (int) ($effect['stun'] ?? 0));
                }
                break;

            case 'token':
                for ($i = 0; $i < $amount; $i++) {
                    $token = $this->createToken((array) $effect['token'], $controller);
                    if ($effect['haste'] ?? false) {
                        $token->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['haste']];
                    }
                    if (isset($effect['endStep'])) {
                        $token->alt = $effect['endStep'] === 'exile' ? 'end_exile' : 'temporary';
                    }
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
                        $countered = $this->objects[$item['object']];
                        if (! self::isAbility($item) && $this->hasKeyword($countered, "can't be countered")) {
                            $this->log("{$countered->name()} can't be countered.");
                            break;
                        }
                        // "… unless its controller pays {N}": they pay when they can (rule 118.12).
                        if (isset($effect['unless']) && ($payment = $this->payFor($item['controller'], $effect['unless'])) !== null) {
                            $this->pay($item['controller'], $payment);
                            $this->log("{$this->players[$item['controller']]->name} pays {$effect['unless']}, so {$countered->name()} is not countered.");
                            break;
                        }
                        array_splice($this->stack, $index, 1);
                        $this->log("{$countered->name()} is countered.", null, "{$countered->name()} countered");
                        if (($effect['exileCountered'] ?? false) && ! self::isAbility($item)) {
                            $this->moveTo($countered, GameObject::EXILE);
                        } else {
                            $this->spellLeavesStack($countered, $item);
                        }
                        break;
                    }
                }
                break;

            case 'discard':
                if (($effect['chooser'] ?? null) === 'you') {
                    // "Target opponent reveals their hand. You choose a nonland card from it. That player discards that card."
                    $victim = (int) substr((string) $target, 2);
                    $hand = $this->players[$victim]->hand;
                    $this->log("{$this->players[$victim]->name} reveals ".($hand === [] ? 'an empty hand' : implode(', ', array_map(fn (int $id) => $this->objects[$id]->name(), $hand))).'.');
                    $cards = array_values(array_filter($hand, fn (int $id) => $this->matchesFilter($this->objects[$id]->definition(), $effect['filter'] ?? 'any')));
                    if (count($cards) === 1) {
                        ($effect['exile'] ?? false) ? $this->exileFromHand($victim, $cards) : $this->discardCards($victim, $cards);
                    } elseif ($cards !== []) {
                        $this->pendingChoice = ['type' => 'discard', 'seat' => $controller, 'from' => $victim, 'count' => 1, 'cards' => $cards, 'next' => []] + (($effect['exile'] ?? false) ? ['exile' => true] : []);

                        return true;
                    }
                    break;
                }
                $seats = match ($effect['each'] ?? null) {
                    'opponent' => array_values($opponents),
                    'player' => array_keys($this->players),
                    default => [$target === null ? $controller : (int) substr($target, 2)],
                };
                $choosing = [];
                foreach ($seats as $seat) {
                    // With no more cards than they must discard, there is nothing to choose.
                    if (count($this->players[$seat]->hand) <= $amount) {
                        $this->discardCards($seat, $this->players[$seat]->hand);
                    } else {
                        $choosing[] = $seat;
                    }
                }
                if ($choosing !== []) {
                    $this->pendingChoice = ['type' => 'discard', 'seat' => array_shift($choosing), 'count' => $amount, 'next' => $choosing];

                    return true;
                }
                break;

            case 'mill':
                $seats = match ($effect['each'] ?? null) {
                    'opponent' => array_values($opponents),
                    default => [$target === null ? $controller : (int) substr($target, 2)],
                };
                foreach ($seats as $seat) {
                    $this->mill($seat, $amount);
                }
                break;

            case 'control':
                // Until end of turn (rule 613.1b): it comes back in the cleanup step.
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD && $affected->controller !== $controller) {
                    $affected->borrowedFrom ??= $affected->controller;
                    $affected->controller = $controller;
                    $affected->sick = true;
                    $this->log("{$this->players[$controller]->name} gains control of {$affected->name()} until end of turn.");
                }
                break;

            case 'reanimate':
                if ($affected !== null && $affected->zone === GameObject::GRAVEYARD) {
                    $this->putOntoBattlefield($affected, $controller);
                    $this->log("{$affected->name()} returns to the battlefield.");
                    $this->followed = [(string) $target => "o:{$affected->id}:{$affected->incarnation}"];
                    if (isset($effect['endStep'])) {
                        $affected->alt = $effect['endStep'] === 'exile' ? 'end_exile' : 'temporary';
                    }
                }
                break;

            case 'chosen':
                // The creature that fights; see the next effect.
                break;

            case 'fight':
                $fighter = $effect['with'] === null ? null : $this->targetObject($effect['with']);
                if ($fighter !== null && $affected !== null && $fighter->zone === GameObject::BATTLEFIELD && $affected->zone === GameObject::BATTLEFIELD && $this->isCreature($fighter)) {
                    $this->dealDamage($fighter, "o:{$affected->id}", $this->power($fighter));
                    if ($effect['mutual'] && $this->isCreature($affected)) {
                        $this->dealDamage($affected, "o:{$fighter->id}", $this->power($affected));
                    }
                    $this->log("{$fighter->name()} ".($effect['mutual'] ? 'fights' : 'deals damage equal to its power to')." {$affected->name()}.");
                }
                break;

            case 'energy':
                $this->players[$controller]->energy += $amount;
                $this->log("{$this->players[$controller]->name} gets {$amount} energy.");
                break;

            case 'return_own':
                // A land (or creature …) its controller returns to hand: a tapped one other than this, basic lands first; else this one.
                $kind = $effect['filter'];
                $pick = fn (GameObject $o) => match ($kind) {
                    'land' => $o->definition()->isLand(),
                    'creature' => $this->isCreature($o),
                    default => ! $o->definition()->isLand(),
                };
                $own = array_values(array_filter($this->permanents($controller), $pick));
                usort($own, fn (GameObject $a, GameObject $b) => [$a->id === $source->id, ! $a->tapped, ! in_array('Basic', $a->definition()->supertypes, true), $a->id]
                    <=> [$b->id === $source->id, ! $b->tapped, ! in_array('Basic', $b->definition()->supertypes, true), $b->id]);
                if (($back = $own[0] ?? null) !== null) {
                    $this->moveTo($back, GameObject::HAND);
                    $this->log("{$this->players[$controller]->name} returns {$back->name()} to hand.");
                }
                break;

            case 'echo':
                // Echo: paid when its controller can, otherwise sacrificed (rule 702.30a).
                if ($affected !== null && ! $affected->echoPaid && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->echoPaid = true;
                    if (($payment = $this->payFor($controller, $affected->definition()->altCosts['echo'])) !== null) {
                        $this->pay($controller, $payment);
                        $this->log("{$this->players[$controller]->name} pays echo for {$affected->name()}.");
                    } else {
                        $this->log("{$this->players[$controller]->name} sacrifices {$affected->name()} (echo).");
                        $this->moveTo($affected, GameObject::GRAVEYARD);
                    }
                }
                break;

            case 'edict':
                // Annihilator: the defending player sacrifices their weakest permanents.
                foreach ($opponents as $seat) {
                    for ($i = 0; $i < $amount && ($victim = $this->weakest($seat, fn () => true)) !== null; $i++) {
                        $this->log("{$this->players[$seat]->name} sacrifices {$victim->name()}.");
                        $this->moveTo($victim, GameObject::GRAVEYARD);
                    }
                }
                break;

            case 'cascade':
                // Not cast while the trigger waited: on the bottom of its owner's library.
                $hit = $this->objects[(int) ($effect['hit'] ?? 0)] ?? null;
                if ($hit !== null && $hit->zone === GameObject::EXILE && $hit->exiledBy === 'cascade') {
                    $this->removeFromZone($hit);
                    $hit->moveTo(GameObject::LIBRARY);
                    array_unshift($this->players[$hit->owner]->library, $hit->id);
                    $this->log("{$hit->name()} goes to the bottom of the library.");
                }
                break;

            case 'madness':
                // Not cast while the trigger waited: into the graveyard.
                if ($source->zone === GameObject::EXILE && $source->exiledBy === 'madness') {
                    $this->moveTo($source, GameObject::GRAVEYARD);
                    $this->log("{$source->name()} goes to the graveyard.");
                }
                break;

            case 'regrow':
                if ($source->zone === GameObject::GRAVEYARD) {
                    $this->moveTo($source, GameObject::HAND);
                    $this->log("{$source->name()} returns to {$this->players[$source->owner]->name}'s hand.");
                }
                break;

            case 'ninjutsu':
                if ($source->zone === GameObject::HAND && in_array($this->step, [Step::DeclareBlockers, Step::CombatDamage, Step::FirstStrikeDamage], true)) {
                    $this->putOntoBattlefield($source, $controller);
                    $source->tapped = true;
                    $this->attackers[$source->id] = true;
                    $this->log("{$source->name()} enters tapped and attacking.");
                }
                break;

            case 'proliferate':
                // Proliferate (rule 701.27): your permanents' counters grow (not -1/-1, stun or age); your opponents' -1/-1 and stun counters,
                // and each player's poison (opponents) or energy (you).
                foreach ($this->permanents() as $object) {
                    $yours = $object->controller === $controller;
                    foreach (array_keys($object->counters) as $kind) {
                        if ($yours !== in_array($kind, ['-1/-1', 'stun', 'age'], true)) {
                            $object->addCounters($kind, 1);
                        }
                    }
                }
                foreach ($opponents as $seat) {
                    $this->players[$seat]->poison += $this->players[$seat]->poison > 0 ? 1 : 0;
                }
                $this->players[$controller]->energy += $this->players[$controller]->energy > 0 ? 1 : 0;
                $this->log("{$this->players[$controller]->name} proliferates.");
                break;

            case 'embalm':
                // A token copy that's a Zombie with no mana cost: 4/4 and black when eternalized, white when embalmed.
                $printed = $source->printed();
                $copy = $this->createToken(array_merge($printed->card, [
                    'name' => $printed->name,
                    'manaCost' => null,
                    'colors' => $effect['way'] === 'eternalize' ? ['B'] : ['W'],
                    'type' => $printed->card['type'].(in_array('Zombie', $printed->subtypes, true) ? '' : ' Zombie'),
                    'subtypes' => array_values(array_unique([...$printed->subtypes, 'Zombie'])),
                ], $effect['way'] === 'eternalize' ? ['power' => '4', 'toughness' => '4'] : []), $controller);
                $this->log("{$this->players[$controller]->name} creates a token copy of {$copy->name()} ({$effect['way']}).");
                break;

            case 'offspring':
                // A 1/1 token copy of the creature (rule 702.175).
                $copy = $this->createToken(['power' => '1', 'toughness' => '1', 'name' => $source->printed()->name] + $source->printed()->card, $controller);
                $this->log("{$this->players[$controller]->name} creates a 1/1 token copy of {$copy->name()}.");
                break;

            case 'backup':
                if ($affected !== null) {
                    $affected->addCounters('+1/+1', $amount);
                    if ($affected->id !== $source->id) {
                        $keywords = array_values(array_diff($source->definition()->keywords, ['backup']));
                        $affected->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => $keywords];
                    }
                }
                break;

            case 'unearth':
                if ($source->zone === GameObject::GRAVEYARD) {
                    $this->putOntoBattlefield($source, $controller);
                    $source->unearthed = true;
                    $source->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['haste']];
                    $this->log("{$source->name()} returns to the battlefield with haste.");
                }
                break;

            case 'empower':
                $this->empowerJace($controller, $amount);
                break;

            case 'search':
                // `Its controller may search …`: the owner of the card the spell just destroyed or exiled.
                $seat = ($effect['theirs'] ?? false) ? $this->targetOwner($target) : $controller;
                if ($seat !== null) {
                    $this->searchForLand($seat, $effect['find'], $effect['to'], $effect['count'] ?? 1);
                }
                break;

            case 'incubate':
                $incubator = $this->createToken(['name' => 'Incubator Token', 'type' => 'Token Artifact — Incubator', 'types' => ['Artifact'], 'subtypes' => ['Incubator'], 'colors' => [], 'text' => '{2}: Transform Incubator Token.', 'manaCost' => null, 'layout' => 'transform',
                    'back' => ['name' => 'Phyrexian Token', 'type' => 'Token Artifact Creature — Phyrexian', 'types' => ['Artifact', 'Creature'], 'subtypes' => ['Phyrexian'], 'colors' => [], 'power' => '0', 'toughness' => '0', 'text' => '', 'manaCost' => null]], $controller);
                $incubator->addCounters('+1/+1', $amount);
                $this->log("{$this->players[$controller]->name} incubates {$amount}.");
                break;

            case 'job_select':
                // Job select: a 1/1 Hero token, and this Equipment attached to it.
                $hero = $this->createToken(['name' => 'Hero Token', 'type' => 'Token Creature — Hero', 'types' => ['Creature'], 'subtypes' => ['Hero'], 'colors' => [], 'power' => '1', 'toughness' => '1', 'text' => '', 'manaCost' => null], $controller);
                if ($self !== null) {
                    $self->attachedTo = $hero->id;
                    $this->log("{$this->players[$controller]->name} creates a Hero token, and {$self->name()} is attached to it.");
                }
                break;

            case 'exile_spell':
                if ($source->zone === GameObject::STACK) {
                    $this->moveTo($source, GameObject::EXILE);
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

            case 'impulse':
                // Exiled from the top; playable until the end of this turn or the controller's next.
                $until = $this->turn;
                if ($effect['until'] === 'next') {
                    for ($seat = $this->active, $until++; ($seat = $this->opponent($seat)) !== $controller && $until < $this->turn + 8; $until++);
                }
                $exiled = [];
                for ($i = 0; $i < $amount && ($top = array_pop($this->players[$controller]->library)) !== null; $i++) {
                    $this->players[$controller]->library[] = $top;
                    $this->moveTo($this->objects[$top], GameObject::EXILE);
                    $this->objects[$top]->exiledBy = 'impulse';
                    $this->objects[$top]->exiledOn = $until;
                    $exiled[] = $this->objects[$top]->name();
                }
                if ($exiled !== []) {
                    $this->log("{$this->players[$controller]->name} exiles ".implode(', ', $exiled).' and may play '.(count($exiled) === 1 ? 'it' : 'them').($effect['until'] === 'this' ? ' this turn.' : ' until the end of their next turn.'));
                }
                break;

            case 'look':
                $cards = array_reverse(array_slice($this->players[$controller]->library, -$amount));
                if ($amount > 0 && $cards !== []) {
                    $eligible = array_values(array_filter($cards, fn (int $id) => $this->matchesFilter($this->objects[$id]->printed(), $effect['filter'])));
                    $this->pendingChoice = [
                        'type' => 'look',
                        'seat' => $controller,
                        'cards' => $cards,
                        'eligible' => $eligible,
                        'take' => min((int) $effect['take'], count($eligible)),
                        'may' => (bool) $effect['may'],
                        'rest' => $effect['rest'],
                        'to' => $effect['to'] ?? 'hand',
                    ];

                    return true;
                }
                break;

            case 'regenerate':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->shields++;
                }
                break;

            case 'prevent':
                $shielded = ($effect['you'] ?? false) ? "p:{$controller}" : ($affected !== null ? "o:{$affected->id}" : $target);
                if ($shielded !== null) {
                    $this->prevent[$shielded] = ($effect['amount'] ?? 0) === 'all' ? PHP_INT_MAX : ($this->prevent[$shielded] ?? 0) + $amount;
                }
                break;

            case 'choose_target':
                break;

            case 'exile_named':
                // Surgical Extraction, Crumble to Dust: every card with that card's name from its owner's graveyard, hand and library.
                $named = $target === null ? null : ($this->objects[(int) (explode(':', $target)[1] ?? 0)] ?? null);
                if ($named !== null) {
                    $owner = $this->players[$named->owner];
                    $found = array_values(array_filter([...$owner->graveyard, ...$owner->hand, ...$owner->library], fn (int $id) => $id !== $named->id && $this->objects[$id]->name() === $named->name()));
                    foreach ($found as $id) {
                        $this->moveTo($this->objects[$id], GameObject::EXILE);
                    }
                    $this->shuffle($named->owner);
                    $this->log("{$this->players[$controller]->name} exiles ".count($found)." card(s) named {$named->name()} from {$owner->name}'s graveyard, hand and library.");
                }
                break;

            case 'steal_search':
                // Bribery: the opponent's best matching card, onto the battlefield under the caster's control.
                $seat = $this->targetOwner($target);
                if ($seat !== null) {
                    $found = array_values(array_filter($this->players[$seat]->library, fn (int $id) => $this->matchesFilter($this->objects[$id]->printed(), $effect['filter'])));
                    usort($found, fn (int $a, int $b) => $this->objects[$b]->printed()->cost->manaValue() <=> $this->objects[$a]->printed()->cost->manaValue());
                    if ($found !== []) {
                        $this->putOntoBattlefield($this->objects[$found[0]], $controller);
                        $this->log("{$this->players[$controller]->name} puts {$this->objects[$found[0]]->name()} from {$this->players[$seat]->name}'s library onto the battlefield.");
                    }
                    $this->shuffle($seat);
                }
                break;

            case 'shuffle':
                // `Then shuffle.`, or `Then that player shuffles.` after an effect on a player or their permanent.
                $seat = ($effect['that'] ?? false) ? ($this->targetOwner($target) ?? $controller) : $controller;
                $this->shuffle($seat);
                $this->log("{$this->players[$seat]->name} shuffles their library.");
                break;

            case 'pay_transform':
                // `At the beginning of your first main phase, you may pay {N}. If you do, transform CARDNAME.`: paid when it can be.
                if ($self !== null && $self->zone === GameObject::BATTLEFIELD && ! $self->transformed && ($payment = $this->payFor($controller, $effect['cost'])) !== null) {
                    $this->pay($controller, $payment);
                    $before = $self->name();
                    $self->transformed = true;
                    $this->log("{$this->players[$controller]->name} pays {$effect['cost']}, and {$before} transforms into {$self->name()}.");
                }
                break;

            case 'transform':
                // Rule 701.28: a double-faced permanent turns over; the werewolves' only if the spell count says so.
                $met = match ($effect['if'] ?? null) {
                    'no_spells' => $this->spellsLastTurn() === 0,
                    'two_spells' => $this->spellsLastTurn() >= 2,
                    default => true,
                };
                if ($met && $self !== null && $self->zone === GameObject::BATTLEFIELD && $self->printed()->back !== null) {
                    $before = $self->name();
                    $self->transformed = ! $self->transformed;
                    $this->log("{$before} transforms into {$self->name()}.");
                }
                break;

            case 'exile_transformed':
                if ($self !== null && $self->zone === GameObject::BATTLEFIELD && $self->printed()->back !== null) {
                    $this->moveTo($self, GameObject::EXILE);
                    $this->putOntoBattlefield($self, $controller, transformed: true);
                    $this->log("{$self->printed()->name} returns to the battlefield transformed into {$self->name()}.");
                }
                break;

            case 'shuffle_self':
                if ($self !== null && $self->zone !== GameObject::LIBRARY && $self->zone !== GameObject::STACK) {
                    $this->log("{$self->name()} is shuffled into {$this->players[$self->owner]->name}'s library.");
                    $this->moveTo($self, GameObject::LIBRARY);
                    $this->shuffle($self->owner);
                }
                break;

            case 'unattach':
                if ($self !== null && $self->attachedTo !== null) {
                    $self->attachedTo = null;
                    $this->log("{$self->name()} becomes unattached.");
                }
                break;

            case 'saddled':
                if ($self !== null) {
                    $self->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['saddled']];
                    $this->log("{$self->name()} becomes saddled until end of turn.");
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

            case 'charge':
                if ($self !== null && $amount > 0) {
                    $self->addCounters('charge', $amount);
                    $this->log("{$self->name()} gets {$amount} charge counters.");
                }
                break;

            case 'class_level':
                if ($self !== null) {
                    $self->counters['class'] = $amount;
                    $this->log("{$self->name()} becomes level {$amount}.");
                    $this->trigger($self, "class_level_{$amount}", $self->controller);
                }
                break;
        }

        return false;
    }

    /**
     * Whether a card matches a filter such as `nonland`.
     *
     * @param CardDefinition $card
     * @param string         $filter
     *
     * @return bool
     */
    private function matchesFilter(CardDefinition $card, string $filter): bool
    {
        return match ($filter) {
            'nonland' => ! $card->isLand(),
            'creature' => $card->isCreature(),
            'noncreature' => ! $card->isCreature(),
            'noncreature_nonland' => ! $card->isCreature() && ! $card->isLand(),
            'instant_sorcery' => $card->is('Instant') || $card->is('Sorcery'),
            'land' => $card->isLand(),
            'creature_or_land' => $card->isCreature() || $card->isLand(),
            'artifact' => $card->is('Artifact'),
            'enchantment' => $card->is('Enchantment'),
            'instant' => $card->is('Instant'),
            'sorcery' => $card->is('Sorcery'),
            'planeswalker' => $card->isPlaneswalker(),
            'battle' => $card->is('Battle'),
            'permanent' => $card->isPermanentCard(),
            'nonland_permanent' => $card->isPermanentCard() && ! $card->isLand(),
            'any' => true,
            // `creature|land` or `sub:Dwarf|sub:Equipment`: any of them.
            default => str_contains($filter, '|') ? array_filter(explode('|', $filter), fn (string $piece) => $this->matchesFilter($card, $piece)) !== []
                : (! str_starts_with($filter, 'sub:') || in_array(substr($filter, 4), $card->subtypes, true)),
        };
    }

    /**
     * Puts the top cards of a player's library into their graveyard (rule 701.13).
     *
     * @param int $seat
     * @param int $count
     *
     * @return void
     */
    private function mill(int $seat, int $count): void
    {
        $milled = [];
        for ($i = 0; $i < $count && $this->players[$seat]->library !== []; $i++) {
            $object = $this->objects[end($this->players[$seat]->library)];
            $this->moveTo($object, GameObject::GRAVEYARD);
            $milled[] = $object->name();
        }
        $this->log("{$this->players[$seat]->name} mills ".($milled === [] ? 'nothing' : implode(', ', $milled)).'.');
    }

    /**
     * Empower Jace: loyalty counters on a Jace token the player controls,
     * creating one first if they have none.
     *
     * @param int $seat
     * @param int $amount
     *
     * @return void
     */
    private function empowerJace(int $seat, int $amount): void
    {
        $jace = null;
        foreach ($this->permanents($seat) as $object) {
            if (($object->card['token'] ?? false) && $object->definition()->isPlaneswalker() && in_array('Jace', $object->definition()->subtypes, true)) {
                $jace = $object;
                break;
            }
        }
        if ($jace === null) {
            $jace = $this->createToken([
                'name' => 'Jace Token', 'type' => 'Token Planeswalker — Jace', 'types' => ['Planeswalker'], 'subtypes' => ['Jace'], 'colors' => ['U'],
                'loyalty' => '0', 'text' => "−1: Surveil 1.\n−3: Draw a card.", 'manaCost' => null,
            ], $seat);
        }
        $jace->addCounters('loyalty', $amount);
        $this->log("{$this->players[$seat]->name} empowers Jace {$amount}.");
    }

    /**
     * Searches a player's library for a land and shuffles (rule 701.19).
     * The game picks a basic land of the color the hand needs most that
     * their lands make least.
     *
     * @param int    $seat
     * @param string $find `basic land` or a basic land type.
     * @param string $to   `hand`, `battlefield` or `tapped`.
     *
     * @return void
     */
    private function searchForLand(int $seat, string $find, string $to, int $count = 1): void
    {
        // `up to N`: each land is picked like a single search; Cultivate puts the first onto the battlefield tapped and the second into hand.
        if ($count > 1) {
            for ($i = 0; $i < $count; $i++) {
                $this->searchForLand($seat, $find, $to === 'split' ? ($i === 0 ? 'tapped' : 'hand') : $to);
            }

            return;
        }
        $player = $this->players[$seat];
        $found = array_values(array_filter($player->library, function (int $id) use ($find) {
            $card = $this->objects[$id]->definition();

            return $card->isLand() && ($find === 'basic land' ? in_array('Basic', $card->supertypes, true) : in_array($find, $card->subtypes, true));
        }));
        if ($found === []) {
            $this->log("{$player->name} searches their library and finds nothing.");
            $this->shuffle($seat);

            return;
        }
        $need = [];
        foreach ($player->hand as $id) {
            foreach (str_split(preg_replace('/[^WUBRG]/', '', (string) ($this->objects[$id]->definition()->card['manaCost'] ?? ''))) as $color) {
                $need[$color] = ($need[$color] ?? 0) + 1;
            }
        }
        foreach ($this->permanents($seat) as $object) {
            foreach ($object->definition()->manaAbility['colors'] ?? [] as $color) {
                $need[$color] = ($need[$color] ?? 0) - 1;
            }
        }
        usort($found, fn (int $a, int $b) => max(array_map(fn ($c) => $need[$c] ?? 0, $this->objects[$b]->definition()->manaAbility['colors'] ?? ['C']))
            <=> max(array_map(fn ($c) => $need[$c] ?? 0, $this->objects[$a]->definition()->manaAbility['colors'] ?? ['C'])));
        $land = $this->objects[$found[0]];
        if ($to === 'hand') {
            $this->moveTo($land, GameObject::HAND);
        } else {
            $this->putOntoBattlefield($land, $seat);
            $land->tapped = $land->tapped || $to === 'tapped';
        }
        $this->log("{$player->name} searches their library for {$land->name()} and puts it ".($to === 'hand' ? 'into their hand' : 'onto the battlefield'.($to === 'tapped' ? ' tapped' : '')).'.');
        $this->shuffle($seat);
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
     * @param int        $context     For modular, the +1/+1 counters it died with; for graft, the creature that entered.
     *
     * @return void
     */
    private function trigger(GameObject $source, string $event, int $controller, ?int $incarnation = null, int $context = 0): void
    {
        foreach ($source->definition()->triggersOn($event) as $ability) {
            // Modular: the +1/+1 counters it had as it died. Graft: the creature that entered. Mentor: lesser power than it has now.
            $ability['effects'] = array_map(fn (array $effect) => match (true) {
                ($effect['amount'] ?? null) === 'counters' => ['amount' => $context] + $effect,
                $effect['type'] === 'graft' => ['to' => $context] + $effect,
                $effect['type'] === 'cascade' => ['hit' => $context] + $effect,
                ($effect['target'] ?? null) === 'attacking_lesser' => ['target' => 'attacking_power_lt_'.$this->power($source)] + $effect,
                default => $effect,
            }, $ability['effects']);
            // "When this creature enters, if it was kicked, …"
            if (($ability['kicked'] ?? false) && ! $source->kicked) {
                continue;
            }
            // "Whenever this creature attacks while saddled, …"
            if (($ability['saddled'] ?? false) && ! $this->hasKeyword($source, 'saddled')) {
                continue;
            }
            if (! $this->gained($source, $ability)) {
                continue;
            }
            // Echo triggers only until it has been paid once (rule 702.30a).
            if ($source->echoPaid && ($ability['effects'][0]['type'] ?? '') === 'echo') {
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
            ] + (isset($ability['modes']) ? ['modes' => $ability['modes'], 'choose' => $ability['choose']] : []);
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
            // A modal one first gets its modes (rule 603.3c): only those with targets to choose.
            if (isset($trigger['modes'])) {
                $options = array_values(array_filter(
                    CardDefinition::choices(count($trigger['modes']), $trigger['choose']),
                    fn (array $modes) => array_filter(self::targetKindsOf($this->modeEffects($trigger, $modes)), fn (string $kind) => $this->targetOptions($trigger['controller'], $kind) === []) === []
                ));
                if ($options === []) {
                    array_shift($this->pendingTriggers);
                    $this->log("{$trigger['label']} has no mode it can choose and is removed.");

                    continue;
                }
                if (count($options) > 1) {
                    $this->pendingChoice = ['type' => 'mode', 'seat' => $trigger['controller'], 'label' => $trigger['label'], 'options' => $options, 'texts' => array_map(
                        fn (array $modes) => implode(' and ', array_map(fn (int $mode) => rtrim(str_replace('CARDNAME', $this->objects[$trigger['source']]->name(), $trigger['modes'][$mode]['text']), '.'), $modes)),
                        $options,
                    )];

                    return true;
                }
                $this->setTriggerModes($options[0]);
                $trigger = $this->pendingTriggers[0];
            }
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
     * The effects of a modal triggered ability's chosen modes, in order.
     *
     * @param array $trigger
     * @param int[] $modes
     *
     * @return array[]
     */
    private function modeEffects(array $trigger, array $modes): array
    {
        $effects = [];
        foreach ($modes as $mode) {
            array_push($effects, ...$trigger['modes'][$mode]['effects']);
        }

        return $effects;
    }

    /**
     * Fixes the modes of the modal triggered ability next in line.
     *
     * @param int[] $modes
     *
     * @return void
     */
    private function setTriggerModes(array $modes): void
    {
        $trigger = $this->pendingTriggers[0];
        $trigger['effects'] = $this->modeEffects($trigger, $modes);
        $trigger['kinds'] = self::targetKindsOf($trigger['effects']);
        $trigger['text'] = implode(' and ', array_map(fn (int $mode) => rtrim(str_replace('CARDNAME', $this->objects[$trigger['source']]->name(), $trigger['modes'][$mode]['text']), '.'), $modes)).'.';
        unset($trigger['modes'], $trigger['choose']);
        $this->pendingTriggers[0] = $trigger;
    }

    /**
     * Chooses the modes of a modal triggered ability, as one of the options
     * {@see choiceAwaiting()} lists.
     *
     * @param int $seat
     * @param int $option
     *
     * @return void
     */
    public function chooseMode(int $seat, int $option): void
    {
        $choice = $this->pendingChoice;
        if ($choice === null || $choice['type'] !== 'mode' || $choice['seat'] !== $seat || $this->stage !== self::PLAYING) {
            throw new GameException('You have no modes to choose.');
        }
        if (! isset($choice['options'][$option])) {
            throw new GameException('Choose one of the modes offered.');
        }
        $this->pendingChoice = null;
        $this->setTriggerModes($choice['options'][$option]);
        $this->log("{$this->players[$seat]->name} chooses \"{$choice['texts'][$option]}\" for {$this->pendingTriggers[0]['label']}.", $seat);
        $this->settle();
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
        $this->checkTargets($trigger['kinds'], array_values($targets), $seat, $trigger['label'], $this->objects[$trigger['source']] ?? null);
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
        [$wardMana, $wardLife, $wardDiscard] = $this->wardCost(array_map(fn (string $target) => $this->pinTarget($target), $targets), $trigger['controller']);
        if ($wardMana !== '' || $wardLife > 0 || $wardDiscard > 0) {
            $payment = $this->payFor($trigger['controller'], $wardMana, $wardLife);
            if ($payment === null || $wardDiscard > count($this->players[$trigger['controller']]->hand)) {
                $this->log("{$trigger['label']} is countered by ward.", $trigger['controller']);

                return;
            }
            $this->pay($trigger['controller'], $payment);
            $this->discardCheapest($trigger['controller'], $wardDiscard);
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
    private function checkTargets(array $kinds, array $targets, int $seat, string $name, ?GameObject $source = null): void
    {
        if (count($targets) !== count($kinds)) {
            throw new GameException("{$name} needs ".count($kinds).' target'.(count($kinds) === 1 ? '' : 's').'.');
        }
        foreach ($kinds as $slot => $kind) {
            if (! $this->isLegalTarget($kind, (string) $targets[$slot], $seat) || $this->protectedFrom($this->targetObject((string) $targets[$slot]), $source)) {
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
        if (($split = $this->splitSecond()) !== null) {
            return "{$split} has split second: no abilities can be activated while it is on the stack.";
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
        if (isset($ability['fromLevel']) && $this->classLevel($object) !== $ability['fromLevel']) {
            return $this->classLevel($object) > $ability['fromLevel'] ? "{$card->name} is already past that level." : "{$card->name} must reach level {$ability['fromLevel']} first.";
        }
        if (! $this->gained($object, $ability)) {
            return match (true) {
                isset($ability['charge']) => "{$card->name} needs {$ability['charge']} charge counters for that ability.",
                isset($ability['maxSpeed']) => "{$card->name} needs you at max speed for that ability.",
                default => "{$card->name} gains that ability at level {$ability['classLevel']}.",
            };
        }
        if (($ability['cost']['station'] ?? false) && $this->stationCrew($object) === null) {
            return "{$card->name} needs another untapped creature you control to station it.";
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
            return (str_starts_with(strtolower($card->activated[$index]['text']), 'saddle') ? 'Saddling' : 'Crewing')." {$card->name} needs untapped creatures with total power {$cost['crew']} or more.";
        }
        if (($cost['life'] ?? 0) > $this->players[$seat]->life) {
            return 'You do not have enough life.';
        }
        if (($cost['energy'] ?? 0) > $this->players[$seat]->energy) {
            return 'You do not have enough energy.';
        }
        if (isset($cost['remove']) && $object->counter($cost['remove'][0]) < $cost['remove'][1]) {
            return "{$object->name()} needs {$cost['remove'][1]} {$cost['remove'][0]} counters.";
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
        $this->checkTargets($kinds, $targets, $seat, $label, $object);
        [$wardMana, $wardLife, $wardDiscard] = $this->wardCost(array_map(fn (string $target) => $this->pinTarget($target), $targets), $seat);
        if ($wardDiscard > count($this->players[$seat]->hand)) {
            throw new GameException('You need a card in your hand to discard for ward.');
        }
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
        $effects = $ability['effects'];
        if ($cost['station'] ?? false) {
            $station = $this->stationCrew($object);
            $station->tapped = true;
            $effects[0]['amount'] = $this->power($station);
            $crew = [$station->id];
        }
        if (isset($cost['loyalty'])) {
            $object->addCounters('loyalty', $cost['loyalty']);
        }
        $player->energy -= $cost['energy'] ?? 0;
        $this->discardCheapest($seat, $wardDiscard);
        if (isset($cost['remove'])) {
            $object->addCounters($cost['remove'][0], -$cost['remove'][1]);
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
            'effects' => $effects,
            'kinds' => $kinds,
            'label' => $label,
        ], $targets, 'is activated', $object->name().'*'.($crew === [] ? '' : ' (crew '.implode(', ', array_map(fn (int $c) => $this->objects[$c]->name(), $crew)).')'));
        $this->targetedTriggers($targets);
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
        // `up to one target …`: `?kind`, and `-` when none is chosen.
        if (str_starts_with($kind, '?')) {
            if ($target === '-') {
                return true;
            }
            $kind = substr($kind, 1);
        }
        $parts = explode(':', $target);
        switch ($parts[0]) {
            case 'p':
                $seat = (int) ($parts[1] ?? -1);
                if (! isset($this->players[$seat]) || $this->players[$seat]->lost) {
                    return false;
                }
                // `You have hexproof.`
                if ($seat !== $controller && array_filter($this->permanents($seat), fn (GameObject $o) => in_array('you have hexproof', $o->definition()->keywords, true)) !== []) {
                    return false;
                }

                return match ($kind) {
                    'any', 'player', 'player_or_planeswalker' => true,
                    'opponent' => $seat !== $controller,
                    default => false,
                };

            case 'o':
                $object = $this->targetObject($target);
                // Cards in a graveyard are targeted only as cards (`target creature card from your graveyard`).
                $inGraveyard = self::isGraveyardKind($kind);
                if ($object === null || $object->zone !== ($inGraveyard ? GameObject::GRAVEYARD : GameObject::BATTLEFIELD)) {
                    return false;
                }
                if ($inGraveyard) {
                    return $this->matchesKind($object, $kind, $controller);
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
                            'instant_sorcery_spell' => ! $card->isPermanentCard(),
                            'instant_sorcery_spell_yours' => ! $card->isPermanentCard() && $item['controller'] === $controller,
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
            'artifact_or_creature' => $card->is('Artifact') || $creature,
            'artifact_creature' => $card->is('Artifact') && $creature,
            'artifact_or_land' => $card->is('Artifact') || $card->isLand(),
            'creature_nonblack' => $creature && ! in_array('B', $card->colors, true),
            'creature_nonartifact' => $creature && ! $card->is('Artifact'),
            'creature_nonartifact_nonblack' => $creature && ! $card->is('Artifact') && ! in_array('B', $card->colors, true),
            'creature_or_vehicle' => $creature || in_array('Vehicle', $card->subtypes, true),
            'attacking_or_blocking' => $creature && (isset($this->attackers[$object->id]) || isset($this->blockers[$object->id])),
            'attacking' => $creature && isset($this->attackers[$object->id]),
            'blocking' => $creature && isset($this->blockers[$object->id]),
            'creature_flying' => $creature && $this->hasKeyword($object, 'flying'),
            'creature_no_flying' => $creature && ! $this->hasKeyword($object, 'flying'),
            'tapped_creature' => $creature && $object->tapped,
            'untapped_creature' => $creature && ! $object->tapped,
            'creature_card_yours' => $object->printed()->isCreature() && $object->owner === $controller,
            'card_yours' => $object->owner === $controller,
            'card_graveyard_nonbasic' => ! ($object->printed()->isLand() && in_array('Basic', $object->printed()->supertypes, true)),
            'nonbasic_land' => $card->isLand() && ! in_array('Basic', $card->supertypes, true),
            'creature_or_planeswalker_opponent' => ($creature || $card->isPlaneswalker()) && $object->controller !== $controller,
            'land_you_control' => $card->isLand() && $object->controller === $controller,
            'land' => $card->isLand(),
            'nonland_permanent' => ! $card->isLand(),
            'nonland_permanent_opponent' => ! $card->isLand() && $object->controller !== $controller,
            'artifact_or_creature_opponent' => ($card->is('Artifact') || $creature) && $object->controller !== $controller,
            'artifact_creature_enchantment_opponent' => ($card->is('Artifact') || $creature || $card->is('Enchantment')) && $object->controller !== $controller,
            'permanent' => true,
            // Soulshift N: a Spirit card with mana value N or less. Mentor: an attacker with power less than N.
            default => ((bool) preg_match('/^spirit_card_yours_(\d+)$/', $kind, $m) && $object->owner === $controller
                && in_array('Spirit', $object->printed()->subtypes, true) && $object->printed()->cost->manaValue() <= (int) $m[1])
                || ((bool) preg_match('/^attacking_power_lt_(-?\d+)$/', $kind, $m) && $creature && isset($this->attackers[$object->id]) && $this->power($object) < (int) $m[1]),
        };
    }

    /**
     * Whether a kind of target is a card in a graveyard.
     *
     * @param string $kind
     *
     * @return bool
     */
    private static function isGraveyardKind(string $kind): bool
    {
        return in_array($kind, self::GRAVEYARD_KINDS, true) || str_starts_with($kind, 'spirit_card_yours_');
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
        if (self::isGraveyardKind(ltrim($kind, '?'))) {
            foreach ($this->players as $player) {
                foreach ($player->graveyard as $id) {
                    $options[] = "o:{$id}";
                }
            }
        }
        foreach ($this->stack as $item) {
            $options[] = "s:{$item['id']}";
        }
        if (str_starts_with($kind, '?')) {
            $options[] = '-';
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
    /**
     * Adds a lore counter to a Saga and triggers that chapter (rule 714.2b).
     *
     * @param GameObject $saga
     *
     * @return void
     */
    private function addLore(GameObject $saga): void
    {
        $saga->addCounters('lore', 1);
        $this->trigger($saga, 'chapter_'.$saga->counter('lore'), $saga->controller);
    }

    /**
     * Whether one of a permanent's abilities is waiting to go on the stack or to resolve.
     *
     * @param GameObject $object
     *
     * @return bool
     */
    private function waitsOn(GameObject $object): bool
    {
        foreach ($this->pendingTriggers as $trigger) {
            if ($trigger['source'] === $object->id) {
                return true;
            }
        }
        foreach ($this->stack as $item) {
            if (self::isAbility($item) && $item['object'] === $object->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spells cast during the turn before this one, by anyone.
     *
     * @return int
     */
    private function spellsLastTurn(): int
    {
        return match ($this->spellsTurn) {
            $this->turn => $this->spellsBefore,
            $this->turn - 1 => $this->spellsCast,
            default => 0,
        };
    }

    /**
     * Day and night (rule 726): as a turn begins, day becomes night if no
     * spells were cast last turn, and night becomes day if two or more
     * were; daybound permanents turn to their nightbound faces and back.
     *
     * @return void
     */
    private function dayNightCheck(): void
    {
        $spells = $this->spellsLastTurn();
        $next = match (true) {
            $this->dayNight === 'day' && $spells === 0 => 'night',
            $this->dayNight === 'night' && $spells >= 2 => 'day',
            default => $this->dayNight,
        };
        if ($next === $this->dayNight) {
            return;
        }
        $this->dayNight = $next;
        $this->log("It becomes {$next}.");
        foreach ($this->permanents() as $object) {
            if (! $object->faceDown && $object->printed()->back !== null && in_array('daybound', $object->printed()->keywords, true)) {
                $object->transformed = $next === 'night';
            }
        }
    }

    /**
     * The player a target names, or the owner of the card it names, even
     * after that card has changed zones.
     *
     * @param string|null $target
     *
     * @return int|null
     */
    private function targetOwner(?string $target): ?int
    {
        $parts = explode(':', (string) $target);

        return match ($parts[0]) {
            'p' => (int) ($parts[1] ?? 0),
            'o' => ($this->objects[(int) ($parts[1] ?? 0)] ?? null)?->owner,
            default => null,
        };
    }

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
            '-' => 'no target',
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
        // Creatures that attack each combat if able always join (rule 508.1d).
        foreach ($candidates as $id) {
            if (! in_array($id, $ids, true) && $this->hasKeyword($this->objects[$id], 'attacks each combat if able')) {
                $ids[] = $id;
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

        // Battle cry (rule 702.91): each other attacking creature gets +1/+0.
        foreach ($ids as $id) {
            if ($this->hasKeyword($this->objects[$id], 'battle cry') && count($ids) > 1) {
                foreach ($ids as $other) {
                    if ($other !== $id) {
                        $this->objects[$other]->untilEndOfTurn[] = ['power' => 1, 'toughness' => 0, 'keywords' => []];
                    }
                }
                $this->log("Battle cry: each other attacking creature gets +1/+0 until end of turn.");
            }
        }

        // Enlist (rule 702.154): taps your weakest nonattacking creature that could attack, for its power.
        foreach ($ids as $id) {
            if (! $this->hasKeyword($this->objects[$id], 'enlist')) {
                continue;
            }
            $helper = $this->weakest($seat, fn (GameObject $o) => $this->isCreature($o) && ! $o->tapped && ! isset($this->attackers[$o->id])
                && (! $o->sick || $this->hasKeyword($o, 'haste')) && $this->power($o) > 0);
            if ($helper !== null) {
                $helper->tapped = true;
                $this->objects[$id]->untilEndOfTurn[] = ['power' => $this->power($helper), 'toughness' => 0, 'keywords' => []];
                $this->log("{$this->objects[$id]->name()} enlists {$helper->name()} and gets +{$this->power($helper)}/+0 until end of turn.");
            }
        }

        // Exalted (rule 702.83): each instance gives a creature attacking alone +1/+1.
        if (count($ids) === 1) {
            $exalted = count(array_filter($this->permanents($seat), fn (GameObject $o) => $this->hasKeyword($o, 'exalted')));
            if ($exalted > 0) {
                $this->objects[$ids[0]]->untilEndOfTurn[] = ['power' => $exalted, 'toughness' => $exalted, 'keywords' => []];
                $this->log("Exalted: {$this->objects[$ids[0]]->name()} gets +{$exalted}/+{$exalted} until end of turn.");
            }
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
    /**
     * Protection from a color (rule 702.16): no damage, blocking or targeting by sources of that color.
     *
     * @param GameObject|null $object
     * @param GameObject|null $source
     *
     * @return bool
     */
    private function protectedFrom(?GameObject $object, ?GameObject $source): bool
    {
        if ($object === null || $source === null || $object->zone !== GameObject::BATTLEFIELD) {
            return false;
        }
        $colors = ['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G'];
        foreach ($this->keywords($object) as $keyword) {
            if (preg_match('/^protection from (white|blue|black|red|green)$/', $keyword, $m) && in_array($colors[$m[1]], $source->definition()->colors, true)) {
                return true;
            }
        }

        return false;
    }

    public function canBlock(GameObject $blocker, GameObject $attacker): bool
    {
        if (! $this->isCreature($blocker) || $blocker->tapped || $blocker->zone !== GameObject::BATTLEFIELD || $blocker->controller !== $this->defender() || $this->hasKeyword($blocker, "can't block")
            || ($this->hasKeyword($blocker, 'unleash') && $blocker->counter('+1/+1') > 0)) {
            return false;
        }
        if (! isset($this->attackers[$attacker->id]) || $attacker->zone !== GameObject::BATTLEFIELD) {
            return false;
        }
        if ($this->hasKeyword($attacker, 'flying') && ! $this->hasKeyword($blocker, 'flying') && ! $this->hasKeyword($blocker, 'reach')) {
            return false;
        }
        if ($this->protectedFrom($attacker, $blocker)) {
            return false;
        }
        $artifact = $blocker->definition()->is('Artifact');
        $colors = $blocker->definition()->colors;

        foreach (['Plains', 'Island', 'Swamp', 'Mountain', 'Forest'] as $land) {
            // Landwalk (rule 702.14): unblockable while the defender controls that land type.
            if ($this->hasKeyword($attacker, strtolower($land).'walk')) {
                foreach ($this->permanents($blocker->controller) as $permanent) {
                    if (in_array($land, $permanent->definition()->subtypes, true)) {
                        return false;
                    }
                }
            }
        }

        return ! match (true) {
            $this->hasKeyword($attacker, "can't be blocked") => true,
            // Shadow (rule 702.28): blocks and is blocked only by creatures with shadow.
            $this->hasKeyword($attacker, 'shadow') !== $this->hasKeyword($blocker, 'shadow') => true,
            // Fear (702.36): only artifact and black creatures; intimidate (702.13): only artifact creatures and those sharing a color.
            $this->hasKeyword($attacker, 'fear') && ! $artifact && ! in_array('B', $colors, true) => true,
            $this->hasKeyword($attacker, 'intimidate') && ! $artifact && array_intersect($colors, $attacker->definition()->colors) === [] => true,
            // Skulk (702.118): not by creatures with greater power.
            $this->hasKeyword($attacker, 'skulk') && $this->power($blocker) > $this->power($attacker) => true,
            $this->hasKeyword($blocker, 'can block only creatures with flying') && ! $this->hasKeyword($attacker, 'flying') => true,
            $this->evadesByPower($attacker, $this->power($blocker)) => true,
            $this->hasKeyword($attacker, "can't be blocked by lesser power") && $this->power($blocker) < $this->power($attacker) => true,
            default => false,
        };
    }

    /**
     * Whether an attacker can't be blocked by creatures with this power
     * (`can't be blocked by creatures with power 2 or greater`).
     *
     * @param GameObject $attacker
     * @param int        $power
     *
     * @return bool
     */
    private function evadesByPower(GameObject $attacker, int $power): bool
    {
        foreach ($this->keywords($attacker) as $keyword) {
            if (preg_match("/^can't be blocked by power (\\d+) or (greater|less)$/", $keyword, $m)
                && ($m[2] === 'greater' ? $power >= (int) $m[1] : $power <= (int) $m[1])) {
                return true;
            }
        }

        return false;
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
        // An attacker that must be blocked if able gets a free creature that can block it (rule 509.1c).
        foreach (array_keys($this->attackers) as $attacker) {
            if (in_array($attacker, array_map('intval', $blocks), true) || ! $this->hasKeyword($this->objects[$attacker], 'must be blocked if able') || $this->hasKeyword($this->objects[$attacker], 'menace')) {
                continue;
            }
            foreach ($this->blockCandidates() as $candidate) {
                if (! isset($blocks[$candidate]) && $this->canBlock($this->objects[$candidate], $this->objects[$attacker])) {
                    $blocks[$candidate] = $attacker;
                    break;
                }
            }
        }
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
            if ($n > 1 && $this->hasKeyword($this->objects[$attacker], "can't be blocked by more than one creature")) {
                throw new GameException($this->objects[$attacker]->name().' cannot be blocked by more than one creature.');
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
        // Flanking (rule 702.25): a blocker without flanking gets -1/-1 for each instance.
        foreach ($this->blockers as $blocker => $attacker) {
            if ($this->hasKeyword($this->objects[$attacker], 'flanking') && ! $this->hasKeyword($this->objects[$blocker], 'flanking')) {
                $this->objects[$blocker]->untilEndOfTurn[] = ['power' => -1, 'toughness' => -1, 'keywords' => []];
                $this->log("Flanking: {$this->objects[$blocker]->name()} gets -1/-1 until end of turn.");
            }
        }
        // `Whenever this creature becomes blocked`, afflict.
        foreach (array_keys($this->blocked) as $id) {
            $this->trigger($this->objects[$id], 'blocked', $this->objects[$id]->controller);
        }
        // Bushido (rule 702.45): +N/+N when it blocks or becomes blocked.
        foreach (array_unique([...array_keys($this->blockers), ...array_keys($this->blocked)]) as $id) {
            if (($n = $this->keywordAmount($this->objects[$id], 'bushido')) > 0) {
                $this->objects[$id]->untilEndOfTurn[] = ['power' => $n, 'toughness' => $n, 'keywords' => []];
                $this->log("Bushido: {$this->objects[$id]->name()} gets +{$n}/+{$n} until end of turn.");
            }
        }
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
        if ($this->fogTurn === $this->turn) {
            $this->log('All combat damage is prevented this turn.');

            return;
        }
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
                // Toxic (rule 702.164): poison counters too.
                if (($toxic = $this->keywordAmount($source, 'toxic')) > 0) {
                    $this->players[(int) substr($target, 2)]->poison += $toxic;
                }
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
        // Prevention shields (rule 615.7): `Prevent the next N damage that would be dealt to …`.
        if (($shield = $this->prevent[$target] ?? 0) > 0) {
            $prevented = min($shield, $amount);
            $this->prevent[$target] = $shield - $prevented;
            $amount -= $prevented;
            $this->log("{$prevented} damage from {$source->name()} is prevented.");
            if ($amount <= 0) {
                return;
            }
        }
        $parts = explode(':', $target);
        if ($parts[0] === 'p') {
            $this->players[(int) $parts[1]]->damagedOnTurn = $this->turn;
            // Infect (rule 702.90b): poison counters instead of life loss.
            if ($this->hasKeyword($source, 'infect')) {
                $this->players[(int) $parts[1]]->poison += $amount;
            } else {
                $this->players[(int) $parts[1]]->life -= $amount;
            }
        } else {
            $object = $this->targetObject($target);
            if ($object === null || $object->zone !== GameObject::BATTLEFIELD) {
                return;
            }
            if ($this->protectedFrom($object, $source)) {
                $this->log("Protection prevents the damage {$source->name()} would deal to {$object->name()}.");

                return;
            }
            if ($object->definition()->isPlaneswalker() && ! $this->isCreature($object)) {
                $object->addCounters('loyalty', -$amount);
            } elseif ($this->hasKeyword($source, 'infect') || $this->hasKeyword($source, 'wither')) {
                // Infect and wither (rules 702.90 and 702.80): -1/-1 counters instead of damage.
                $object->addCounters('-1/-1', $amount);
                if ($this->hasKeyword($source, 'deathtouch')) {
                    $object->deathtouched = true;
                }
            } else {
                $object->damage += $amount;
                if ($this->hasKeyword($source, 'deathtouch')) {
                    $object->deathtouched = true;
                }
            }
        }
        if ($this->hasKeyword($source, 'lifelink')) {
            $this->gainLife($source->controller, $amount);
        }
    }

    /**
     * A player gains life, triggering `Whenever you gain life, …`.
     *
     * @param int $seat
     * @param int $amount
     *
     * @return void
     */
    private function gainLife(int $seat, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $this->players[$seat]->life += $amount;
        foreach ($this->permanents($seat) as $permanent) {
            $this->trigger($permanent, 'gain_life', $seat);
        }
    }

    private function destroy(?GameObject $object, bool $regenerates = true): void
    {
        if ($object === null || $object->zone !== GameObject::BATTLEFIELD || $this->hasKeyword($object, 'indestructible') || ($regenerates && $this->regenerate($object)) || $this->umbraArmor($object)) {
            return;
        }
        $this->moveTo($object, GameObject::GRAVEYARD);
    }

    /**
     * Umbra armor (rule 702.89): an Aura with it is destroyed instead, and
     * the creature's damage is removed.
     *
     * @param GameObject $object
     *
     * @return bool Whether an Aura took its place.
     */
    private function umbraArmor(GameObject $object): bool
    {
        foreach ($this->permanents() as $aura) {
            $words = $aura->definition()->keywords;
            if ($aura->attachedTo === $object->id && (in_array('umbra armor', $words, true) || in_array('totem armor', $words, true))) {
                $object->damage = 0;
                $object->deathtouched = false;
                $this->log("{$aura->name()} is destroyed instead of {$object->name()}.");
                $this->moveTo($aura, GameObject::GRAVEYARD);

                return true;
            }
        }

        return false;
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
    /**
     * Dredge (rule 702.52): instead of drawing, mill N and return the card
     * from the graveyard to hand. Taken whenever the library has at least
     * N cards more than the player's hand size, the biggest dredge first.
     *
     * @param int $seat
     *
     * @return bool Whether the draw was replaced.
     */
    private function dredge(int $seat): bool
    {
        $player = $this->players[$seat];
        $best = null;
        foreach ($player->graveyard as $id) {
            if (($n = (int) substr((string) current(preg_grep('/^dredge \d+$/', $this->objects[$id]->definition()->keywords) ?: ['dredge 0']), 7)) > 0 && count($player->library) >= $n + GamePlayer::HAND_SIZE && ($best === null || $n > $best[1])) {
                $best = [$id, $n];
            }
        }
        if ($best === null) {
            return false;
        }
        [$id, $n] = $best;
        $this->log("{$player->name} dredges {$this->objects[$id]->name()}.");
        $this->mill($seat, $n);
        $this->moveTo($this->objects[$id], GameObject::HAND);

        return true;
    }

    private function draw(int $seat, int $count): void
    {
        $player = $this->players[$seat];
        for ($i = 0; $i < $count; $i++) {
            if ($this->stage === self::PLAYING && $this->dredge($seat)) {
                continue;
            }
            $id = array_pop($player->library);
            if ($id === null) {
                $player->drewFromEmpty = true;

                return;
            }
            $this->objects[$id]->moveTo(GameObject::HAND);
            $player->hand[] = $id;
            if ($this->stage === self::PLAYING) {
                if ($player->drawnTurn !== $this->turn) {
                    [$player->drawn, $player->drawnTurn] = [0, $this->turn];
                }
                // "Whenever you draw your second card each turn, …"
                if (++$player->drawn === 2) {
                    foreach ($this->permanents($seat) as $permanent) {
                        $this->trigger($permanent, 'second_draw', $seat);
                    }
                }
            }
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
    private function putOntoBattlefield(GameObject $object, int $controller, bool $triggers = true, bool $faceDown = false, bool $kicked = false, int $x = 0, bool $transformed = false): void
    {
        $this->removeFromZone($object);
        $object->moveTo(GameObject::BATTLEFIELD);
        $object->faceDown = $faceDown;
        $object->kicked = $kicked;
        $object->transformed = $transformed && $object->printed()->back !== null;
        // Daybound (rule 702.145): it becomes day if it is neither, and it enters transformed at night.
        if (! $faceDown && $object->printed()->back !== null && in_array('daybound', $object->printed()->keywords, true)) {
            $this->dayNight ??= 'day';
            $object->transformed = $this->dayNight === 'night';
        }
        $card = $object->definition();
        $object->controller = $controller;
        $object->sick = true;
        $object->tapped = $card->entersTapped || ($card->entersTappedUnless !== null && ! $this->entersUntapped($object, $controller));
        if ($card->isPlaneswalker() && $card->loyalty !== null) {
            $object->addCounters('loyalty', $card->loyalty);
        }
        $counters = ($card->entersWithCounters === 'X' ? $x : (int) $card->entersWithCounters) + ($kicked ? $card->kickerCounters : 0);
        if ($counters > 0) {
            $object->addCounters('+1/+1', $counters);
        }
        if ($card->entersWithMinusCounters > 0) {
            $object->addCounters('-1/-1', $card->entersWithMinusCounters);
        }
        if ($card->chooses !== null) {
            $object->chosen = $this->choiceFor($controller, $card->chooses);
            $this->log("{$this->players[$controller]->name} chooses {$object->chosen} for {$object->name()}.");
        }
        // Bloodthirst (rule 702.54): if an opponent was dealt damage this turn.
        foreach ($card->entersWithOther as $kind => $n) {
            $object->addCounters($kind, $n);
        }
        // Devour (rule 702.82): the game feeds it your creature tokens.
        if (($n = $this->keywordAmount($object, 'devour')) > 0) {
            $eaten = array_filter($this->permanents($controller), fn (GameObject $o) => $o->id !== $object->id && $o->definition()->isToken() && $this->isCreature($o));
            foreach ($eaten as $token) {
                $this->moveTo($token, GameObject::GRAVEYARD);
            }
            if ($eaten !== []) {
                $object->addCounters('+1/+1', $n * count($eaten));
                $this->log("{$object->name()} devours ".count($eaten).' creature'.(count($eaten) === 1 ? '' : 's').'.');
            }
        }
        if (($n = $this->keywordAmount($object, 'vanishing')) > 0) {
            $object->addCounters('time', $n);
        }
        if (($n = $this->keywordAmount($object, 'bloodthirst')) > 0) {
            foreach ($this->players as $seat => $player) {
                if ($seat !== $controller && $player->damagedOnTurn === $this->turn) {
                    $object->addCounters('+1/+1', $n);
                    break;
                }
            }
        }
        $this->battlefield[] = $object->id;
        if ($triggers) {
            $this->trigger($object, 'enters', $controller);
            // A Saga gets its first lore counter as it enters (rule 714.3a).
            if ($this->keywordAmount($object, 'saga') > 0) {
                $this->addLore($object);
            }
            // Evolve (rule 702.100): a bigger creature entering under your control. Graft: any other creature of yours.
            if ($this->isCreature($object)) {
                foreach ($this->permanents($controller) as $permanent) {
                    if ($permanent->id !== $object->id) {
                        $this->trigger($permanent, 'creature_enters_other', $controller);
                    }
                }
                foreach ($this->permanents($controller) as $permanent) {
                    if ($permanent->id !== $object->id && $this->hasKeyword($permanent, 'graft') && $permanent->counter('+1/+1') > 0) {
                        $this->trigger($permanent, 'graft', $controller, null, $object->id);
                    }
                }
                foreach ($this->permanents($controller) as $permanent) {
                    if ($permanent->id !== $object->id && $this->hasKeyword($permanent, 'evolve') && $this->isCreature($permanent)
                        && ($this->power($object) > $this->power($permanent) || $this->toughness($object) > $this->toughness($permanent))) {
                        $this->trigger($permanent, 'evolve', $controller);
                    }
                }
            }
            if ($card->isLand()) {
                foreach ($this->permanents($controller) as $permanent) {
                    $this->trigger($permanent, 'landfall', $controller);
                }
            }
        }
    }

    /**
     * What a player chooses as a permanent enters: the creature type most
     * common among their creature cards, or the color most common in their
     * cards' mana costs.
     *
     * @param int    $seat
     * @param string $kind `type` or `color`.
     *
     * @return string A creature type, or a color letter.
     */
    private function choiceFor(int $seat, string $kind): string
    {
        $counts = [];
        foreach ($this->objects as $object) {
            if ($object->owner !== $seat || $object->definition()->isToken()) {
                continue;
            }
            $card = $object->printed();
            if ($kind === 'type') {
                if ($card->isCreature()) {
                    foreach ($card->subtypes as $subtype) {
                        $counts[$subtype] = ($counts[$subtype] ?? 0) + 1;
                    }
                }
            } else {
                preg_match_all('/[WUBRG]/', (string) ($card->card['manaCost'] ?? ''), $symbols);
                foreach ($symbols[0] as $color) {
                    $counts[$color] = ($counts[$color] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return (string) (array_key_first($counts) ?? ($kind === 'type' ? 'Human' : 'W'));
    }

    /**
     * Whether a land that enters tapped unless something is true enters
     * untapped. The game pays the life for the player only on their own
     * turn, when they have more than 10 life.
     *
     * @param GameObject $object
     * @param int        $controller
     *
     * @return bool
     */
    private function entersUntapped(GameObject $object, int $controller): bool
    {
        $unless = $object->definition()->entersTappedUnless;
        if (isset($unless['player_life'])) {
            return min(array_map(fn (GamePlayer $player) => $player->life, $this->players)) <= $unless['player_life'];
        }
        if (isset($unless['life'])) {
            if ($this->active !== $controller || $this->players[$controller]->life <= 10) {
                return false;
            }
            $this->players[$controller]->life -= $unless['life'];
            $this->log("{$this->players[$controller]->name} pays {$unless['life']} life so {$object->name()} enters untapped.");

            return true;
        }
        $others = array_filter($this->permanents($controller), fn (GameObject $o) => $o->id !== $object->id);
        $lands = count(array_filter($others, fn (GameObject $o) => $o->definition()->isLand()));
        if (isset($unless['lands_min']) || isset($unless['lands_max'])) {
            return $lands >= ($unless['lands_min'] ?? 0) && $lands <= ($unless['lands_max'] ?? PHP_INT_MAX);
        }
        foreach ($others as $other) {
            $card = $other->definition();
            foreach ($unless['any'] ?? [] as $noun) {
                $type = ucwords($noun);
                if (match ($noun) {
                    'basic land' => $card->isLand() && in_array('Basic', $card->supertypes, true),
                    'legendary creature' => $this->isCreature($other) && $card->isLegendary(),
                    default => $card->is($type) || in_array($type, $card->subtypes, true) || ($type === 'Creature' && $this->isCreature($other)),
                }) {
                    return true;
                }
            }
        }

        return false;
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
        if ($object->unearthed && $object->zone === GameObject::BATTLEFIELD && $zone !== GameObject::BATTLEFIELD) {
            $zone = GameObject::EXILE;
        }
        if ($object->exileIfDies === $this->turn && $object->zone === GameObject::BATTLEFIELD && $zone === GameObject::GRAVEYARD && $this->isCreature($object)) {
            $zone = GameObject::EXILE;
        }
        // A disturbed back face: `If CARDNAME would be put into a graveyard from anywhere, exile it instead.`
        if ($zone === GameObject::GRAVEYARD && in_array('exile instead of graveyard', $object->definition()->keywords, true)) {
            $zone = GameObject::EXILE;
        }
        $toGraveyard = $object->zone === GameObject::BATTLEFIELD && $zone === GameObject::GRAVEYARD;
        $dies = $object->zone === GameObject::BATTLEFIELD && $zone === GameObject::GRAVEYARD && $this->isCreature($object);
        $plusCounters = $object->counter('+1/+1');
        // Persist and undying (rules 702.79 and 702.93) look at its counters as it died.
        $returns = ! $dies ? null : match (true) {
            $this->hasKeyword($object, 'persist') && $object->counter('-1/-1') === 0 => '-1/-1',
            $this->hasKeyword($object, 'undying') && $object->counter('+1/+1') === 0 => '+1/+1',
            default => null,
        };
        if ($object->definition()->isToken()) {
            $zone = GameObject::GONE;
        }

        $held = $object->zone === GameObject::BATTLEFIELD && $zone !== GameObject::BATTLEFIELD ? $object->holding : [];
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
            $this->trigger($object, 'dies', $controller, $incarnation, $plusCounters);
        }
        if ($toGraveyard) {
            $this->trigger($object, 'to_graveyard', $controller, $incarnation);
        }
        // What it exiled "until this leaves the battlefield" returns.
        foreach ($held as $id) {
            if (isset($this->objects[$id]) && $this->objects[$id]->zone === GameObject::EXILE) {
                $this->putOntoBattlefield($this->objects[$id], $this->objects[$id]->owner);
                $this->log("{$this->objects[$id]->name()} returns to the battlefield.");
            }
        }
        if ($returns !== null && $object->zone === GameObject::GRAVEYARD) {
            $this->putOntoBattlefield($object, $object->owner);
            $object->addCounters($returns, 1);
            $this->log("{$object->name()} returns to the battlefield with a {$returns} counter.");
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
        $this->updateSpeed();
        // Ascend (rule 702.131): ten or more permanents and one with ascend.
        foreach ($this->players as $seat => $player) {
            $permanents = $this->permanents($seat);
            if (! $player->blessed && count($permanents) >= 10 && array_filter($permanents, fn (GameObject $o) => in_array('ascend', $o->definition()->keywords, true)) !== []) {
                $player->blessed = true;
                $this->log("{$player->name} gets the city's blessing.");
            }
        }
        $this->updateControl();
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
                    } elseif (($object->damage >= $toughness || $object->deathtouched) && ! $this->hasKeyword($object, 'indestructible')) {
                        if ($this->regenerate($object) || $this->umbraArmor($object)) {
                            $changed = true;
                        } else {
                            $toGraveyard[$id] = "{$object->name()} dies";
                        }
                    }
                }
                if ($card->isPlaneswalker() && $object->counter('loyalty') <= 0) {
                    $toGraveyard[$id] = "{$object->name()} has no loyalty left";
                }
                // A Saga is sacrificed once its last chapter has resolved (rule 714.4).
                if (($last = $this->keywordAmount($object, 'saga')) > 0 && $object->counter('lore') >= $last && ! $this->waitsOn($object)) {
                    $toGraveyard[$id] = "{$object->name()} is sacrificed after its last chapter";
                }
                // An Aura on a player stays while that player is in the game, and the game ends when either one leaves.
                if ($card->isAura() && $object->enchantedPlayer === null) {
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

    /**
     * Ends the game as a draw from outside it, as when a tournament round
     * runs out of time (rule 104.4b). A game already over is left as it is.
     *
     * @param string $reason For the log.
     *
     * @return void
     */
    public function endInDraw(string $reason): void
    {
        if ($this->stage === self::OVER) {
            return;
        }
        $this->stage = self::OVER;
        $this->priority = null;
        $this->winner = null;
        $this->log("{$reason} The game is a draw.");
        $this->recordPosition();
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
        // A bestowed Aura is not a creature while it is attached (rule 702.103b).
        if ($object->bestowed && $object->attachedTo !== null) {
            return false;
        }
        // An attached Equipment creature, by reconfigure, is not a creature (rule 702.151b).
        if ($object->attachedTo !== null && $object->definition()->isEquipment()) {
            return false;
        }
        // A Spacecraft with a power and toughness is an artifact creature at its last station threshold.
        $bands = $object->definition()->stationBands;
        if ($bands !== [] && $object->definition()->power !== null && $object->zone === GameObject::BATTLEFIELD && $object->counter('charge') >= max(array_column($bands, 'min'))) {
            return true;
        }
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
     * `You control enchanted creature.`: its controller is the Aura's while
     * the Aura is on it, and goes back to its owner after.
     *
     * @return void
     */
    private function updateControl(): void
    {
        foreach ($this->permanents() as $object) {
            if ($object->stolenBy !== null) {
                $aura = $this->objects[$object->stolenBy] ?? null;
                if ($aura === null || $aura->zone !== GameObject::BATTLEFIELD || $aura->attachedTo !== $object->id) {
                    $object->stolenBy = null;
                    $object->controller = $object->owner;
                    $object->sick = true;
                    $this->log("{$object->name()} returns to {$this->players[$object->owner]->name}'s control.");
                }
            }
        }
        foreach ($this->permanents() as $aura) {
            $stolen = $aura->attachedTo === null ? null : ($this->objects[$aura->attachedTo] ?? null);
            if (($aura->definition()->aura['control'] ?? false) && $stolen !== null && $stolen->zone === GameObject::BATTLEFIELD && $stolen->controller !== $aura->controller) {
                $stolen->controller = $aura->controller;
                $stolen->stolenBy = $aura->id;
                $stolen->sick = true;
                $this->log("{$this->players[$aura->controller]->name} gains control of {$stolen->name()}.");
            }
        }
    }

    /**
     * Speed (rule 702.179): it becomes 1 when a permanent with "Start your
     * engines!" is yours, then goes up by one, to at most 4, the first time
     * in each of your turns that an opponent loses life.
     *
     * @return void
     */
    private function updateSpeed(): void
    {
        foreach ($this->players as $seat => $player) {
            if ($player->speed === 0 && array_filter($this->permanents($seat), fn (GameObject $o) => in_array('start your engines', $o->definition()->keywords, true)) !== []) {
                $player->speed = 1;
                $this->log("{$player->name}'s speed is 1.");
            }
        }
        $player = $this->players[$this->active] ?? null;
        if ($player === null || $player->speed === 0 || $player->speed >= 4 || $player->speedTurn === $this->turn) {
            return;
        }
        foreach ($this->players as $seat => $opponent) {
            if ($seat !== $this->active && $opponent->life < $opponent->lifeMark) {
                $player->speed++;
                $player->speedTurn = $this->turn;
                $this->log("{$player->name}'s speed is {$player->speed}".($player->speed === 4 ? ' (max speed)' : '').'.');

                return;
            }
        }
    }

    /**
     * What a permanent's `Max speed — CARDNAME gets …/has …` gives it now.
     *
     * @param  GameObject  $object
     * @return array{power: int, toughness: int, keywords: string[]}|null
     */
    private function maxSpeedBonus(GameObject $object): ?array
    {
        $bonus = $object->definition()->maxSpeed;

        return $bonus !== null && $object->zone === GameObject::BATTLEFIELD && $this->players[$object->controller]->speed >= 4 ? $bonus : null;
    }

    /**
     * Whether a permanent has an ability yet: a Class at its level, a
     * Spacecraft with enough charge counters.
     *
     * @param GameObject $object
     * @param array      $ability
     *
     * @return bool
     */
    private function gained(GameObject $object, array $ability): bool
    {
        return ($ability['classLevel'] ?? 1) <= $this->classLevel($object) && ($ability['charge'] ?? 0) <= $object->counter('charge')
            && (! ($ability['maxSpeed'] ?? false) || $this->players[$object->controller]->speed >= 4);
    }

    /**
     * The creature tapped to station a Spacecraft: the other untapped
     * creature with the most power that could not attack this turn, or else
     * the one with the most power.
     *
     * @param GameObject $spacecraft
     *
     * @return GameObject|null
     */
    private function stationCrew(GameObject $spacecraft): ?GameObject
    {
        $crew = array_values(array_filter($this->permanents($spacecraft->controller), fn (GameObject $o) => $o->id !== $spacecraft->id && $this->isCreature($o) && ! $o->tapped && $this->power($o) > 0));
        usort($crew, fn (GameObject $a, GameObject $b) => [$b->sick && ! $this->hasKeyword($b, 'haste'), $this->power($b)] <=> [$a->sick && ! $this->hasKeyword($a, 'haste'), $this->power($a)]);

        return $crew[0] ?? null;
    }

    /**
     * A Class's level (rule 716.3): 1 until it gains another.
     *
     * @param GameObject $object
     *
     * @return int
     */
    private function classLevel(GameObject $object): int
    {
        return max(1, $object->counter('class'));
    }

    /**
     * What an Aura, Equipment or bestowed Aura gives the permanent it is attached to.
     *
     * @param GameObject $attached
     *
     * @return array{power: int, toughness: int, keywords: string[]}|null
     */
    private function bonusOf(GameObject $attached): ?array
    {
        return $attached->bestowed ? $attached->definition()->bestow : $attached->definition()->attachmentBonus();
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
        return array_values(array_filter($this->permanents(), fn (GameObject $attached) => $attached->attachedTo === $object->id && $this->bonusOf($attached) !== null));
    }

    /**
     * `Its power and toughness are each equal to the number of lands you control.`
     *
     * @param GameObject $object
     * @param string     $stat   `power` or `toughness`.
     *
     * @return int|null
     */
    /**
     * Prototype (rule 702.160): the smaller power and toughness when cast for its prototype cost.
     *
     * @param GameObject $object
     *
     * @return array{0: int, 1: int}|array{}
     */
    private function prototype(GameObject $object): array
    {
        if ($object->alt !== 'prototype') {
            return [];
        }
        foreach ($object->definition()->keywords as $keyword) {
            if (preg_match('/^prototype (\d+)\/(\d+)$/', $keyword, $m)) {
                return [(int) $m[1], (int) $m[2]];
            }
        }

        return [];
    }

    /**
     * `CARDNAME gets +1/+1 for each artifact you control.`
     *
     * @param GameObject $object
     *
     * @return array{0: int, 1: int}
     */
    private function scaling(GameObject $object): array
    {
        $bonus = [0, 0];
        if ($object->zone !== GameObject::BATTLEFIELD) {
            return $bonus;
        }
        foreach ($object->definition()->keywords as $keyword) {
            if (preg_match('/^gets \+(\d+)\/\+(\d+) for each (.+)$/', $keyword, $m)) {
                $n = $this->countYours($object->controller, $m[3], $object);
                $bonus = [$bonus[0] + (int) $m[1] * $n, $bonus[1] + (int) $m[2] * $n];
            }
        }

        return $bonus;
    }

    /**
     * How many of a kind of permanent a player controls, for `for each artifact you control` and the like.
     *
     * @param int             $seat
     * @param string          $kind   artifact, creature, other creature, land or enchantment.
     * @param GameObject|null $source Left out of `other creature`.
     *
     * @return int
     */
    private function countYours(int $seat, string $kind, ?GameObject $source = null): int
    {
        return count(array_filter($this->permanents($seat), fn (GameObject $o) => match ($kind) {
            'artifact' => $o->definition()->is('Artifact'),
            'creature' => $this->isCreature($o),
            'other creature' => $o->id !== $source?->id && $this->isCreature($o),
            'land' => $o->definition()->isLand(),
            'enchantment' => $o->definition()->is('Enchantment'),
            default => false,
        }));
    }

    /**
     * The size of a player's party (rule 700.8): up to one each of Cleric, Rogue, Warrior and Wizard among their creatures.
     *
     * @param int $seat
     *
     * @return int
     */
    public function partySize(int $seat): int
    {
        $roles = ['Cleric', 'Rogue', 'Warrior', 'Wizard'];
        $creatures = array_map(fn (GameObject $o) => array_values(array_intersect($roles, $this->hasKeyword($o, 'changeling') ? $roles : $o->definition()->subtypes)), array_values(array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o))));
        $best = 0;
        // Try every assignment of creatures to roles; parties are at most four.
        $assign = function (int $i, array $used) use (&$assign, &$best, $creatures): void {
            $best = max($best, count($used));
            if ($best === 4 || $i >= count($creatures)) {
                return;
            }
            $assign($i + 1, $used);
            foreach ($creatures[$i] as $role) {
                if (! isset($used[$role])) {
                    $assign($i + 1, $used + [$role => true]);
                }
            }
        };
        $assign(0, []);

        return $best;
    }

    private function counted(GameObject $object, string $stat): ?int
    {
        $counts = $object->definition()->countsAs;
        if ($counts === null || ! $counts[$stat] || $object->zone !== GameObject::BATTLEFIELD) {
            return null;
        }
        $player = $this->players[$object->controller];
        switch ($counts['of']) {
            case 'hand':
                return count($player->hand);
            case 'graveyard':
                return count($player->graveyard);
            case 'creature cards in graveyard':
                return count(array_filter($player->graveyard, fn (int $id) => $this->objects[$id]->definition()->is('Creature')));
        }

        return count(array_filter($this->permanents($object->controller), fn (GameObject $o) => match ($counts['of']) {
            'lands' => $o->definition()->isLand(),
            'creatures' => $this->isCreature($o),
            'artifacts' => $o->definition()->is('Artifact'),
            'enchantments' => $o->definition()->is('Enchantment'),
            default => in_array(ucfirst(rtrim($counts['of'], 's')), $o->definition()->subtypes, true) || ($counts['of'] === 'plains' && in_array('Plains', $o->definition()->subtypes, true)),
        }));
    }

    public function power(GameObject $object): int
    {
        $power = ($this->levelBand($object)['power'] ?? $this->counted($object, 'power') ?? $this->prototype($object)[0] ?? $object->definition()->power ?? 0) + $object->counter('+1/+1') - $object->counter('-1/-1');
        $power += $this->maxSpeedBonus($object)['power'] ?? 0;
        $power += $this->scaling($object)[0];
        foreach ($this->attachments($object) as $attached) {
            $power += $this->bonusOf($attached)['power'];
        }
        foreach ($object->untilEndOfTurn as $effect) {
            $power += $effect['power'];
        }
        foreach ($this->anthems($object) as $anthem) {
            $power += $anthem['power'];
        }

        return $power;
    }

    public function toughness(GameObject $object): int
    {
        $toughness = ($this->levelBand($object)['toughness'] ?? $this->counted($object, 'toughness') ?? $this->prototype($object)[1] ?? $object->definition()->toughness ?? 0) + $object->counter('+1/+1') - $object->counter('-1/-1');
        $toughness += $this->maxSpeedBonus($object)['toughness'] ?? 0;
        $toughness += $this->scaling($object)[1];
        foreach ($this->attachments($object) as $attached) {
            $toughness += $this->bonusOf($attached)['toughness'];
        }
        foreach ($object->untilEndOfTurn as $effect) {
            $toughness += $effect['toughness'];
        }
        foreach ($this->anthems($object) as $anthem) {
            $toughness += $anthem['toughness'];
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
            array_push($keywords, ...($this->maxSpeedBonus($object)['keywords'] ?? []));
            if ($this->active === $object->controller) {
                array_push($keywords, ...$object->definition()->yourTurnKeywords);
            }
            if ($object->alt === 'dash' || ($object->alt === 'suspend' && $this->isCreature($object))) {
                $keywords[] = 'haste';
            }
            foreach ($object->definition()->stationBands as $band) {
                if ($object->counter('charge') >= $band['min']) {
                    array_push($keywords, ...$band['keywords']);
                }
            }
            foreach ($this->attachments($object) as $attached) {
                array_push($keywords, ...$this->bonusOf($attached)['keywords']);
            }
            foreach ($object->untilEndOfTurn as $effect) {
                array_push($keywords, ...$effect['keywords']);
            }
            foreach ($this->anthems($object) as $anthem) {
                array_push($keywords, ...$anthem['keywords']);
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * The static bonuses its controller's permanents give it, as a creature
     * on the battlefield (`Creatures you control get +1/+1`).
     *
     * @param GameObject $object
     *
     * @return array[]
     */
    private function anthems(GameObject $object): array
    {
        if ($object->zone !== GameObject::BATTLEFIELD) {
            return [];
        }
        $anthems = [];
        foreach ($this->battlefield as $id) {
            $source = $this->objects[$id];
            // "Creatures enchanted player controls get -1/-1."
            if ($source->enchantedPlayer === $object->controller) {
                array_push($anthems, ...array_filter($source->definition()->anthem, fn (array $anthem) => $anthem['enchantedPlayer'] ?? false));
            }
            if ($source->controller !== $object->controller || $source->faceDown) {
                continue;
            }
            foreach ($source->definition()->anthem as $anthem) {
                if (($anthem['enchantedPlayer'] ?? false) || ! $this->gained($source, $anthem) || (($anthem['withCounter'] ?? false) && $object->counter('+1/+1') === 0)) {
                    continue;
                }
                if (($anthem['chosenType'] ?? false) && ! in_array($source->chosen, $object->definition()->subtypes, true) && ! in_array('changeling', $object->definition()->keywords, true)) {
                    continue;
                }
                if (! ($anthem['other'] && $source->id === $object->id)) {
                    $anthems[] = $anthem;
                }
            }
        }
        if ($anthems !== [] && ! $this->isCreature($object)) {
            return [];
        }

        return $anthems;
    }

    /**
     * The N of keywords such as `toxic 2` and `bushido 1`, added up.
     *
     * @param GameObject $object
     * @param string     $keyword
     *
     * @return int
     */
    private function keywordAmount(GameObject $object, string $keyword): int
    {
        $total = 0;
        foreach ($this->keywords($object) as $word) {
            if (preg_match('/^'.preg_quote($keyword, '/').' (\d+)$/', $word, $m)) {
                $total += (int) $m[1];
            }
        }

        return $total;
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
            'fogTurn' => $this->fogTurn,
            'spellsCast' => $this->spellsCast,
            'spellsTurn' => $this->spellsTurn,
            'spellsBefore' => $this->spellsBefore,
            'dayNight' => $this->dayNight,
            'prevent' => $this->prevent,
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
            'fromHand' => (bool) ($item['fromHand'] ?? false),
            'bestowed' => (bool) ($item['bestowed'] ?? false),
            'alt' => (string) ($item['alt'] ?? ''),
            'sunburst' => (int) ($item['sunburst'] ?? 0),
            'escaped' => (bool) ($item['escaped'] ?? false),
            'disturbed' => (bool) ($item['disturbed'] ?? false),
        ])), (array) $data['stack']));
        $game->pendingTriggers = array_values(array_map(fn ($trigger) => [
            'source' => (int) $trigger['source'],
            'incarnation' => (int) $trigger['incarnation'],
            'controller' => (int) $trigger['controller'],
            'effects' => array_values((array) $trigger['effects']),
            'kinds' => array_values(array_map('strval', (array) $trigger['kinds'])),
            'label' => (string) $trigger['label'],
            'text' => (string) ($trigger['text'] ?? ''),
        ] + (isset($trigger['modes']) ? ['modes' => array_values((array) $trigger['modes']), 'choose' => (array) $trigger['choose']] : []), (array) ($data['pendingTriggers'] ?? [])));
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
        $game->fogTurn = (int) ($data['fogTurn'] ?? 0);
        $game->spellsCast = (int) ($data['spellsCast'] ?? 0);
        $game->spellsTurn = (int) ($data['spellsTurn'] ?? 0);
        $game->spellsBefore = (int) ($data['spellsBefore'] ?? 0);
        $game->dayNight = isset($data['dayNight']) ? (string) $data['dayNight'] : null;
        $game->prevent = array_map('intval', (array) ($data['prevent'] ?? []));

        return $game;
    }
}
