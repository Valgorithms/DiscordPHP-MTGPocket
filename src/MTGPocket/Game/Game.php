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
     * A rules decision, including an as-enters life payment. Resolution
     * waits for the responsible player; `resume` stores the remaining steps.
     *
     * @var array{type: string, seat: int, cards?: int[], id?: int, options?: string[], resume?: array}|null
     */
    public ?array $pendingChoice = null;

    /**
     * Persisted rules-state format; unversioned records are version 1.
     * Version 11 adds optional source untap; version 10 added optional source counters.
     */
    public const int STATE_VERSION = 12;

    /** @var array<int, array> Entries waiting for an as-enters life choice. */
    private array $pendingEntries = [];

    /** Monotonic identity prevents an old panel from answering a later choice. */
    private int $nextChoiceId = 1;

    /** Target kinds for cards in a graveyard. */
    private const array GRAVEYARD_KINDS = ['creature_card_yours', 'legendary_creature_card_yours', 'card_yours', 'card_graveyard_nonbasic', 'instant_sorcery_card_yours', 'artifact_card_yours', 'land_card_yours', 'enchantment_card_yours', 'permanent_card_yours', 'creature_card_graveyard', 'card_graveyard', 'card_graveyard_opponent', 'creature_card_graveyard_opponent'];

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
    public const array SPECIAL_PLAYS = ['cycle', 'unearth', 'plot', 'suspend', 'ninjutsu', 'regrow', 'foretell', 'eternalize', 'embalm', 'scavenge', 'transmute', 'bloodrush'];

    /** @var array<int, true> Attacking creatures. */
    public array $attackers = [];

    /** @var array<int, int> Blocking creature => the attacker it blocks, in declaration order. */
    public array $blockers = [];

    /** @var array<int, int[]> Blocking creature => the other attackers it blocks (`can block an additional creature each combat`). */
    public array $extraBlocks = [];

    /** @var array<int, true> Attackers that were blocked, even if their blockers have left. */
    public array $blocked = [];

    /** The turn in which all combat damage is prevented (`Prevent all combat damage that would be dealt this turn.`). */
    public int $fogTurn = 0;

    /** The turn in which damage can't be prevented (`Damage can't be prevented this turn.`). */
    public int $unpreventableTurn = 0;

    /** @var array<int, int> Combat damage each source dealt to players in this damage step, for `that many`; not saved, read as triggers are put on the stack. */
    private array $combatDealt = [];

    /** How many creatures attacked in the last attack declared (Windbrisk Heights). */
    public int $attackedWith = 0;

    /** @var array<string, string> A card returned to the battlefield, by its old target, for `It gains haste …` after it. */
    private array $followed = [];

    /** @var array<string, int> Damage to prevent this turn, by target (`p:SEAT` or `o:ID`) (rule 615). */
    public array $prevent = [];

    /** @var array<int, array{seat: int, cost: string, turn: int, name: string, lose: bool}> The Pacts: pay at its caster's next upkeep or lose the game. */
    public array $pacts = [];

    /** @var list<array{createdTurn: int, source: int, incarnation: int, controller: int, label: string}> One-card draws waiting for the next turn's upkeep. */
    public array $delayedDraws = [];

    /** @var int[] Permanents with "Whenever CARDNAME becomes tapped" seen tapped at the last check. */
    public array $seenTapped = [];

    /** @var array<int, int> +1/+1 counters on each permanent at the last check, by id, for `Whenever one or more +1/+1 counters are put on …`. */
    public array $seenCounters = [];

    /** @var array<string, int> Who gains life equal to the damage a shield in {@see $prevent} prevents, by target. */
    public array $preventGain = [];

    /** Creatures that died in turn `$diedTurn`, for `if N or more creatures died this turn`. */
    public int $died = 0;

    public int $diedTurn = 0;

    /** The last turn a nonland permanent left the battlefield, and the last turn a spell was cast for its warp cost, for Void. */
    public int $nonlandLeftTurn = 0;

    public int $warpedTurn = 0;

    /** The power the creature dying right now last had on the battlefield, for `if it had power 3 or greater`; only read while its dies triggers are queued. */
    private int $diedPower = 0;

    /** Whether the creature dying right now last had a +1/+1 counter on the battlefield; read like {@see $diedPower}. */
    private bool $diedWithCounter = false;

    /** Spells cast in turn `$spellsTurn`, by anyone, for storm (rule 702.40). */
    public int $spellsCast = 0;

    public int $spellsTurn = 0;

    /** Spells cast in the turn before `$spellsTurn`, when that was the turn before it. */
    public int $spellsBefore = 0;

    /** `day`, `night`, or null while it is neither (rule 726). */
    public ?string $dayNight = null;

    /** @var array<int, int> Seat => the last turn an Assassin or commander they controlled dealt combat damage to a player, for freerunning (rule 702.173). */
    public array $freerun = [];

    /** @var int[] Seats taking extra turns after this one, the next first (rule 500.7). */
    public array $extraTurns = [];

    /** @var array<int, true> Creatures that dealt first-strike damage this combat. */
    public array $struckFirst = [];

    /** @var array<int, int> Creatures to destroy at end of combat, by id, with the incarnation that was marked. */
    public array $destroyAtEndOfCombat = [];

    /** @var array<int, int> Decayed creatures that attacked, to sacrifice at end of combat, by id, with the incarnation that was marked. */
    public array $sacrificeAtEndOfCombat = [];

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

    /** Set by `End the turn.`; acted on once the spell finishes resolving, see {@see endTheTurn()}. */
    private bool $endingTurn = false;

    /** The creature type a resolving spell's `Choose a creature type.` picked. */
    private ?string $chosenType = null;

    /** The X {@see abilityPayment()} found for an ability with {X} in its cost. */
    private int $abilityX = 0;

    /** The spell whose casting is triggering abilities, for `copy that spell`; see {@see castTriggers()}. */
    private ?int $castSpell = null;

    /** Inside a `create N tokens` effect: `Whenever you create one or more tokens, …` waits for the whole batch. */
    private bool $creatingTokens = false;

    /** Synchronous death notification scope; never crosses a saved decision. */
    private ?DeathObserverBatch $deathObserverBatch = null;

    /** The cards the last mill put into a graveyard, for `You may put a land card from among them into your hand.` */
    private array $lastMilled = [];

    /** The spell a payment is for, while {@see paymentFor()} works it out. */
    private ?GameObject $spending = null;

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

    /**
     * `End the turn.` (rule 724): spells and abilities on the stack are
     * exiled, combat ends, and the turn goes straight to its cleanup step.
     *
     * @return void
     */
    private function endTheTurn(): void
    {
        $this->endingTurn = false;
        foreach (array_reverse($this->stack) as $item) {
            if (! self::isAbility($item) && ($this->objects[$item['object']] ?? null)?->zone === GameObject::STACK) {
                $this->moveTo($this->objects[$item['object']], GameObject::EXILE);
            }
        }
        [$this->stack, $this->pendingTriggers, $this->attackers, $this->blockers] = [[], [], [], []];
        $this->log('The turn ends.');
        $this->enterStep(Step::Cleanup);
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
            if ($this->endingTurn) {
                $this->endTheTurn();

                return;
            }
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
            if ($this->stage !== self::PLAYING || $this->priority === null || $this->pendingChoice !== null) {
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
            // The Ring, third level: `Whenever your Ring-bearer becomes blocked by a creature, that creature's controller sacrifices it at end of combat.`
            foreach ($this->blockers as $blocker => $attacker) {
                if ($this->ringLevel($this->objects[$attacker]) >= 3 && $this->objects[$blocker]->zone === GameObject::BATTLEFIELD) {
                    $this->log("The Ring: {$this->objects[$blocker]->name()} is sacrificed.");
                    $this->sacrifice($this->objects[$blocker]);
                }
            }
            // `… destroy that creature at end of combat.`
            foreach ($this->destroyAtEndOfCombat as $id => $incarnation) {
                if (($object = $this->objects[$id] ?? null) !== null && $object->incarnation === $incarnation) {
                    $this->destroy($object);
                }
            }
            $this->destroyAtEndOfCombat = [];
            // Decayed (rule 702.147a): `When this creature attacks, sacrifice it at end of combat.`
            foreach ($this->sacrificeAtEndOfCombat as $id => $incarnation) {
                if (($object = $this->objects[$id] ?? null) !== null && $object->incarnation === $incarnation && $object->zone === GameObject::BATTLEFIELD) {
                    $this->log(in_array('decayed', $object->definition()->keywords, true) ? "{$object->name()} decays." : "{$object->name()} is sacrificed at end of combat.");
                    $this->sacrifice($object);
                }
            }
            $this->sacrificeAtEndOfCombat = [];
            $this->attackers = $this->blockers = $this->extraBlocks = $this->blocked = $this->struckFirst = [];
        }
        $this->enterStep($this->step->next());
    }

    private function nextTurn(): void
    {
        $this->recordPosition();
        $this->turn++;
        // The most recently created extra turn is taken first (rule 500.7).
        $this->active = $this->extraTurns !== [] ? array_shift($this->extraTurns) : $this->opponent($this->active);
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
                // Goad lasts until the goading player's next turn.
                foreach ($this->battlefield as $id) {
                    if ($this->objects[$id]->goadedBy === $this->active) {
                        $this->objects[$id]->goadedBy = null;
                    }
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
                            'blitz' => $this->sacrifice($object),
                            'warp' => $this->moveTo($object, GameObject::EXILE),
                            'temporary' => $this->sacrifice($object),
                            'end_exile' => $this->moveTo($object, GameObject::EXILE),
                            default => null,
                        };
                        // Impending: a time counter off at the beginning of its controller's end step.
                        if ($alt === 'impending' && $object->controller === $this->active && $object->counter('time') > 0) {
                            $object->addCounters('time', -1);
                            $this->log("A time counter is removed from {$object->name()}".($object->counter('time') === 0 ? '; it is a creature again.' : '.'));
                        }
                        if ($alt === 'warp' && $object->zone === GameObject::EXILE) {
                            $object->exiledBy = 'warp';
                            $object->exiledOn = $this->turn;
                            $this->log("{$object->name()} is exiled (warp); it can be cast from exile on a later turn.");
                        }
                    }
                }
                if ($step === Step::Upkeep) {
                    $this->dayNightCheck();
                    $this->payPacts();
                    $this->triggerDelayedDraws();
                }
                foreach ($this->permanents($this->active) as $object) {
                    $this->trigger($object, $step === Step::Upkeep ? 'upkeep' : 'end_step', $object->controller);
                }
                // `At the beginning of each opponent's upkeep, …`
                foreach ($step === Step::Upkeep ? $this->permanents() : [] as $object) {
                    if ($object->controller !== $this->active) {
                        $this->trigger($object, 'opponent_upkeep', $object->controller, null, $this->active);
                    }
                }
                // "At the beginning of each upkeep, …": every turn's.
                foreach ($step === Step::Upkeep ? $this->permanents() : [] as $object) {
                    $this->trigger($object, 'each_upkeep', $object->controller, null, $this->active);
                }
                // "At the beginning of the end step, …": every turn's.
                foreach ($step === Step::End ? $this->permanents() : [] as $object) {
                    $this->trigger($object, 'each_end_step', $object->controller);
                }
                // `At the beginning of each opponent's end step, …`
                foreach ($step === Step::End ? $this->permanents() : [] as $object) {
                    if ($object->controller !== $this->active) {
                        $this->trigger($object, 'opponent_end_step', $object->controller, null, $this->active);
                    }
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
                // Necrodominance: `Skip your draw step.`
                if (! ($this->turn === 1 && $this->active === $this->startingPlayer) && array_filter($this->permanents($this->active), fn (GameObject $o) => $this->hasKeyword($o, 'skip your draw step')) === []) {
                    $this->draw($this->active, 1);
                }
                // "At the beginning of your draw step, …": after the draw (rule 504.1).
                foreach ($this->permanents($this->active) as $object) {
                    $this->trigger($object, 'draw_step', $object->controller);
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
                // Paradigm: a free copy of each paradigm card its owner has resolved, from exile.
                foreach ($this->exile as $id) {
                    $object = $this->objects[$id];
                    if ($object->exiledBy === 'paradigm' && $object->owner === $this->active && ($effects = $object->printed()->spellEffects()) !== []) {
                        $this->pendingTriggers[] = ['source' => $id, 'incarnation' => $object->incarnation, 'controller' => $this->active, 'effects' => $effects,
                            'kinds' => self::targetKindsOf($effects), 'label' => "A copy of {$object->name()}", 'text' => "A copy of {$object->name()} (paradigm)"];
                    }
                }
                break;

            case Step::BeginCombat:
                // "At the beginning of combat on your turn, …"
                foreach ($this->permanents($this->active) as $object) {
                    $this->trigger($object, 'begin_combat', $object->controller);
                }
                // "At the beginning of each combat, …": every turn's.
                foreach ($this->permanents() as $object) {
                    $this->trigger($object, 'each_combat', $object->controller);
                }
                break;

            case Step::PostcombatMain:
                // "At the beginning of your second main phase, …"
                foreach ($this->permanents($this->active) as $object) {
                    $this->trigger($object, 'second_main', $object->controller);
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
        $this->preventGain = [];
        foreach ($this->battlefield as $id) {
            $object = $this->objects[$id];
            $object->damage = 0;
            $object->deathtouched = false;
            $object->damagedBy = [];
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
        if ($ids === [] && ($this->pendingChoice['may'] ?? false)) {
            $count = 0;
        }
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
            if (isset($this->pendingChoice['connive'])) {
                [$id, $incarnation] = $this->pendingChoice['connive'];
                $this->connived($this->objects[$id]->incarnation === $incarnation ? $this->objects[$id] : null, $ids);
            }
            $this->discardCards($from, $ids);
            // `If you do, draw a card.`
            if ($ids !== [] && ($this->pendingChoice['draw'] ?? 0) > 0) {
                $this->draw($seat, $this->pendingChoice['draw']);
            }
        }
        $choice = $this->pendingChoice;
        if ($choice === null) {
            $this->cleanup();
        } elseif ($choice['next'] !== []) {
            // The next opponent chooses what they discard.
            $this->pendingChoice['seat'] = array_shift($this->pendingChoice['next']);
        } else {
            $this->pendingChoice = null;
            // `you may discard a card. When you do, …`: what discarding pays for comes first.
            $steps = $ids !== [] ? [...$choice['then'] ?? [], ...$choice['resume']['steps']] : $choice['resume']['steps'];
            $this->runSteps($choice['resume']['item'], $steps, $choice['resume']['self']);
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

    /**
     * Connive's last step: a +1/+1 counter on the creature if a nonland card is being discarded.
     *
     * @param GameObject|null $creature
     * @param int[]           $ids      The cards about to be discarded.
     *
     * @return void
     */
    private function connived(?GameObject $creature, array $ids): void
    {
        if ($creature !== null && $creature->zone === GameObject::BATTLEFIELD
            && array_filter($ids, fn (int $id) => ! $this->objects[$id]->definition()->isLand()) !== []) {
            $creature->addCounters('+1/+1', 1);
            $this->log("{$creature->name()} gets a +1/+1 counter.");
        }
    }

    private function discardCards(int $seat, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $discarded = implode(', ', array_map(fn ($id) => $this->objects[$id]->name(), $ids));
        $this->log("{$this->players[$seat]->name} discards {$discarded}.", $seat, "discard {$discarded}");
        // `Whenever you discard a card, …`
        foreach ($ids as $id) {
            foreach ($this->permanents($seat) as $permanent) {
                $this->trigger($permanent, 'you_discard', $seat);
            }
            $this->discardedBy($seat, $id);
        }
        // `Whenever you discard one or more cards, …`: once for them all.
        foreach ($this->permanents($seat) as $permanent) {
            $this->trigger($permanent, 'you_discard_batch', $seat);
        }
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
                // For mayhem: discarded this turn.
                [$object->exiledBy, $object->exiledOn] = ['discarded', $this->turn];
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
                // Splice onto Arcane: each card that could be spliced onto it, then all of them.
                $splicers = $how === 'morph' ? [] : $this->splicers($seat, $id);
                foreach (count($splicers) > 1 ? [...array_map(fn (int $splicer) => [$splicer], $splicers), $splicers] : ($splicers === [] ? [] : [$splicers]) as $set) {
                    if ($this->canCast($seat, $id, $spliced = implode(',', array_filter([$how, 'splice'.implode('+', $set)])))) {
                        $plays[] = ['id' => $id, 'how' => $spliced];
                    }
                }
            }
            if ($card->cycling !== null && $this->canCycle($seat, $id)) {
                $plays[] = ['id' => $id, 'how' => 'cycle'];
            }
            if (isset($card->altCosts['bloodrush']) && $this->whyNotBloodrush($seat, $id) === null) {
                $plays[] = ['id' => $id, 'how' => 'bloodrush'];
            }
            if (isset($card->altCosts['transmute']) && $this->whyNotTransmute($seat, $id) === null) {
                $plays[] = ['id' => $id, 'how' => 'transmute'];
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
        $top = end($this->players[$seat]->library);
        if ($top !== false && $this->playableFromTop($seat, $top)) {
            $card = $this->objects[$top]->printed();
            $ways = $card->isLand() ? ($this->canPlayLand($seat, $top) ? [''] : []) : array_filter(self::castWays($card, false), fn (string $how) => $this->canCast($seat, $top, $how));
            foreach ($ways as $how) {
                $plays[] = ['id' => $top, 'how' => $how];
            }
        }
        foreach ($this->players[$seat]->graveyard as $id) {
            $card = $this->objects[$id]->printed();
            if ($card->isLand() && $this->canPlayLand($seat, $id)) {
                $plays[] = ['id' => $id, 'how' => ''];
            }
            if ($card->flashback !== null) {
                foreach (self::castWays($card, true) as $how) {
                    if ($this->canCast($seat, $id, $how)) {
                        $plays[] = ['id' => $id, 'how' => $how];
                    }
                }
            }
            $disturb = isset($card->altCosts['disturb']) && $card->back !== null && ! $card->back->isAura();
            foreach (['mh' => isset($card->altCosts['mayhem']), 'hz' => isset($card->altCosts['harmonize']), 'es' => isset($card->altCosts['escape']), 'js' => in_array('jump-start', $card->keywords, true), 'rt' => in_array('retrace', $card->keywords, true), 'db' => $disturb] as $code => $has) {
                foreach ($has ? self::castWays($card, false) : [] as $how) {
                    if (! preg_match('/kick|dash|evoke|warp|morph|bestow|bargain|evidence|blitz|buyback|awaken|freerunning|surge|spectacle|cleave|mastery|webslinging|mutate/', $how) && $this->canCast($seat, $id, $how = implode(',', array_filter([$code, $how])))) {
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
            foreach (['eternalize', 'embalm', 'scavenge'] as $way) {
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
            } elseif (in_array($exiled->exiledBy, ['plot', 'warp', 'madness', 'suspended', 'impulse', 'foretell', 'cascade', 'airbend'], true) && $exiled->owner === $seat) {
                foreach (self::castWays($exiled->printed(), false) as $how) {
                    if (preg_match('/kick|dash|evoke|warp|morph|bestow|blitz|buyback|awaken|freerunning|surge|spectacle|cleave|mastery|webslinging|mutate/', $how)) {
                        continue;
                    }
                    $how = implode(',', array_filter([['plot' => 'pl', 'warp' => 'wx', 'madness' => 'md', 'suspended' => 'sp', 'impulse' => 'ix', 'foretell' => 'ft', 'cascade' => 'cc', 'airbend' => 'ab'][$exiled->exiledBy] ?? null, $how]));
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
                // Multikicker: twice or three times too.
                foreach (in_array('multikicker', $card->keywords, true) ? [2, 3] : [] as $times) {
                    $ways[] = implode(',', [...$base, "kick{$times}"]);
                }
            }
            if (in_array('bargain', $card->keywords, true)) {
                $ways[] = implode(',', [...$base, 'bargain']);
            }
            if (isset($card->altCosts['gift'])) {
                $ways[] = implode(',', [...$base, 'gift']);
            }
            if (isset($card->altCosts['evidence'])) {
                $ways[] = implode(',', [...$base, 'evidence']);
            }
            if (self::casualty($card) > 0) {
                $ways[] = implode(',', [...$base, 'casualty']);
            }
            if (in_array('conspire', $card->keywords, true)) {
                $ways[] = implode(',', [...$base, 'conspire']);
            }
            if (isset($card->altCosts['buyback'])) {
                $ways[] = implode(',', [...$base, 'buyback']);
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
        foreach (['dash', 'evoke', 'warp', 'overload', 'prototype', 'blitz', 'awaken', 'freerunning', 'surge', 'spectacle', 'cleave', 'mastery', 'miracle', 'sneak', 'emerge', 'webslinging', 'mutate', 'impending', 'conditional'] as $alt) {
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
     * @return array{modes: int[], kicked: bool, flashback: bool, faceDown: bool, rebound: bool, bestowed: bool, alt: string, exiled: string, entwined: bool, grave: string, bargained: bool, casualty: bool, buyback: bool, conspire: bool, kicks: int, gifted: bool, evidence: bool, splice: int[]}
     */
    public static function castOptions(string|array $how): array
    {
        $options = ['modes' => [], 'kicked' => false, 'flashback' => false, 'faceDown' => false, 'rebound' => false, 'bestowed' => false, 'alt' => '', 'exiled' => '', 'entwined' => false, 'grave' => '', 'bargained' => false, 'casualty' => false, 'buyback' => false, 'conspire' => false, 'kicks' => 0, 'gifted' => false, 'evidence' => false, 'splice' => []];
        if (is_array($how)) {
            return array_intersect_key($how, $options) + $options;
        }
        foreach (array_filter(explode(',', $how)) as $part) {
            if ($part === 'kick') {
                $options['kicked'] = true;
                $options['kicks'] = 1;
            } elseif (preg_match('/^kick([2-9])$/', $part, $kicks)) {
                $options['kicked'] = true;
                $options['kicks'] = (int) $kicks[1];
            } elseif ($part === 'fb') {
                $options['flashback'] = true;
            } elseif ($part === 'rb') {
                $options['rebound'] = true;
            } elseif ($part === 'bestow') {
                $options['bestowed'] = true;
            } elseif ($part === 'entwine') {
                $options['entwined'] = true;
            } elseif ($part === 'conspire') {
                $options['conspire'] = true;
            } elseif ($part === 'buyback') {
                $options['buyback'] = true;
            } elseif ($part === 'casualty') {
                // Casualty (rule 702.153): sacrifice a creature with enough power, and the spell is copied.
                $options['casualty'] = true;
            } elseif ($part === 'gift') {
                // Gift (rule 702.174): promised to an opponent; "if the gift was promised" reads as kicked.
                $options['kicked'] = $options['gifted'] = true;
            } elseif ($part === 'evidence') {
                // Collect evidence (rule 701.59): exile cards with enough total mana value from your graveyard; reads as kicked.
                $options['kicked'] = $options['evidence'] = true;
            } elseif ($part === 'bargain') {
                // Bargain (rule 702.166): like kicker, paid by sacrificing an artifact, enchantment or token.
                $options['kicked'] = $options['bargained'] = true;
            } elseif (in_array($part, ['es', 'js', 'rt', 'db', 'hz', 'mh'], true)) {
                // From the graveyard: escape, jump-start, retrace, disturb (transformed, rule 702.146) or harmonize.
                $options['grave'] = ['es' => 'escape', 'js' => 'jumpstart', 'rt' => 'retrace', 'db' => 'disturb', 'hz' => 'harmonize', 'mh' => 'mayhem'][$part];
            } elseif ($part === 'morph') {
                $options['faceDown'] = true;
            } elseif (in_array($part, ['dash', 'evoke', 'warp', 'overload', 'prototype', 'blitz', 'awaken', 'freerunning', 'surge', 'spectacle', 'cleave', 'mastery', 'miracle', 'sneak', 'emerge', 'webslinging', 'mutate', 'impending', 'conditional'], true)) {
                $options['alt'] = $part;
            } elseif (in_array($part, ['pl', 'wx', 'md', 'sp', 'ix', 'ft', 'cc', 'ab'], true)) {
                // Cast from exile after plotting it, after warp exiled it, discarded with madness, its last time counter removed, foretold, cascaded into, or airbent.
                $options['exiled'] = ['pl' => 'plot', 'wx' => 'warp', 'md' => 'madness', 'sp' => 'suspended', 'ix' => 'impulse', 'ft' => 'foretell', 'cc' => 'cascade', 'ab' => 'airbend'][$part];
            } elseif (preg_match('/^splice\d+(?:\+\d+)*$/', $part)) {
                // Splice onto Arcane (rule 702.47): the cards in hand spliced onto it.
                $options['splice'] = array_map('intval', explode('+', substr($part, 6)));
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
     * Leyline of Anticipation: `You may cast spells as though they had flash.`, or only creature spells.
     *
     * @param int            $seat
     * @param CardDefinition $card
     *
     * @return bool
     */
    private function flashGranted(int $seat, CardDefinition $card): bool
    {
        foreach ($this->permanents($seat) as $permanent) {
            if ($this->hasKeyword($permanent, 'spells have flash') || ($card->isCreature() && $this->hasKeyword($permanent, 'creature spells have flash'))) {
                return true;
            }
        }

        return false;
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

        return $object !== null && $object->zone === GameObject::EXILE && $object->exiledBy === 'impulse' && $object->owner === $seat && $this->turn <= $object->exiledOn && $this->turn >= $object->playableFrom;
    }

    /**
     * `You may play lands from the top of your library.` and `You may play
     * lands and cast spells from the top of your library.`: the top card,
     * while a permanent of the player's says so.
     *
     * @param int $seat
     * @param int $id
     *
     * @return bool
     */
    private function playableFromTop(int $seat, int $id): bool
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::LIBRARY || end($this->players[$seat]->library) !== $id) {
            return false;
        }
        $land = $object->printed()->isLand();

        $creature = ! $land && $object->printed()->isCreature();

        return array_filter($this->permanents($seat), fn (GameObject $o) => $this->hasKeyword($o, 'play from top') || ($land && $this->hasKeyword($o, 'lands from top')) || ($creature && $this->hasKeyword($o, 'creatures from top'))) !== [];
    }

    public function canPlayLand(int $seat, int $id): bool
    {
        return $this->whyNotPlayLand($seat, $id) === null;
    }

    private function whyNotPlayLand(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        // `You may play lands from your graveyard.`
        $fromGraveyard = $object !== null && in_array($id, $this->players[$seat]->graveyard, true)
            && array_filter($this->permanents($seat), fn (GameObject $o) => $this->hasKeyword($o, 'lands from graveyard')) !== [];
        if ($object === null || (! in_array($id, $this->players[$seat]->hand, true) && ! $this->impulsePlayable($seat, $id) && ! $fromGraveyard && ! $this->playableFromTop($seat, $id))) {
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
        // `Whenever you play a land, …`: played, not put onto the battlefield.
        foreach ($this->permanents($seat) as $permanent) {
            $this->trigger($permanent, 'you_play_land', $seat);
        }
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
        // `Your opponents can't cast spells during your turn.`
        foreach ($this->active !== $seat ? $this->permanents($this->active) : [] as $permanent) {
            if ($this->hasKeyword($permanent, 'opponents cast nothing on your turn')) {
                return "{$permanent->name()} stops you casting spells during {$this->players[$this->active]->name}'s turn.";
            }
        }
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
            if ($options['exiled'] === 'impulse' && $this->turn < $object->playableFrom) {
                return 'It can be played during your next turn.';
            }
            if (! in_array($options['exiled'], ['madness', 'suspended', 'impulse', 'cascade', 'airbend'], true) && $object->exiledOn >= $this->turn) {
                return 'It can be cast from exile on a later turn.';
            }
        } elseif ($object === null || ! (in_array($id, $player->hand, true) || in_array($id, $this->commandCards($seat), true) || $this->playableFromTop($seat, $id))) {
            return 'That card is not in your hand.';
        }
        $card = $object->printed();
        if ($options['alt'] !== '' && (! isset($card->altCosts[$options['alt']]) || $options['flashback'] || $options['rebound'] || $options['exiled'] !== '')) {
            return "{$card->name} cannot be cast for a {$options['alt']} cost that way.";
        }
        if ($card->isLand()) {
            return 'Lands are played, not cast.';
        }
        // Miracle: while its reveal trigger would be resolving, at any speed.
        if ($options['alt'] === 'miracle' && ($object->zone !== GameObject::HAND || $object->miracle !== $this->turn.':'.$this->step->name)) {
            return "{$card->name} can be cast for its miracle cost only right after it is drawn as your first card this turn.";
        }
        if ($options['alt'] === 'webslinging' && $this->webslinger($seat, $id) === null) {
            return "Web-slinging {$card->name} needs a tapped creature you control to return to your hand.";
        }
        if ($options['alt'] === 'emerge' && $this->emergeFodder($seat, $id) === null) {
            return "{$card->name} needs a creature to sacrifice to emerge.";
        }
        // Sneak: during the declare blockers step, returning an unblocked attacker, at any speed.
        if ($options['alt'] === 'sneak' && ($this->step !== Step::DeclareBlockers || $this->active !== $seat || $this->unblockedAttackers($seat) === [])) {
            return "{$card->name} can be cast for its sneak cost only during the declare blockers step, returning an unblocked attacker.";
        }
        if ($options['flashback'] && $card->flashback === null) {
            return "{$card->name} has no flashback.";
        }
        if ($options['kicks'] > 1 && ! in_array('multikicker', $card->keywords, true)) {
            return "{$card->name} can be kicked only once.";
        }
        if ($options['evidence'] && (! isset($card->altCosts['evidence']) || $this->evidence($seat, $id, (int) $card->altCosts['evidence']) === null)) {
            return "{$card->name} cannot collect evidence now.";
        }
        if ($options['kicked'] && ! $options['bargained'] && ! $options['gifted'] && ! $options['evidence'] && $card->kicker === null) {
            return "{$card->name} has no kicker.";
        }
        if ($options['gifted'] && ! isset($card->altCosts['gift'])) {
            return "{$card->name} has no gift.";
        }
        if ($options['bargained'] && (! in_array('bargain', $card->keywords, true) || $this->bargainFodder($seat, $card) === null)) {
            return "{$card->name} cannot be bargained now.";
        }
        if ($options['casualty'] && (self::casualty($card) === 0 || $this->casualtyFodder($seat, self::casualty($card)) === null)) {
            return "{$card->name} needs a creature with power ".self::casualty($card).' or greater to sacrifice.';
        }
        if ($options['conspire'] && (! in_array('conspire', $card->keywords, true) || $this->conspirators($seat, $card, $id) === null)) {
            return "{$card->name} needs two untapped creatures that share a color with it to conspire.";
        }
        // Spectacle (rule 702.137): an opponent lost life this turn.
        if ($options['alt'] === 'spectacle' && array_filter($this->players, fn (GamePlayer $other, int $otherSeat) => $otherSeat !== $seat && $other->lostLifeOnTurn === $this->turn, ARRAY_FILTER_USE_BOTH) === []) {
            return 'Spectacle needs an opponent to have lost life this turn.';
        }
        // `If you control a Swamp, you may pay 4 life rather than pay CARDNAME's mana cost.`
        if ($options['alt'] === 'conditional') {
            foreach ($card->keywords as $keyword) {
                if (preg_match('/^conditional control (\w+)$/', $keyword, $m) && array_filter($this->permanents($seat), fn (GameObject $o) => in_array($m[1], $o->definition()->subtypes, true)) === []) {
                    return "{$card->name} needs you to control a {$m[1]}.";
                }
                if ($keyword === 'conditional not your turn' && $this->active === $seat) {
                    return "{$card->name} can be cast this way only when it isn't your turn.";
                }
            }
            if (preg_grep('/^conditional (exile|sacrifice) /', $card->keywords) !== [] && $this->pitchFodder($seat, $id, $card) === null) {
                return "{$card->name} needs a card of its color to exile or sacrifice.";
            }
        }
        // Surge (rule 702.117): you've cast another spell this turn.
        if ($options['alt'] === 'surge' && ($player->castTurn !== $this->turn || $player->cast === 0)) {
            return 'Surge needs you to have cast another spell this turn.';
        }
        if ($options['alt'] === 'freerunning' && ($this->freerun[$seat] ?? 0) !== $this->turn) {
            return 'Freerunning needs an Assassin or your commander to have dealt combat damage to a player this turn.';
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
        if ($options['entwined'] && isset($card->altCosts['teamwork']) && $this->teamworkFodder($seat, (int) $card->altCosts['teamwork']) === null) {
            return "Teamwork needs untapped creatures with total power {$card->altCosts['teamwork']} or more.";
        }
        if ($options['splice'] !== [] && ($why = $this->whyNotSplice($seat, $id, $card, $options)) !== null) {
            return $why;
        }
        // Fuse (rule 702.102): both halves only from its owner's hand.
        if ($card->split && count(array_unique($options['modes'])) > 1 && $object?->zone !== GameObject::HAND) {
            return "{$card->name}'s halves can be fused only from your hand.";
        }
        // `You may choose the same mode more than once.`: repeats count, in any order.
        $chosen = array_map('intval', $options['modes']);
        if ($card->choose['repeat'] ?? false) {
            sort($chosen);
        } else {
            $chosen = array_values(array_unique($chosen));
        }
        if (! $options['faceDown'] && ! $options['entwined'] && ! in_array($chosen, $card->modeChoices(), true)) {
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
        if (! $options['faceDown'] && $this->orPayKeyword($card) === null && $card->additionalCost === 'sacrifice_creature' && array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o)) === []) {
            return "{$card->name} needs a creature to sacrifice.";
        }
        if (! $options['faceDown'] && $this->orPayKeyword($card) === null && $card->additionalCost === 'sacrifice_artifact' && array_filter($this->permanents($seat), fn (GameObject $o) => $o->definition()->is('Artifact')) === []) {
            return "{$card->name} needs an artifact to sacrifice.";
        }
        if (! $options['faceDown'] && $this->orPayKeyword($card) === null && $card->additionalCost === 'sacrifice_artifact_or_creature'
            && array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o) || $o->definition()->is('Artifact')) === []) {
            return "{$card->name} needs an artifact or creature to sacrifice.";
        }
        if (! $options['faceDown'] && $this->orPayKeyword($card) === null && $card->additionalCost === 'discard' && count($player->hand) < 2) {
            return "{$card->name} needs another card in your hand to discard.";
        }
        if (! $options['faceDown'] && $this->orPayKeyword($card) === null && in_array($card->additionalCost, ['exile_creature_card', 'exile_card'], true) && $this->graveyardFodder($seat, $card, $id) === []) {
            return "{$card->name} needs ".($card->additionalCost === 'exile_card' ? 'another card' : 'a creature card').' in your graveyard to exile.';
        }
        // Face down it is a 2/2 creature spell with no abilities, flash included.
        // A plotted card is cast as a sorcery (rule 702.170d).
        // Madness casts it whenever its trigger waits (rule 702.35c).
        // Cascade casts it while its trigger waits (rule 702.85a).
        if (! $options['rebound'] && ! in_array($options['alt'], ['miracle', 'sneak'], true) && ! in_array($options['exiled'], ['madness', 'suspended', 'cascade'], true) && ($options['faceDown'] || ! ($card->hasInstantSpeed() || $this->flashGranted($seat, $card)) || $options['exiled'] === 'plot') && ! $this->sorcerySpeed($seat)) {
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
        return self::castTargetKinds($card, $options, $this);
    }

    /**
     * Why cards cannot be spliced onto a spell, or null when they can: each
     * a different card with splice onto Arcane in its caster's hand, onto an
     * Arcane spell cast face up.
     *
     * @param int            $seat
     * @param int            $id
     * @param CardDefinition $card
     * @param array          $options
     *
     * @return ?string
     */
    private function whyNotSplice(int $seat, int $id, CardDefinition $card, array $options): ?string
    {
        if (! in_array('Arcane', $card->subtypes, true) || $options['faceDown']) {
            return "Only Arcane spells can be spliced onto, and {$card->name} isn't one.";
        }
        if (count(array_unique($options['splice'])) !== count($options['splice'])) {
            return 'Each card can be spliced onto a spell only once.';
        }
        foreach ($options['splice'] as $splicer) {
            $object = $this->objects[$splicer] ?? null;
            if ($splicer === $id || $object === null || ! in_array($splicer, $this->players[$seat]->hand, true) || ! isset($object->printed()->altCosts['splice']) || $object->printed()->spellEffects() === []) {
                return 'Only other cards with splice onto Arcane in your hand can be spliced onto it.';
            }
        }

        return null;
    }

    /**
     * The cards in a player's hand that could be spliced onto a spell.
     *
     * @param int $seat
     * @param int $id   The spell.
     *
     * @return int[]
     */
    private function splicers(int $seat, int $id): array
    {
        if (! in_array('Arcane', $this->objects[$id]->printed()->subtypes, true)) {
            return [];
        }

        return array_values(array_filter($this->players[$seat]->hand, fn (int $card) => $card !== $id && isset($this->objects[$card]->printed()->altCosts['splice']) && $this->objects[$card]->printed()->spellEffects() !== []));
    }

    /**
     * The card a spell's `you may reveal a Dragon card from your hand` cost
     * reveals (or the Dragon its caster controls, for `or controlled a
     * Dragon`), or null.
     *
     * @param int            $seat
     * @param int            $id
     * @param CardDefinition $card
     *
     * @return ?GameObject
     */
    private function revealed(int $seat, int $id, CardDefinition $card): ?GameObject
    {
        foreach ($card->keywords as $keyword) {
            if (preg_match('/^reveal ([A-Z][a-z]+)$/', $keyword, $m)) {
                foreach ($this->players[$seat]->hand as $other) {
                    if ($other !== $id && in_array($m[1], $this->objects[$other]->printed()->subtypes, true)) {
                        return $this->objects[$other];
                    }
                }
                foreach (in_array('reveal or control', $card->keywords, true) ? $this->permanents($seat) : [] as $permanent) {
                    if (in_array($m[1], $permanent->definition()->subtypes, true)) {
                        return $permanent;
                    }
                }
            }
        }

        return null;
    }

    /**
     * What splicing cards onto a spell adds to its cost.
     *
     * @param int[] $ids
     *
     * @return string
     */
    private function spliceCost(array $ids): string
    {
        return implode('', array_map(fn (int $id) => (string) ($this->objects[$id]->printed()->altCosts['splice'] ?? ''), $ids));
    }

    /**
     * The effects spliced cards add to a spell, after its own (rule 702.47b).
     *
     * @param int[] $ids
     *
     * @return array[]
     */
    public function splicedEffects(array $ids): array
    {
        return array_merge([], ...array_map(fn (int $id) => isset($this->objects[$id]) ? $this->objects[$id]->printed()->spellEffects() : [], $ids));
    }

    /**
     * The targets a spell needs, cast a given way: none face down, the
     * creature it enchants when bestowed.
     *
     * @param CardDefinition $card
     * @param string|array   $how
     * @param ?Game          $game For the targets of the cards spliced onto it.
     *
     * @return string[]
     */
    public static function castTargetKinds(CardDefinition $card, string|array $how, ?Game $game = null): array
    {
        $options = self::castOptions($how);

        return match (true) {
            $options['faceDown'], $options['alt'] === 'overload' => [],
            $options['bestowed'] => [$card->bestow['enchant']],
            $options['alt'] === 'mutate' => ['mutate_host'],
            default => [...$card->targetKinds($options['modes'], $options['kicked'] || in_array($options['alt'], ['surge', 'spectacle', 'cleave', 'mastery'], true)), ...($game === null ? [] : self::targetKindsOf($game->splicedEffects($options['splice'])))],
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
        $more = ($options['kicked'] && ! $options['bargained'] && ! $options['gifted'] && ! $options['evidence'] ? str_repeat((string) $card->kicker, max(1, $options['kicks'])) : '').($options['entwined'] ? (string) $card->entwine : '').($options['buyback'] ? (string) ($card->altCosts['buyback'] ?? '') : '')
            .implode('', array_map(fn ($mode) => $card->modes[(int) $mode]['cost'] ?? '', $options['modes']))
            .str_repeat((string) ($card->altCosts['escalate'] ?? ''), max(0, count($options['modes']) - 1));
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
        // Airbend (TLA): {2} rather than its mana cost.
        if ($options['exiled'] === 'airbend') {
            return '{2}'.$more;
        }
        if (in_array($options['grave'], ['escape', 'disturb', 'harmonize', 'mayhem'], true)) {
            return $card->altCosts[$options['grave']].$more;
        }
        if ($options['bestowed']) {
            return $card->bestow['cost'];
        }
        // A split card's halves each bring their own cost, as Spree's modes do (rule 709.3).
        $cost = $options['faceDown'] ? '{3}' : ($options['flashback'] ? (string) $card->flashback : ($card->split ? '' : (string) $card->cost));

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
     * @param ?array  $targets  The pinned targets, or null before they are chosen.
     *
     * @return array{life: int, pool: array<string, int>, tap: int[], float: array<string, int>, made: array<int, string>}|null
     */
    private function paymentFor(int $seat, int $id, int $x, array $options = [], string $wardMana = '', int $wardLife = 0, ?array $targets = null): ?array
    {
        // `Spend this mana only to cast a creature spell.`: such sources pay only for a spell of that type; see payFor().
        $this->spending = $this->objects[$id];
        try {
            return $this->spellPayment($seat, $id, $x, $options, $wardMana, $wardLife, $targets);
        } finally {
            $this->spending = null;
        }
    }

    private function spellPayment(int $seat, int $id, int $x, array $options, string $wardMana, int $wardLife, ?array $targets): ?array
    {
        $options = self::castOptions($options);
        $tax = $this->objects[$id]->zone === GameObject::COMMAND ? $this->commanderTax($id) : 0;

        $card = $this->objects[$id]->printed();
        if ($options['grave'] === 'harmonize' && ($helper = $this->harmonizer($seat, $id)) !== null) {
            $tax -= $this->power($helper);
        }
        if ($options['alt'] === 'emerge' && ($fodder = $this->emergeFodder($seat, $id)) !== null) {
            $tax -= $fodder->definition()->cost->manaValue();
        }
        foreach ($options['alt'] === 'conditional' ? $card->keywords : [] as $keyword) {
            if (preg_match('/^conditional life (\d+)$/', $keyword, $m)) {
                $wardLife += (int) $m[1];
            }
        }
        // Affinity for artifacts (rule 702.41): {1} less for each artifact you control.
        if (! $options['faceDown'] && in_array('affinity for artifacts', $card->keywords, true)) {
            $tax -= count(array_filter($this->permanents($seat), fn (GameObject $o) => $o->definition()->is('Artifact')));
        }
        // `This spell costs {1} less to cast for each creature in your party.` (or artifact you control …)
        foreach ($options['faceDown'] ? [] : $card->keywords as $keyword) {
            if (str_starts_with($keyword, 'affinity for sub:')) {
                $tax -= count(array_filter($this->permanents($seat), fn (GameObject $o) => in_array(substr($keyword, 17), $o->definition()->subtypes, true)));
            }
            if (preg_match('/^costs (\d+) less for each (\w+)$/', $keyword, $m)) {
                $tax -= (int) $m[1] * $this->costCount($seat, $id, $m[2]);
            }
            if (preg_match('/^costs (\d+) less if you control (.+)$/', $keyword, $m)
                && array_filter($this->permanents($seat), fn (GameObject $o) => match ($m[2]) {
                    'legendary creature' => $this->isCreature($o) && $o->definition()->isLegendary(),
                    'artifact', 'enchantment' => $o->definition()->is(ucfirst($m[2])),
                    default => in_array($m[2], $o->definition()->subtypes, true) || in_array('changeling', $o->definition()->keywords, true) && $this->isCreature($o),
                }) !== []) {
                $tax -= (int) $m[1];
            }
            // `This spell costs {2} less to cast if it targets a tapped creature.`; before targets are chosen, if one could.
            if (preg_match('/^costs (\d+) less if it targets a tapped creature$/', $keyword, $m)) {
                $candidates = $targets === null ? $this->permanents() : array_filter(array_map(fn ($target) => $this->targetObject((string) $target), $targets));
                if (array_filter($candidates, fn (GameObject $o) => $o->tapped && $this->isCreature($o)) !== []) {
                    $tax -= (int) $m[1];
                }
            }
        }
        // `Instant and sorcery spells you cast cost {1} less to cast.`, and Thalia's `Noncreature spells cost {1} more to cast.`
        foreach ($this->permanents() as $permanent) {
            foreach ($permanent->definition()->costReductions as $reduction) {
                if (match ($reduction['who'] ?? 'yours') {
                    'yours' => $permanent->controller !== $seat,
                    'opponents' => $permanent->controller === $seat,
                    default => false,
                }) {
                    continue;
                }
                if (match ($reduction['kind']) {
                    'instant_sorcery' => $card->is('Instant') || $card->is('Sorcery'),
                    'creature' => $card->isCreature(),
                    'noncreature' => ! $card->isCreature(),
                    'artifact' => $card->is('Artifact'),
                    'enchantment' => $card->is('Enchantment'),
                    'any' => true,
                    default => str_starts_with($reduction['kind'], 'color_') ? in_array(substr($reduction['kind'], 6), $card->colors, true)
                        : in_array(substr($reduction['kind'], 8), $card->subtypes, true),
                }) {
                    $tax -= $reduction['amount'] * (isset($reduction['each']) ? $this->costCount($seat, $id, $reduction['each']) : 1);
                }
            }
        }
        $cost = self::castCost($card, $options).$this->spliceCost($options['splice']).$wardMana.($options['faceDown'] ? '' : $this->orPay($seat, $id, $card));
        // Strive (an ability word): more for each target beyond the first.
        if (isset($card->altCosts['strive']) && $targets !== null) {
            $cost .= str_repeat($card->altCosts['strive'], max(0, count(array_filter($targets, fn ($target) => $target !== '-')) - 1));
        }
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
        $sources = array_filter(
            array_diff_key($this->manaSources($seat), array_flip($without)),
            fn (array $source) => ! isset($source['only']) || ($this->spending !== null && $this->spending->definition()->is($source['only']))
        );
        if ($convoke) {
            foreach ($this->permanents($seat) as $object) {
                if ($this->isCreature($object) && ! $object->tapped && ! isset($sources[$object->id]) && ! in_array($object->id, $without, true)) {
                    $sources[$object->id] = ['count' => 1, 'colors' => [...$object->definition()->colors, 'C'], 'convoke' => true];
                }
            }
        }
        if ($improvise) {
            foreach ($this->permanents($seat) as $object) {
                if ($object->definition()->is('Artifact') && ! $object->tapped && ! isset($sources[$object->id]) && ! in_array($object->id, $without, true)) {
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
            // A filter land's `{W/U}` is paid with either color: each way is tried.
            for ($k = 1; $plan === null && $k <= count($filters) && $k <= $others; $k++) {
                $filtered = $sources;
                $ways = [$payment['mana']];
                foreach (array_slice($filters, 0, $k) as $id) {
                    $filtered[$id] = $sources[$id]['filterAs'] ?? ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];
                    $next = [];
                    foreach ($ways as $way) {
                        foreach ($sources[$id]['filterPays'] ?? ['generic'] as $type) {
                            $next[] = [$type => ($way[$type] ?? 0) + ($sources[$id]['filterCost'] ?? 1)] + $way;
                        }
                    }
                    $ways = $next;
                }
                foreach ($ways as $way) {
                    if (($plan = ManaPayer::plan(['mana' => $way] + $payment, $player->manaPool, $filtered)) !== null) {
                        break;
                    }
                }
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
            $ability = $this->objects[$source]->definition()->manaAbility ?? [];
            if (! ($ability['noTap'] ?? false)) {
                $this->objects[$source]->tapped = true;
            } elseif ($ability['once'] ?? false) {
                $this->objects[$source]->used[-1] = $this->turn;
            }
        }
        // A Treasure is sacrificed for its mana.
        foreach ($payment['tap'] as $source) {
            if ($this->objects[$source]->definition()->manaAbility['sacrifice'] ?? false) {
                $this->sacrifice($this->objects[$source]);
            }
            // `{T}, Remove a charge counter from this artifact: Add …`.
            if (($kind = $this->objects[$source]->definition()->manaAbility['counter'] ?? null) !== null) {
                $this->objects[$source]->addCounters($kind, -1);
            }
            [$kind, $base] = [$this->objects[$source]->definition()->manaAbility['withCounter'][0] ?? null, $this->objects[$source]->definition()->manaAbility['colors'] ?? []];
            if ($kind !== null && ! in_array($payment['made'][$source] ?? $base[0], $base, true)) {
                $this->objects[$source]->addCounters($kind, -1);
            }
            // `{T}, Tap an untapped creature you control: Add …`: the weakest creature without a mana ability.
            $ability = $this->objects[$source]->definition()->manaAbility ?? [];
            if (($ability['tapCreature'] ?? false) || (isset($ability['withCreature']) && ($payment['made'][$source] ?? 'C') !== 'C')) {
                $helpers = array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o) && ! $o->tapped && $o->definition()->manaAbility === null);
                usort($helpers, fn (GameObject $a, GameObject $b) => $this->power($a) <=> $this->power($b));
                if ($helpers !== []) {
                    $helpers[0]->tapped = true;
                }
            }
        }
        foreach ($payment['pool'] as $type => $amount) {
            $player->manaPool->remove($type, $amount);
        }
        foreach ($payment['float'] as $type => $amount) {
            $player->manaPool->add($type, $amount);
        }
        // Expend: `Whenever you expend 4, …` as the fourth total mana this turn is spent.
        if (($count = (int) ($payment['count'] ?? 0)) > 0) {
            $before = $player->expendedTurn === $this->turn ? $player->expended : 0;
            [$player->expended, $player->expendedTurn] = [$before + $count, $this->turn];
            foreach ($this->permanents($seat) as $permanent) {
                for ($n = $before + 1; $n <= $before + $count; $n++) {
                    $this->trigger($permanent, "expend_{$n}", $seat);
                }
            }
        }
        $player->life -= $payment['life'];
        if ($payment['life'] > 0) {
            $this->lostLife($seat);
        }
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
     * @param bool     $spell   Whether a spell targets them: only spells pay a targeting tax.
     *
     * @return array{0: string, 1: int} Mana, and life.
     */
    private function wardCost(array $targets, int $seat, bool $spell = true): array
    {
        $mana = '';
        $life = $discard = 0;
        foreach ($targets as $target) {
            $object = $this->targetObject($target);
            if ($object === null || $object->zone !== GameObject::BATTLEFIELD || $object->controller === $seat || ($ward = $object->definition()->ward ?? $this->grantedWard($object)) === null || (($ward['tax'] ?? false) && ! $spell)) {
                continue;
            }
            $mana .= $ward['mana'] ?? '';
            $life += $ward['life'] ?? 0;
            $discard += $ward['discard'] ?? 0;
        }

        return [$mana, $life, $discard];
    }

    /**
     * Ward a permanent has from another one: `Creatures you control have ward {1}.`
     *
     * @param GameObject $object
     *
     * @return array{mana: string}|null
     */
    private function grantedWard(GameObject $object): ?array
    {
        foreach ($this->keywords($object) as $keyword) {
            if (preg_match('/^ward (\{\d+\})$/', $keyword, $match)) {
                return ['mana' => $match[1]];
            }
        }

        return null;
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
        // Chromatic Lantern: `Lands you control have "{T}: Add one mana of any color."`
        $lantern = array_filter($this->permanents($seat), fn (GameObject $o) => $this->hasKeyword($o, 'lands tap for any color')) !== [];
        foreach ($this->permanents($seat) as $object) {
            $ability = $object->definition()->manaAbility;
            if ($lantern && $object->definition()->isLand() && ($ability === null || (($ability['count'] ?? 0) === 1 && ! isset($ability['fixed']) && ! ($ability['filter'] ?? false) && ! ($ability['noTap'] ?? false) && ! ($ability['sacrifice'] ?? false)))) {
                $ability = ['count' => 1, 'colors' => array_values(array_unique([...($ability['colors'] ?? []), 'W', 'U', 'B', 'R', 'G']))] + ($ability ?? []);
            }
            // A filter that doesn't tap: `{2}: Add one mana of any color.`, or once each turn.
            $noTap = $ability['noTap'] ?? false;
            if ($noTap && ($ability['once'] ?? false) && ($object->used[-1] ?? 0) === $this->turn) {
                continue;
            }
            if ($ability === null || ($object->tapped && ! $noTap) || $this->hasKeyword($object, "abilities can't be activated") || (isset($ability['counter']) && $object->counter($ability['counter']) <= 0)) {
                continue;
            }
            // A creature's {T} abilities need it to have been under your control since your turn began (rule 302.6).
            if ($this->isCreature($object) && $object->sick && ! $noTap && ! $this->hasKeyword($object, 'haste')) {
                continue;
            }
            if (($ability['chosen'] ?? false) && $object->chosen !== null) {
                $ability['colors'] = [$object->chosen];
            }
            if (isset($ability['countPer'])) {
                $ability['count'] = count(array_filter($this->permanents($seat), fn (GameObject $o) => in_array($ability['countPer'], $o->definition()->subtypes, true) || $o->definition()->is($ability['countPer'])));
                if ($ability['count'] <= 0) {
                    continue;
                }
            }
            if ($ability['powerCount'] ?? false) {
                $ability['count'] = $this->power($object);
                if ($ability['count'] <= 0) {
                    continue;
                }
            }
            // `{T}, Remove a charge counter from this land: Add one mana of any color.` beside `{T}: Add {R}.`
            if (isset($ability['withCounter']) && $object->counter($ability['withCounter'][0]) > 0) {
                $ability['colors'] = array_values(array_unique([...$ability['colors'], ...$ability['withCounter'][1]]));
            }
            // `{T}, Tap an untapped creature you control: Add …`: one creature without a mana ability for each.
            if (($ability['tapCreature'] ?? false) || isset($ability['withCreature'])) {
                $helpers ??= count(array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o) && ! $o->tapped && $o->definition()->manaAbility === null));
                if ($helpers > 0) {
                    $helpers--;
                    $ability = isset($ability['withCreature']) ? $ability['withCreature'] + ['tapCreature' => true] : $ability;
                } elseif ($ability['tapCreature'] ?? false) {
                    continue;
                }
                unset($ability['withCreature']);
            }
            // `Whenever enchanted land is tapped for mana, its controller adds an additional {G}.`: one more unit for each.
            $extra = [];
            foreach ($object->definition()->isLand() && ! ($ability['same'] ?? false) && ! $noTap && ($ability['count'] ?? 0) > 0 ? $this->battlefield : [] as $auraId) {
                if ($this->objects[$auraId]->attachedTo === $object->id) {
                    foreach ($this->keywords($this->objects[$auraId]) as $keyword) {
                        if (preg_match('/^enchanted land adds (\w+)$/', $keyword, $m)) {
                            array_push($extra, ...($m[1] === 'any' ? [['W', 'U', 'B', 'R', 'G']] : array_map(fn (string $color) => [$color], str_split($m[1]))));
                        }
                    }
                }
            }
            if ($extra !== []) {
                $ability['fixed'] = [...($ability['fixed'] ?? array_fill(0, $ability['count'], $ability['colors'])), ...$extra];
                $ability['count'] = count($ability['fixed']);
                $ability['colors'] = array_values(array_unique(array_merge(...$ability['fixed'])));
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
        // `As an additional cost to cast CARDNAME, you may reveal a Dragon card from your hand.`: always, when one can be.
        $revealed = $options['faceDown'] || $card->targetKinds($options['modes'], true) !== $card->targetKinds($options['modes'], false) ? null : $this->revealed($seat, $id, $card);
        if ($revealed !== null || in_array($options['alt'], ['surge', 'spectacle', 'cleave', 'mastery'], true)) {
            $options['kicked'] = true;
        }
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
        $payment = $this->paymentFor($seat, $id, $x, $options, $wardMana, $wardLife, $targets);
        if ($payment === null) {
            $cost = ManaCost::parse(self::castCost($card, $options).$this->spliceCost($options['splice']));
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
        if ($options['evidence'] && ($evidence = $this->evidence($seat, $id, (int) $card->altCosts['evidence'])) !== null) {
            foreach ($evidence as $exiled) {
                $this->moveTo($this->objects[$exiled], GameObject::EXILE);
            }
            $this->log("{$this->players[$seat]->name} collects evidence, exiling ".count($evidence).' card'.(count($evidence) === 1 ? '' : 's').' from their graveyard.');
        }
        if ($options['bargained'] && ($fodder = $this->bargainFodder($seat, $card)) !== null) {
            $this->log("{$this->players[$seat]->name} sacrifices {$fodder->name()} to bargain.");
            $this->sacrifice($fodder);
        }
        if ($options['entwined'] && isset($card->altCosts['teamwork']) && ($team = $this->teamworkFodder($seat, (int) $card->altCosts['teamwork'])) !== null) {
            foreach ($team as $member) {
                $member->tapped = true;
            }
            $this->log("{$this->players[$seat]->name} taps ".implode(' and ', array_map(fn (GameObject $o) => $o->name(), $team)).' (teamwork).');
        }
        $conspirators = $options['conspire'] ? $this->conspirators($seat, $card, $id) : null;
        foreach ($conspirators ?? [] as $conspirator) {
            $conspirator->tapped = true;
        }
        if ($conspirators !== null) {
            $this->log("{$this->players[$seat]->name} taps ".implode(' and ', array_map(fn (GameObject $o) => $o->name(), $conspirators)).' (conspire).');
        }
        if ($options['alt'] === 'webslinging' && ($slinger = $this->webslinger($seat, $id)) !== null) {
            $this->log("{$this->players[$seat]->name} returns {$slinger->name()} to their hand (web-slinging).");
            $this->moveTo($slinger, GameObject::HAND);
        }
        if ($options['alt'] === 'conditional' && ($fodder = $this->pitchFodder($seat, $id, $card)) !== null) {
            $exiled = $fodder->zone === GameObject::HAND;
            $this->log("{$this->players[$seat]->name} ".($exiled ? 'exiles' : 'sacrifices')." {$fodder->name()} to cast {$card->name}.");
            $exiled ? $this->moveTo($fodder, GameObject::EXILE) : $this->sacrifice($fodder);
        }
        if ($options['alt'] === 'emerge' && ($fodder = $this->emergeFodder($seat, $id)) !== null) {
            $this->log("{$this->players[$seat]->name} sacrifices {$fodder->name()} to emerge.");
            $this->sacrifice($fodder);
        }
        $casualty = $options['casualty'] ? $this->casualtyFodder($seat, self::casualty($card)) : null;
        if ($casualty !== null) {
            $this->log("{$this->players[$seat]->name} sacrifices {$casualty->name()} (casualty).");
            $this->sacrifice($casualty);
        }
        if ($options['alt'] === 'sneak') {
            $unblocked = $this->unblockedAttackers($seat);
            usort($unblocked, fn (GameObject $a, GameObject $b) => $this->power($a) <=> $this->power($b));
            $this->log("{$this->players[$seat]->name} returns {$unblocked[0]->name()} to hand (sneak).");
            $this->moveTo($unblocked[0], GameObject::HAND);
        }
        // Ward—Discard a card: the cheapest other cards in hand.
        $this->discardCheapest($seat, $wardDiscard, $id);

        $player = $this->players[$seat];
        // Cast triggers need the actual origin, not the zone after moving or the payment method.
        $castFrom = $object->zone;
        $fromCommand = $castFrom === GameObject::COMMAND;
        $fromHand = $object->zone === GameObject::HAND;
        if ($fromCommand) {
            $tax = $this->commanderTax($id);
            $this->commanders[$id]++;
        }
        $orPaid = ! $options['faceDown'] && $this->orPay($seat, $id, $card) !== '';
        $this->removeFromZone($object);
        $object->moveTo(GameObject::STACK);
        $object->controller = $seat;
        $object->faceDown = $options['faceDown'];
        $object->transformed = $options['grave'] === 'disturb';
        if (! $options['faceDown'] && $card->additionalCost !== null && ! $orPaid) {
            $this->payAdditionalCost($seat, $card);
        }
        $this->stack[] = [
            'id' => $this->nextStackId++,
            'object' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'x' => $x,
            'targets' => $targets,
        ] + array_filter(['modes' => $options['modes'], 'kicked' => $options['kicked'], 'flashback' => $options['flashback'] || in_array($options['grave'], ['escape', 'jumpstart', 'harmonize'], true), 'escaped' => $options['grave'] === 'escape', 'disturbed' => $options['grave'] === 'disturb', 'faceDown' => $options['faceDown'], 'fromHand' => $fromHand, 'bestowed' => $options['bestowed'], 'alt' => $options['exiled'] === 'suspended' ? 'suspend' : $options['alt'], 'buyback' => $options['buyback'], 'kicks' => $options['kicks'] > 1 ? $options['kicks'] : 0,
            'sunburst' => in_array('sunburst', $card->keywords, true) && ! $options['faceDown'] ? $colorsSpent : 0,
            'spent' => implode('', array_intersect(['W', 'U', 'B', 'R', 'G'], [...array_values($payment['made'] ?? []), ...array_keys(array_filter($payment['pool'] ?? []))])),
            // Compleated (rule 702.150): life paid for Phyrexian mana.
            'compleated' => in_array('compleated', $card->keywords, true) ? $payment['life'] - $wardLife : 0,
            // Splice onto Arcane: the spliced cards' effects, copied as it is cast (rule 702.47b).
            'spliced' => $this->splicedEffects($options['splice'])]);
        if ($options['alt'] === 'warp') {
            $this->warpedTurn = $this->turn;
        }
        $this->passes = 0;

        $named = array_map(fn (string $target) => $this->describeTarget($target), $targets);
        $ways = array_filter([
            $fromCommand ? 'from the command zone'.($tax > 0 ? " (tax {{$tax}})" : '') : '',
            $options['flashback'] ? 'with flashback' : '',
            $options['grave'] !== '' ? "from the graveyard ({$options['grave']})" : '',
            $options['bargained'] ? 'bargained' : '',
            $options['evidence'] ? 'collecting evidence' : '',
            $options['gifted'] ? 'with the gift promised' : '',
            $options['rebound'] ? 'from exile with rebound' : '',
            $options['bestowed'] ? 'bestowed' : '',
            $options['alt'] !== '' ? "for its {$options['alt']} cost" : '',
            $options['exiled'] !== '' ? "from exile ({$options['exiled']})" : '',
            $options['kicked'] && $revealed !== null ? "revealing {$revealed->name()}" : ($options['kicked'] && ! in_array($options['alt'], ['surge', 'spectacle', 'cleave', 'mastery'], true) ? 'kicked' : ''),
            $options['buyback'] ? 'with buyback' : '',
            $options['faceDown'] ? 'face down' : '',
            $options['splice'] === [] ? '' : 'splicing '.implode(' and ', array_map(fn (int $splicer) => $this->objects[$splicer]->name(), $options['splice'])).' onto it',
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
        if ($conspirators !== null) {
            $this->copySpell(end($this->stack), $seat, 'conspire');
        }
        $this->castTriggers($seat, $object, $castFrom);
        $this->targetedTriggers($targets, $seat, true);
        $this->settle();
    }

    /**
     * `When this creature becomes the target of a spell or ability, …`, and
     * the versions for an opponent's spell or ability.
     *
     * @param string[] $targets
     * @param int      $seat    Who controls the spell or ability.
     * @param bool     $spell   A spell, not an ability: `When CARDNAME becomes the target of a spell, …`.
     *
     * @return void
     */
    private function targetedTriggers(array $targets, int $seat, bool $spell = false): void
    {
        // Committing a crime (rule 700.13): targeting an opponent, or their spell, permanent or card in a graveyard.
        $crime = false;
        foreach (array_unique($targets) as $target) {
            $object = str_starts_with($target, 'o:') ? $this->targetObject($target) : null;
            $crime = $crime || str_starts_with($target, 'p:') && (int) substr($target, 2) !== $seat
                || $object !== null && ($object->zone === GameObject::GRAVEYARD ? $object->owner : $object->controller) !== $seat;
            foreach (str_starts_with($target, 's:') ? $this->stack : [] as $item) {
                $crime = $crime || "s:{$item['id']}" === $target && $item['controller'] !== $seat;
            }
        }
        foreach ($crime ? $this->permanents($seat) : [] as $permanent) {
            $this->trigger($permanent, 'you_commit_crime', $seat);
        }
        foreach (array_unique($targets) as $target) {
            $object = str_starts_with($target, 'o:') ? $this->targetObject($target) : null;
            if ($object !== null && $object->zone === GameObject::BATTLEFIELD) {
                $this->trigger($object, 'targeted', $object->controller);
                if ($spell) {
                    $this->trigger($object, 'targeted_by_spell', $object->controller);
                }
                foreach ($this->permanents($object->controller) as $permanent) {
                    $this->trigger($permanent, 'yours_targeted', $object->controller, null, $object->id);
                }
                // `Whenever CARDNAME (a creature you control) becomes the target of a spell or ability you control, …`
                if ($object->controller === $seat) {
                    $this->trigger($object, 'targeted_by_you', $seat);
                    foreach ($this->permanents($seat) as $permanent) {
                        $this->trigger($permanent, 'yours_targeted_by_you', $seat, null, $object->id);
                    }
                }
                // `Whenever CARDNAME (a creature you control) becomes the target of a spell or ability an opponent controls, …`
                if ($object->controller !== $seat) {
                    $this->trigger($object, 'targeted_by_opponent', $object->controller);
                    foreach ($this->permanents($object->controller) as $permanent) {
                        $this->trigger($permanent, 'yours_targeted_by_opponent', $object->controller, null, $object->id);
                    }
                }
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
            'harmonize' => ! isset($card->altCosts['harmonize']) ? "{$card->name} has no harmonize." : null,
            'mayhem' => ! isset($card->altCosts['mayhem']) ? "{$card->name} has no mayhem."
                : ($this->objects[$id]->exiledBy !== 'discarded' || $this->objects[$id]->exiledOn !== $this->turn ? 'Mayhem needs you to have discarded it this turn.' : null),
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
        } elseif ($options['grave'] === 'harmonize' && ($helper = $this->harmonizer($seat, $id)) !== null
            && (ManaCost::parse($this->objects[$id]->printed()->altCosts['harmonize'])->payments(0)[0]['mana']['generic'] ?? 0) > 0) {
            $helper->tapped = true;
            $this->log("{$player->name} taps {$helper->name()} to harmonize.");
        }
    }

    /**
     * Harmonize's creature: the untapped one with the most power and no mana ability, tapped for its power off the generic cost.
     *
     * @param int $seat
     * @param int $id   The spell, which isn't on the battlefield.
     *
     * @return GameObject|null
     */
    private function harmonizer(int $seat, int $id): ?GameObject
    {
        $creatures = array_filter($this->permanents($seat), fn (GameObject $o) => $o->id !== $id && $this->isCreature($o) && ! $o->tapped && $o->definition()->manaAbility === null && $this->power($o) > 0);
        usort($creatures, fn (GameObject $a, GameObject $b) => $this->power($b) <=> $this->power($a));

        return $creatures[0] ?? null;
    }

    /**
     * A pitch spell's cost: the cheapest card of its color in hand to exile,
     * or the weakest nontoken creature of its color to sacrifice.
     *
     * @param int            $seat
     * @param int            $id   The spell.
     * @param CardDefinition $card
     *
     * @return GameObject|null
     */
    private function pitchFodder(int $seat, int $id, CardDefinition $card): ?GameObject
    {
        foreach ($card->keywords as $keyword) {
            if (preg_match('/^conditional exile ([WUBRG])$/', $keyword, $m)) {
                $cards = array_values(array_filter(array_map(fn (int $other) => $this->objects[$other], $this->players[$seat]->hand), fn (GameObject $o) => $o->id !== $id && in_array($m[1], $o->definition()->colors, true)));
                usort($cards, fn (GameObject $a, GameObject $b) => $a->definition()->cost->manaValue() <=> $b->definition()->cost->manaValue());

                return $cards[0] ?? null;
            }
            if (preg_match('/^conditional sacrifice ([WUBRG])$/', $keyword, $m)) {
                return $this->weakest($seat, fn (GameObject $o) => $this->isCreature($o) && ! $o->definition()->isToken() && in_array($m[1], $o->definition()->colors, true));
            }
        }

        return null;
    }

    /**
     * Emerge's sacrifice: the creature with the highest mana value, the weakest of those.
     *
     * @param int $seat
     * @param int $id   The spell.
     *
     * @return GameObject|null
     */
    private function emergeFodder(int $seat, int $id): ?GameObject
    {
        $creatures = array_values(array_filter($this->permanents($seat), fn (GameObject $o) => $o->id !== $id && $this->isCreature($o)));
        usort($creatures, fn (GameObject $a, GameObject $b) => [$b->definition()->cost->manaValue(), $this->power($a)] <=> [$a->definition()->cost->manaValue(), $this->power($b)]);

        return $creatures[0] ?? null;
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

    /**
     * Conspire: two untapped creatures the player controls that share a
     * color with the spell, the weakest first.
     *
     * @param int            $seat
     * @param CardDefinition $card
     * @param int            $id   The spell's own card, never one of them.
     *
     * @return GameObject[]|null
     */
    private function conspirators(int $seat, CardDefinition $card, int $id): ?array
    {
        $creatures = array_values(array_filter($this->permanents($seat), fn (GameObject $o) => $o->id !== $id && ! $o->tapped && $this->isCreature($o)
            && array_intersect($o->definition()->colors, $card->colors) !== []));
        usort($creatures, fn (GameObject $a, GameObject $b) => $this->power($a) <=> $this->power($b));

        return count($creatures) >= 2 ? array_slice($creatures, 0, 2) : null;
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
        $effects = $card->isPermanentCard() ? [] : [...$card->spellEffects($item['modes'] ?? [], $item['kicked'] ?? false), ...$item['spliced'] ?? []];
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

    /**
     * The graveyard cards collecting evidence N exiles (rule 701.59): the highest mana values first, until they total N.
     *
     * @param int $seat
     * @param int $id   The spell being cast.
     * @param int $n
     *
     * @return int[]|null Null when the graveyard's total is short.
     */
    private function evidence(int $seat, int $id, int $n): ?array
    {
        $cards = array_values(array_diff($this->players[$seat]->graveyard, [$id]));
        usort($cards, fn (int $a, int $b) => $this->objects[$b]->printed()->cost->manaValue() <=> $this->objects[$a]->printed()->cost->manaValue());
        [$picked, $total] = [[], 0];
        foreach ($cards as $card) {
            if ($total >= $n) {
                break;
            }
            $picked[] = $card;
            $total += $this->objects[$card]->printed()->cost->manaValue();
        }

        return $total >= $n ? $picked : null;
    }

    private function bargainFodder(int $seat, ?CardDefinition $card = null): ?GameObject
    {
        // `Kicker—Sacrifice an artifact or creature.`: only those types.
        if (isset($card?->altCosts['bargain'])) {
            $types = explode('|', $card->altCosts['bargain']);

            return $this->weakest($seat, fn (GameObject $o) => array_filter($types, fn (string $type) => $o->definition()->is($type)) !== []);
        }

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
    /**
     * Teamwork: the fewest untapped creatures with at least that much total
     * power, the strongest first and those that make mana last, or null.
     *
     * @param int $seat
     * @param int $power
     *
     * @return GameObject[]|null
     */
    private function teamworkFodder(int $seat, int $power): ?array
    {
        $creatures = array_values(array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o) && ! $o->tapped && $this->power($o) > 0));
        usort($creatures, fn (GameObject $a, GameObject $b) => [$a->definition()->manaAbility !== null, $this->power($b)] <=> [$b->definition()->manaAbility !== null, $this->power($a)]);
        $team = [];
        $total = 0;
        foreach ($creatures as $creature) {
            if ($total >= $power) {
                break;
            }
            $team[] = $creature;
            $total += $this->power($creature);
        }

        return $total >= $power ? $team : null;
    }

    /**
     * `… sacrifice a creature or pay {3}.` / `reveal an Elf card from your hand or pay {3}.`: the mana, or null.
     *
     * @param CardDefinition $card
     *
     * @return ?string
     */
    private function orPayKeyword(CardDefinition $card): ?string
    {
        foreach ($card->keywords as $keyword) {
            if (str_starts_with($keyword, 'or pay ')) {
                return substr($keyword, 7);
            }
        }

        return null;
    }

    /** How many of `$what` count toward a `costs {N} less for each` reduction for this spell. */
    private function costCount(int $seat, int $id, string $what): int
    {
        return match ($what) {
            'party' => $this->partySize($seat),
            'creature' => $this->countYours($seat, 'creature'),
            'artifact' => $this->countYours($seat, 'artifact'),
            'land' => $this->countYours($seat, 'land'),
            'card' => count(array_diff($this->players[$seat]->hand, [$id])),
            'opponent' => count($this->players) - 1,
            'graveyard_spell', 'graveyard_creature', 'graveyard_card' => count(array_filter($this->players[$seat]->graveyard, fn (int $card) => match ($what) {
                'graveyard_spell' => $this->objects[$card]->definition()->is('Instant') || $this->objects[$card]->definition()->is('Sorcery'),
                'graveyard_creature' => $this->objects[$card]->definition()->is('Creature'),
                default => true,
            })),
            default => 0,
        };
    }

    /**
     * The mana an `… or pay {M}` additional cost adds: none while the other
     * choice can be made (it always is when it can).
     *
     * @param int            $seat
     * @param int            $id
     * @param CardDefinition $card
     *
     * @return string
     */
    private function orPay(int $seat, int $id, CardDefinition $card): string
    {
        if (($mana = $this->orPayKeyword($card)) === null) {
            return '';
        }
        foreach ($card->keywords as $keyword) {
            if (str_starts_with($keyword, 'reveal or pay ')) {
                $type = substr($keyword, 14);
                foreach ($this->players[$seat]->hand as $other) {
                    if ($other !== $id && in_array($type, $this->objects[$other]->printed()->subtypes, true)) {
                        return '';
                    }
                }
                // Behold: a Dragon you control does as well.
                foreach (in_array('reveal or control', $card->keywords, true) ? $this->permanents($seat) : [] as $permanent) {
                    if (in_array($type, $permanent->definition()->subtypes, true)) {
                        return '';
                    }
                }

                return $mana;
            }
        }
        $can = match ($card->additionalCost) {
            'sacrifice_creature' => array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o)) !== [],
            'sacrifice_artifact' => array_filter($this->permanents($seat), fn (GameObject $o) => $o->definition()->is('Artifact')) !== [],
            'sacrifice_artifact_or_creature' => array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o) || $o->definition()->is('Artifact')) !== [],
            'discard' => count(array_diff($this->players[$seat]->hand, [$id])) > 0,
            'exile_creature_card', 'exile_card' => $this->graveyardFodder($seat, $card, $id) !== [],
            default => true,
        };

        return $can ? '' : $mana;
    }

    private function payAdditionalCost(int $seat, CardDefinition $card): void
    {
        if (in_array($card->additionalCost, ['sacrifice_creature', 'sacrifice_artifact_or_creature', 'sacrifice_artifact'], true)) {
            $creatures = $card->additionalCost !== 'sacrifice_artifact';
            $artifacts = $card->additionalCost !== 'sacrifice_creature';
            $weakest = $this->weakest($seat, fn (GameObject $o) => ($creatures && $this->isCreature($o)) || ($artifacts && $o->definition()->is('Artifact')));
            $this->log("{$this->players[$seat]->name} sacrifices {$weakest->name()} to cast {$card->name}.");
            $this->sacrifice($weakest);
        } elseif ($card->additionalCost === 'exile_creature_card' || $card->additionalCost === 'exile_card') {
            $exiled = $this->objects[$this->graveyardFodder($seat, $card)[0]];
            $this->log("{$this->players[$seat]->name} exiles {$exiled->name()} from their graveyard to cast {$card->name}.");
            $this->moveTo($exiled, GameObject::EXILE);
        } else {
            $hand = $this->players[$seat]->hand;
            usort($hand, fn (int $a, int $b) => $this->objects[$a]->definition()->cost->manaValue() <=> $this->objects[$b]->definition()->cost->manaValue());
            $this->discardCards($seat, [$hand[0]]);
        }
    }

    /**
     * Cards in a player's graveyard that could pay `exile a (creature) card
     * from your graveyard`, lowest mana value first.
     *
     * @param int            $seat
     * @param CardDefinition $card
     * @param int|null       $id   The spell itself, which can't pay for itself.
     *
     * @return int[]
     */
    private function graveyardFodder(int $seat, CardDefinition $card, ?int $id = null): array
    {
        $cards = array_values(array_filter($this->players[$seat]->graveyard, fn (int $other) => $other !== $id
            && ($card->additionalCost !== 'exile_creature_card' || $this->objects[$other]->printed()->isCreature())));
        usort($cards, fn (int $a, int $b) => $this->objects[$a]->printed()->cost->manaValue() <=> $this->objects[$b]->printed()->cost->manaValue());

        return $cards;
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
     * @param string     $from The zone the spell was cast from, before moving to the stack.
     *
     * @return void
     */
    private function castTriggers(int $seat, GameObject $spell, string $from): void
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
        // Replicate (rule 702.56): a copy for each time its replicate cost was paid.
        for ($i = 0; $i < max(1, (int) ($item['kicks'] ?? 0)) && in_array('replicate', $card->keywords, true) && $item !== false && $item['object'] === $spell->id && ($item['kicked'] ?? false); $i++) {
            $this->copySpell($item, $seat, 'replicate');
        }
        // Increment: `Whenever you cast a spell, if the amount of mana you spent is greater than this creature's power or toughness, put a +1/+1 counter on this creature.`
        // The mana spent is read as the spell's mana value with its X.
        $spent = $card->cost->manaValue($item !== false && $item['object'] === $spell->id ? (int) ($item['x'] ?? 0) : 0);
        foreach ($this->permanents($seat) as $permanent) {
            if ($permanent->id !== $spell->id && $this->hasKeyword($permanent, 'increment') && $this->isCreature($permanent)
                && ($spent > $this->power($permanent) || $spent > $this->toughness($permanent))) {
                $permanent->addCounters('+1/+1', 1);
                $this->log("Increment: {$permanent->name()} gets a +1/+1 counter.");
            }
        }
        if (in_array('cascade', $card->keywords, true)) {
            $this->cascade($seat, $spell);
        }
        $caster = $this->players[$seat];
        [$caster->cast, $caster->castTurn] = [$caster->castTurn === $this->turn ? $caster->cast + 1 : 1, $this->turn];
        if (! $card->isCreature()) {
            $caster->noncreatureCastTurn = $this->turn;
        }
        $events = [...($from === GameObject::GRAVEYARD ? ['cast_from_graveyard'] : []), ...($caster->cast === 1 ? ['first_spell', ...($this->active !== $seat ? ['first_spell_opponent_turn'] : [])] : []), ...($caster->cast === 2 ? ['second_spell'] : []), ...($caster->cast === 3 ? ['third_spell'] : []), 'cast_spell', ...($card->isCreature() ? ['cast_creature'] : ['cast_noncreature']), ...($card->is('Artifact') ? ['cast_artifact'] : []), ...($card->is('Enchantment') ? ['cast_enchantment'] : []), ...($card->isPermanentCard() ? [] : ['cast_instant_sorcery']),
            ...(array_intersect(['Spirit', 'Arcane'], $card->subtypes) !== [] ? ['cast_spirit_arcane'] : []),
            ...array_map(fn (string $color) => "cast_color_{$color}", $card->colors), ...(count($card->colors) > 1 ? ['cast_multicolored'] : [])];
        $this->castSpell = $spell->id;
        $this->trigger($spell, 'cast_self', $seat);
        foreach ($this->permanents($seat) as $permanent) {
            foreach ($events as $event) {
                $this->trigger($permanent, $event, $seat);
            }
            $this->trigger($permanent, 'you_cast_kind', $seat, null, $spell->id);
        }
        $this->castSpell = null;
        // Chalice of the Void: a spell with mana value equal to its charge counters is countered.
        foreach ($item !== false && $item['object'] === $spell->id ? $this->permanents() : [] as $permanent) {
            if ($this->hasKeyword($permanent, 'chalice') && $permanent->counter('charge') === $card->cost->manaValue((int) ($item['x'] ?? 0))) {
                $this->pushAbility(['source' => $permanent->id, 'incarnation' => $permanent->incarnation, 'controller' => $permanent->controller, 'effects' => [['type' => 'counter', 'target' => 'spell']],
                    'kinds' => ['spell'], 'label' => "{$permanent->name()}'s ability"], ["s:{$item['id']}"], 'triggers');
            }
        }
        // `Whenever an opponent casts a spell, …`
        foreach ($this->permanents() as $permanent) {
            if ($permanent->controller !== $seat) {
                $this->trigger($permanent, 'opponent_cast', $permanent->controller);
                $this->trigger($permanent, 'opponent_cast_kind', $permanent->controller, null, $spell->id);
            }
        }
        // `Whenever a player casts a red spell, …`
        foreach ($this->permanents() as $permanent) {
            $this->trigger($permanent, 'any_cast', $permanent->controller);
            $this->trigger($permanent, 'any_cast_kind', $permanent->controller, null, $spell->id);
            foreach ($card->colors as $color) {
                $this->trigger($permanent, "any_cast_color_{$color}", $permanent->controller);
            }
        }
        // Heroic: `Whenever you cast a spell that targets CARDNAME, …`, once however often it is targeted.
        $targeted = [];
        foreach ($item !== false && $item['object'] === $spell->id ? $item['targets'] : [] as $target) {
            $object = $this->targetObject((string) $target);
            if ($object !== null && $object->zone === GameObject::BATTLEFIELD && $object->controller === $seat && ! isset($targeted[$object->id])) {
                $targeted[$object->id] = true;
                $this->trigger($object, 'heroic', $seat);
            }
        }
        // `Whenever you cast a spell that targets a creature you control, …`
        if (array_filter(array_keys($targeted), fn (int $id) => $this->isCreature($this->objects[$id])) !== []) {
            foreach ($this->permanents($seat) as $permanent) {
                $this->trigger($permanent, 'heroic_any', $seat, null, $spell->id);
            }
        }
        // `Whenever you cast a spell that targets a creature an opponent controls, …`
        foreach ($item !== false && $item['object'] === $spell->id ? $item['targets'] : [] as $target) {
            if (($object = $this->targetObject((string) $target)) !== null && $object->zone === GameObject::BATTLEFIELD && $object->controller !== $seat && $this->isCreature($object)) {
                foreach ($this->permanents($seat) as $permanent) {
                    $this->trigger($permanent, 'cast_targets_opponent_creature', $seat, null, $spell->id);
                }
                break;
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
        // `When you cycle CARDNAME, …` and `Whenever you cycle a card, …`, above the cycling draw.
        $this->trigger($object, 'cycled', $seat);
        foreach ($this->permanents($seat) as $permanent) {
            // Cycling discards the card (rule 702.29a).
            $this->trigger($permanent, 'you_cycle', $seat);
            $this->trigger($permanent, 'you_discard', $seat);
            $this->trigger($permanent, 'you_discard_batch', $seat);
        }
        $this->discardedBy($seat, $object->id);
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

    /**
     * Transmutes a card (rule 702.53): pay its transmute cost and discard it
     * as a sorcery; an ability that finds a card with the same mana value goes
     * on the stack.
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function transmute(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotTransmute($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $card = $object->printed();
        $this->pay($seat, $this->payFor($seat, $card->altCosts['transmute']));
        $value = $card->cost->manaValue();
        $this->moveTo($object, GameObject::GRAVEYARD);
        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => [['type' => 'tutor', 'filter' => "mv_ge_{$value}&mv_le_{$value}", 'to' => 'hand']],
            'kinds' => [],
            'label' => "{$card->name}'s transmute",
        ], [], 'is activated', "transmute {$card->name}");
        $this->settle();
    }

    /**
     * Bloodrush: pay its cost and discard it from your hand; the pump goes on
     * the stack for your biggest attacking creature.
     *
     * @param int $seat
     * @param int $id
     *
     * @return void
     */
    public function bloodrush(int $seat, int $id): void
    {
        $this->expect($seat, 'priority');
        if (($reason = $this->whyNotBloodrush($seat, $id)) !== null) {
            throw new GameException($reason);
        }
        $object = $this->objects[$id];
        $card = $object->printed();
        $attacker = $this->bloodrushTarget($seat);
        $this->pay($seat, $this->payFor($seat, $card->altCosts['bloodrush']));
        $this->discardCards($seat, [$id]);
        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => TextParser::effects($card->altCosts['bloodrushText']) ?? [],
            'kinds' => ['attacking'],
            'label' => "{$card->name}'s bloodrush",
        ], ["o:{$attacker->id}"], 'is activated', "bloodrush {$card->name}");
        $this->settle();
    }

    private function bloodrushTarget(int $seat): ?GameObject
    {
        $attackers = array_values(array_filter(
            array_map(fn (int $id) => $this->objects[$id] ?? null, array_keys($this->attackers)),
            fn (?GameObject $o) => $o !== null && $o->controller === $seat && ! $this->hasKeyword($o, 'hexproof') && ! $this->hasKeyword($o, 'shroud')
        ));
        usort($attackers, fn (GameObject $a, GameObject $b) => $this->power($b) <=> $this->power($a));

        return $attackers[0] ?? null;
    }

    private function whyNotBloodrush(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::HAND || $object->owner !== $seat) {
            return 'That card is not in your hand.';
        }
        if (! isset($object->printed()->altCosts['bloodrush'])) {
            return "{$object->name()} has no bloodrush.";
        }
        if ($this->priority !== $seat) {
            return 'You do not have priority.';
        }
        if ($this->bloodrushTarget($seat) === null) {
            return 'Bloodrush needs an attacking creature of yours.';
        }
        if ($this->payFor($seat, $object->printed()->altCosts['bloodrush']) === null) {
            return "You cannot pay {$object->printed()->altCosts['bloodrush']}.";
        }

        return null;
    }

    private function recover(GameObject $card): void
    {
        $payment = $this->payFor($card->owner, $card->printed()->altCosts['recover']);
        if ($payment !== null) {
            $this->pay($card->owner, $payment);
            $this->log("{$this->players[$card->owner]->name} pays recover and returns {$card->name()} to their hand.");
            $this->moveTo($card, GameObject::HAND);

            return;
        }
        $this->log("{$card->name()} is exiled (recover wasn't paid).");
        $this->moveTo($card, GameObject::EXILE);
    }

    private function whyNotTransmute(int $seat, int $id): ?string
    {
        $object = $this->objects[$id] ?? null;
        if ($object === null || $object->zone !== GameObject::HAND || $object->owner !== $seat) {
            return 'That card is not in your hand.';
        }
        if (! isset($object->printed()->altCosts['transmute'])) {
            return "{$object->name()} has no transmute.";
        }
        if (! $this->sorcerySpeed($seat)) {
            return 'Transmute only as a sorcery.';
        }
        if ($this->payFor($seat, $object->printed()->altCosts['transmute']) === null) {
            return "You cannot pay {$object->printed()->altCosts['transmute']}.";
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
     * black when eternalized, white when embalmed) with no mana cost. Scavenge
     * (rule 702.97) exiles it the same way for +1/+1 counters equal to its power.
     *
     * @param int    $seat
     * @param int    $id
     * @param string $way  `embalm`, `eternalize` or `scavenge`.
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
            'effects' => [$way === 'scavenge' ? ['type' => 'scavenge', 'amount' => max(0, (int) ($card->card['power'] ?? 0))] : ['type' => 'embalm', 'way' => $way]],
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
        if ($way === 'scavenge' && array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o)) === []) {
            return 'Scavenge needs a creature you control to put the counters on.';
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
        $this->chosenType = null;
        $item = array_pop($this->stack);
        $object = $this->objects[$item['object']];
        $ability = self::isAbility($item);
        if (! $ability && ($object->zone !== GameObject::STACK || $object->incarnation !== $item['incarnation'])) {
            return;
        }
        $card = $object->definition();
        $effects = $ability ? $item['effects'] : ($card->isPermanentCard() ? [] : [...$card->spellEffects($item['modes'] ?? [], $item['kicked'] ?? false), ...$item['spliced'] ?? []]);
        $kinds = $ability ? $item['kinds'] : match (true) {
            (bool) ($item['bestowed'] ?? false) => [$card->bestow['enchant']],
            ($item['alt'] ?? '') === 'mutate' => ['mutate_host'],
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
        if ($chosen !== [] && ! in_array(true, $chosen, true) && ! $ability && ($item['alt'] ?? '') === 'mutate') {
            // A mutating creature spell with an illegal target resolves as a creature spell (rule 702.140b).
            [$item['alt'], $item['targets'], $legal, $chosen] = ['', [], [], []];
        }
        if (! $ability && ($item['alt'] ?? '') === 'mutate' && ($host = $this->targetObject((string) ($item['targets'][0] ?? ''))) !== null) {
            $this->mutate($object, $host);

            return;
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

        // Gift: the opponent gets it before the spell's other effects.
        if (! $ability && ($item['kicked'] ?? false) && isset($card->altCosts['gift'])) {
            $this->giveGift($this->opponent($item['controller']), (string) $card->altCosts['gift'], $object);
        }

        if (! $ability && $card->isPermanentCard()) {
            $this->putOntoBattlefield($object, $item['controller'], true, $item['faceDown'] ?? false, $item['kicked'] ?? false, (int) ($item['x'] ?? 0), (bool) ($item['disturbed'] ?? false), entryState: ['cast' => ($item['fromHand'] ?? false) ? 'hand' : 'elsewhere']);
            // Multikicker: a counter set for each time it was kicked.
            if (($item['kicks'] ?? 0) > 1 && $card->kickerCounters > 0) {
                $object->addCounters('+1/+1', $card->kickerCounters * ((int) $item['kicks'] - 1));
            }
            $object->alt = ($item['alt'] ?? '') === '' ? null : $item['alt'];
            // Impending (rule 702.176): with N time counters, not a creature until the last is removed.
            if ($object->alt === 'impending') {
                foreach ($card->keywords as $keyword) {
                    if (preg_match('/^impending (\d+)$/', $keyword, $m)) {
                        $object->addCounters('time', (int) $m[1]);
                    }
                }
            }
            // Sneak: a creature enters tapped and attacking.
            if ($object->alt === 'sneak' && $this->isCreature($object) && in_array($this->step, [Step::DeclareBlockers, Step::FirstStrikeDamage, Step::CombatDamage], true)) {
                $object->tapped = true;
                $this->attackers[$object->id] = true;
                $this->log("{$object->name()} enters tapped and attacking.");
            }
            foreach (($item['escaped'] ?? false) ? $card->keywords : [] as $keyword) {
                if (preg_match('/^escapes with (\d+)$/', $keyword, $m)) {
                    $object->addCounters('+1/+1', (int) $m[1]);
                }
            }
            foreach ($card->keywords as $keyword) {
                if (preg_match('/^raid counters (\d+)$/', $keyword, $m) && $this->players[$item['controller']]->attackedTurn === $this->turn) {
                    $object->addCounters('+1/+1', (int) $m[1]);
                }
                if (preg_match('/^enters counters (\d+) if (.+)$/', $keyword, $m) && $this->conditionMet($m[2], $object, $item['controller'])) {
                    $object->addCounters('+1/+1', (int) $m[1]);
                }
            }
            $object->spent = (string) ($item['spent'] ?? '');
            if (($item['sunburst'] ?? 0) > 0) {
                $object->addCounters($this->isCreature($object) ? '+1/+1' : 'charge', (int) $item['sunburst']);
            }
            // Compleated: two fewer loyalty counters for each Phyrexian symbol paid with 2 life.
            if (($item['compleated'] ?? 0) > 0) {
                $object->addCounters('loyalty', -min($object->counter('loyalty'), (int) $item['compleated']));
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
        if ($this->pendingChoice !== null) {
            $this->pendingChoice['resume'] = ['item' => $item, 'steps' => $steps, 'self' => $self, 'followed' => $this->followed];

            return;
        }
        $source = $this->objects[$item['object']];
        $permanent = $self === null ? null : $this->objects[$self[0]];
        if ($permanent !== null && ($permanent->zone !== GameObject::BATTLEFIELD || $permanent->incarnation !== $self[1])) {
            $permanent = null;
        }
        // Divided damage: how many targets share it.
        $sharing = count(array_filter($steps, fn (array $step) => $step[0]['type'] === 'divided' && $step[1] !== null && $step[1] !== '-'));
        $exiled = null;
        while ($steps !== []) {
            [$effect, $target] = array_shift($steps);
            if ($effect['type'] === 'divided') {
                $effect['sharing'] = max(1, $sharing);
            }
            if ($effect['type'] === 'clash') {
                $item['won'] = $this->clash($item['controller'], $source);

                continue;
            }
            if (($effect['ifWon'] ?? false) && ! ($item['won'] ?? false)) {
                continue;
            }
            if (isset($effect['ifCondition']) && ! $this->conditionMet($effect['ifCondition'], $source, $item['controller'])) {
                continue;
            }
            // Excess damage (rule 120.4a): beyond lethal damage, which is 1 from a deathtouch source.
            if (isset($effect['excess']) && is_int($effect['amount']) && ($hurt = $this->targetObject((string) $target)) !== null && $this->isCreature($hurt)) {
                $lethal = max(0, $this->toughness($hurt) - $hurt->damage);
                $excess = max(0, $effect['amount'] - ($this->hasKeyword($source, 'deathtouch') ? min(1, $lethal) : $lethal));
                $item['excess'] = $excess > 0;
                if ($effect['excess'] === 'controller' && $excess > 0) {
                    // `Excess damage is dealt to that creature's controller instead.`
                    $effect['amount'] -= $excess;
                    array_unshift($steps, [['type' => 'damage', 'amount' => $excess], "p:{$hurt->controller}"]);
                }
            }
            if (($effect['ifExcess'] ?? false) && ! ($item['excess'] ?? false)) {
                continue;
            }
            // `If you control a Goblin, it deals 3 damage instead.`
            if (isset($effect['insteadIf']) && $this->conditionMet($effect['insteadIf'], $source, $item['controller'])) {
                $effect = (isset($effect['insteadAmount']) ? ['amount' => $effect['insteadAmount']] : []) + ($effect['instead'] ?? []) + $effect;
            }
            // `If it's a Human, it also gains indestructible …`: the same target, as it is now.
            if (isset($effect['ifTargetSubtype']) && ! (($it = $this->targetObject((string) $target)) !== null
                && (in_array($effect['ifTargetSubtype'], $it->definition()->subtypes, true) || in_array('changeling', $it->definition()->keywords, true)))) {
                continue;
            }
            // `If it was a creature card, …`: the card the step before exiled.
            if (isset($effect['ifItWas'])) {
                // By id: exiling it made it a new object.
                $it = $exiled === null || ! str_starts_with($exiled, 'o:') ? null : ($this->objects[(int) explode(':', $exiled)[1]] ?? null);
                $kind = ucfirst(preg_replace('/^non/', '', $effect['ifItWas']));
                if ($it === null || $it->zone !== GameObject::EXILE || $it->printed()->is($kind) === str_starts_with($effect['ifItWas'], 'non')) {
                    continue;
                }
            }
            if ($effect['type'] === 'exile') {
                $exiled = $target;
            }
            // `Return CARDNAME to its owner's hand.` on a spell: it goes there as it finishes resolving.
            if ($effect['type'] === 'bounce' && ($effect['self'] ?? false) && ! self::isAbility($item)) {
                $item['toHand'] = true;

                continue;
            }
            if ($this->applyEffect($effect, $source, $permanent, $item['controller'], $item['x'], $target, (int) $item['incarnation'])) {
                $steps = array_merge($this->pendingChoice['nestedSteps'] ?? [], $steps);
                unset($this->pendingChoice['nestedSteps']);
                $this->pendingChoice['resume'] = ['item' => $item, 'steps' => $steps, 'self' => $self, 'followed' => $this->followed];

                return;
            }
        }

        $this->log((self::isAbility($item) ? $item['label'] : $source->name()).' resolves.');
        if (! self::isAbility($item) && ($item['alt'] ?? '') === 'awaken') {
            $this->awaken($item['controller'], (int) substr((string) current(preg_grep('/^awaken \d+$/', $source->definition()->keywords) ?: ['awaken 0']), 7));
        }
        if (! self::isAbility($item) && $source->zone === GameObject::STACK) {
            $this->spellLeavesStack($source, $item, true);
        }
    }

    /**
     * Names a card for the caster and exiles up to that many cards with
     * the name from a player's graveyard, hand and library. The name is
     * the one seen most among their battlefield and graveyard, else among
     * their other cards.
     *
     * @param int        $seat
     * @param string     $filter `any`, `nonland`, `creature` or `artifact`.
     * @param int        $amount
     * @param GameObject $source
     *
     * @return void
     */
    private function nameExile(int $seat, string $filter, int $amount, GameObject $source): void
    {
        $player = $this->players[$seat];
        $names = fn (array $ids) => array_count_values(array_map(fn (int $id) => $this->objects[$id]->printed()->name, array_filter($ids, fn (int $id) => $this->matchesFilter($this->objects[$id]->printed(), $filter))));
        $seen = $names([...array_map(fn (GameObject $o) => $o->id, array_filter($this->permanents($seat), fn (GameObject $o) => $o->owner === $seat && ! $o->printed()->isToken())), ...$player->graveyard]);
        if ($seen === []) {
            $seen = $names([...$player->hand, ...$player->library]);
        }
        if ($seen === []) {
            $this->log("{$source->name()} names nothing.");

            return;
        }
        arsort($seen);
        $name = (string) array_key_first($seen);
        $exiled = 0;
        foreach ([...$player->graveyard, ...$player->hand, ...$player->library] as $id) {
            if ($exiled < $amount && $this->objects[$id]->printed()->name === $name) {
                $this->moveTo($this->objects[$id], GameObject::EXILE);
                $exiled++;
            }
        }
        $this->log("{$source->name()} names {$name} and exiles {$exiled} card".($exiled === 1 ? '' : 's').'.');
    }

    /**
     * Gift (rule 702.174): what the promised opponent gets.
     *
     * @param int        $seat   The opponent.
     * @param string     $gift   `card`, `food`, `treasure`, `clue` or `tapped fish`.
     * @param GameObject $source
     *
     * @return void
     */
    private function giveGift(int $seat, string $gift, GameObject $source): void
    {
        $this->log("{$this->players[$seat]->name} gets {$source->name()}'s gift.");
        if ($gift === 'card') {
            $this->draw($seat, 1);

            return;
        }
        $token = $gift === 'tapped fish'
            ? ['name' => 'Fish Token', 'type' => 'Token Creature — Fish', 'types' => ['Creature'], 'subtypes' => ['Fish'], 'colors' => ['U'], 'power' => '1', 'toughness' => '1', 'text' => '', 'manaCost' => null]
            : TextParser::artifactToken(ucfirst($gift));
        $this->createToken($token, $seat)->tapped = $gift === 'tapped fish';
    }

    /**
     * Clash (rule 701.23): the player and their opponent each reveal their
     * library's top card; the player wins with a higher mana value. Both
     * cards stay on top.
     *
     * @param int        $seat
     * @param GameObject $source
     *
     * @return bool Whether the player won.
     */
    private function clash(int $seat, GameObject $source): bool
    {
        $values = [];
        $shown = [];
        foreach ([$seat, $this->opponent($seat)] as $player) {
            $top = end($this->players[$player]->library);
            $values[$player] = $top === false ? -1 : $this->objects[$top]->definition()->cost->manaValue();
            $shown[] = $this->players[$player]->name.' reveals '.($top === false ? 'nothing' : $this->objects[$top]->name());
        }
        $won = $values[$seat] > $values[$this->opponent($seat)];
        $this->log("{$source->name()}: clash. ".implode(', ', $shown).". {$this->players[$seat]->name} ".($won ? 'wins' : 'does not win').'.');

        return $won;
    }

    /**
     * Awaken: N +1/+1 counters on a land the player controls, which becomes
     * a 0/0 Elemental creature with haste. The game picks the land: one
     * already awakened, else a tapped one, else any.
     *
     * @param int $seat
     * @param int $n
     *
     * @return GameObject|null The land awakened.
     */
    private function awaken(int $seat, int $n): ?GameObject
    {
        $lands = array_values(array_filter($this->permanents($seat), fn (GameObject $o) => $o->definition()->isLand()));
        usort($lands, fn (GameObject $a, GameObject $b) => [$b->awakened, $b->tapped] <=> [$a->awakened, $a->tapped]);
        if ($lands === [] || $n <= 0) {
            return null;
        }
        $land = $lands[0];
        $land->addCounters('+1/+1', $n);
        $land->awakened = true;
        $this->log("Awaken {$n}: {$land->name()} becomes a 0/0 Elemental creature with haste and gets {$n} +1/+1 counters.");

        return $land;
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
        // Haunt (rule 702.55): a haunt spell is exiled haunting a creature as it resolves.
        if ($resolved && in_array('haunt', $object->printed()->keywords, true) && $this->haunt($object, $item['controller'], true)) {
            return;
        }
        // Paradigm: exiled, to be copied at the beginning of each of its owner's first main phases.
        if ($resolved && in_array('paradigm', $object->printed()->keywords, true)) {
            $this->moveTo($object, GameObject::EXILE);
            $object->exiledBy = 'paradigm';
            $this->log("{$object->name()} is exiled (paradigm).");

            return;
        }
        // Cipher (rule 702.99): exiled encoded on the controller's strongest creature.
        if ($resolved && ! ($item['flashback'] ?? false) && in_array('cipher', $object->printed()->keywords, true)
            && ($creatures = array_filter($this->permanents($item['controller']), fn (GameObject $o) => $this->isCreature($o))) !== []) {
            usort($creatures, fn (GameObject $a, GameObject $b) => $this->power($b) <=> $this->power($a));
            $this->moveTo($object, GameObject::EXILE);
            $object->exiledBy = 'cipher';
            $creatures[0]->encoded[] = $object->id;
            $this->log("{$object->name()} is encoded on {$creatures[0]->name()}.");

            return;
        }
        if ($resolved && ($item['toHand'] ?? false)) {
            $this->moveTo($object, GameObject::HAND);
            $this->log("{$object->name()} returns to {$this->players[$object->owner]->name}'s hand.");

            return;
        }
        if ($resolved && ($item['buyback'] ?? false) && ! ($item['flashback'] ?? false)) {
            $this->moveTo($object, GameObject::HAND);
            $this->log("{$object->name()} returns to {$this->players[$object->owner]->name}'s hand (buyback).");

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
     * The current engine-owned decision and its legal options.
     *
     * @return array|null
     */
    public function choiceAwaiting(): ?array
    {
        return $this->pendingChoice;
    }

    /**
     * Chooses the exact creatures for the current simultaneous sacrifice.
     * All players' selections wait until the last answer; invalid answers change nothing.
     *
     * @param int   $seat
     * @param int   $choiceId Identity from choiceAwaiting().
     * @param int[] $ids      Exactly the current choice's count of distinct objects.
     *
     * @return void
     */
    public function chooseSacrifice(int $seat, int $choiceId, array $ids): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || $choice === null || $choice['type'] !== 'sacrifice' || $choice['seat'] !== $seat || $choice['id'] !== $choiceId) {
            throw new GameException('That sacrifice choice is no longer available to you.');
        }
        if (count($ids) !== $choice['count'] || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            throw new GameException("Choose exactly {$choice['count']} distinct creatures.");
        }
        foreach ($ids as $id) {
            if (! is_int($id) || ! in_array($id, $choice['cards'], true)) {
                throw new GameException('Choose only creatures available for this sacrifice.');
            }
        }
        $groups = $choice['groups'];
        $groups[$choice['group']]['selected'] = array_values($ids);
        $this->validateEdictGroups($groups);
        $this->followed = $choice['resume']['followed'] ?? [];
        $this->pendingChoice = null;
        if ($this->advanceEdictChoices($groups, $choice['resume'])) {
            return;
        }
        $resume = $choice['resume'];
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /** Validates the whole pre-event selection plan before any state changes. */
    private function validateEdictGroups(array $groups): void
    {
        if (! array_is_list($groups) || count($groups) !== count($this->players)) {
            throw new GameException('Invalid sacrifice selection plan.');
        }
        $seen = [];
        $seats = [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! is_int($group['seat'] ?? null) || ! isset($this->players[$group['seat']]) ||
                isset($seats[$group['seat']]) || ! is_array($group['cards'] ?? null) || ! array_is_list($group['cards']) || ! is_array($group['incarnations'] ?? null) ||
                ! is_int($group['count'] ?? null) || $group['count'] < 0 || $group['count'] > count($group['cards']) ||
                ! array_key_exists('selected', $group)) {
                throw new GameException('Invalid sacrifice selection plan.');
            }
            $seats[$group['seat']] = true;
            if ($group['selected'] === null && ($group['count'] === 0 || $group['count'] === count($group['cards']))) {
                throw new GameException('Invalid sacrifice selection plan.');
            }
            foreach ($group['cards'] as $id) {
                $object = is_int($id) ? ($this->objects[$id] ?? null) : null;
                if ($object === null || isset($seen[$id]) || $object->zone !== GameObject::BATTLEFIELD ||
                    $object->controller !== $group['seat'] || $object->incarnation !== ($group['incarnations'][$id] ?? null) || ! $this->isCreature($object)) {
                    throw new GameException('That sacrifice selection is no longer legal.');
                }
                $seen[$id] = true;
            }
            if ($group['selected'] !== null) {
                if (! is_array($group['selected']) || ! array_is_list($group['selected']) || count($group['selected']) !== $group['count'] || count(array_unique($group['selected'], SORT_REGULAR)) !== $group['count']) {
                    throw new GameException('Invalid sacrifice selection plan.');
                }
                foreach ($group['selected'] as $id) {
                    if (! is_int($id) || ! in_array($id, $group['cards'], true)) {
                        throw new GameException('Invalid sacrifice selection plan.');
                    }
                }
            }
        }
    }

    /** Requests the next player's choice, or moves the fully selected group once. */
    private function advanceEdictChoices(array $groups, ?array $resume = null): bool
    {
        $this->validateEdictGroups($groups);
        foreach ($groups as $index => $group) {
            if ($group['selected'] === null) {
                $this->pendingChoice = ['type' => 'sacrifice', 'id' => $this->nextChoiceId++, 'seat' => $group['seat'],
                    'cards' => $group['cards'], 'count' => $group['count'], 'min' => $group['count'], 'max' => $group['count'],
                    'group' => $index, 'groups' => $groups] + ($resume === null ? [] : ['resume' => $resume]);

                return true;
            }
        }
        $victims = array_merge(...array_column($groups, 'selected'));
        $this->withDeathObservers(array_map(fn (int $id) => $this->objects[$id], $victims), function (GameObject $victim): void {
            $this->log("{$this->players[$victim->controller]->name} sacrifices {$victim->name()}.");
            $this->sacrifice($victim);
        });

        return false;
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
        $this->pendingChoice = null;
        foreach ($ids as $id) {
            if ($to === 'top') {
                // The Harbingers: stays in the library, put on top after the shuffle.
                continue;
            }
            if ($to === 'hand') {
                $this->moveTo($this->objects[$id], GameObject::HAND);
            } else {
                // `You may put a land card from among them onto the battlefield tapped.`
                $this->putOntoBattlefield($this->objects[$id], $seat, tapped: $to === 'tapped');
            }
        }
        $rest = array_values(array_diff($choice['cards'], $ids));
        shuffle($rest);
        foreach (in_array($choice['rest'], ['top', 'library'], true) ? [] : $rest as $id) {
            $object = $this->objects[$id];
            if ($choice['rest'] === 'graveyard') {
                $this->moveTo($object, GameObject::GRAVEYARD);
            } else {
                $this->removeFromZone($object);
                $object->moveTo(GameObject::LIBRARY);
                array_unshift($player->library, $id);
            }
        }
        if ($choice['rest'] === 'library') {
            // A tutor: the rest stay in the library, which is then shuffled.
            $this->log("{$player->name} searches their library".($ids === [] ? ' and takes nothing.' : ' for '.$this->objects[$ids[0]]->name().'.'), $seat);
            $this->shuffle($seat);
            if ($to === 'top' && $ids !== []) {
                $player->library = [...array_values(array_diff($player->library, $ids)), ...$ids];
            }
            $this->runSteps($choice['resume']['item'], $choice['resume']['steps'], $choice['resume']['self']);
            $this->settle();

            return;
        }
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
     * @param int|null        $sourceIncarnation Resolving source identity, retained through nested effects.
     *
     * @return bool Whether it waits for a player's choice.
     */
    private function applyEffect(array $effect, GameObject $source, ?GameObject $self, int $controller, int $x, ?string $target, ?int $sourceIncarnation = null): bool
    {
        // `At the beginning of each opponent's upkeep, that player loses 1 life.`: the player the trigger names; see trigger().
        if (isset($effect['player'])) {
            $target = "p:{$effect['player']}";
        }
        if ($effect['toEnchanted'] ?? false) {
            $target = $self?->enchantedPlayer === null ? null : "p:{$self->enchantedPlayer}";
            if ($target === null) {
                return false;
            }
        }
        if (($effect['sameTarget'] ?? false) && $target !== null && isset($this->followed[$target])) {
            $target = $this->followed[$target];
        }
        $sameSource = $source->zone === GameObject::BATTLEFIELD && ($sourceIncarnation === null || $source->incarnation === $sourceIncarnation);
        $sourceStatistics = $source->lastKnownStatistics[$sourceIncarnation ?? $source->incarnation] ?? null;
        $amount = match ($effect['amount'] ?? 0) {
            'X' => $x,
            // `Look at the top X cards of your library, where X is the number of lands you control.`
            'lands' => count(array_filter($this->permanents($controller), fn (GameObject $o) => $o->definition()->isLand())),
            // `… equal to the number of Mountains you control.`
            'yours:lands', 'yours:creatures', 'yours:artifacts', 'yours:enchantments' => $this->countYours($controller, rtrim(substr($effect['amount'], 6), 's')),
            'yours:Forests', 'yours:Islands', 'yours:Mountains', 'yours:Plains', 'yours:Swamps' => count(array_filter($this->permanents($controller), fn (GameObject $o) => in_array($effect['amount'] === 'yours:Plains' ? 'Plains' : rtrim(substr($effect['amount'], 6), 's'), $o->definition()->subtypes, true))),
            // Swords to Plowshares: `… gains life equal to its power.`, read from the object wherever it went.
            'target_power', 'target_toughness' => ($known = $target !== null && str_starts_with($target, 'o:') ? ($this->objects[(int) explode(':', $target)[1]] ?? null) : null) === null ? 0
                : max(0, $effect['amount'] === 'target_power' ? $this->power($known) : $this->toughness($known)),
            'self_power' => max(0, $sameSource ? $this->power($source) : ($sourceStatistics['power'] ?? (int) $source->definition()->power)),
            'self_toughness' => max(0, $sameSource ? $this->toughness($source) : ($sourceStatistics['toughness'] ?? (int) $source->definition()->toughness)),
            default => (int) ($effect['amount'] ?? 0),
        };
        // `Draw a card for each creature you control.`: counted as it resolves.
        if (isset($effect['per'])) {
            // `Target opponent loses life equal to the number of cards in their hand.`: the targeted player's hand.
            $amount *= $effect['per'] === 'their_hand' ? (str_starts_with((string) $target, 'p:') ? count($this->players[(int) substr((string) $target, 2)]->hand) : 0)
                : $this->perCount($controller, (string) $effect['per'], $source);
        }
        $opponents = array_filter(array_keys($this->players), fn (int $seat) => $seat !== $controller);
        $affected = match (true) {
            isset($effect['thatObject']) => ($that = $this->objects[$effect['thatObject']] ?? null)?->zone === GameObject::BATTLEFIELD ? $that : null,
            isset($effect['graveyardSelf']) => $source->zone === GameObject::GRAVEYARD && $source->incarnation === $effect['graveyardSelf'] ? $source : null,
            (bool) ($effect['self'] ?? false) => $self,
            (bool) ($effect['enchanted'] ?? false) => $self?->attachedTo === null ? null : ($this->objects[$self->attachedTo] ?? null),
            default => $target === null ? null : $this->targetObject($target),
        };

        switch ($effect['type']) {
            case 'damage':
                // Harnessed Lightning: `then you may pay any amount of {E}. CARDNAME deals that much damage …`: all of it.
                if ($effect['payEnergy'] ?? false) {
                    [$amount, $this->players[$controller]->energy] = [$this->players[$controller]->energy, 0];
                    $this->log("{$this->players[$controller]->name} pays {$amount} energy.");
                }
                $hits = match ($effect['each'] ?? null) {
                    'opponent' => array_map(fn (int $seat) => "p:{$seat}", $opponents),
                    'player' => array_map(fn (int $seat) => "p:{$seat}", $this->turnOrderSeats()),
                    'creature' => array_map(fn (GameObject $o) => "o:{$o->id}", array_filter($this->permanents(), fn (GameObject $o) => $this->isCreature($o))),
                    'creature_without_flying' => array_map(fn (GameObject $o) => "o:{$o->id}", array_filter($this->permanents(), fn (GameObject $o) => $this->isCreature($o) && ! $this->hasKeyword($o, 'flying'))),
                    'creature_with_flying' => array_map(fn (GameObject $o) => "o:{$o->id}", array_filter($this->permanents(), fn (GameObject $o) => $this->isCreature($o) && $this->hasKeyword($o, 'flying'))),
                    'creature_opponents' => array_map(fn (GameObject $o) => "o:{$o->id}", array_filter($this->permanents(), fn (GameObject $o) => $this->isCreature($o) && $o->controller !== $controller)),
                    // `… deals 2 damage to each creature blocking it.`
                    'blocking_self' => array_map(fn (int $id) => "o:{$id}", array_values(array_filter(array_keys($this->blockers, $source->id, true), fn (int $id) => ($this->objects[$id] ?? null)?->zone === GameObject::BATTLEFIELD))),
                    // `… deals 1 damage to that creature.`: the creature it blocked or was blocked by.
                    'that' => ($that = $this->objects[(int) ($effect['that'] ?? -1)] ?? null) !== null && $that->zone === GameObject::BATTLEFIELD ? ["o:{$that->id}"] : [],
                    // `… deals 3 damage to that creature's controller.`
                    default => isset($effect['toController']) ? ($affected === null ? [] : ["p:{$affected->controller}"]) : [$target],
                };
                foreach ($hits as $hit) {
                    // `It can't be regenerated.`: its regeneration shields are gone.
                    if (($effect['noRegen'] ?? false) && ($hurt = $this->targetObject((string) $hit)) !== null) {
                        $hurt->shields = 0;
                    }
                    $this->dealDamage($source, (string) $hit, $amount);
                    if (($effect['exileIfDies'] ?? false) && ($hurt = $this->targetObject((string) $hit)) !== null) {
                        $hurt->exileIfDies = $this->turn;
                    }
                }
                break;

            case 'draw':
                if (isset($effect['each'])) {
                    foreach (array_filter($this->turnOrderSeats(), fn (int $seat) => $effect['each'] === 'player' || in_array($seat, $opponents, true)) as $seat) {
                        $this->draw($seat, $amount);
                    }
                    break;
                }
                $this->draw($target !== null ? (int) substr($target, 2) : (isset($effect['opponent']) ? $this->opponent($controller) : $controller), $amount);
                break;

            case 'draw_next_upkeep':
                // Created only as this effect resolves, not when its spell or ability is put on the stack.
                $this->delayedDraws[] = [
                    'createdTurn' => $this->turn,
                    'source' => $source->id,
                    'incarnation' => $source->incarnation,
                    'controller' => $controller,
                    'label' => "{$source->name()}'s delayed draw",
                ];
                break;

            case 'gain_life':
                if ($effect['perOpponent'] ?? false) {
                    $amount *= count($opponents);
                }
                if (isset($effect['each'])) {
                    foreach (array_filter($this->turnOrderSeats(), fn (int $seat) => $effect['each'] === 'player' || in_array($seat, $opponents, true)) as $seat) {
                        $this->gainLife($seat, $amount);
                    }
                    break;
                }
                if (isset($effect['toController'])) {
                    $this->gainLife(str_starts_with((string) $target, 'o:') ? ($this->objects[(int) explode(':', (string) $target)[1]] ?? null)?->controller ?? $controller : $controller, $amount);
                    break;
                }
                $this->gainLife(isset($effect['target']) && str_starts_with((string) $target, 'p:') ? (int) substr((string) $target, 2) : $controller, $amount);
                break;

            case 'sacrifice':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $this->log("{$this->players[$affected->controller]->name} sacrifices {$affected->name()}.");
                    $this->sacrifice($affected);
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
                // `Whenever a creature you control explores, …`
                foreach ($affected !== null ? $this->permanents($affected->controller) : [] as $permanent) {
                    $this->trigger($permanent, 'creature_explores', $permanent->controller, null, $affected->id);
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
                    // `copy that spell`: the spell that triggered it, while it is still on the stack.
                    if (isset($effect['spell']) ? ! self::isAbility($copied) && $copied['object'] === $effect['spell'] : $copied['id'] === (int) substr((string) $target, 2)) {
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
                        $this->lostLife($seat);
                    }
                    $this->gainLife($controller, count($opponents));
                    $this->log("{$this->players[$controller]->name} extorts.");
                }
                break;

            case 'optional_red_goblin':
                $this->pendingChoice = [
                    'type' => 'optional_red_goblin', 'id' => $this->nextChoiceId++, 'seat' => $controller,
                    'object' => $source->id, 'incarnation' => $sourceIncarnation ?? $source->incarnation,
                    'label' => $source->name(), 'options' => ['accept', 'decline'],
                ];

                return true;

            case 'optional_source_untap':
                $this->pendingChoice = [
                    'type' => 'optional_source_untap', 'id' => $this->nextChoiceId++, 'seat' => $controller,
                    'object' => $source->id, 'incarnation' => $sourceIncarnation ?? $source->incarnation,
                    'label' => $source->name(), 'options' => ['accept', 'decline'],
                ];

                return true;

            case 'optional_source_counter':
                $this->pendingChoice = [
                    'type' => 'optional_source_counter', 'id' => $this->nextChoiceId++, 'seat' => $controller,
                    'object' => $source->id, 'incarnation' => $sourceIncarnation ?? $source->incarnation,
                    'label' => $source->name(), 'options' => ['accept', 'decline'],
                ];

                return true;

            case 'optional_life_gain':
                $this->pendingChoice = [
                    'type' => 'optional_life_gain', 'id' => $this->nextChoiceId++, 'seat' => $controller,
                    'object' => $source->id, 'incarnation' => $sourceIncarnation ?? $source->incarnation,
                    'label' => $source->name(), 'options' => ['accept', 'decline'],
                ];

                return true;

            case 'optional_life_loss':
                $this->pendingChoice = [
                    'type' => 'optional_life_loss', 'id' => $this->nextChoiceId++, 'seat' => $controller,
                    'object' => $source->id, 'incarnation' => $sourceIncarnation ?? $source->incarnation,
                    'target' => $target, 'label' => $source->name(), 'options' => ['accept', 'decline'],
                ];

                return true;

            case 'lose_life':
                $seats = match (true) {
                    ($effect['each'] ?? null) === 'player' => array_keys($this->players),
                    isset($effect['each']) => $opponents,
                    isset($effect['you']) => [$controller],
                    // "Its controller loses 2 life": whoever controlled the target; for a countered spell, see the counter effect.
                    isset($effect['toController']) => [str_starts_with((string) $target, 'p:') ? (int) substr((string) $target, 2) : $this->objects[(int) explode(':', (string) $target)[1]]->controller],
                    default => [(int) substr((string) $target, 2)],
                };
                foreach ($seats as $seat) {
                    $this->players[$seat]->life -= $amount;
                    $this->lostLife($seat);
                }
                break;

            case 'destroy':
            case 'exile':
                if (isset($effect['all'])) {
                    $doomed = $this->allMatching($effect['all'], $source, $controller);
                    if ($effect['type'] === 'destroy') {
                        $this->withDeathObservers($doomed, fn (GameObject $object) => $this->destroy($object, ! ($effect['noRegen'] ?? false)));
                    } else {
                        foreach ($doomed as $object) {
                            $this->moveTo($object, GameObject::EXILE);
                        }
                    }
                    break;
                }
                if ($effect['type'] === 'destroy') {
                    $this->destroy($affected, ! ($effect['noRegen'] ?? false));
                    break;
                }
                // "… until this leaves the battlefield": nothing is exiled if it already has (rule 610.3c).
                if ($effect['until'] ?? false) {
                    if ($self === null || $self->zone !== GameObject::BATTLEFIELD || $affected === null) {
                        break;
                    }
                    $this->moveTo($affected, GameObject::EXILE);
                    $self->holding[] = $affected->id;
                    if ($effect['until'] === 'hand') {
                        $affected->exiledBy = 'held:hand';
                    }
                    break;
                }
                $this->moveTo($affected, GameObject::EXILE);
                break;

            case 'airbend':
                // Airbend (TLA): exiled; its owner may cast it for {2} for as long as it remains exiled (see Game::castCost()).
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $this->moveTo($affected, GameObject::EXILE);
                    $affected->exiledBy = 'airbend';
                    $affected->exiledOn = $this->turn;
                    $this->log("{$affected->name()} is airbent.");
                }
                break;

            case 'prevent_dealing':
                if ($affected !== null) {
                    $affected->preventDealing = $this->turn;
                    $this->log("Damage {$affected->name()} would deal is prevented this turn.");
                }
                break;

            case 'fog':
                $this->fogTurn = $this->turn;
                $this->log('Combat damage is prevented this turn.');
                break;

            case 'ring':
                $this->temptWithRing($controller);
                break;

            case 'no_prevention':
                $this->unpreventableTurn = $this->turn;
                $this->log("Damage can't be prevented this turn.");
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
                // Evacuation: `Return all creatures to their owners' hands.`
                if (isset($effect['all'])) {
                    foreach ($this->allMatching($effect['all'], $source, $controller) as $object) {
                        $this->moveTo($object, GameObject::HAND);
                    }
                    break;
                }
                if ($affected !== null) {
                    $this->moveTo($affected, GameObject::HAND);
                }
                break;

            case 'return_self':
                // `When CARDNAME dies, return it to the battlefield tapped under its owner's control.`
                if ($affected !== null && $affected->zone !== GameObject::BATTLEFIELD) {
                    $this->log("{$affected->name()} returns to the battlefield.");
                    $this->putOntoBattlefield($affected, $affected->owner, tapped: (bool) ($effect['tapped'] ?? false));
                }
                break;

            case 'bottom_self':
                if ($affected !== null) {
                    $this->log("{$affected->name()} is put on the bottom of {$this->players[$affected->owner]->name}'s library.");
                    $this->moveTo($affected, GameObject::LIBRARY);
                    // moveTo() puts it on top (the end of the list); the bottom is the start.
                    $library = &$this->players[$affected->owner]->library;
                    if ($affected->zone === GameObject::LIBRARY && end($library) === $affected->id) {
                        array_unshift($library, array_pop($library));
                    }
                    unset($library);
                }
                break;

            case 'cant_block_source':
                if ($affected !== null) {
                    $affected->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ["can't block o{$source->id}"]];
                }
                break;

            case 'must_block_source':
                if ($affected !== null) {
                    $affected->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ["blocks o{$source->id} if able"]];
                }
                break;

            case 'pump':
            case 'base_pt':
                // Goblin Piledriver: `… for each other attacking Goblin.`
                if (isset($effect['perAttacking'])) {
                    $times = count(array_filter(array_keys($this->attackers), fn (int $id) => $id !== $source->id && isset($this->objects[$id])
                        && ($effect['perAttacking'] === 'creature' || in_array($effect['perAttacking'], $this->objects[$id]->definition()->subtypes, true))));
                    $effect = ['power' => $effect['power'] * $times, 'toughness' => $effect['toughness'] * $times] + $effect;
                }
                // `… for each creature blocking it.`
                if ($effect['perBlocking'] ?? false) {
                    $times = count(array_keys($this->blockers, $source->id, true));
                    $effect = ['power' => $effect['power'] * $times, 'toughness' => $effect['toughness'] * $times] + $effect;
                }
                if (isset($effect['xPump'])) {
                    $n = isset($effect['amount']) ? $amount : $x;
                    $effect = ['power' => $effect['xPump'][0] * $n, 'toughness' => $effect['xPump'][1] * $n] + $effect;
                }
                $pumped = match ($effect['each'] ?? null) {
                    null => $affected === null ? [] : [$affected],
                    'yours' => $this->permanents($controller),
                    'opponents' => array_filter($this->permanents(), fn (GameObject $o) => $o->controller !== $controller),
                    'without_flying' => array_filter($this->permanents(), fn (GameObject $o) => ! $this->hasKeyword($o, 'flying')),
                    'attacking' => array_filter($this->permanents(), fn (GameObject $o) => isset($this->attackers[$o->id])),
                    default => $this->permanents(),
                };
                $subtype = ($effect['subtype'] ?? null) === 'chosen' ? $this->chosenType ?? $this->choiceFor($controller, 'type') : $effect['subtype'] ?? null;
                foreach ($pumped as $object) {
                    if (isset($effect['each']) && ! $this->isCreature($object) || (($effect['other'] ?? false) && $object->id === $source->id)) {
                        continue;
                    }
                    // `If that creature would die this turn, exile it instead.`
                    if ($effect['exileIfDies'] ?? false) {
                        $object->exileIfDies = $this->turn;
                    }
                    if ($subtype !== null && ! in_array($subtype, $object->definition()->subtypes, true) && ! in_array('changeling', $object->definition()->keywords, true)) {
                        continue;
                    }
                    $object->untilEndOfTurn[] = ['power' => $effect['power'], 'toughness' => $effect['toughness'], 'keywords' => array_map(fn (string $keyword) => $keyword === 'protection from the color of your choice' ? 'protection from '.$this->colorToFear($controller) : $keyword, $effect['keywords'])] + (isset($effect['base']) ? ['base' => $effect['base']] : []) + (($effect['animate'] ?? false) ? ['creature' => true] : []);
                }
                break;

            case 'end_turn':
                $this->endingTurn = true;
                break;

            case 'choose_type':
                $this->chosenType = $this->choiceFor($controller, 'type');
                $this->log("{$this->players[$controller]->name} chooses {$this->chosenType}.");
                break;

            case 'fabricate':
                if ($self === null) {
                    $this->fabricateServos($source, $controller, $amount);
                    break;
                }
                $this->pendingChoice = [
                    'type' => 'fabricate', 'id' => $this->nextChoiceId++, 'seat' => $controller,
                    'object' => $source->id, 'incarnation' => $sourceIncarnation ?? $source->incarnation,
                    'count' => $amount, 'label' => $source->name(), 'options' => ['counters', 'servos'],
                ];

                return true;

            case 'counters':
                if (($effect['each'] ?? null) === 'yours') {
                    foreach ($this->permanents($controller) as $object) {
                        if ((isset($effect['subtype']) ? (in_array($effect['subtype'], $object->definition()->subtypes, true) || in_array('changeling', $object->definition()->keywords, true)) : $this->isCreature($object))
                            && (! ($effect['other'] ?? false) || $object->id !== $source->id)
                            && (! isset($effect['withKeyword']) || $this->hasKeyword($object, $effect['withKeyword']))
                            && (! isset($effect['withCounter']) || $object->counter('+1/+1') > 0)) {
                            $object->addCounters('+1/+1', $amount);
                        }
                    }
                    break;
                }
                if (($effect['each'] ?? null) === 'attacking') {
                    foreach ($this->permanents() as $object) {
                        if (isset($this->attackers[$object->id]) && (! ($effect['other'] ?? false) || $object->id !== $source->id)) {
                            $object->addCounters('+1/+1', $amount);
                        }
                    }
                    break;
                }
                $affected?->addCounters($effect['kind'] ?? '+1/+1', $amount);
                break;

            case 'double_counters':
                // Rule 701.10e: put as many +1/+1 counters on it as it already has.
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD && $affected->counter('+1/+1') > 0) {
                    $affected->addCounters('+1/+1', $affected->counter('+1/+1'));
                    $this->log("{$affected->name()} now has {$affected->counter('+1/+1')} +1/+1 counters.");
                }
                break;

            case 'endure':
                // Endure N: counters while it's here, else an N/N Spirit; a target that became illegal does nothing.
                if (isset($effect['target']) || ($effect['sameTarget'] ?? false)) {
                    $affected?->addCounters('+1/+1', $amount);
                } elseif ($source->zone === GameObject::BATTLEFIELD) {
                    $source->addCounters('+1/+1', $amount);
                } else {
                    $this->createToken(['name' => 'Spirit Token', 'type' => 'Token Creature — Spirit', 'types' => ['Creature'], 'subtypes' => ['Spirit'], 'colors' => ['W'], 'power' => (string) $amount, 'toughness' => (string) $amount, 'text' => '', 'manaCost' => null], $controller);
                }
                break;

            case 'sac_unless':
                // Paid when its controller can, else sacrificed.
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    if (($payment = $this->payFor($controller, $effect['cost'])) !== null) {
                        $this->pay($controller, $payment);
                        $this->log("{$this->players[$controller]->name} pays {$effect['cost']} for {$affected->name()}.");
                    } else {
                        $this->log("{$this->players[$controller]->name} sacrifices {$affected->name()}.");
                        $this->sacrifice($affected);
                    }
                }
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
                        $this->sacrifice($affected);
                    }
                }
                break;

            case 'tap':
            case 'untap':
                // `Untap all creatures you control.` / `Tap all creatures your opponents control.`
                if (isset($effect['each'])) {
                    foreach ($this->permanents() as $object) {
                        if ($this->isCreature($object) && ($object->controller === $controller) === ($effect['each'] === 'yours') && ! (($effect['other'] ?? false) && $object->id === $source->id)) {
                            $object->tapped = $effect['type'] === 'tap';
                        }
                    }
                    break;
                }
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    if ($effect['type'] === 'untap' && $affected->tapped && $affected->counter('stun') > 0) {
                        // Like an untap step, an untap effect consumes one stun counter instead.
                        $affected->addCounters('stun', -1);
                    } else {
                        $affected->tapped = $effect['type'] === 'tap';
                    }
                    $affected->frozen = $affected->frozen || ($effect['freeze'] ?? false);
                    $affected->addCounters('stun', (int) ($effect['stun'] ?? 0));
                }
                break;

            case 'sacrifice_end_combat':
                // `Whenever CARDNAME attacks, sacrifice it at end of combat.`
                if ($self !== null && $self->zone === GameObject::BATTLEFIELD) {
                    $this->sacrificeAtEndOfCombat[$self->id] = $self->incarnation;
                }
                break;

            case 'destroy_end_combat':
                // `Whenever CARDNAME blocks or becomes blocked by a (non-Wall) creature, destroy that creature at end of combat.`
                // `Whenever CARDNAME deals combat damage to a creature, destroy that creature at end of combat.`: the creature it dealt damage to.
                $that = isset($effect['target']) ? $affected : $this->objects[(int) ($effect['that'] ?? -1)] ?? null;
                if ($that !== null && $that->zone === GameObject::BATTLEFIELD && ! in_array($effect['notSubtype'] ?? null, $that->definition()->subtypes, true)) {
                    $this->destroyAtEndOfCombat[$that->id] = $that->incarnation;
                }
                break;

            case 'freeze':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->frozen = true;
                }
                break;

            case 'goad':
                // With one opponent, a goaded creature simply attacks each combat if able (rule 701.15b).
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->goadedBy = $controller;
                    $this->log("{$affected->name()} is goaded.");
                }
                break;

            case 'token':
                // Beast Within: the destroyed permanent's last controller.
                $for = isset($effect['toController']) && $target !== null && str_starts_with($target, 'o:') ? ($this->objects[(int) explode(':', $target)[1]] ?? null)?->controller ?? $controller : $controller;
                $this->creatingTokens = true;
                $created = [];
                for ($i = 0; $i < $amount; $i++) {
                    $token = $this->createToken((array) $effect['token'], $for);
                    $created[] = $token;
                    if ($effect['haste'] ?? false) {
                        $token->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['haste']];
                    }
                    if (isset($effect['endStep'])) {
                        $token->alt = $effect['endStep'] === 'exile' ? 'end_exile' : 'temporary';
                    }
                    if ($effect['tapped'] ?? false) {
                        $token->tapped = true;
                    }
                    // `… creature token that's tapped and attacking.`: attacking only once attackers are declared.
                    if ($effect['attacking'] ?? false) {
                        $token->tapped = true;
                        if ($this->step === Step::DeclareAttackers || $this->step === Step::DeclareBlockers) {
                            $this->attackers[$token->id] = true;
                        }
                    }
                    // Dwarven Shortsword: `create a 2/2 red Dwarf creature token, then attach CARDNAME to it.`
                    if (($effect['attachSelf'] ?? false) && $self !== null && $self->zone === GameObject::BATTLEFIELD) {
                        $self->attachedTo = $token->id;
                    }
                }
                $this->creatingTokens = false;
                foreach ($amount > 0 ? $this->permanents($for) : [] as $permanent) {
                    $this->trigger($permanent, 'you_create_tokens', $for);
                    if (array_filter($created, fn (GameObject $token) => $token->id !== $permanent->id && $token->zone === GameObject::BATTLEFIELD && $this->isCreature($token)) !== []) {
                        $this->trigger($permanent, 'creatures_enter_other', $for);
                    }
                }
                $this->log("{$this->players[$for]->name} creates {$amount} {$effect['token']['name']}".($amount === 1 ? '' : 's').'.');
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
                        // `{X}`: the X this spell was cast with.
                        $unless = isset($effect['unless']) ? str_replace('{X}', '{'.max(0, $x).'}', $effect['unless']) : null;
                        if ($unless !== null && ($payment = $this->payFor($item['controller'], $unless)) !== null) {
                            $this->pay($item['controller'], $payment);
                            $this->log("{$this->players[$item['controller']]->name} pays {$unless}, so {$countered->name()} is not countered.");
                            break;
                        }
                        array_splice($this->stack, $index, 1);
                        $this->log("{$countered->name()} is countered.", null, "{$countered->name()} countered");
                        // `Its controller loses 2 life.` after it: that player.
                        $this->followed[(string) $target] = "p:{$item['controller']}";
                        if (($effect['exileCountered'] ?? false) && ! self::isAbility($item)) {
                            $this->moveTo($countered, GameObject::EXILE);
                        } else {
                            $this->spellLeavesStack($countered, $item);
                        }
                        break;
                    }
                }
                break;

            case 'exile_graveyard':
                $seats = match ($effect['each'] ?? null) {
                    'opponent' => array_values($opponents),
                    'player' => array_keys($this->players),
                    default => $target === null ? [] : [(int) substr($target, 2)],
                };
                foreach ($seats as $seat) {
                    $exiled = $this->players[$seat]->graveyard;
                    foreach ($exiled as $id) {
                        $this->moveTo($this->objects[$id], GameObject::EXILE);
                    }
                    $this->log("{$this->players[$seat]->name}'s graveyard is exiled (".count($exiled).' '.(count($exiled) === 1 ? 'card' : 'cards').').');
                }
                break;

            case 'reveal_hand':
                $victim = (int) substr((string) $target, 2);
                $hand = $this->players[$victim]->hand;
                $this->log("{$this->players[$victim]->name} reveals ".($hand === [] ? 'an empty hand' : implode(', ', array_map(fn (int $id) => $this->objects[$id]->name(), $hand))).'.');
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
                // `Discard your hand.`, `Each player discards their hand.`
                if ($effect['all'] ?? false) {
                    foreach ($seats as $seat) {
                        $this->discardCards($seat, $this->players[$seat]->hand);
                    }
                    break;
                }
                // `You may discard a card. If you do, draw a card.`
                if ($effect['may'] ?? false) {
                    if ($this->players[$controller]->hand !== []) {
                        $this->pendingChoice = ['type' => 'discard', 'seat' => $controller, 'count' => 1, 'next' => [], 'may' => true, 'draw' => (int) ($effect['draw'] ?? 0)];

                        return true;
                    }
                    break;
                }
                $choosing = [];
                foreach ($seats as $seat) {
                    // With no more cards than they must discard, there is nothing to choose.
                    if (count($this->players[$seat]->hand) <= $amount) {
                        $this->discardCards($seat, $this->players[$seat]->hand);
                    } elseif ($effect['random'] ?? false) {
                        // `discards a card at random`: from the game's seed, so a replay discards the same card.
                        $this->discardCards($seat, array_slice($this->random()->shuffleArray($this->players[$seat]->hand), 0, $amount));
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
                    'player' => array_keys($this->players),
                    default => match (true) {
                        isset($effect['toController']) => [str_starts_with((string) $target, 'p:') ? (int) substr((string) $target, 2) : $this->objects[(int) explode(':', (string) $target)[1]]->controller],
                        default => [$target === null ? $controller : (int) substr($target, 2)],
                    },
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
                    $this->putOntoBattlefield($affected, $controller, tapped: $effect['tapped'] ?? false, entryState: isset($effect['endStep']) ? ['alt' => $effect['endStep'] === 'exile' ? 'end_exile' : 'temporary'] : []);
                    $this->log("{$affected->name()} returns to the battlefield.");
                    $this->followed = [(string) $target => "o:{$affected->id}:{$affected->incarnation}"];
                }
                break;

            case 'chosen':
                // The creature that fights; see the next effect.
                break;

            case 'fight':
                // `it fights target creature`: the source itself.
                $fighter = ($effect['selfFight'] ?? false) ? $source : ($effect['with'] === null ? null : $this->targetObject($effect['with']));
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
                $own = array_values(array_filter($this->permanents($controller), fn (GameObject $o) => $pick($o) && (! isset($effect['color']) || in_array($effect['color'], $o->definition()->colors, true))));
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
                        $this->sacrifice($affected);
                    }
                }
                break;

            case 'edict':
                // Each-player creature edicts request choices; other edict families retain their existing policy.
                $seats = match ($effect['each'] ?? (isset($effect['kind']) && $target !== null ? 'target' : 'opponent')) {
                    'target' => [(int) substr($target, 2)],
                    'player' => array_keys($this->players),
                    default => array_values($opponents),
                };
                $fits = fn (GameObject $o) => match ($effect['kind'] ?? 'permanent') {
                    'creature' => $this->isCreature($o),
                    'attacking creature' => $this->isCreature($o) && isset($this->attackers[$o->id]),
                    'creature or planeswalker' => $this->isCreature($o) || $o->definition()->isPlaneswalker(),
                    'artifact or creature' => $this->isCreature($o) || $o->definition()->is('Artifact'),
                    'nonland permanent' => ! $o->definition()->isLand(),
                    'permanent' => true,
                    default => $o->definition()->is(ucfirst($effect['kind'])),
                };
                if (($effect['each'] ?? null) === 'player' && ($effect['kind'] ?? null) === 'creature' && ! isset($effect['pick'])) {
                    // Each player chooses in APNAP order; no selected object moves until all have chosen.
                    usort($seats, fn (int $a, int $b) => (($a - $this->active + count($this->players)) % count($this->players)) <=> (($b - $this->active + count($this->players)) % count($this->players)));
                    $groups = [];
                    foreach ($seats as $seat) {
                        $eligible = array_values(array_filter($this->permanents($seat), $fits));
                        $cards = array_map(fn (GameObject $o) => $o->id, $eligible);
                        $groups[] = ['seat' => $seat, 'cards' => $cards, 'incarnations' => array_column(array_map(fn (GameObject $o) => ['id' => $o->id, 'incarnation' => $o->incarnation], $eligible), 'incarnation', 'id'),
                            'count' => min($amount, count($cards)), 'selected' => $amount === 0 ? [] : (count($cards) <= $amount ? $cards : null)];
                    }
                    if ($this->advanceEdictChoices($groups)) {
                        return true;
                    }
                    break;
                }
                $victims = [];
                foreach ($seats as $seat) {
                    $fitting = $fits;
                    // `… the creature with the greatest power among creatures they control.`: a tie goes to the weakest of them.
                    if (isset($effect['pick'])) {
                        $measure = fn (GameObject $o) => str_ends_with($effect['pick'], 'power') ? $this->power($o) : $o->definition()->cost->manaValue();
                        $values = array_map($measure, array_filter($this->permanents($seat), $fits));
                        if ($values !== []) {
                            $best = str_starts_with($effect['pick'], 'greatest') ? max($values) : min($values);
                            $fitting = fn (GameObject $o) => $fits($o) && $measure($o) === $best;
                        }
                    }
                    for ($i = 0; $i < $amount && ($victim = $this->weakest($seat, fn (GameObject $o) => $fitting($o) && ! isset($victims[$o->id]))) !== null; $i++) {
                        $victims[$victim->id] = $victim;
                    }
                }
                // All players' selections belong to one event, even when an observer is among them.
                $this->withDeathObservers(array_values($victims), function (GameObject $victim): void {
                    $this->log("{$this->players[$victim->controller]->name} sacrifices {$victim->name()}.");
                    $this->sacrifice($victim);
                });
                break;

            case 'cascade':
                // Not cast while the trigger waited: on the bottom of its owner's library.
                $hit = $this->objects[(int) ($effect['hit'] ?? 0)] ?? null;
                // Discover: into its owner's hand instead.
                if ($hit !== null && $hit->zone === GameObject::EXILE && $hit->exiledBy === 'cascade' && ($effect['toHand'] ?? false)) {
                    $this->moveTo($hit, GameObject::HAND);
                    $this->log("{$hit->name()} goes to {$this->players[$hit->owner]->name}'s hand.");
                    break;
                }
                if ($hit !== null && $hit->zone === GameObject::EXILE && $hit->exiledBy === 'cascade' && ($effect['stay'] ?? false)) {
                    $hit->exiledBy = 'hideaway';
                    break;
                }
                if ($hit !== null && $hit->zone === GameObject::EXILE && $hit->exiledBy === 'cascade') {
                    $this->removeFromZone($hit);
                    $hit->moveTo(GameObject::LIBRARY);
                    array_unshift($this->players[$hit->owner]->library, $hit->id);
                    $this->log("{$hit->name()} goes to the bottom of the library.");
                }
                break;

            case 'discover':
                // Discover N (rule 701.57): the first nonland card with mana value N or less, cast free or put into your hand.
                $player = $this->players[$controller];
                $missed = [];
                $hit = null;
                while ($hit === null && ($id = array_pop($player->library)) !== null) {
                    $card = $this->objects[$id]->printed();
                    if (! $card->isLand() && $card->cost->manaValue() <= $amount) {
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
                    $this->log("{$player->name} discovers nothing.");
                    break;
                }
                $hit->moveTo(GameObject::EXILE);
                $this->exile[] = $hit->id;
                $hit->exiledBy = 'cascade';
                $hit->exiledOn = $this->turn;
                $this->log("{$player->name} discovers {$hit->name()}.");
                $this->pendingTriggers[] = [
                    'source' => $source->id, 'incarnation' => $source->incarnation, 'controller' => $controller,
                    'effects' => [['type' => 'cascade', 'hit' => $hit->id, 'toHand' => true]], 'kinds' => [], 'label' => "{$source->name()}'s discover", 'text' => "Cast {$hit->name()} without paying its mana cost, or put it into your hand.",
                ];
                break;

            case 'madness':
                // Not cast while the trigger waited: into the graveyard.
                if ($source->zone === GameObject::EXILE && $source->exiledBy === 'madness') {
                    $this->moveTo($source, GameObject::GRAVEYARD);
                    $this->log("{$source->name()} goes to the graveyard.");
                }
                break;

            case 'regrow':
                if ($source->zone === GameObject::GRAVEYARD && isset($source->printed()->altCosts['regrowTo'])) {
                    $this->putOntoBattlefield($source, $source->owner, tapped: $source->printed()->altCosts['regrowTo'] === 'battlefield_tapped');
                    $this->log("{$source->name()} returns to the battlefield.");
                } elseif ($source->zone === GameObject::GRAVEYARD) {
                    $this->moveTo($source, GameObject::HAND);
                    $this->log("{$source->name()} returns to {$this->players[$source->owner]->name}'s hand.");
                }
                break;

            case 'ninjutsu':
                if ($source->zone === GameObject::HAND && in_array($this->step, [Step::DeclareBlockers, Step::CombatDamage, Step::FirstStrikeDamage], true)) {
                    $this->putOntoBattlefield($source, $controller, tapped: true, entryState: ['attacking' => true]);
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

            case 'scavenge':
                // The game picks your strongest creature for the counters.
                $creatures = array_filter($this->permanents($controller), fn (GameObject $o) => $this->isCreature($o));
                usort($creatures, fn (GameObject $a, GameObject $b) => $this->power($b) <=> $this->power($a));
                if ($creatures !== [] && $amount > 0) {
                    $creatures[0]->addCounters('+1/+1', $amount);
                    $this->log("{$creatures[0]->name()} gets {$amount} +1/+1 counter".($amount === 1 ? '' : 's').' (scavenge).');
                }
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
                    $this->putOntoBattlefield($source, $controller, entryState: ['unearthed' => true]);
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

            case 'ingest':
                $opponent = $this->opponent($controller);
                if (($top = end($this->players[$opponent]->library)) !== false) {
                    $this->moveTo($this->objects[$top], GameObject::EXILE);
                    $this->log("Ingest: {$this->players[$opponent]->name} exiles {$this->objects[$top]->name()} from the top of their library.");
                }
                break;

            case 'incubate':
                $incubator = $this->createToken(['name' => 'Incubator Token', 'type' => 'Token Artifact — Incubator', 'types' => ['Artifact'], 'subtypes' => ['Incubator'], 'colors' => [], 'text' => '{2}: Transform Incubator Token.', 'manaCost' => null, 'layout' => 'transform',
                    'back' => ['name' => 'Phyrexian Token', 'type' => 'Token Artifact Creature — Phyrexian', 'types' => ['Artifact', 'Creature'], 'subtypes' => ['Phyrexian'], 'colors' => [], 'power' => '0', 'toughness' => '0', 'text' => '', 'manaCost' => null]], $controller);
                $incubator->addCounters('+1/+1', $amount);
                $this->log("{$this->players[$controller]->name} incubates {$amount}.");
                break;

            case 'divided':
                if ($target !== null && $target !== '-') {
                    $this->dealDamage($source, $target, intdiv($effect['total'], $effect['sharing']) + ($effect['slot'] === 0 ? $effect['total'] % $effect['sharing'] : 0));
                }
                break;

            case 'land_from_hand':
                $land = array_values(array_filter($this->players[$controller]->hand, fn (int $id) => $this->objects[$id]->printed()->isLand()))[0] ?? null;
                if ($land !== null) {
                    $this->putOntoBattlefield($this->objects[$land], $controller, tapped: $effect['tapped'] ?? false);
                    $this->log("{$this->players[$controller]->name} puts {$this->objects[$land]->name()} onto the battlefield from their hand.");
                }
                break;

            case 'gy_shuffle':
                // The best cards back: highest mana value nonlands first.
                $player = $this->players[$controller];
                $cards = $player->graveyard;
                usort($cards, fn (int $a, int $b) => [$this->objects[$a]->printed()->isLand(), $this->objects[$b]->definition()->cost->manaValue()] <=> [$this->objects[$b]->printed()->isLand(), $this->objects[$a]->definition()->cost->manaValue()]);
                foreach (array_slice($cards, 0, $effect['amount'] ?? count($cards)) as $id) {
                    $this->moveTo($this->objects[$id], GameObject::LIBRARY);
                }
                $this->shuffle($controller);
                $this->log("{$player->name} shuffles cards from their graveyard into their library.");
                break;

            case 'copy_token':
                // An unmerged transforming copy keeps both faces and enters on the same face.
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $copyCard = $affected->printed()->card;
                    if ($affected->faceDown && ($affected->printed()->morph['kind'] ?? null) === 'morph') {
                        // Plain morph copies its nameless 2/2 characteristics, not the
                        // printed card or our synthetic turn-face-up action (708.2).
                        $copyCard = array_replace($affected->printed()->faceDownCard(), ['name' => '', 'text' => '']);
                    }
                    $copy = $this->createToken(
                        $copyCard,
                        $controller,
                        ['haste' => $effect['haste'] ?? false] + (isset($effect['endStep']) ? ['alt' => $effect['endStep'] === 'exile' ? 'end_exile' : 'temporary'] : []),
                        transformed: ! $affected->faceDown && $affected->mutations === [] && $affected->transformed && ($affected->card['layout'] ?? null) === 'transform'
                    );
                    $this->log("{$this->players[$controller]->name} creates a token copy of {$copy->name()}.");
                }
                break;

            case 'bottom_draw':
                // A spare land when it holds two or more, else nothing.
                $player = $this->players[$controller];
                $lands = array_values(array_filter($player->hand, fn (int $id) => $this->objects[$id]->printed()->isLand()));
                if (($effect['draw'] ?? false) && count($lands) >= 2) {
                    $this->moveTo($this->objects[$lands[0]], GameObject::LIBRARY);
                    $player->library = [$lands[0], ...array_values(array_diff($player->library, [$lands[0]]))];
                    $this->log("{$player->name} puts a card from their hand on the bottom of their library.");
                    $this->draw($controller, 1);
                }
                break;

            case 'flicker':
                // It comes back as a new object, untapped and without counters, under its owner's control.
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $this->moveTo($affected, GameObject::EXILE);
                    if ($affected->zone === GameObject::EXILE) {
                        $this->putOntoBattlefield($affected, $affected->owner);
                        $this->log("{$affected->name()} is exiled and returns to the battlefield.");
                    }
                }
                break;

            case 'self_damage':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $this->dealDamage($affected, "o:{$affected->id}:{$affected->incarnation}", $this->power($affected));
                }
                break;

            case 'tuck':
                if ($affected !== null && in_array($affected->zone, [GameObject::BATTLEFIELD, GameObject::GRAVEYARD], true)) {
                    $this->moveTo($affected, GameObject::LIBRARY);
                    $library = &$this->players[$affected->owner]->library;
                    if (($effect['bottom'] ?? false) && in_array($affected->id, $library, true)) {
                        $library = [$affected->id, ...array_values(array_diff($library, [$affected->id]))];
                    }
                    unset($library);
                    $this->log("{$affected->name()} is put on the ".(($effect['bottom'] ?? false) ? 'bottom' : 'top')." of {$this->players[$affected->owner]->name}'s library.");
                }
                break;

            case 'amass':
                // An Army you control, or a new 0/0 black Army token of that subtype.
                $army = null;
                foreach ($this->permanents($controller) as $object) {
                    if ($this->isCreature($object) && in_array('Army', $object->definition()->subtypes, true)) {
                        $army = $object;
                        break;
                    }
                }
                $kind = $effect['subtype'] ?? 'Zombie';
                $army ??= $this->createToken(['name' => "{$kind} Army Token", 'type' => "Token Creature — {$kind} Army", 'types' => ['Creature'], 'subtypes' => [$kind, 'Army'], 'colors' => ['B'], 'power' => '0', 'toughness' => '0', 'text' => '', 'manaCost' => null], $controller);
                $army->addCounters('+1/+1', $amount);
                $this->log("{$this->players[$controller]->name} amasses {$kind} {$amount}.");
                break;

            case 'earthbend':
                // Earthbend (TLA): awaken, and `When it dies or is exiled, return it to the battlefield tapped.`
                if (($land = $this->awaken($controller, $amount)) !== null) {
                    $land->earthbent = true;
                }
                break;

            case 'extra_turn':
                array_unshift($this->extraTurns, $controller);
                $this->log("{$this->players[$controller]->name} will take an extra turn after this one.");
                break;

            case 'connive':
                // `Up to one target creature you control connives.` with no target chosen: nothing.
                if (isset($effect['target']) && $affected === null) {
                    break;
                }
                $this->draw($controller, 1);
                $hand = $this->players[$controller]->hand;
                $this->log("{$this->players[$controller]->name} connives.");
                if (count($hand) === 1) {
                    $this->connived($affected, $hand);
                    $this->discardCards($controller, $hand);
                } elseif ($hand !== []) {
                    $this->pendingChoice = ['type' => 'discard', 'seat' => $controller, 'count' => 1, 'next' => []] + ($affected === null ? [] : ['connive' => [$affected->id, $affected->incarnation]]);

                    return true;
                }
                break;

            case 'job_select':
                // Job select: a 1/1 Hero token, and this Equipment attached to it.
                $hero = $this->createToken(['name' => 'Hero Token', 'type' => 'Token Creature — Hero', 'types' => ['Creature'], 'subtypes' => ['Hero'], 'colors' => [], 'power' => '1', 'toughness' => '1', 'text' => '', 'manaCost' => null], $controller);
                if ($self !== null) {
                    $self->attachedTo = $hero->id;
                    $this->log("{$this->players[$controller]->name} creates a Hero token, and {$self->name()} is attached to it.");
                }
                break;

            case 'extra_land':
                // One more land drop this turn: as if one fewer land had been played (reset each turn).
                $this->players[$controller]->landsPlayed--;
                break;

            case 'haunt':
                if ($source->zone === GameObject::GRAVEYARD) {
                    $this->haunt($source, $controller);
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
                // `Whenever you scry, …`, `Whenever you surveil, …` (rule 701.22b: not for scry 0).
                foreach ($amount > 0 && ! ($effect['quiet'] ?? false) ? $this->permanents($controller) : [] as $permanent) {
                    $this->trigger($permanent, "you_{$effect['type']}", $controller);
                }
                if ($amount > 0 && $cards !== []) {
                    $this->pendingChoice = ['type' => $effect['type'], 'seat' => $controller, 'cards' => $cards];

                    return true;
                }
                break;

            case 'impulse':
                // Exiled from the top; playable until the end of this turn or the controller's next.
                $until = $this->turn;
                $window = $effect['until'] === 'end_step' ? ($this->active === $controller ? 'this' : 'next') : $effect['until'];
                if ($window !== 'this') {
                    for ($seat = $this->active, $until++; ($seat = $this->opponent($seat)) !== $controller && $until < $this->turn + 8; $until++);
                }
                $exiled = [];
                for ($i = 0; $i < $amount && ($top = array_pop($this->players[$controller]->library)) !== null; $i++) {
                    $this->players[$controller]->library[] = $top;
                    $this->moveTo($this->objects[$top], GameObject::EXILE);
                    $this->objects[$top]->exiledBy = 'impulse';
                    $this->objects[$top]->exiledOn = $until;
                    $this->objects[$top]->playableFrom = $window === 'during_next' ? $until : 0;
                    $exiled[] = $this->objects[$top]->name();
                }
                if ($exiled !== []) {
                    $this->log("{$this->players[$controller]->name} exiles ".implode(', ', $exiled).' and may play '.(count($exiled) === 1 ? 'it' : 'them').match ($window) {
                        'this' => ' this turn.',
                        'during_next' => ' during their next turn.',
                        default => ' until the end of their next turn.',
                    });
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

            case 'tutor':
                // One card of each name that fits; the searcher picks (rule 701.19b: they may fail to find one with a stated quality).
                $eligible = [];
                foreach (array_reverse($this->players[$controller]->library) as $id) {
                    $card = $this->objects[$id]->printed();
                    if ($this->matchesFilter($card, $effect['filter']) && ! isset($eligible[$card->name])) {
                        $eligible[$card->name] = $id;
                    }
                }
                if ($eligible === []) {
                    $this->log("{$this->players[$controller]->name} searches their library and finds nothing.");
                    $this->shuffle($controller);
                    break;
                }
                $this->pendingChoice = ['type' => 'look', 'seat' => $controller, 'cards' => array_values($eligible), 'eligible' => array_values($eligible), 'take' => 1, 'may' => $effect['filter'] !== 'any', 'rest' => 'library', 'to' => $effect['to']];

                return true;

            case 'regenerate':
                if ($affected !== null && $affected->zone === GameObject::BATTLEFIELD) {
                    $affected->shields++;
                }
                break;

            case 'prevent':
                $shielded = ($effect['you'] ?? false) ? "p:{$controller}" : ($affected !== null ? "o:{$affected->id}" : ($target === null ? null : implode(':', array_slice(explode(':', $target), 0, 2))));
                if ($shielded !== null) {
                    $this->prevent[$shielded] = ($effect['amount'] ?? 0) === 'all' ? PHP_INT_MAX : ($this->prevent[$shielded] ?? 0) + $amount;
                    if ($effect['gain'] ?? false) {
                        $this->preventGain[$shielded] = $controller;
                    }
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

            case 'bolster':
                $creatures = array_values(array_filter($this->permanents($controller), fn (GameObject $o) => $this->isCreature($o)));
                if ($creatures !== []) {
                    usort($creatures, fn (GameObject $a, GameObject $b) => $this->toughness($a) <=> $this->toughness($b));
                    $creatures[0]->addCounters('+1/+1', $amount);
                    $this->log("{$this->players[$controller]->name} bolsters {$amount}: {$creatures[0]->name()}.");
                }
                break;

            case 'name_exile':
                // `Choose a card name. Search target opponent's graveyard, hand, and library for … cards with that name and exile them.`
                $seat = (int) substr((string) $target, 2);
                $this->nameExile($seat, $effect['filter'], (int) $effect['amount'], $source);
                break;

            case 'shuffle':
                // `Then shuffle.`, or `Then that player shuffles.` after an effect on a player or their permanent.
                $seat = ($effect['that'] ?? false) ? ($this->targetOwner($target) ?? $controller) : $controller;
                $this->shuffle($seat);
                $this->log("{$this->players[$seat]->name} shuffles their library.");
                break;

            case 'return_card':
                // `Return a creature card from your graveyard to your hand.`: the one with the highest mana value.
                // `… from among them`: only the cards just milled.
                $cards = array_values(array_filter($this->players[$controller]->graveyard, fn (int $id) => $id !== $source->id
                    && (! ($effect['fromMilled'] ?? false) || in_array($id, $this->lastMilled, true)) && match ($effect['kind']) {
                        'card' => true,
                        'instant or sorcery' => $this->objects[$id]->definition()->is('Instant') || $this->objects[$id]->definition()->is('Sorcery'),
                        'permanent' => ! $this->objects[$id]->definition()->is('Instant') && ! $this->objects[$id]->definition()->is('Sorcery'),
                        'nonland' => ! $this->objects[$id]->definition()->isLand(),
                        'creature or land' => $this->objects[$id]->definition()->isCreature() || $this->objects[$id]->definition()->isLand(),
                        'land' => $this->objects[$id]->definition()->isLand(),
                        default => $this->objects[$id]->definition()->is(ucfirst($effect['kind'])),
                    }));
                usort($cards, fn (int $a, int $b) => $this->objects[$b]->definition()->cost->manaValue() <=> $this->objects[$a]->definition()->cost->manaValue());
                if (($back = $this->objects[$cards[0] ?? -1] ?? null) !== null) {
                    $this->moveTo($back, GameObject::HAND);
                    $this->log("{$this->players[$controller]->name} returns {$back->name()} to hand.");
                }
                break;

            case 'return_that':
                // `When enchanted land dies, return that card to its owner's hand.`
                $that = $this->objects[(int) ($effect['that'] ?? -1)] ?? null;
                if ($that !== null && $that->zone === GameObject::GRAVEYARD) {
                    $this->moveTo($that, GameObject::HAND);
                    $this->log("{$that->name()} returns to its owner's hand.");
                }
                break;

            case 'sacrifice_unless_spent':
                // Court Hussar: `When CARDNAME enters, sacrifice it unless {W} was spent to cast it.`
                if ($self !== null && $self->zone === GameObject::BATTLEFIELD && ! str_contains($self->spent, $effect['color'])) {
                    $this->log("No {{$effect['color']}} was spent to cast {$self->name()}, so {$this->players[$self->controller]->name} sacrifices it.");
                    $this->sacrifice($self);
                }
                break;

            case 'hideaway':
                if ($self !== null) {
                    $this->hideaway($self, $controller, $amount);
                }
                break;

            case 'play_hidden':
                $hidden = $self?->hidden === null ? null : ($this->objects[$self->hidden] ?? null);
                if ($hidden === null || $hidden->zone !== GameObject::EXILE || $hidden->exiledBy !== 'hideaway' || ! $this->conditionMet($effect['if'] ?? 'always', $self, $controller)) {
                    break;
                }
                $self->hidden = null;
                if ($hidden->printed()->isLand()) {
                    $this->putOntoBattlefield($hidden, $controller);
                    $this->log("{$this->players[$controller]->name} plays {$hidden->name()} from exile.");
                    break;
                }
                // Cast it for free while this waits on the stack, as cascade does; if not, it stays exiled.
                $hidden->exiledBy = 'cascade';
                $hidden->exiledOn = $this->turn;
                $this->log("{$this->players[$controller]->name} may cast {$hidden->name()} without paying its mana cost.");
                $this->pendingTriggers[] = [
                    'source' => $self->id, 'incarnation' => $self->incarnation, 'controller' => $controller,
                    'effects' => [['type' => 'cascade', 'hit' => $hidden->id, 'stay' => true]], 'kinds' => [], 'label' => "{$self->name()}'s hideaway", 'text' => "Cast {$hidden->name()} without paying its mana cost.",
                ];
                break;

            case 'pact':
                $this->pacts[] = ['seat' => $controller, 'cost' => $effect['cost'], 'turn' => $this->turn, 'name' => $source->name(), 'lose' => (bool) ($effect['lose'] ?? false)];
                break;

            case 'pay':
                // `you may pay any amount of {E}. …`: all of it, and X is what was paid.
                if ($effect['cost'] === 'any {E}') {
                    if (($paid = $this->players[$controller]->energy) > 0) {
                        $this->players[$controller]->energy = 0;
                        $this->log("{$this->players[$controller]->name} pays {$paid} energy for {$source->name()}.");
                        foreach ($effect['then'] as $then) {
                            $this->applyEffect($then, $source, $self, $controller, $paid, $target, $sourceIncarnation);
                        }
                    }
                    break;
                }
                // `you may exert CARDNAME. When you do, …` (rule 701.43): always, while it is still on the battlefield; it skips its controller's next untap step.
                if ($effect['cost'] === 'exert') {
                    if ($self !== null && $self->zone === GameObject::BATTLEFIELD) {
                        $self->frozen = true;
                        $this->log("{$this->players[$controller]->name} exerts {$self->name()}.");
                        foreach ($effect['then'] as $then) {
                            $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                        }
                    }
                    break;
                }
                // `you may behold a Dragon. If you do, …`: a Dragon card in your hand or a Dragon you control, whenever there is one.
                if (preg_match('/^behold (\w+)$/', $effect['cost'], $m)) {
                    $beheld = array_filter(
                        [...array_map(fn (int $id) => $this->objects[$id], $this->players[$controller]->hand), ...$this->permanents($controller)],
                        fn (GameObject $o) => $o->id !== $source->id && in_array($m[1], $o->definition()->subtypes, true)
                    );
                    if ($beheld !== []) {
                        $this->log("{$this->players[$controller]->name} beholds ".reset($beheld)->name()." for {$source->name()}.");
                        foreach ($effect['then'] as $then) {
                            $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                        }
                    }
                    break;
                }
                // `you may discard a card. When you do, …`: its controller chooses, as with `If you do, draw a card.`; see discard().
                if ($effect['cost'] === 'discard') {
                    if ($this->players[$controller]->hand !== []) {
                        $this->pendingChoice = ['type' => 'discard', 'seat' => $controller, 'count' => 1, 'next' => [], 'may' => true, 'then' => array_map(fn (array $then) => [$then, $target], $effect['then'])];

                        return true;
                    }
                    break;
                }
                // `you may sacrifice a Food (it, CARDNAME). If you do, …`, `you may mill two cards. …`: whenever it can.
                // `you may pay 2 life. …`: while 10 or more life would be left.
                if (preg_match('/^(?:sacrifice (?:self|sub:(\w+))|mill (\d+)|life (\d+))$/', $effect['cost'], $m)) {
                    $paid = match (true) {
                        ($m[1] ?? '') !== '' => $this->fodder($controller, $source, "any {$m[1]}"),
                        ($m[2] ?? '') !== '' => true,
                        ($m[3] ?? '') !== '' => $this->players[$controller]->life - (int) $m[3] >= 10,
                        default => $self !== null && $self->zone === GameObject::BATTLEFIELD && $self->controller === $controller ? $self : null,
                    };
                    if ($paid === null || $paid === false) {
                        break;
                    }
                    if ($paid instanceof GameObject) {
                        $this->log("{$this->players[$controller]->name} sacrifices {$paid->name()} for {$source->name()}.");
                        $this->sacrifice($paid);
                    } elseif (($m[2] ?? '') !== '') {
                        $this->mill($controller, (int) $m[2]);
                    } else {
                        $this->players[$controller]->life -= (int) $m[3];
                        $this->lostLife($controller);
                        $this->log("{$this->players[$controller]->name} pays {$m[3]} life for {$source->name()}.");
                    }
                    foreach ($effect['then'] as $then) {
                        $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                    }
                    break;
                }
                // `When CARDNAME dies, you may exile it. If you do, …`: while it is still that card in the graveyard.
                if ($effect['cost'] === 'exile self') {
                    if ($affected !== null && $affected->zone === GameObject::GRAVEYARD) {
                        $this->log("{$this->players[$controller]->name} exiles {$affected->name()} from their graveyard.");
                        $this->moveTo($affected, GameObject::EXILE);
                        foreach ($effect['then'] as $then) {
                            $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                        }
                    }
                    break;
                }
                // `you may exile a card from your graveyard. If you do, …`: lands first, then the lowest mana value; see graveFodder().
                // `you may return a land you control to its owner's hand. …`: only a land already tapped for mana.
                if (preg_match('/^(?:exile grave (\d+)|bounce land)$/', $effect['cost'], $m)) {
                    if (($m[1] ?? '') !== '') {
                        if (($exiled = $this->graveFodder($controller, (int) $m[1])) === null) {
                            break;
                        }
                        foreach ($exiled as $card) {
                            $this->moveTo($card, GameObject::EXILE);
                        }
                        $this->log("{$this->players[$controller]->name} exiles ".implode(', ', array_map(fn (GameObject $c) => $c->name(), $exiled))." from their graveyard for {$source->name()}.");
                    } else {
                        $lands = array_values(array_filter($this->permanents($controller), fn (GameObject $o) => $o->tapped && $o->definition()->isLand()));
                        if ($lands === []) {
                            break;
                        }
                        $this->log("{$this->players[$controller]->name} returns {$lands[0]->name()} to their hand for {$source->name()}.");
                        $this->moveTo($lands[0], GameObject::HAND);
                    }
                    foreach ($effect['then'] as $then) {
                        $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                    }
                    break;
                }
                // `you may forage. If you do, …`: three cards from your graveyard, else a Food, whenever it can.
                if ($effect['cost'] === 'forage') {
                    $exiled = $this->graveFodder($controller, 3);
                    $food = $exiled === null ? $this->fodder($controller, $source, 'any Food') : null;
                    if ($exiled !== null || $food !== null) {
                        foreach ($exiled ?? [] as $card) {
                            $this->moveTo($card, GameObject::EXILE);
                        }
                        if ($food !== null) {
                            $this->sacrifice($food);
                        }
                        $this->log("{$this->players[$controller]->name} forages for {$source->name()}.");
                        foreach ($effect['then'] as $then) {
                            $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                        }
                    }
                    break;
                }
                // `you may tap an untapped creature you control. If you do, …`: the weakest one, whenever there is one.
                if (preg_match('/^tap (creature|artifact)( other)?$/', $effect['cost'], $m)) {
                    $tapped = $this->weakest($controller, fn (GameObject $o) => ! $o->tapped && (($m[2] ?? '') === '' || $o->id !== $source->id)
                        && ($m[1] === 'creature' ? $this->isCreature($o) : $o->definition()->is('Artifact')));
                    if ($tapped !== null) {
                        $tapped->tapped = true;
                        $this->log("{$this->players[$controller]->name} taps {$tapped->name()} for {$source->name()}.");
                        foreach ($effect['then'] as $then) {
                            $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                        }
                    }
                    break;
                }
                // `you may collect evidence 4. If you do, …`: when the graveyard has enough, highest mana values first; see evidence().
                if (preg_match('/^evidence (\d+)$/', $effect['cost'], $m)) {
                    if (($evidence = $this->evidence($controller, $source->id, (int) $m[1])) !== null) {
                        foreach ($evidence as $exiled) {
                            $this->moveTo($this->objects[$exiled], GameObject::EXILE);
                        }
                        $this->log("{$this->players[$controller]->name} collects evidence {$m[1]} for {$source->name()}.");
                        foreach ($effect['then'] as $then) {
                            $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                        }
                    }
                    break;
                }
                // `you may sacrifice another creature. If you do, …`: only something cheap to lose, a token or a small creature.
                if (preg_match('/^sacrifice (\w+)( other)?$/', $effect['cost'], $m)) {
                    $fodder = $this->weakest($controller, fn (GameObject $o) => (($m[2] ?? '') === '' || $o->id !== $source->id)
                        && ($m[1] === 'permanent' || ($m[1] === 'creature' ? $this->isCreature($o) : $o->definition()->is(ucfirst($m[1])))));
                    if ($fodder !== null && ($fodder->definition()->isToken() || ($this->isCreature($fodder) && $this->power($fodder) + $this->toughness($fodder) <= 2))) {
                        $this->log("{$this->players[$controller]->name} sacrifices {$fodder->name()} for {$source->name()}.");
                        $this->sacrifice($fodder);
                        foreach ($effect['then'] as $then) {
                            $this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation);
                        }
                    }
                    break;
                }
                // `you may pay {2}{G}. If you do, …`: paid when it can be; `{E}{E}` from its controller's energy.
                $energy = str_contains($effect['cost'], '{E}') ? substr_count($effect['cost'], '{E}') : 0;
                if ($energy > 0 ? $this->players[$controller]->energy >= $energy : ($payment = $this->payFor($controller, $effect['cost'])) !== null) {
                    if ($energy > 0) {
                        $this->players[$controller]->energy -= $energy;
                    } else {
                        $this->pay($controller, $payment);
                    }
                    $this->log("{$this->players[$controller]->name} pays {$effect['cost']} for {$source->name()}.");
                    $remaining = $effect['then'];
                    while ($remaining !== []) {
                        $then = array_shift($remaining);
                        if ($this->applyEffect($then, $source, $self, $controller, $x, $target, $sourceIncarnation)) {
                            $this->pendingChoice['nestedSteps'] = array_merge(
                                $this->pendingChoice['nestedSteps'] ?? [],
                                array_map(fn (array $next) => [$next, $target], $remaining)
                            );

                            return true;
                        }
                    }
                }
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

            case 'prepare':
                if ($self !== null && $self->zone === GameObject::BATTLEFIELD && ! $self->prepared && $self->printed()->prepare !== null) {
                    $self->prepared = true;
                    $this->log("{$self->name()} becomes prepared.");
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
                // `When CARDNAME is put into a graveyard from anywhere, shuffle it …`: the card while it is still there.
                $self ??= ($effect['event'] ?? null) === 'to_graveyard_anywhere' && $source->zone === GameObject::GRAVEYARD ? $source : null;
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

        return $this->pendingChoice !== null;
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
            'colorless' => $card->colors === [],
            'permanent' => $card->isPermanentCard(),
            'nonland_permanent' => $card->isPermanentCard() && ! $card->isLand(),
            'any' => true,
            // `not:creature&mv_le_3`: all of them.
            default => str_contains($filter, '&') ? array_filter(explode('&', $filter), fn (string $piece) => ! $this->matchesFilter($card, $piece)) === []
                : (str_starts_with($filter, 'not:') ? ! $this->matchesFilter($card, substr($filter, 4))
                : (preg_match('/^mv_(le|ge)_(\d+)$/', $filter, $m) ? ($m[1] === 'le' ? $card->cost->manaValue() <= (int) $m[2] : $card->cost->manaValue() >= (int) $m[2])
                // `pow_le_2`: a creature card's printed power or toughness.
                : (preg_match('/^(pow|tou)_(le|ge)_(\d+)$/', $filter, $m) ? $card->isCreature() && is_numeric($value = $m[1] === 'pow' ? $card->power : $card->toughness) && ($m[2] === 'le' ? (int) $value <= (int) $m[3] : (int) $value >= (int) $m[3])
                // `creature|land` or `sub:Dwarf|sub:Equipment`: any of them.
                : (str_contains($filter, '|') ? array_filter(explode('|', $filter), fn (string $piece) => $this->matchesFilter($card, $piece)) !== []
                : (! str_starts_with($filter, 'sub:') || in_array(substr($filter, 4), $card->subtypes, true)))))),
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
        [$milled, $this->lastMilled] = [[], []];
        for ($i = 0; $i < $count && $this->players[$seat]->library !== []; $i++) {
            $object = $this->objects[end($this->players[$seat]->library)];
            $this->moveTo($object, GameObject::GRAVEYARD);
            $milled[] = $object->name();
            $this->lastMilled[] = $object->id;
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

            return ! in_array($id, array_column($this->pendingEntries, 'object'), true) && $card->isLand() && ($find === 'basic land' ? in_array('Basic', $card->supertypes, true) : in_array($find, $card->subtypes, true));
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
        // `… then shuffle and put that card on top.`
        if ($to === 'top') {
            $player->library = array_values(array_diff($player->library, [$land->id]));
            $this->shuffle($seat);
            $player->library[] = $land->id;
            $this->log("{$player->name} searches their library for {$land->name()} and puts it on top.");

            return;
        }
        if ($to === 'hand') {
            $this->moveTo($land, GameObject::HAND);
        } else {
            $this->putOntoBattlefield($land, $seat, tapped: $to === 'tapped');
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
     * @param array $entryState State applied by the existing permanent-entry path.
     * @param bool  $transformed Whether a transforming copy enters back face up.
     *
     * @return GameObject
     */
    private function createToken(array $token, int $controller, array $entryState = [], bool $transformed = false): GameObject
    {
        $object = new GameObject($this->nextId++, $controller, ['token' => true] + $token, GameObject::GONE);
        $this->objects[$object->id] = $object;
        $this->putOntoBattlefield($object, $controller, transformed: $transformed, entryState: $entryState);
        // `Whenever you create a (creature) token, …`; `… one or more tokens, …` once per batch.
        foreach ($this->permanents($controller) as $permanent) {
            $this->trigger($permanent, 'you_create_token', $controller, null, $object->id);
            if (! $this->creatingTokens) {
                $this->trigger($permanent, 'you_create_tokens', $controller);
            }
        }

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
     * A triggered ability's intervening "if" clause (rule 603.4).
     *
     * @param string     $condition  See TextParser::triggered().
     * @param GameObject $source
     * @param int        $controller
     *
     * @return bool
     */
    private function conditionMet(string $condition, GameObject $source, int $controller): bool
    {
        [$kind, $n] = explode(' ', $condition.' 0');

        return match ($kind) {
            'unprepared' => ! $source->prepared,
            'lands' => count(array_filter($this->permanents($controller), fn (GameObject $object) => $object->definition()->is('Land'))) >= (int) $n,
            'died' => $this->diedTurn === $this->turn && $this->died >= (int) $n,
            'gained' => $this->players[$controller]->gainedTurn === $this->turn && $this->players[$controller]->gained >= (int) $n,
            'creatures' => count(array_filter($this->permanents($controller), fn (GameObject $object) => $this->isCreature($object))) >= (int) $n,
            'no_creatures' => array_filter($this->permanents($controller), fn (GameObject $object) => $this->isCreature($object)) === [],
            'power_ge' => array_filter($this->permanents($controller), fn (GameObject $object) => $this->isCreature($object) && $this->power($object) >= (int) $n) !== [],
            // Coven: three or more creatures with different powers.
            'coven' => count(array_unique(array_map(fn (GameObject $object) => $this->power($object), array_filter($this->permanents($controller), fn (GameObject $object) => $this->isCreature($object))))) >= 3,
            'artifacts', 'enchantments' => count(array_filter($this->permanents($controller), fn (GameObject $object) => $object->definition()->is(ucfirst(rtrim($kind, 's'))))) >= (int) $n,
            'graveyard' => count($this->players[$controller]->graveyard) >= (int) $n,
            'gy_permanent' => count(array_filter($this->players[$controller]->graveyard, fn (int $id) => $this->objects[$id]->printed()->isPermanentCard())) >= (int) $n,
            'gy_creature', 'gy_land', 'gy_artifact', 'gy_enchantment' => count(array_filter($this->players[$controller]->graveyard, fn (int $id) => $this->objects[$id]->printed()->is(ucfirst(substr($kind, 3))))) >= (int) $n,
            'life' => $this->players[$controller]->life >= (int) $n,
            'your_turn' => $this->active === $controller,
            'attacked' => $this->players[$controller]->attackedTurn === $this->turn,
            'self_attacked' => $source->attackedTurn === $this->turn,
            // Day and night (rule 726): neither until it becomes day.
            'night' => $this->dayNight === 'night',
            // `When CARDNAME enters, if you cast it (from your hand), …`: as it entered.
            'cast_it' => $source->cast !== '',
            'cast_hand' => $source->cast === 'hand',
            'day' => $this->dayNight === 'day',
            'opponent_lost' => array_filter($this->players, fn (GamePlayer $other, int $seat) => $seat !== $controller && $other->lostLifeOnTurn === $this->turn, ARRAY_FILTER_USE_BOTH) !== [],
            'main_phase' => $this->active === $controller && in_array($this->step, [Step::PrecombatMain, Step::PostcombatMain], true),
            'attacking' => isset($this->attackers[$source->id]),
            'controls' => array_filter($this->permanents($controller), fn (GameObject $o) => (in_array($n, $o->definition()->subtypes, true) || $o->definition()->is($n))) !== [],
            'opp_controls' => array_filter($this->permanents(), fn (GameObject $o) => $o->controller !== $controller && (in_array($n, $o->definition()->subtypes, true) || $o->definition()->is($n))) !== [],
            'opp_flier' => array_filter($this->permanents(), fn (GameObject $o) => $o->controller !== $controller && $this->isCreature($o) && $this->hasKeyword($o, 'flying')) !== [],
            // `if you control two or more other Elves`.
            'controls_others' => count(array_filter($this->permanents($controller), fn (GameObject $o) => $o->id !== $source->id && (in_array(explode('_', $n)[0], $o->definition()->subtypes, true) || $o->definition()->is(explode('_', $n)[0])))) >= (int) (explode('_', $n)[1] ?? 1),
            'controls_other' => array_filter($this->permanents($controller), fn (GameObject $o) => $o->id !== $source->id && (in_array($n, $o->definition()->subtypes, true) || $o->definition()->is($n))) !== [],
            'not_your_turn' => $this->active !== $controller,
            'cast_noncreature' => $this->players[$controller]->noncreatureCastTurn === $this->turn,
            'void' => $this->nonlandLeftTurn === $this->turn || $this->warpedTurn === $this->turn,
            'hand_le' => count($this->players[$controller]->hand) <= (int) $n,
            'hand_ge' => count($this->players[$controller]->hand) >= (int) $n,
            'opp_life_le' => array_filter($this->players, fn (GamePlayer $player) => $player->seat !== $controller && ! $player->lost && $player->life <= (int) $n) !== [],
            'has_counter' => $source->counter('+1/+1') > 0,
            'alone' => array_filter($this->permanents($controller), fn (GameObject $o) => $o->id !== $source->id && $this->isCreature($o)) === [],
            'delirium' => count(array_filter(['Artifact', 'Battle', 'Creature', 'Enchantment', 'Instant', 'Kindred', 'Land', 'Planeswalker', 'Sorcery'], fn (string $type) => array_filter($this->players[$controller]->graveyard, fn (int $id) => $this->objects[$id]->definition()->is($type)) !== [])) >= (int) $n,
            'always' => true,
            'untapped' => ! $source->tapped,
            'tapped' => $source->tapped,
            'library_le' => count($this->players[$controller]->library) <= (int) $n,
            'no_hands' => array_filter($this->players, fn (GamePlayer $player) => $player->hand !== []) === [],
            'total_power' => array_sum(array_map(fn (GameObject $o) => $this->power($o), array_filter($this->permanents($controller), fn (GameObject $o) => $this->isCreature($o)))) >= (int) $n,
            'attackers' => $this->players[$controller]->attackedTurn === $this->turn && $this->attackedWith >= (int) $n,
            'hand_behind' => array_filter($this->players, fn (GamePlayer $player) => $player->seat !== $controller && ! $player->lost && count($player->hand) > count($this->players[$controller]->hand)) !== [],
            'lands_behind' => array_filter($this->players, fn (GamePlayer $player) => $player->seat !== $controller && ! $player->lost && $this->countYours($player->seat, 'land') > $this->countYours($controller, 'land')) !== [],
            'creatures_behind' => array_filter($this->players, fn (GamePlayer $player) => $player->seat !== $controller && ! $player->lost && $this->countYours($player->seat, 'creature') > $this->countYours($controller, 'creature')) !== [],
            'life_behind' => array_filter($this->players, fn (GamePlayer $player) => $player->seat !== $controller && ! $player->lost && $player->life > $this->players[$controller]->life) !== [],
            default => false,
        };
    }

    /**
     * Queues a permanent's abilities that trigger on an event (rule 603.2).
     *
     * @param GameObject                    $source
     * @param string                        $event                  See {@see CardDefinition::triggersOn()}.
     * @param int                           $controller             Who controlled the source when the event happened.
     * @param int|null                      $incarnation            The source's, if it has since changed zones.
     * @param int                           $context                For modular, the +1/+1 counters it died with; for graft, the creature that entered.
     * @param DepartureCharacteristics|null $contextCharacteristics The departing permanent's battlefield definition and current creature status.
     * @param CardDefinition|null           $sourceDefinition       The departing source's battlefield face for its own departure abilities.
     *
     * @return void
     */
    private function trigger(GameObject $source, string $event, int $controller, ?int $incarnation = null, int $context = 0, ?DepartureCharacteristics $contextCharacteristics = null, ?CardDefinition $sourceDefinition = null): void
    {
        $definition = $sourceDefinition ?? $source->definition();
        foreach ($definition->triggersOn($event) as $ability) {
            // Modular: the +1/+1 counters it had as it died. Graft: the creature that entered. Mentor: lesser power than it has now.
            $ability['effects'] = array_map(fn (array $effect) => match (true) {
                // `Whenever another creature you control enters, put a +1/+1 counter on it.`: the creature that entered.
                ($effect['entering'] ?? false) => ['thatObject' => $context] + $effect,
                ($effect['thatPlayer'] ?? false) => ['player' => $context] + $effect,
                ($effect['amount'] ?? null) === 'counters' => ['amount' => $context] + $effect,
                ($effect['amount'] ?? null) === 'that_much' => ['amount' => $context] + $effect,
                // Freeze battlefield mana value before movement resets the active face.
                ($effect['amount'] ?? null) === 'departing_mana_value' => ['amount' => $contextCharacteristics?->manaValue ?? $contextCharacteristics?->definition->cost->manaValue() ?? 0] + $effect,
                ($effect['amount'] ?? null) === 'combat_dealt' => ['amount' => $this->combatDealt[$event === 'combat_damage' ? $source->id : $context] ?? 0] + $effect,
                $effect['type'] === 'graft' => ['to' => $context] + $effect,
                ($effect['each'] ?? null) === 'that' => ['that' => $context] + $effect,
                $effect['type'] === 'cascade' => ['hit' => $context] + $effect,
                $effect['type'] === 'copy_spell' && ($effect['thatSpell'] ?? false) => ['spell' => $this->castSpell] + $effect,
                $event === 'to_graveyard_anywhere' => ['event' => $event] + $effect,
                ($effect['target'] ?? null) === 'attacking_lesser' => ['target' => 'attacking_power_lt_'.$this->power($source)] + $effect,
                in_array($effect['target'] ?? null, ['creature_other', '?creature_other'], true) => ['target' => str_replace('creature_other', "creature_not_{$source->id}", $effect['target'])] + $effect,
                // `another target creature card from your graveyard`: not the source, even once it is there.
                in_array($effect['target'] ?? null, ['creature_card_yours_other', '?creature_card_yours_other'], true) => ['target' => str_replace('creature_card_yours_other', "creature_card_yours_not_{$source->id}", $effect['target'])] + $effect,
                in_array($effect['target'] ?? null, ['creature_you_control_other', '?creature_you_control_other'], true) => ['target' => str_replace('creature_you_control_other', "creature_yours_not_{$source->id}", $effect['target'])] + $effect,
                // `another target permanent you control`, `another target nonland permanent`: not the source.
                (bool) preg_match('/^\??(nonland_)?permanent_you_control_other$/', $effect['target'] ?? '') => ['target' => str_replace('_you_control_other', "_yours_not_{$source->id}", $effect['target'])] + $effect,
                (bool) preg_match('/^\??(nonland_)?permanent_other$/', $effect['target'] ?? '') => ['target' => str_replace('_other', "_not_{$source->id}", $effect['target'])] + $effect,
                // `When CARDNAME dies, return it to its owner's hand.`: the card it became in the graveyard (rule 400.7).
                in_array($event, ['dies', 'to_graveyard'], true) && $source->zone === GameObject::GRAVEYARD && ($effect['self'] ?? false)
                    && (in_array($effect['type'], ['bounce', 'return_self', 'bottom_self'], true) || $effect['type'] === 'pay' && $effect['cost'] === 'exile self') => ['graveyardSelf' => $source->incarnation] + $effect,
                default => $effect,
            }, $ability['effects']);
            if (isset($ability['subtype']) && (($ability['other'] ?? false) && $context === $source->id
                || ! in_array($ability['subtype'], ($contextCharacteristics?->definition ?? ($this->objects[$context] ?? null)?->definition())?->subtypes ?? [], true))) {
                continue;
            }
            // `Whenever you sacrifice another creature, …`: the permanent sacrificed (see Game::sacrifice()).
            if (isset($ability['objectKind']) && (($ability['other'] ?? false) && $context === $source->id || ! $this->objectFits($this->objects[$context], $ability['objectKind'], $contextCharacteristics))) {
                continue;
            }
            // `Whenever a nontoken creature you control dies, …`: the creature that died, or this one (whose context is its +1/+1 counters).
            if (($ability['nontoken'] ?? false) && ($event === 'dies' ? $source : $this->objects[$context])->definition()->isToken()) {
                continue;
            }
            // `Whenever a creature token you control dies, …`: the creature that died.
            if (($ability['tokenOnly'] ?? false) && ! ($this->objects[$context] ?? null)?->definition()->isToken()) {
                continue;
            }
            // `Whenever another creature you control dies, if it had power 3 or greater, …`
            if (isset($ability['diedMinPower']) && $this->diedPower < $ability['diedMinPower']) {
                continue;
            }
            if (($ability['diedWithCounter'] ?? false) && ! $this->diedWithCounter) {
                continue;
            }
            // `Whenever a creature you control with power 4 or greater enters, …`: the creature that entered, or this one.
            if (isset($ability['minPower']) && $this->power($this->objects[$context] ?? $source) < $ability['minPower']) {
                continue;
            }
            // "When this creature enters, if it was kicked, …"
            if (($ability['kicked'] ?? false) && ! $source->kicked) {
                continue;
            }
            // "Whenever this creature attacks while saddled, …"
            if (($ability['saddled'] ?? false) && ! $this->hasKeyword($source, 'saddled')) {
                continue;
            }
            if (isset($ability['if']) && ! $this->conditionMet($ability['if'], $source, $controller)) {
                continue;
            }
            if (! $this->gained($source, $ability)) {
                continue;
            }
            // Echo triggers only until it has been paid once (rule 702.30a).
            if ($source->echoPaid && ($ability['effects'][0]['type'] ?? '') === 'echo') {
                continue;
            }
            // Valiant: `… for the first time each turn, …`; recorded with the activated-ability uses, under a negative key.
            if ($ability['oncePerTurn'] ?? false) {
                $key = -2 - (crc32($ability['text']) % 1000000000);
                if (($source->used[$key] ?? 0) === $this->turn) {
                    continue;
                }
                $source->used[$key] = $this->turn;
            }
            // `Whenever one or more other creatures you control die, …`: once for those dying together, while it waits to go on the stack.
            if (($ability['batch'] ?? false) && array_filter($this->pendingTriggers, fn (array $pending) => $pending['source'] === $source->id
                && $pending['text'] === str_replace('CARDNAME', $definition->name, $ability['text'])) !== []) {
                continue;
            }
            $this->pendingTriggers[] = [
                'source' => $source->id,
                'incarnation' => $incarnation ?? $source->incarnation,
                'controller' => $controller,
                'effects' => $ability['effects'],
                'kinds' => self::targetKindsOf($ability['effects']),
                'label' => "{$definition->name}'s ability",
                'text' => str_replace('CARDNAME', $definition->name, $ability['text']),
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
            // Not targeted: the object the event named.
            if (isset($trigger['targets'])) {
                array_shift($this->pendingTriggers);
                $this->pushAbility(array_diff_key($trigger, ['targets' => true]), $trigger['targets'], 'triggers');

                continue;
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
        [$wardMana, $wardLife, $wardDiscard] = $this->wardCost(array_map(fn (string $target) => $this->pinTarget($target), $targets), $trigger['controller'], false);
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
            'x' => $ability['x'] ?? 0,
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
        // Channel, reinforce and the like: abilities activated by discarding the card from your hand.
        foreach ($this->players[$seat]->hand as $id) {
            foreach ($this->objects[$id]->definition()->activated as $index => $ability) {
                if (($ability['hand'] ?? false) && $this->canActivate($seat, $id, $index)) {
                    $abilities[] = [$id, $index];
                }
            }
        }
        // Renew and the like: abilities activated by exiling the card from your graveyard.
        foreach ($this->players[$seat]->graveyard as $id) {
            foreach ($this->objects[$id]->definition()->activated as $index => $ability) {
                if (($ability['graveyard'] ?? false) && $this->canActivate($seat, $id, $index)) {
                    $abilities[] = [$id, $index];
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
        $fromHand = (bool) ($object?->definition()->activated[$index]['hand'] ?? false);
        if ($fromHand && ($object->zone !== GameObject::HAND || $object->owner !== $seat)) {
            return 'That card is not in your hand.';
        }
        $fromGraveyard = (bool) ($object?->definition()->activated[$index]['graveyard'] ?? false);
        if ($fromGraveyard && ($object->zone !== GameObject::GRAVEYARD || $object->owner !== $seat)) {
            return 'That card is not in your graveyard.';
        }
        if (! $fromHand && ! $fromGraveyard && ($object === null || $object->zone !== GameObject::BATTLEFIELD || $object->controller !== $seat)) {
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
        if (($ability['prepare'] ?? false) && ! $object->prepared) {
            return "{$card->name} is not prepared.";
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
        if (($ability['ever'] ?? false) && isset($object->used[$index])) {
            return "You have already activated that ability of {$card->name}.";
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
        if (($cost['discard'] ?? 0) > count($this->players[$seat]->hand)) {
            return 'You have no card to discard.';
        }
        if (isset($cost['fodder']) && $this->fodderMany($seat, $object, $cost['fodder'], $cost['fodderCount'] ?? 1) === null) {
            return 'You have no '.(explode(' ', $cost['fodder'], 2)[1] ?? 'creature').' to sacrifice.';
        }
        if (isset($cost['tapOthers']) && $this->fodderMany($seat, $object, "any {$cost['tapOthers'][1]}", $cost['tapOthers'][0], true) === null) {
            return "You have no untapped {$cost['tapOthers'][1]} to tap.";
        }
        if (isset($cost['bounce']) && $this->bounceFodder($seat, $object, $cost['bounce']) === null) {
            return 'You have no '.($cost['bounce'][1] ? 'untapped ' : '').explode(' ', $cost['bounce'][0], 2)[1].' to return.';
        }
        if (isset($cost['removeAny']) && $this->counterHost($seat, $object, $cost['removeAny']) === null) {
            return "You control no {$cost['removeAny'][2]} with {$cost['removeAny'][1]} {$cost['removeAny'][0]} counters.";
        }
        if (($cost['mill'] ?? 0) > count($this->players[$seat]->library)) {
            return 'Your library is too small to mill.';
        }
        if (isset($cost['exileGrave']) && $this->graveFodder($seat, $cost['exileGrave'], $cost['exileGraveKind'] ?? 'card') === null) {
            return "You need {$cost['exileGrave']} cards in your graveyard.";
        }
        if (($cost['forage'] ?? false) && $this->graveFodder($seat, 3) === null && $this->fodder($seat, $object, 'any Food') === null) {
            return 'To forage, you need three cards in your graveyard or a Food.';
        }
        if (isset($cost['craft']) && $this->craftMaterial($seat, $object, $cost['craft']) === null) {
            return "You have no {$cost['craft']} to craft {$object->name()} with.";
        }

        return null;
    }

    /**
     * Merges a mutating creature spell into its target (rule 721): on top
     * when it is the bigger creature, else under it. The merged permanent
     * has every card's abilities (see GameObject::definition()).
     *
     * @param GameObject $spell
     * @param GameObject $host
     *
     * @return void
     */
    private function mutate(GameObject $spell, GameObject $host): void
    {
        $over = (int) $spell->printed()->power + (int) $spell->printed()->toughness > $this->power($host) + $this->toughness($host);
        $this->removeFromZone($spell);
        $spell->moveTo(GameObject::GONE);
        $host->addMutation($spell->id, $spell->card, $over);
        $this->log("{$spell->printed()->name} mutates ".($over ? 'onto' : 'under')." {$host->name()}.");
        $this->trigger($host, 'mutates', $host->controller);
    }

    /**
     * Exiles a haunt card haunting a creature (rule 702.55): an opponent's weakest, else its controller's own.
     *
     * @param GameObject $card
     * @param int        $controller
     * @param bool       $spell      Whether it is a resolving spell, still on the stack.
     *
     * @return bool Whether it found a creature to haunt.
     */
    private function haunt(GameObject $card, int $controller, bool $spell = false): bool
    {
        $creature = null;
        foreach ([...array_filter(array_keys($this->players), fn (int $seat) => $seat !== $controller), $controller] as $seat) {
            $creature ??= $this->weakest($seat, fn (GameObject $o) => $o->id !== $card->id && $this->isCreature($o));
        }
        if ($creature === null) {
            return false;
        }
        $this->moveTo($card, GameObject::EXILE);
        $card->exiledBy = "haunt:{$creature->id}:{$creature->incarnation}";
        $this->log("{$card->name()} haunts {$creature->name()}.");

        return true;
    }

    /**
     * The tapped creature web-slinging returns to its owner's hand: the weakest.
     *
     * @param int $seat
     * @param int $id   The spell being cast.
     *
     * @return GameObject|null
     */
    private function webslinger(int $seat, int $id): ?GameObject
    {
        return $this->weakest($seat, fn (GameObject $o) => $o->id !== $id && $o->tapped && $this->isCreature($o));
    }

    /**
     * What Craft (rule 702.167) exiles: a card of that kind from your graveyard, else the weakest other such permanent you control.
     *
     * @param int        $seat
     * @param GameObject $source
     * @param string     $kind   `artifact`, `creature`, `land`, or a subtype.
     *
     * @return GameObject|null
     */
    private function craftMaterial(int $seat, GameObject $source, string $kind): ?GameObject
    {
        $fits = fn (GameObject $o) => $o->id !== $source->id && (in_array($kind, ['artifact', 'creature', 'land'], true) ? $o->definition()->is(ucfirst($kind)) : in_array($kind, $o->definition()->subtypes, true));
        foreach ($this->players[$seat]->graveyard as $id) {
            if ($fits($this->objects[$id])) {
                return $this->objects[$id];
            }
        }

        return $this->weakest($seat, $fits);
    }

    /**
     * Whether the permanent a trigger is about (sacrificed, or put into a
     * graveyard, or a spell cast) is the kind it names: `Whenever you sacrifice a Food, …`.
     *
     * @param GameObject $object
     * @param string     $kind   `permanent`, `creature`, `noncreature`, `token`, `nontoken creature`, `instant or sorcery`, `multicolored`, `mana value N or greater` (or less), a card type or a subtype.
     * @param DepartureCharacteristics|null $characteristics Battlefield definition/status for departure type/subtype observers; other filters still use current object state.
     *
     * @return bool
     */
    private function objectFits(GameObject $object, string $kind, ?DepartureCharacteristics $characteristics = null): bool
    {
        $card = $characteristics?->definition ?? $object->definition();
        $isCreature = $characteristics?->isCreature ?? $card->is('Creature');
        // `a creature spell with mana value 5 or greater`: both.
        if (preg_match('/^(creature|noncreature|artifact|enchantment) with (mana value \d+ or (?:greater|less))$/', $kind, $match)) {
            return $this->objectFits($object, $match[1], $characteristics) && $this->objectFits($object, $match[2], $characteristics);
        }
        // `a spell with mana value 5 or greater`: X counts on the stack (rule 202.3e).
        if (preg_match('/^mana value (\d+) or (greater|less)$/', $kind, $match)) {
            $x = 0;
            foreach ($this->stack as $item) {
                if (($item['object'] ?? null) === $object->id) {
                    $x = (int) ($item['x'] ?? 0);
                }
            }
            $value = $card->cost->manaValue($x);

            return $match[2] === 'greater' ? $value >= (int) $match[1] : $value <= (int) $match[1];
        }
        // `a creature you control with flying`: as it is now.
        if (preg_match('/^creature with ([a-z ]+)$/', $kind, $match) && ! str_contains($kind, 'or greater')) {
            return $isCreature && $this->hasKeyword($object, $match[1]);
        }
        // `a creature spell with power 4 or greater`: as printed, or as it is on the battlefield.
        if (preg_match('/^creature with (power|toughness) (\d+) or greater$/', $kind, $match)) {
            $onField = $object->zone === GameObject::BATTLEFIELD;
            $value = $match[1] === 'power' ? ($onField ? $this->power($object) : (int) $card->power) : ($onField ? $this->toughness($object) : (int) $card->toughness);

            return $isCreature && $value >= (int) $match[2];
        }

        return match ($kind) {
            'permanent' => true,
            'token' => $card->isToken(),
            'nontoken creature' => ! $card->isToken() && $isCreature,
            'nontoken artifact' => ! $card->isToken() && $card->is('Artifact'),
            'creature token' => $card->isToken() && $isCreature,
            'artifact creature' => $card->is('Artifact') && $isCreature,
            'nontoken permanent' => ! $card->isToken(),
            'creature' => $isCreature,
            'artifact', 'enchantment', 'land' => $card->is(ucfirst($kind)),
            'noncreature', 'noncreature permanent' => ! $isCreature,
            'instant or sorcery' => $card->is('Instant') || $card->is('Sorcery'),
            'historic' => $card->is('Artifact') || in_array('Legendary', $card->supertypes, true) || in_array('Saga', $card->subtypes, true),
            'multicolored' => count($card->colors) > 1,
            // `a spell that has an X in its mana cost`
            'x spell' => $card->cost->xCount > 0,
            // `an Aura, Equipment, or Vehicle spell`
            default => str_starts_with($kind, 'any:') ? array_intersect(explode('|', substr($kind, 4)), $card->subtypes) !== [] : in_array($kind, $card->subtypes, true),
        };
    }

    /**
     * `Whenever you discard a creature card, …` (the card is the context)
     * and `Whenever an opponent discards a card, …` (that player is).
     *
     * @param int $seat Who discarded it.
     * @param int $id   The card.
     *
     * @return void
     */
    private function discardedBy(int $seat, int $id): void
    {
        foreach ($this->permanents() as $permanent) {
            if ($permanent->controller === $seat) {
                $this->trigger($permanent, 'you_discard_kind', $seat, null, $id);
            } else {
                $this->trigger($permanent, 'opponent_discards', $permanent->controller, null, $seat);
            }
        }
    }

    /**
     * Sacrifices a permanent (rule 701.21), then triggers `Whenever you
     * sacrifice a permanent, …` on its controller's permanents, itself
     * included, and `Whenever a player (an opponent) sacrifices …` on everyone's.
     *
     * @param GameObject $object
     *
     * @return void
     */
    private function sacrifice(GameObject $object): void
    {
        $seat = $object->controller;
        $characteristics = $this->deathObserverBatch?->characteristicsOf($object);
        $watchers = $characteristics !== null ? $this->deathObserverBatch->observers : array_map(fn (GameObject $watcher) => clone $watcher, $this->permanents());
        $characteristics ??= new DepartureCharacteristics($object->definition(), $this->isCreature($object), manaValue: $object->battlefieldManaValue());
        $this->moveTo($object, GameObject::GRAVEYARD);
        foreach ($watchers as $watcher) {
            $actual = $this->objects[$watcher->id];
            $watcher->used = array_replace($watcher->used, $actual->used);
            $this->trigger($watcher, $watcher->controller === $seat ? 'you_sacrifice' : 'opponent_sacrifices', $watcher->controller, $watcher->incarnation, $object->id, $characteristics, $watcher->definition());
            $this->trigger($watcher, 'player_sacrifices', $watcher->controller, $watcher->incarnation, $object->id, $characteristics, $watcher->definition());
            if ($actual->zone === GameObject::BATTLEFIELD && $actual->incarnation === $watcher->incarnation) {
                $actual->used = array_replace($actual->used, $watcher->used);
            }
        }
    }

    /**
     * What `Sacrifice a creature` sacrifices: the weakest other creature, or the source itself when that is all there is.
     *
     * @param int        $seat
     * @param GameObject $source
     * @param string     $which  `any`, or `other` for `Sacrifice another creature`; with ` artifact` for an artifact.
     *
     * @return GameObject|null
     */
    private function fodder(int $seat, GameObject $source, string $which, array $except = []): ?GameObject
    {
        // `any creature`, `other artifact`, `any Food`, …: see Game::objectFits().
        $kind = explode(' ', $which, 2)[1] ?? 'creature';
        $fits = $kind === 'creature' ? fn (GameObject $o) => $this->isCreature($o) : fn (GameObject $o) => $this->objectFits($o, $kind);

        return $this->weakest($seat, fn (GameObject $o) => $o->id !== $source->id && ! in_array($o->id, $except, true) && $fits($o))
            ?? (str_starts_with($which, 'any') && ! in_array($source->id, $except, true) && $source->zone === GameObject::BATTLEFIELD && $source->controller === $seat && $fits($source) ? $source : null);
    }

    /**
     * Several permanents for one cost: `Sacrifice two creatures`, `Tap two
     * untapped creatures you control`; the weakest first.
     *
     * @param int        $seat
     * @param GameObject $source
     * @param string     $which  As for {@see fodder()}.
     * @param int        $count
     * @param bool       $untapped Only untapped ones, never the source itself.
     *
     * @return GameObject[]|null Null when there are not enough.
     */
    private function fodderMany(int $seat, GameObject $source, string $which, int $count, bool $untapped = false): ?array
    {
        $chosen = [];
        for ($i = 0; $i < $count; $i++) {
            $except = array_map(fn (GameObject $o) => $o->id, $chosen);
            if ($untapped) {
                $except = [...$except, $source->id, ...array_map(fn (GameObject $o) => $o->id, array_filter($this->permanents($seat), fn (GameObject $o) => $o->tapped))];
            }
            if (($next = $this->fodder($seat, $source, $which, $except)) === null) {
                return null;
            }
            $chosen[] = $next;
        }

        return $chosen;
    }

    /**
     * What `Return a land you control to its owner's hand` returns: a tapped
     * one first (it has already given its mana), else the weakest.
     *
     * @param int        $seat
     * @param GameObject $source
     * @param array      $bounce [`any land`/`other creature`, untapped only].
     *
     * @return GameObject|null
     */
    private function bounceFodder(int $seat, GameObject $source, array $bounce): ?GameObject
    {
        [$which, $untapped] = $bounce;
        $untappedIds = array_map(fn (GameObject $o) => $o->id, array_filter($this->permanents($seat), fn (GameObject $o) => ! $o->tapped));
        if ($untapped) {
            return $this->fodderMany($seat, $source, $which, 1, true)[0] ?? null;
        }

        return $this->fodder($seat, $source, $which, [...$untappedIds, $source->id]) ?? $this->fodder($seat, $source, $which);
    }

    /**
     * What `Remove a +1/+1 counter from a creature you control` takes from:
     * the permanent with the most of them, itself included unless `another`.
     *
     * @param int        $seat
     * @param GameObject $source
     * @param array      $remove [counter kind, how many, `creature`/`artifact`/`permanent`, another].
     *
     * @return GameObject|null
     */
    private function counterHost(int $seat, GameObject $source, array $remove): ?GameObject
    {
        [$kind, $count, $type, $another] = $remove;
        $hosts = array_filter($this->permanents($seat), fn (GameObject $o) => $o->counter($kind) >= $count && ($type === 'permanent' || ($type === 'creature' ? $this->isCreature($o) : $this->objectFits($o, $type))) && ! ($another && $o->id === $source->id));
        usort($hosts, fn (GameObject $a, GameObject $b) => $b->counter($kind) <=> $a->counter($kind));

        return $hosts[0] ?? null;
    }

    /**
     * Cards exiled from a graveyard to pay a cost (`Exile two cards from your
     * graveyard:`, forage): lands first, then the lowest mana value.
     *
     * @param int    $seat
     * @param int    $count
     * @param string $kind  `card`, or a kind {@see objectFits()} knows: `Exile a creature card from your graveyard:`.
     *
     * @return GameObject[]|null Null when there are not enough.
     */
    private function graveFodder(int $seat, int $count, string $kind = 'card'): ?array
    {
        $cards = array_values(array_filter(array_map(fn (int $id) => $this->objects[$id], $this->players[$seat]->graveyard), fn (GameObject $c) => $kind === 'card' || $this->objectFits($c, $kind)));
        if (count($cards) < $count) {
            return null;
        }
        usort($cards, fn (GameObject $a, GameObject $b) => [! $a->definition()->isLand(), $a->definition()->cost->manaValue()] <=> [! $b->definition()->isLand(), $b->definition()->cost->manaValue()]);

        return array_slice($cards, 0, $count);
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
        $exclude = ($ability['cost']['tap'] ?? false) ? [$object->id] : [];
        $mana = $ability['cost']['mana'] ?? '';
        $this->abilityX = 0;
        // Waterbend (TLA): its generic cost can be paid by tapping untapped artifacts and creatures, {1} each; not ward's.
        if (($ability['cost']['waterbend'] ?? false) && $wardMana === '') {
            return $this->payFor($seat, $mana, ($ability['cost']['life'] ?? 0) + $wardLife, $exclude, 0, 0, true, true);
        }
        if (! str_contains($mana, '{X}')) {
            return $this->payFor($seat, $mana.$wardMana, ($ability['cost']['life'] ?? 0) + $wardLife, $exclude);
        }
        // X is as much as can be paid, and at least 1.
        for ($n = min(20, count($this->permanents($seat)) + $this->players[$seat]->manaPool->total()); $n >= 1; $n--) {
            if (($payment = $this->payFor($seat, str_replace('{X}', "{{$n}}", $mana).$wardMana, ($ability['cost']['life'] ?? 0) + $wardLife, $exclude)) !== null) {
                $this->abilityX = $n;

                return $payment;
            }
        }

        return null;
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
        [$wardMana, $wardLife, $wardDiscard] = $this->wardCost(array_map(fn (string $target) => $this->pinTarget($target), $targets), $seat, false);
        if ($wardDiscard + ($ability['cost']['discard'] ?? 0) > count($this->players[$seat]->hand)) {
            throw new GameException('You need a card in your hand to discard for ward.');
        }
        $payment = $this->abilityPayment($seat, $object, $ability, $wardMana, $wardLife);
        $x = $this->abilityX;
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
        foreach (isset($cost['tapOthers']) ? (array) $this->fodderMany($seat, $object, "any {$cost['tapOthers'][1]}", $cost['tapOthers'][0], true) : [] as $tapped) {
            $tapped->tapped = true;
            $this->log("{$player->name} taps {$tapped->name()}.");
        }
        foreach (isset($cost['fodder']) ? (array) $this->fodderMany($seat, $object, $cost['fodder'], $cost['fodderCount'] ?? 1) : [] as $fodder) {
            $this->log("{$player->name} sacrifices {$fodder->name()}.");
            $this->sacrifice($fodder);
        }
        if (isset($cost['bounce']) && ($bounced = $this->bounceFodder($seat, $object, $cost['bounce'])) !== null) {
            $this->log("{$player->name} returns {$bounced->name()} to its owner's hand.");
            $this->moveTo($bounced, GameObject::HAND);
        }
        if (isset($cost['removeAny']) && ($host = $this->counterHost($seat, $object, $cost['removeAny'])) !== null) {
            $host->addCounters($cost['removeAny'][0], -$cost['removeAny'][1]);
            $this->log("{$player->name} removes {$cost['removeAny'][1]} {$cost['removeAny'][0]} counter".($cost['removeAny'][1] === 1 ? '' : 's')." from {$host->name()}.");
        }
        if (($cost['mill'] ?? 0) > 0) {
            $this->mill($seat, $cost['mill']);
        }
        // Forage: exile three cards from your graveyard or sacrifice a Food; the graveyard when it can.
        $exiled = isset($cost['exileGrave']) ? $this->graveFodder($seat, $cost['exileGrave'], $cost['exileGraveKind'] ?? 'card') : (($cost['forage'] ?? false) ? $this->graveFodder($seat, 3) : null);
        if (($cost['forage'] ?? false) && $exiled === null && ($food = $this->fodder($seat, $object, 'any Food')) !== null) {
            $this->log("{$player->name} forages, sacrificing {$food->name()}.");
            $this->sacrifice($food);
        }
        foreach ($exiled ?? [] as $card) {
            $this->moveTo($card, GameObject::EXILE);
        }
        if ($exiled !== null) {
            $this->log("{$player->name} exiles ".implode(', ', array_map(fn (GameObject $c) => $c->name(), $exiled)).' from their graveyard.');
        }
        if (isset($cost['craft']) && ($material = $this->craftMaterial($seat, $object, $cost['craft'])) !== null) {
            $this->log("{$player->name} exiles {$material->name()} to craft {$object->name()}.");
            $this->moveTo($material, GameObject::EXILE);
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
        if ($cost['discardSelf'] ?? false) {
            $this->discardCards($seat, [$object->id]);
        }
        if ($cost['exileSelf'] ?? false) {
            $this->moveTo($object, GameObject::EXILE);
        }
        // `Discard a card` as a cost, with ward's: the cheapest cards.
        $this->discardCheapest($seat, $wardDiscard + ($cost['discard'] ?? 0));
        if (isset($cost['remove'])) {
            $object->addCounters($cost['remove'][0], -$cost['remove'][1]);
        }

        if (self::isSpecialAction($ability)) {
            $this->turnFaceUp($object);
            $this->settle();

            return;
        }
        $object->used[$index] = $this->turn;
        // Casting the copy of its prepare spell unprepares it.
        if ($ability['prepare'] ?? false) {
            $object->prepared = false;
            $label = "A copy of {$object->definition()->prepare?->name}";
        }

        $this->pushAbility([
            'source' => $object->id,
            'incarnation' => $object->incarnation,
            'controller' => $seat,
            'effects' => $effects,
            'kinds' => $kinds,
            'label' => $label,
            'x' => $x,
        ], $targets, 'is activated', $object->name().'*'.($crew === [] ? '' : ' (crew '.implode(', ', array_map(fn (int $c) => $this->objects[$c]->name(), $crew)).')'));
        $this->targetedTriggers($targets, $seat);
        // `Whenever you activate an ability that isn't a mana ability (an exhaust ability), …`
        foreach ($this->permanents($seat) as $permanent) {
            $this->trigger($permanent, 'you_activate', $seat);
            if ($ability['exhaust'] ?? false) {
                $this->trigger($permanent, 'you_activate_exhaust', $seat);
            }
        }
        if ($cost['sacrifice'] ?? false) {
            $this->sacrifice($object);
            $this->log("{$player->name} sacrifices {$object->name()}.", $seat, "sac {$object->name()}");
        }
        if (($cost['bounceSelf'] ?? false) && $object->zone === GameObject::BATTLEFIELD) {
            $this->log("{$player->name} returns {$object->name()} to its owner's hand.");
            $this->moveTo($object, GameObject::HAND);
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
        // `Whenever a permanent (creature) you control is turned face up, …`
        foreach ($this->permanents($object->controller) as $permanent) {
            $this->trigger($permanent, 'permanent_turned_face_up', $permanent->controller, null, $object->id);
        }
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
                            // `target spell with mana value 2 or less`.
                            default => (bool) preg_match('/^spell_mv_le_(\d+)$/', $kind, $m) && $card->cost->manaValue() <= (int) $m[1],
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
            // Mutate (rule 702.140): a non-Human creature with the same owner.
            'mutate_host' => $creature && $object->owner === $controller && ! in_array('Human', $card->subtypes, true),
            'creature_opponent' => $creature && $object->controller !== $controller,
            'creature_or_planeswalker' => $creature || $card->isPlaneswalker(),
            'player_or_planeswalker', 'planeswalker' => $card->isPlaneswalker(),
            'artifact' => $card->is('Artifact'),
            'enchantment' => $card->is('Enchantment'),
            'artifact_or_enchantment' => $card->is('Artifact') || $card->is('Enchantment'),
            'artifact_opponent' => $card->is('Artifact') && $object->controller !== $controller,
            'enchantment_opponent' => $card->is('Enchantment') && $object->controller !== $controller,
            'land_opponent' => $card->isLand() && $object->controller !== $controller,
            'permanent_opponent' => $object->controller !== $controller,
            'artifact_or_enchantment_opponent' => ($card->is('Artifact') || $card->is('Enchantment')) && $object->controller !== $controller,
            'artifact_or_creature' => $card->is('Artifact') || $creature,
            'artifact_creature' => $card->is('Artifact') && $creature,
            'artifact_or_land' => $card->is('Artifact') || $card->isLand(),
            'creature_nonblack' => $creature && ! in_array('B', $card->colors, true),
            'creature_nonartifact' => $creature && ! $card->is('Artifact'),
            'creature_nonartifact_nonblack' => $creature && ! $card->is('Artifact') && ! in_array('B', $card->colors, true),
            'creature_or_vehicle' => $creature || in_array('Vehicle', $card->subtypes, true),
            'attacking_or_blocking' => $creature && (isset($this->attackers[$object->id]) || isset($this->blockers[$object->id])),
            'attacking' => $creature && isset($this->attackers[$object->id]),
            'attacking_flying' => $creature && isset($this->attackers[$object->id]) && $this->hasKeyword($object, 'flying'),
            'attacking_no_flying' => $creature && isset($this->attackers[$object->id]) && ! $this->hasKeyword($object, 'flying'),
            'blocking' => $creature && isset($this->blockers[$object->id]),
            'creature_flying' => $creature && $this->hasKeyword($object, 'flying'),
            'creature_no_flying' => $creature && ! $this->hasKeyword($object, 'flying'),
            'tapped_creature' => $creature && $object->tapped,
            'untapped_creature' => $creature && ! $object->tapped,
            'creature_card_yours' => $object->printed()->isCreature() && $object->owner === $controller,
            'legendary_creature_card_yours' => $object->printed()->isCreature() && $object->printed()->isLegendary() && $object->owner === $controller,
            'card_yours' => $object->owner === $controller,
            'instant_sorcery_card_yours' => ($object->printed()->is('Instant') || $object->printed()->is('Sorcery')) && $object->owner === $controller,
            'artifact_card_yours' => $object->printed()->is('Artifact') && $object->owner === $controller,
            'land_card_yours' => $object->printed()->isLand() && $object->owner === $controller,
            'enchantment_card_yours' => $object->printed()->is('Enchantment') && $object->owner === $controller,
            'permanent_card_yours' => $object->printed()->isPermanentCard() && $object->owner === $controller,
            'creature_card_graveyard' => $object->printed()->isCreature(),
            'card_graveyard' => true,
            // `target card from defending player's graveyard`: with one opponent, theirs.
            'card_graveyard_opponent' => $object->owner !== $controller,
            'creature_card_graveyard_opponent' => $object->printed()->isCreature() && $object->owner !== $controller,
            'card_graveyard_nonbasic' => ! ($object->printed()->isLand() && in_array('Basic', $object->printed()->supertypes, true)),
            'nonbasic_land' => $card->isLand() && ! in_array('Basic', $card->supertypes, true),
            'creature_or_planeswalker_opponent' => ($creature || $card->isPlaneswalker()) && $object->controller !== $controller,
            'land_you_control' => $card->isLand() && $object->controller === $controller,
            'land' => $card->isLand(),
            'nonland_permanent' => ! $card->isLand(),
            'noncreature_permanent' => ! $creature,
            'nonland_permanent_opponent' => ! $card->isLand() && $object->controller !== $controller,
            'artifact_or_creature_opponent' => ($card->is('Artifact') || $creature) && $object->controller !== $controller,
            'artifact_enchantment_flier' => $card->is('Artifact') || $card->is('Enchantment') || ($creature && $this->hasKeyword($object, 'flying')),
            'artifact_creature_enchantment_opponent' => ($card->is('Artifact') || $creature || $card->is('Enchantment')) && $object->controller !== $controller,
            'permanent' => true,
            'battle' => $card->is('Battle'),
            'creature_unenchanted' => $creature && array_filter($this->permanents(), fn (GameObject $o) => $o->attachedTo === $object->id && $o->definition()->isAura()) === [],
            'basic_land' => $card->isLand() && in_array('Basic', $card->supertypes, true),
            'snow_land' => $card->isLand() && in_array('Snow', $card->supertypes, true),
            'legendary_creature' => $creature && in_array('Legendary', $card->supertypes, true),
            // Modified (rule 700.9): counters, or Equipment or an Aura its controller controls.
            'modified_creature' => $creature && (array_filter($object->counters) !== []
                || array_filter($this->objects, fn (GameObject $other) => $other->zone === GameObject::BATTLEFIELD && $other->attachedTo === $object->id && $other->controller === $object->controller) !== []),
            'nonaura_enchantment' => $card->is('Enchantment') && ! in_array('Aura', $card->subtypes, true),
            'creature_other' => $creature,
            'creature_you_control_other' => $creature && $object->controller === $controller,
            'permanent_you_control', 'permanent_you_control_other' => $object->controller === $controller,
            'nonland_permanent_you_control', 'nonland_permanent_you_control_other' => ! $card->isLand() && $object->controller === $controller,
            'permanent_other' => true,
            'nonland_permanent_other' => ! $card->isLand(),
            // Soulshift N: a Spirit card with mana value N or less. Mentor: an attacker with power less than N.
            default => ((bool) preg_match('/^subtype_creature_([A-Z][a-z]+)$/', $kind, $m) && $creature
                && (in_array($m[1], $card->subtypes, true) || in_array('changeling', $card->keywords, true)))
                || ((bool) preg_match('/^subtype_you_control_([A-Z][a-z]+)$/', $kind, $m) && $creature && $object->controller === $controller
                && (in_array($m[1], $card->subtypes, true) || in_array('changeling', $card->keywords, true)))
                || ((bool) preg_match('/^subtype_card_yours_([A-Z][a-z]+)$/', $kind, $m) && $object->owner === $controller
                && (in_array($m[1], $object->printed()->subtypes, true) || in_array('changeling', $object->printed()->keywords, true)))
                || ((bool) preg_match('/^spirit_card_yours_(\d+)$/', $kind, $m) && $object->owner === $controller
                && in_array('Spirit', $object->printed()->subtypes, true) && $object->printed()->cost->manaValue() <= (int) $m[1])
                || ((bool) preg_match('/^attacking_power_lt_(-?\d+)$/', $kind, $m) && $creature && isset($this->attackers[$object->id]) && $this->power($object) < (int) $m[1])
                // `another target creature`: not the source.
                || ((bool) preg_match('/^creature_not_(\d+)$/', $kind, $m) && $creature && $object->id !== (int) $m[1])
                || ((bool) preg_match('/^creature_yours_not_(\d+)$/', $kind, $m) && $creature && $object->controller === $controller && $object->id !== (int) $m[1])
                || ((bool) preg_match('/^(nonland_)?permanent_yours_not_(\d+)$/', $kind, $m) && ($m[1] === '' || ! $card->isLand()) && $object->controller === $controller && $object->id !== (int) $m[2])
                || ((bool) preg_match('/^(nonland_)?permanent_not_(\d+)$/', $kind, $m) && ($m[1] === '' || ! $card->isLand()) && $object->id !== (int) $m[2])
                // `target non-Zombie creature`.
                || ((bool) preg_match('/^creature_nonsub_(\w+)$/', $kind, $m) && $creature && ! in_array($m[1], $card->subtypes, true) && ! in_array('changeling', $card->keywords, true))
                // `Enchant Forest`: a permanent with that subtype.
                || ((bool) preg_match('/^sub_(\w+)$/', $kind, $m) && in_array($m[1], $card->subtypes, true))
                // `target creature with power 4 or greater` / `… 2 or less`.
                || ((bool) preg_match('/^creature_power_(ge|le)_(\d+)$/', $kind, $m) && $creature && ($m[1] === 'ge' ? $this->power($object) >= (int) $m[2] : $this->power($object) <= (int) $m[2]))
                || ((bool) preg_match('/^creature_(non)?color_([WUBRG])$/', $kind, $m) && $creature && in_array($m[2], $card->colors, true) === ($m[1] === ''))
                || ((bool) preg_match('/^creature_mv_le_(\d+)$/', $kind, $m) && $creature && $card->cost->manaValue() <= (int) $m[1])
                || ((bool) preg_match('/^creature_opp_power_le_(\d+)$/', $kind, $m) && $creature && $object->controller !== $controller && $this->power($object) <= (int) $m[1])
                || ((bool) preg_match('/^creature_opp_mv_le_(\d+)$/', $kind, $m) && $creature && $object->controller !== $controller && $card->cost->manaValue() <= (int) $m[1])
                || ((bool) preg_match('/^creature_card_yours_not_(\d+)$/', $kind, $m) && $object->printed()->isCreature() && $object->owner === $controller && $object->id !== (int) $m[1])
                || ((bool) preg_match('/^creature_card_yours_mv_le_(\d+)$/', $kind, $m) && $object->printed()->isCreature() && $object->owner === $controller && $object->printed()->cost->manaValue() <= (int) $m[1])
                // `Enchant artifact or creature you control`: any of the kinds, with an optional controller.
                || ((bool) preg_match('/^any:([^@]+)(?:@(you|opp))?$/', $kind, $m)
                    && (! isset($m[2]) || ($m[2] === 'you') === ($object->controller === $controller))
                    && array_filter(explode('|', $m[1]), fn (string $one) => $this->matchesKind($object, $one, $controller)) !== []),
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
        return in_array($kind, self::GRAVEYARD_KINDS, true) || str_starts_with($kind, 'spirit_card_yours_') || str_starts_with($kind, 'creature_card_yours_mv_le_') || str_starts_with($kind, 'creature_card_yours_not_') || str_starts_with($kind, 'subtype_card_yours_');
    }

    /**
     * Everything a player's spell could target for one kind of target.
     *
     * @param int      $seat
     * @param string   $kind
     * @param int|null $self
     * @param int|null $source The object it comes from; what has protection from it is left out.
     *
     * @return string[]
     */
    public function targetOptions(int $seat, string $kind, ?int $self = null, ?int $source = null): array
    {
        $from = $source === null ? null : ($this->objects[$source] ?? null);
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

        return array_values(array_filter($options, fn (string $target) => $this->isLegalTarget($kind, $target, $seat, $self)
            && ($from === null || ! $this->protectedFrom($this->targetObject($target), $from))));
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
                && $this->attackAllowedByLands($o)
        )));
    }

    /**
     * How many creatures it takes to block it: 2 for menace, N for `can't be
     * blocked except by N or more creatures`.
     *
     * @param GameObject $attacker
     *
     * @return int
     */
    public function minBlockers(GameObject $attacker): int
    {
        $min = $this->hasKeyword($attacker, 'menace') ? 2 : 1;
        foreach ($this->keywords($attacker) as $keyword) {
            if (preg_match("/^can't be blocked except by (\d+) or more creatures$/", $keyword, $m)) {
                $min = max($min, (int) $m[1]);
            }
        }

        return $min;
    }

    /**
     * `This creature can't attack (block) unless you control another creature (a Dragon).`
     *
     * @param GameObject $creature
     * @param string     $what     `attack` or `block`.
     *
     * @return bool
     */
    private function controlsNeeded(GameObject $creature, string $what): bool
    {
        foreach ($this->keywords($creature) as $keyword) {
            if (preg_match("/^can't {$what} unless you control (another )?(\\w+)$/", $keyword, $m)
                && array_filter($this->permanents($creature->controller), fn (GameObject $o) => ($m[1] === '' || $o->id !== $creature->id)
                    && (in_array($m[2], ['Artifact', 'Creature', 'Enchantment'], true) ? $o->definition()->is($m[2]) : in_array($m[2], $o->definition()->subtypes, true))) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * `This creature can't attack unless defending player controls an Island.`
     *
     * @param GameObject $creature
     *
     * @return bool
     */
    private function attackAllowedByLands(GameObject $creature): bool
    {
        if (! $this->controlsNeeded($creature, 'attack')) {
            return false;
        }
        foreach ($this->keywords($creature) as $keyword) {
            if (preg_match("/^can't attack unless defending player controls an? (\w+)$/", $keyword, $m)
                && array_filter($this->permanents($this->defender()), fn (GameObject $o) => in_array($m[1], $o->definition()->subtypes, true)) === []) {
                return false;
            }
        }

        return true;
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

        // `CARDNAME can't attack or block alone.`: a lone attacker with it stays back.
        if (count($ids) === 1 && ($this->hasKeyword($this->objects[$ids[0]], "can't attack or block alone") || $this->hasKeyword($this->objects[$ids[0]], "can't attack alone"))) {
            $this->note("{$this->objects[$ids[0]]->name()} can't attack alone.", $seat);
            $ids = [];
        }
        $this->declared['attack'] = true;
        if ($ids !== []) {
            $this->players[$seat]->attackedTurn = $this->turn;
            $this->attackedWith = count($ids);
        }
        foreach ($ids as $id) {
            $this->objects[$id]->attackedTurn = $this->turn;
            $this->attackers[$id] = true;
            if ($this->hasKeyword($this->objects[$id], 'decayed')) {
                $this->sacrificeAtEndOfCombat[$id] = $this->objects[$id]->incarnation;
            }
            if (! $this->hasKeyword($this->objects[$id], 'vigilance')) {
                $this->objects[$id]->tapped = true;
            }
            $this->trigger($this->objects[$id], 'attacks', $seat);
            $this->attachedTrigger($id, 'attached_attacks');
            // `Whenever a creature attacks you, …` (two players: each attacker attacks its controller's opponent).
            foreach ($this->permanents() as $permanent) {
                if ($permanent->controller !== $seat) {
                    $this->trigger($permanent, 'opponent_creature_attacks', $permanent->controller, null, $id);
                }
            }
            // `Whenever a creature you control attacks, it gets …`
            foreach ($this->permanents($seat) as $permanent) {
                $this->trigger($permanent, 'creature_attacks', $seat, null, $id);
                if ($permanent->id !== $id) {
                    $this->trigger($permanent, 'creature_attacks_other', $seat, null, $id);
                }
            }
            // The Ring, second level: `Whenever your Ring-bearer attacks, draw a card, then discard a card.`
            if ($this->ringLevel($this->objects[$id]) >= 2) {
                $this->ringTrigger($this->objects[$id], 'Draw a card, then discard a card.');
            }
        }

        // `Whenever you attack, …`
        // `Whenever one or more creatures you control with power 4 or greater attack, …`: once, with the strongest attacker; see trigger()'s minPower.
        $strongest = null;
        foreach ($ids as $id) {
            if ($strongest === null || $this->power($this->objects[$id]) > $this->power($this->objects[$strongest])) {
                $strongest = $id;
            }
        }
        foreach ($ids === [] ? [] : $this->permanents($seat) as $permanent) {
            $this->trigger($permanent, 'you_attack', $seat);
            $this->trigger($permanent, 'you_attack_strongest', $seat, null, $strongest);
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
            // `Whenever CARDNAME attacks alone, …`, `Whenever a creature you control attacks alone, …`
            $this->trigger($this->objects[$ids[0]], 'attacks_alone', $seat);
            foreach ($this->permanents($seat) as $permanent) {
                $this->trigger($permanent, 'creature_attacks_alone', $seat, null, $ids[0]);
            }
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
    /**
     * The color a player picks for `protection from the color of your choice`:
     * the most common one among their opponents' permanents and spells.
     *
     * @param int $seat
     *
     * @return string
     */
    private function colorToFear(int $seat): string
    {
        $count = ['W' => 0, 'U' => 0, 'B' => 0, 'R' => 0, 'G' => 0];
        $objects = [...array_filter($this->permanents(), fn (GameObject $o) => $o->controller !== $seat), ...array_map(fn (array $item) => $this->objects[$item['object']] ?? null, array_filter($this->stack, fn (array $item) => $item['controller'] !== $seat && isset($item['object'])))];
        foreach (array_filter($objects) as $object) {
            foreach ($object->definition()->colors as $color) {
                if (isset($count[$color])) {
                    $count[$color] += $object->zone === GameObject::BATTLEFIELD ? 1 : 2;
                }
            }
        }
        arsort($count);

        return ['W' => 'white', 'U' => 'blue', 'B' => 'black', 'R' => 'red', 'G' => 'green'][array_key_first($count)];
    }

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
            $types = ['artifacts' => 'Artifact', 'creatures' => 'Creature', 'enchantments' => 'Enchantment', 'instants' => 'Instant', 'sorceries' => 'Sorcery', 'planeswalkers' => 'Planeswalker'];
            if (preg_match('/^protection from (\w+)$/', $keyword, $m) && isset($types[$m[1]]) && $source->definition()->is($types[$m[1]])) {
                return true;
            }
            if (str_starts_with($keyword, 'protection from sub:') && in_array(substr($keyword, 20), $source->definition()->subtypes, true)) {
                return true;
            }
        }

        return false;
    }

    public function canBlock(GameObject $blocker, GameObject $attacker): bool
    {
        if (! $this->isCreature($blocker) || $blocker->tapped || $blocker->zone !== GameObject::BATTLEFIELD || $blocker->controller !== $this->defender() || $this->hasKeyword($blocker, "can't block") || $this->hasKeyword($blocker, 'decayed') || ! $this->controlsNeeded($blocker, 'block')
            || ($this->hasKeyword($blocker, 'unleash') && $blocker->counter('+1/+1') > 0)) {
            return false;
        }
        if (! isset($this->attackers[$attacker->id]) || $attacker->zone !== GameObject::BATTLEFIELD) {
            return false;
        }
        // `Target creature can't block CARDNAME this turn.`
        if ($this->hasKeyword($blocker, "can't block o{$attacker->id}")) {
            return false;
        }
        if ($this->hasKeyword($attacker, 'flying') && ((! $this->hasKeyword($blocker, 'flying') && ! $this->hasKeyword($blocker, 'reach')) || $this->hasKeyword($blocker, "can't block flying"))) {
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

        // `CARDNAME can't be blocked as long as defending player controls an artifact.`
        foreach ($this->permanents($blocker->controller) as $permanent) {
            $card = $permanent->definition();
            foreach (['artifact' => $card->is('Artifact'), 'enchantment' => $card->is('Enchantment')] + array_map(fn (string $color) => in_array($color, $card->colors, true), ['white permanent' => 'W', 'blue permanent' => 'U', 'black permanent' => 'B', 'red permanent' => 'R', 'green permanent' => 'G']) as $kind => $is) {
                if ($is && $this->hasKeyword($attacker, "unblockable if defender controls {$kind}")) {
                    return false;
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
            $artifact && $this->hasKeyword($attacker, "can't be blocked by artifact creatures") => true,
            array_filter($colors, fn (string $color) => $this->hasKeyword($attacker, "can't be blocked by ".(['W' => 'white', 'U' => 'blue', 'B' => 'black', 'R' => 'red', 'G' => 'green'][$color] ?? 'colorless').' creatures')) !== [] => true,
            $this->evadesByPower($attacker, $this->power($blocker)) => true,
            $this->blocksOnlyByPower($blocker, $this->power($attacker)) => true,
            array_filter($blocker->definition()->subtypes, fn (string $type) => $this->hasKeyword($attacker, "can't be blocked by sub:{$type}")) !== [] => true,
            $this->hasKeyword($attacker, "can't be blocked by lesser power") && $this->power($blocker) < $this->power($attacker) => true,
            // Your Ring-bearer can't be blocked by creatures with greater power.
            $this->ringLevel($attacker) >= 1 && $this->power($blocker) > $this->power($attacker) => true,
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
     * Whether a blocker can't block creatures with this power
     * (`can't block creatures with power 2 or less`).
     *
     * @param GameObject $blocker
     * @param int        $power
     *
     * @return bool
     */
    private function blocksOnlyByPower(GameObject $blocker, int $power): bool
    {
        foreach ($this->keywords($blocker) as $keyword) {
            if (preg_match("/^can't block power (\\d+) or (greater|less)$/", $keyword, $m)
                && ($m[2] === 'greater' ? $power >= (int) $m[1] : $power <= (int) $m[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Declares blockers: each blocker blocks one attacker (rule 509.1), or
     * two with `can block an additional creature each combat`.
     *
     * @param int                   $seat
     * @param array<int, int|int[]> $blocks Blocker id => attacker id, or attacker ids. May be empty.
     *
     * @return void
     */
    public function declareBlockers(int $seat, array $blocks): void
    {
        $this->expect($seat, 'block');
        $extra = [];
        foreach ($blocks as $blocker => $attackers) {
            if (is_array($attackers)) {
                $attackers = array_values(array_unique(array_map('intval', $attackers)));
                if ($attackers === []) {
                    unset($blocks[$blocker]);

                    continue;
                }
                $blocks[$blocker] = $attackers[0];
                if (count($attackers) > 1) {
                    $extra[(int) $blocker] = array_slice($attackers, 1);
                }
            }
        }
        // An attacker that must be blocked if able gets a free creature that can block it (rule 509.1c).
        foreach (array_keys($this->attackers) as $attacker) {
            if (in_array($attacker, array_map('intval', $blocks), true) || ! $this->hasKeyword($this->objects[$attacker], 'must be blocked if able') || $this->minBlockers($this->objects[$attacker]) > 1) {
                continue;
            }
            foreach ($this->blockCandidates() as $candidate) {
                if (! isset($blocks[$candidate]) && $this->canBlock($this->objects[$candidate], $this->objects[$attacker])) {
                    $blocks[$candidate] = $attacker;
                    break;
                }
            }
        }
        // Vortex Elemental: a creature told to block it does if able (rule 509.1c).
        foreach ($this->blockCandidates() as $candidate) {
            foreach (array_keys($this->attackers) as $attacker) {
                if ($this->hasKeyword($this->objects[$candidate], "blocks o{$attacker} if able") && $this->canBlock($this->objects[$candidate], $this->objects[$attacker])) {
                    $blocks[$candidate] = $attacker;
                    unset($extra[$candidate]);
                    break;
                }
            }
        }
        // Lure: every creature able to block it does (rule 509.1c), unless it already blocks another such attacker.
        foreach (array_keys($this->attackers) as $attacker) {
            if (! $this->hasKeyword($this->objects[$attacker], 'all creatures able to block it do so')) {
                continue;
            }
            foreach ($this->blockCandidates() as $candidate) {
                $current = isset($blocks[$candidate]) ? $this->objects[(int) $blocks[$candidate]] ?? null : null;
                if (($current === null || ! $this->hasKeyword($current, 'all creatures able to block it do so')) && $this->canBlock($this->objects[$candidate], $this->objects[$attacker])) {
                    $blocks[$candidate] = $attacker;
                    unset($extra[$candidate]);
                }
            }
        }
        // `CARDNAME can't attack or block alone.`: a lone blocker with it stays back.
        if (count($blocks) === 1 && ($alone = $this->objects[(int) array_key_first($blocks)] ?? null) !== null && ($this->hasKeyword($alone, "can't attack or block alone") || $this->hasKeyword($alone, "can't block alone"))) {
            $this->note("{$alone->name()} can't block alone.", $seat);
            [$blocks, $extra] = [[], []];
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
        foreach ($extra as $blocker => $others) {
            $blockerObject = $this->objects[$blocker];
            if (count($others) > 1 || ! $this->hasKeyword($blockerObject, 'can block an additional creature each combat')) {
                throw new GameException("{$blockerObject->name()} can block only one attacker.");
            }
            foreach ($others as $attacker) {
                $attackerObject = $this->objects[$attacker] ?? null;
                if ($attackerObject === null || $attacker === (int) $blocks[$blocker] || ! $this->canBlock($blockerObject, $attackerObject)) {
                    throw new GameException("{$blockerObject->name()} cannot block ".($attackerObject?->name() ?? 'that').'.');
                }
                $count[$attacker] = ($count[$attacker] ?? 0) + 1;
            }
        }
        foreach ($count as $attacker => $n) {
            if ($n < ($min = $this->minBlockers($this->objects[$attacker])) && $min > 2) {
                throw new GameException($this->objects[$attacker]->name()." can be blocked only by {$min} or more creatures.");
            }
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
        $this->extraBlocks = $extra;
        foreach ($extra as $blocker => $others) {
            foreach ($others as $attacker) {
                $this->blocked[$attacker] = true;
                $lines[] = $this->objects[$blocker]->name().' also blocks '.$this->objects[$attacker]->name();
                $moves[] = $this->objects[$blocker]->name().':'.$this->objects[$attacker]->name();
            }
        }
        $this->log($lines === [] ? "{$this->players[$seat]->name} does not block." : implode('; ', $lines).'.', $seat, $moves === [] ? null : 'blk '.implode(', ', $moves));
        // Flanking (rule 702.25): a blocker without flanking gets -1/-1 for each instance.
        foreach ($this->blockers as $blocker => $attacker) {
            if ($this->hasKeyword($this->objects[$attacker], 'flanking') && ! $this->hasKeyword($this->objects[$blocker], 'flanking')) {
                $this->objects[$blocker]->untilEndOfTurn[] = ['power' => -1, 'toughness' => -1, 'keywords' => []];
                $this->log("Flanking: {$this->objects[$blocker]->name()} gets -1/-1 until end of turn.");
            }
        }
        // `Whenever CARDNAME blocks, …`
        foreach (array_unique(array_keys($this->blockers)) as $id) {
            $this->trigger($this->objects[$id], 'blocks', $this->objects[$id]->controller);
            $this->attachedTrigger($id, 'attached_blocks');
            // `Whenever a creature you control blocks, …`: "it" is that creature.
            foreach ($this->permanents($this->objects[$id]->controller) as $permanent) {
                $this->trigger($permanent, 'creature_blocks', $permanent->controller, null, $id);
            }
        }
        // `Whenever this creature becomes blocked`, afflict.
        foreach (array_keys($this->blocked) as $id) {
            $this->trigger($this->objects[$id], 'blocked', $this->objects[$id]->controller);
            // `Whenever a creature you control becomes blocked, …`: "it" is that creature.
            foreach ($this->permanents($this->objects[$id]->controller) as $permanent) {
                $this->trigger($permanent, 'creature_becomes_blocked', $permanent->controller, null, $id);
            }
        }
        // `Whenever CARDNAME blocks or becomes blocked by a creature, …`: once for each creature, knowing which.
        $pairs = [];
        foreach ($this->blockers as $blocker => $attacker) {
            $pairs[] = [$blocker, $attacker];
        }
        foreach ($extra as $blocker => $others) {
            foreach ($others as $attacker) {
                $pairs[] = [(int) $blocker, (int) $attacker];
            }
        }
        foreach ($pairs as [$blocker, $attacker]) {
            foreach (['blocks_creature' => [$blocker, $attacker], 'blocked_by_creature' => [$attacker, $blocker]] as $event => [$one, $other]) {
                $this->trigger($this->objects[$one], $event, $this->objects[$one]->controller, null, $other);
                $this->trigger($this->objects[$one], 'block_pair', $this->objects[$one]->controller, null, $other);
            }
            if ($this->hasKeyword($this->objects[$attacker], 'flying')) {
                $this->trigger($this->objects[$blocker], 'blocks_flyer', $this->objects[$blocker]->controller, null, $attacker);
            }
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

    /**
     * The Ring tempts a player (rule 701.54): its level goes up and they
     * choose a creature they control as their Ring-bearer; the strongest one.
     *
     * @param int $seat
     *
     * @return void
     */
    private function temptWithRing(int $seat): void
    {
        $player = $this->players[$seat];
        $player->ring++;
        $creatures = array_values(array_filter($this->permanents($seat), fn (GameObject $o) => $this->isCreature($o)));
        usort($creatures, fn (GameObject $a, GameObject $b) => $this->power($b) <=> $this->power($a));
        $current = $player->ringBearer === null ? null : $this->targetObject($player->ringBearer);
        $bearer = $current !== null && $current->zone === GameObject::BATTLEFIELD && $current->controller === $seat && $creatures !== [] && $this->power($current) >= $this->power($creatures[0]) ? $current : ($creatures[0] ?? null);
        $player->ringBearer = $bearer === null ? null : "o:{$bearer->id}:{$bearer->incarnation}";
        $this->log("The Ring tempts {$player->name}".($bearer === null ? '.' : "; {$bearer->name()} is their Ring-bearer."));
    }

    /**
     * How many times the Ring has tempted the controller of their Ring-bearer, or 0 for any other creature.
     *
     * @param GameObject $object
     *
     * @return int
     */
    public function ringLevel(GameObject $object): int
    {
        $player = $this->players[$object->controller] ?? null;

        return $player !== null && $object->zone === GameObject::BATTLEFIELD && $player->ringBearer === "o:{$object->id}:{$object->incarnation}" ? min(4, $player->ring) : 0;
    }

    private function ringTrigger(GameObject $bearer, string $text): void
    {
        $effects = (new CardDefinition(['name' => 'The Ring', 'type' => 'Sorcery', 'text' => $text]))->spellEffects();
        $this->pendingTriggers[] = [
            'source' => $bearer->id, 'incarnation' => $bearer->incarnation, 'controller' => $bearer->controller,
            'effects' => $effects, 'kinds' => self::targetKindsOf($effects), 'label' => 'The Ring', 'text' => $text,
        ];
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
        if ($this->fogTurn === $this->turn && $this->unpreventableTurn !== $this->turn) {
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
                [...array_keys($this->blockers, $id, true), ...array_keys(array_filter($this->extraBlocks, fn (array $others) => in_array($id, $others, true)))],
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
            if (! $deals($object) || ($power = $this->power($object)) <= 0) {
                continue;
            }
            // Blocking more than one: lethal damage to each in order, the rest to the last.
            $blocked = array_values(array_filter([$attacker, ...$this->extraBlocks[$blocker] ?? []], fn (int $id) => $this->objects[$id]->zone === GameObject::BATTLEFIELD));
            $deathtouch = $this->hasKeyword($object, 'deathtouch');
            foreach ($blocked as $index => $id) {
                $target = $this->objects[$id];
                $amount = $index === count($blocked) - 1 ? $power : min($power, $deathtouch ? 1 : max(0, $this->toughness($target) - $target->damage));
                if ($amount > 0) {
                    $assignments[] = [$object, "o:{$id}", $amount];
                }
                $power -= $amount;
            }
        }

        if ($first) {
            foreach ([...array_keys($this->attackers), ...array_keys($this->blockers)] as $id) {
                if ($deals($this->objects[$id])) {
                    $this->struckFirst[$id] = true;
                }
            }
        }

        // `Prevent all damage target creature would deal this turn.`: it deals none, so nothing triggers on it.
        $assignments = array_values(array_filter($assignments, fn (array $assignment) => $assignment[0]->preventDealing !== $this->turn || $this->unpreventableTurn === $this->turn));
        // All combat damage is dealt at once (rule 510.2).
        $lines = $hitPlayer = $toPlayers = [];
        $this->combatDealt = [];
        foreach ($assignments as [$source, $target, $amount]) {
            $hurt = $target[0] === 'o' ? $this->objects[(int) substr($target, 2)] : null;
            $this->dealDamage($source, $target, $amount);
            $lines[] = "{$source->name()} deals {$amount} to ".$this->describeTarget($target);
            // `Whenever CARDNAME deals combat damage to a creature, tap that creature …`: that creature, not targeted.
            foreach ($hurt !== null ? $source->definition()->triggersOn('combat_damage_creature') : [] as $ability) {
                $this->pendingTriggers[] = [
                    'source' => $source->id, 'incarnation' => $source->incarnation, 'controller' => $source->controller,
                    // `… you gain that much life.`: the damage it dealt to that creature.
                    'effects' => array_map(fn (array $effect) => ($effect['amount'] ?? null) === 'that_much' ? ['amount' => $amount] + $effect : $effect, $ability['effects']),
                    'kinds' => $kinds = self::targetKindsOf($ability['effects']), 'targets' => $kinds === [] ? [] : ["o:{$hurt->id}:{$hurt->incarnation}"],
                    'label' => "{$source->name()}'s ability", 'text' => str_replace('CARDNAME', $source->name(), $ability['text']),
                ];
            }
            if ($target[0] === 'p') {
                // Toxic (rule 702.164): poison counters too.
                if (($toxic = $this->keywordAmount($source, 'toxic')) > 0) {
                    $this->players[(int) substr($target, 2)]->poison += $toxic;
                }
                $hitPlayer[$source->id] = $source;
                $this->combatDealt[$source->id] = ($this->combatDealt[$source->id] ?? 0) + $amount;
                // `Whenever a creature deals combat damage to you, …`
                foreach ($this->permanents((int) substr($target, 2)) as $permanent) {
                    $this->trigger($permanent, 'creature_combat_damages_you', $permanent->controller, null, $source->id);
                }
                $toPlayers[(int) substr($target, 2)] = ($toPlayers[(int) substr($target, 2)] ?? 0) + $amount;
                if ($this->isCommander($source) || in_array('Assassin', $source->definition()->subtypes, true)) {
                    $this->freerun[$source->controller] = $this->turn;
                }
                if ($this->isCommander($source)) {
                    $hit = $this->players[(int) substr($target, 2)];
                    $hit->commanderDamage[$source->id] = ($hit->commanderDamage[$source->id] ?? 0) + $amount;
                }
            }
        }
        // `Whenever one or more creatures you control deal combat damage to a player, …`: once.
        foreach (array_unique(array_map(fn (GameObject $source) => $source->controller, $hitPlayer)) as $seat) {
            foreach ($this->permanents($seat) as $permanent) {
                $this->trigger($permanent, 'you_combat_damage_batch', $seat);
            }
        }
        foreach ($hitPlayer as $source) {
            $this->trigger($source, 'combat_damage', $source->controller);
            $this->attachedTrigger($source->id, 'attached_combat_damage');
            foreach ($this->permanents($source->controller) as $permanent) {
                $this->trigger($permanent, 'creature_combat_damage', $source->controller, null, $source->id);
                if ($permanent->id !== $source->id) {
                    $this->trigger($permanent, 'creature_combat_damage_other', $source->controller, null, $source->id);
                }
            }
            // The Ring, fourth level.
            if ($this->ringLevel($source) >= 4) {
                $this->ringTrigger($source, 'Each opponent loses 3 life.');
            }
            // Cipher: a copy of each card encoded on it, cast without paying its mana cost.
            foreach ($source->encoded as $encoded) {
                $card = $this->objects[$encoded];
                if ($card->zone !== GameObject::EXILE) {
                    continue;
                }
                $effects = $card->printed()->spellEffects();
                $this->pendingTriggers[] = [
                    'source' => $source->id, 'incarnation' => $source->incarnation, 'controller' => $source->controller,
                    'effects' => $effects, 'kinds' => self::targetKindsOf($effects),
                    'label' => "{$card->name()} (cipher copy)", 'text' => trim((string) preg_replace('/\s*\([^)]*\)/', '', $card->printed()->text)),
                ];
            }
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
     * Records that a player lost life this turn, then triggers `Whenever an
     * opponent loses life, …` on the other players' permanents and `Whenever
     * you lose life, …` on that player's own.
     *
     * @param int $seat
     *
     * @return void
     */
    private function lostLife(int $seat): void
    {
        $this->players[$seat]->lostLifeOnTurn = $this->turn;
        foreach ($this->permanents() as $permanent) {
            if ($permanent->controller !== $seat) {
                $this->trigger($permanent, 'opponent_loses_life', $permanent->controller, null, $seat);
                if ($this->active === $permanent->controller) {
                    // `Whenever an opponent loses life during your turn, …`
                    $this->trigger($permanent, 'opponent_loses_life_your_turn', $permanent->controller, null, $seat);
                }
            } else {
                // `Whenever you lose life (during your turn), …`
                $this->trigger($permanent, 'you_lose_life', $seat);
                if ($this->active === $seat) {
                    $this->trigger($permanent, 'you_lose_life_your_turn', $seat);
                }
            }
        }
    }

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
        // Shields are keyed without a target's incarnation (`o:ID`, not `o:ID:N`).
        $shielded = implode(':', array_slice(explode(':', $target), 0, 2));
        $preventable = $this->unpreventableTurn !== $this->turn;
        if ($preventable && $source->preventDealing === $this->turn) {
            $this->log("{$amount} damage from {$source->name()} is prevented.");

            return;
        }
        if ($preventable && ($shield = $this->prevent[$shielded] ?? 0) > 0) {
            $prevented = min($shield, $amount);
            $this->prevent[$shielded] = $shield - $prevented;
            $amount -= $prevented;
            $this->log("{$prevented} damage from {$source->name()} is prevented.");
            if (isset($this->preventGain[$shielded])) {
                $this->gainLife($this->preventGain[$shielded], $prevented);
            }
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
                $this->lostLife((int) $parts[1]);
            }
            // `Whenever CARDNAME deals damage to a player (an opponent), …`: that player is the context.
            $this->trigger($source, 'damages_player', $source->controller, null, (int) $parts[1]);
            if ((int) $parts[1] !== $source->controller) {
                $this->trigger($source, 'damages_opponent', $source->controller, null, (int) $parts[1]);
                // `Whenever a source an opponent controls deals damage to you, …`
                foreach ($this->permanents((int) $parts[1]) as $permanent) {
                    $this->trigger($permanent, 'opponent_source_damages_you', (int) $parts[1]);
                }
            }
        } else {
            $object = $this->targetObject($target);
            if ($object === null || $object->zone !== GameObject::BATTLEFIELD) {
                return;
            }
            if ($preventable && $this->protectedFrom($object, $source)) {
                $this->log("Protection prevents the damage {$source->name()} would deal to {$object->name()}.");

                return;
            }
            // A shield counter (rule 122.1c): prevent the damage and remove the counter instead.
            if ($preventable && $object->counter('shield') > 0) {
                $object->addCounters('shield', -1);
                $this->log("A shield counter on {$object->name()} prevents the damage and is removed.");

                return;
            }
            if ($this->isCreature($object) && ! in_array($source->id, $object->damagedBy, true)) {
                $object->damagedBy[] = $source->id;
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
            // Boros Reckoner: `Whenever CARDNAME is dealt damage, it deals that much damage to any target.`: the amount is the context.
            $this->trigger($object, 'dealt_damage', $object->controller, null, $amount);
            // `Whenever CARDNAME deals damage to a creature, destroy that creature.`: that creature, not targeted.
            foreach ($this->isCreature($object) && $source->zone === GameObject::BATTLEFIELD ? $source->definition()->triggersOn('damages_creature') : [] as $ability) {
                $this->pendingTriggers[] = [
                    'source' => $source->id, 'incarnation' => $source->incarnation, 'controller' => $source->controller,
                    'effects' => $ability['effects'], 'kinds' => $kinds = self::targetKindsOf($ability['effects']), 'targets' => $kinds === [] ? [] : ["o:{$object->id}:{$object->incarnation}"],
                    'label' => "{$source->name()}'s ability", 'text' => str_replace('CARDNAME', $source->name(), $ability['text']),
                ];
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
        // `Players can't gain life.` and `Your opponents can't gain life.`
        foreach ($this->permanents() as $permanent) {
            if ($this->hasKeyword($permanent, "players can't gain life") || ($permanent->controller !== $seat && $this->hasKeyword($permanent, "opponents can't gain life"))) {
                return;
            }
        }
        // `If you would gain life, you gain that much life plus 1 instead.`
        foreach ($this->permanents($seat) as $permanent) {
            $amount += $this->keywordAmount($permanent, 'gain life plus');
        }
        $player = $this->players[$seat];
        $player->life += $amount;
        $first = $player->gainedTurn !== $this->turn;
        [$player->gained, $player->gainedTurn] = [($first ? 0 : $player->gained) + $amount, $this->turn];
        foreach ($this->permanents($seat) as $permanent) {
            $this->trigger($permanent, 'gain_life', $seat);
            if ($first) {
                $this->trigger($permanent, 'first_gain_life', $seat);
            }
        }
        // `Whenever an opponent gains life, …`
        foreach ($this->permanents() as $permanent) {
            if ($permanent->controller !== $seat) {
                $this->trigger($permanent, 'opponent_gains_life', $permanent->controller);
            }
        }
    }

    /**
     * Retain pre-event observers, process a selected group, then notify actual deaths.
     * Replacement/prevention behavior stays in destroy()/moveTo().
     *
     * @param GameObject[] $doomed
     * @param callable(GameObject): void $move
     */
    private function withDeathObservers(array $doomed, callable $move): void
    {
        if ($doomed === []) {
            return;
        }
        $characteristics = [];
        foreach ($doomed as $object) {
            $characteristics[$object->id] = new DepartureCharacteristics($object->definition(), $this->isCreature($object), $this->power($object), $this->toughness($object), $object->battlefieldManaValue());
        }
        $batch = new DeathObserverBatch($this->permanents(), $doomed, $characteristics);
        $previous = $this->deathObserverBatch;
        $this->deathObserverBatch = $batch;
        try {
            foreach ($doomed as $object) {
                $move($object);
            }
        } finally {
            $this->deathObserverBatch = $previous;
        }
        foreach ($batch->deaths as $death) {
            $this->notifyCreatureDeath($death, $batch->observers);
        }
    }

    /**
     * Dispatch one death against the chosen observer registry. Each event keeps
     * its own power/counter facts even after later deaths overwrite the globals.
     *
     * @param GameObject[] $observers
     */
    private function notifyCreatureDeath(CreatureDeath $death, array $observers): void
    {
        [$power, $counter] = [$this->diedPower, $this->diedWithCounter];
        [$this->diedPower, $this->diedWithCounter] = [$death->power, $death->hadPlusCounter];
        try {
            foreach ($observers as $observer) {
                if ($observer->id === $death->id) {
                    continue; // Its own dies ability was queued separately.
                }
                $actual = $this->objects[$observer->id];
                // Share once-per-turn marks with own dies triggers and surviving sources.
                $observer->used = array_replace($observer->used, $actual->used);
                $this->trigger($observer, $observer->controller === $death->controller ? 'creature_dies_other' : 'creature_dies_any', $observer->controller, $observer->incarnation, $death->id, $death->characteristics, $observer->definition());
                if ($actual->zone === GameObject::BATTLEFIELD && $actual->incarnation === $observer->incarnation) {
                    $actual->used = array_replace($actual->used, $observer->used);
                }
            }
        } finally {
            [$this->diedPower, $this->diedWithCounter] = [$power, $counter];
        }
    }

    private function destroy(?GameObject $object, bool $regenerates = true): void
    {
        if ($object === null || $object->zone !== GameObject::BATTLEFIELD || $this->hasKeyword($object, 'indestructible')) {
            return;
        }
        // A shield counter (rule 122.1c): remove it instead.
        if ($object->counter('shield') > 0) {
            $object->addCounters('shield', -1);
            $this->log("A shield counter on {$object->name()} is removed instead of it being destroyed.");

            return;
        }
        if (($regenerates && $this->regenerate($object)) || $this->umbraArmor($object)) {
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
        unset($this->attackers[$object->id], $this->blockers[$object->id], $this->extraBlocks[$object->id]);
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

    /**
     * Every seat in turn order, starting with the active player (rule 101.4).
     *
     * @return list<int>
     */
    private function turnOrderSeats(): array
    {
        $seats = array_keys($this->players);
        $count = count($seats);
        usort($seats, fn (int $a, int $b) => ($a - $this->active + $count) % $count <=> ($b - $this->active + $count) % $count);

        return $seats;
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
                // "Whenever you draw a card, …"
                foreach ($this->permanents($seat) as $permanent) {
                    $this->trigger($permanent, 'you_draw', $seat);
                }
                // `Whenever an opponent draws a card, …`; `Whenever a player draws a card, …`
                foreach ($this->permanents() as $permanent) {
                    $this->trigger($permanent, 'any_draw', $permanent->controller, null, $seat);
                    if ($permanent->controller !== $seat) {
                        $this->trigger($permanent, 'opponent_draw', $permanent->controller, null, $seat);
                    }
                }
                // Miracle (rule 702.94): revealed as the first card drawn this turn.
                if ($player->drawn === 0 && isset($this->objects[$id]->printed()->altCosts['miracle'])) {
                    $this->objects[$id]->miracle = $this->turn.':'.$this->step->name;
                    $this->log("{$player->name} reveals {$this->objects[$id]->name()} (miracle {$this->objects[$id]->printed()->altCosts['miracle']}).");
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
     * @param array{alt?: string, cast?: string, unearthed?: bool, haste?: bool, counters?: array<string, int>, attacking?: bool} $entryState State applied after entering, including after a saved decision.
     *
     * @return void
     */
    private function putOntoBattlefield(GameObject $object, int $controller, bool $triggers = true, bool $faceDown = false, bool $kicked = false, int $x = 0, bool $transformed = false, bool $tapped = false, ?bool $payLife = null, array $entryState = []): void
    {
        // A land's optional payment is chosen before it changes zones. Other
        // entries in this batch wait too; no entry triggers fire prematurely.
        $card = $object->printed();
        if ($payLife === null && $this->stage === self::PLAYING &&
            (($this->pendingChoice['type'] ?? null) === 'entry_life' || (! $faceDown && $card->isLand() && isset($card->entersTappedUnless['life'])))) {
            $this->pendingEntries[] = [
                'object' => $object->id, 'incarnation' => $object->incarnation, 'zone' => $object->zone,
                'controller' => $controller, 'triggers' => $triggers, 'faceDown' => $faceDown,
                'kicked' => $kicked, 'x' => $x, 'transformed' => $transformed, 'tapped' => $tapped, 'entryState' => $entryState,
            ];
            if ($this->pendingChoice === null) {
                $this->advanceEntries();
            }

            return;
        }
        // `… a +1/+1 counter on it for each creature you control`: counted before it enters, so it never counts itself.
        $perCounters = ! $faceDown && is_string($card->entersWithCounters) && str_starts_with($card->entersWithCounters, 'per:')
            ? $this->perCount($controller, substr($card->entersWithCounters, 4), $object) : null;
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
        $object->prepared = ! $faceDown && in_array('enters prepared', $card->keywords, true) && $card->prepare !== null;
        $untapped = $card->entersTappedUnless === null || $this->entersUntapped($object, $controller, $payLife ?? false);
        $object->tapped = $tapped || $card->entersTapped || ! $untapped
            // `Creatures your opponents control enter tapped.`, and Blind Obedience's artifacts and creatures.
            || (! $faceDown && ($card->isCreature() || $card->is('Artifact')) && array_filter($this->permanents(), fn (GameObject $o) => $o->controller !== $controller && (($card->isCreature() && $this->hasKeyword($o, 'opponent creatures enter tapped')) || $this->hasKeyword($o, 'opponent artifacts and creatures enter tapped'))) !== []);
        if ($card->isPlaneswalker() && $card->loyalty !== null) {
            $object->addCounters('loyalty', $card->loyalty);
        }
        $counters = match (true) {
            $card->entersWithCounters === 'X' => $x,
            is_string($card->entersWithCounters) && str_starts_with($card->entersWithCounters, 'per:') => $perCounters ?? 0,
            default => (int) $card->entersWithCounters,
        } + ($kicked ? $card->kickerCounters : 0);
        if ($counters > 0) {
            $object->addCounters('+1/+1', $counters);
        }
        if ($card->entersWithMinusCounters > 0) {
            $object->addCounters('-1/-1', $card->entersWithMinusCounters);
        }
        $this->soulbond($object);
        if ($card->chooses !== null) {
            $object->chosen = $this->choiceFor($controller, $card->chooses);
            $this->log("{$this->players[$controller]->name} chooses {$object->chosen} for {$object->name()}.");
        }
        // Bloodthirst (rule 702.54): if an opponent was dealt damage this turn.
        foreach ($card->entersWithOther as $kind => $n) {
            $object->addCounters($kind, $n === 'X' ? $x : (int) $n);
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
        $object->alt = $entryState['alt'] ?? null;
        $object->cast = $entryState['cast'] ?? '';
        $object->unearthed = $entryState['unearthed'] ?? false;
        if ($object->unearthed || ($entryState['haste'] ?? false)) {
            $object->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['haste']];
        }
        foreach ($entryState['counters'] ?? [] as $kind => $count) {
            $object->addCounters($kind, $count);
        }
        if ($entryState['attacking'] ?? false) {
            $this->attackers[$object->id] = true;
        }
        $this->battlefield[] = $object->id;
        if ($triggers) {
            $this->trigger($object, 'enters', $controller);
            // `Whenever an artifact you control enters, …`, `… an opponent controls enters, …`, `Whenever a land enters, …`: itself included.
            foreach ($this->permanents() as $permanent) {
                $this->trigger($permanent, 'permanent_enters_any', $permanent->controller, null, $object->id);
                $this->trigger($permanent, $permanent->controller === $controller ? 'permanent_enters_yours' : 'permanent_enters_opponent', $permanent->controller, null, $object->id);
            }
            // A Saga gets its first lore counter as it enters (rule 714.3a).
            if ($this->keywordAmount($object, 'saga') > 0) {
                $this->addLore($object);
            }
            // Tribal enters triggers: `Whenever another Elf you control enters, …`.
            if ($object->definition()->subtypes !== []) {
                foreach ($this->permanents($controller) as $permanent) {
                    $this->trigger($permanent, 'subtype_enters', $controller, null, $object->id);
                }
            }
            // Evolve (rule 702.100): a bigger creature entering under your control. Graft: any other creature of yours.
            if ($this->isCreature($object)) {
                foreach ($this->permanents($controller) as $permanent) {
                    if ($permanent->id !== $object->id) {
                        $this->trigger($permanent, 'creature_enters_other', $controller, null, $object->id);
                        // `Whenever one or more other creatures you control enter, …`: tokens created together trigger it once; see Game::applyEffect().
                        if (! $this->creatingTokens) {
                            $this->trigger($permanent, 'creatures_enter_other', $controller);
                        }
                        if (in_array('Ally', $object->definition()->subtypes, true)) {
                            $this->trigger($permanent, 'ally_enters_other', $controller);
                        }
                    }
                }
                // `Whenever another creature enters, …`: any player's; `… an opponent controls enters, …`: only another player's.
                foreach ($this->permanents() as $permanent) {
                    if ($permanent->id !== $object->id) {
                        $this->trigger($permanent, 'any_creature_enters', $permanent->controller, null, $object->id);
                        if ($permanent->controller !== $controller) {
                            $this->trigger($permanent, 'opponent_creature_enters', $permanent->controller, null, $object->id);
                        }
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
                // `Whenever a land enters under an opponent's control, …`
                foreach ($this->permanents() as $permanent) {
                    if ($permanent->controller !== $controller) {
                        $this->trigger($permanent, 'opponent_landfall', $permanent->controller, null, $controller);
                    }
                }
            }
        }
    }

    /**
     * Answers an optional land-entry payment using the current choice id.
     * Invalid, stale, repeated and other-player answers change nothing.
     *
     * @param int    $seat
     * @param int    $choiceId
     * @param string $option `pay` or `decline`, from choiceAwaiting()['options'].
     *
     * @return void
     */
    public function chooseEntryLife(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || $choice === null || $choice['type'] !== 'entry_life' ||
            $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That entry choice is no longer available to you.');
        }
        $entry = $this->pendingEntries[0];
        $object = $this->objects[$entry['object']];
        if ($object->zone !== $entry['zone'] || $object->incarnation !== $entry['incarnation'] ||
            ($option === 'pay' && $this->players[$seat]->life < $choice['life'])) {
            throw new GameException('That entry choice is no longer legal.');
        }
        $this->followed = $choice['resume']['followed'] ?? [];
        $this->pendingChoice = null;
        array_shift($this->pendingEntries);
        $this->finishEntry($entry, $option === 'pay');
        $this->advanceEntries();
        if (isset($choice['resume'])) {
            $resume = $choice['resume'];
            $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        }
        $this->settle();
    }

    /**
     * Answers Fabricate at resolution. The trigger controller chooses even if
     * another player now controls the original permanent. Stale/illegal answers
     * change nothing; a returned incarnation cannot receive these counters.
     *
     * @param int    $seat
     * @param int    $choiceId
     * @param string $option `counters` or `servos`, from choiceAwaiting()['options'].
     *
     * @return void
     */
    public function chooseFabricate(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || $choice === null || $choice['type'] !== 'fabricate' ||
            $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That Fabricate choice is no longer available to you.');
        }
        $object = $this->objects[$choice['object']];
        if ($option === 'counters' && ($object->zone !== GameObject::BATTLEFIELD || $object->incarnation !== $choice['incarnation'])) {
            throw new GameException('That Fabricate choice is no longer legal.');
        }
        $this->followed = $choice['resume']['followed'];
        $this->pendingChoice = null;
        if ($option === 'counters') {
            $object->addCounters('+1/+1', $choice['count']);
        } else {
            $this->fabricateServos($object, $seat, $choice['count']);
        }
        $resume = $choice['resume'];
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /**
     * Whether the pending Goblin decision's original controller can pay {R}.
     * Uses the ordinary mana planner without spending or tapping anything.
     *
     * @param int $seat
     *
     * @return bool
     */
    public function canPayOptionalRedGoblin(int $seat): bool
    {
        return $this->stage === self::PLAYING && ($this->pendingChoice['type'] ?? null) === 'optional_red_goblin'
            && $this->pendingChoice['seat'] === $seat && $this->payFor($seat, '{R}') !== null;
    }

    /**
     * Answers the exact optional {R} payment for a temporary-haste Goblin.
     * The trigger controller pays and creates the token even if the source left.
     *
     * @param int    $seat
     * @param int    $choiceId Identity from choiceAwaiting().
     * @param string $option `accept` or `decline`.
     *
     * @return void
     */
    public function chooseOptionalRedGoblin(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || ($choice['type'] ?? null) !== 'optional_red_goblin' ||
            $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That optional red-payment choice is no longer available to you.');
        }
        $payment = $option === 'accept' ? $this->payFor($seat, '{R}') : null;
        if ($option === 'accept' && $payment === null) {
            throw new GameException('You cannot pay {R} for that Goblin.');
        }
        $resume = $choice['resume'];
        $this->followed = $resume['followed'];
        $this->pendingChoice = null;
        if ($payment !== null) {
            $this->pay($seat, $payment);
            $this->applyEffect(['type' => 'token', 'amount' => 1, 'haste' => true, 'token' => [
                'name' => 'Goblin Token', 'type' => 'Token Creature — Goblin', 'types' => ['Creature'],
                'subtypes' => ['Goblin'], 'colors' => ['R'], 'power' => '1', 'toughness' => '1', 'text' => '', 'manaCost' => null,
            ]], $this->objects[$choice['object']], null, $seat, 0, null, $choice['incarnation']);
        }
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /**
     * Answers the exact optional source-untap decision at resolution.
     * The original trigger controller answers; only the same battlefield
     * incarnation can be untapped. A departed source does not stop
     * the rest of the ability from resolving.
     *
     * @param int    $seat
     * @param int    $choiceId Identity from choiceAwaiting().
     * @param string $option `accept` or `decline`.
     *
     * @return void
     */
    public function chooseOptionalSourceUntap(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || ($choice['type'] ?? null) !== 'optional_source_untap' ||
            $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That optional source-untap choice is no longer available to you.');
        }
        $resume = $choice['resume'];
        $this->followed = $resume['followed'];
        $this->pendingChoice = null;
        $source = $this->objects[$choice['object']];
        if ($option === 'accept' && $source->zone === GameObject::BATTLEFIELD && $source->incarnation === $choice['incarnation']) {
            $this->applyEffect(['type' => 'untap', 'self' => true], $source, $source, $seat, 0, null, $choice['incarnation']);
        }
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /**
     * Answers the exact optional +1/+1 source-counter decision at resolution.
     * The original trigger controller answers; only the same battlefield
     * incarnation can receive a counter. A departed source does not stop
     * the rest of the ability from resolving.
     *
     * @param int    $seat
     * @param int    $choiceId Identity from choiceAwaiting().
     * @param string $option `accept` or `decline`.
     *
     * @return void
     */
    public function chooseOptionalSourceCounter(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || ($choice['type'] ?? null) !== 'optional_source_counter' ||
            $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That optional source-counter choice is no longer available to you.');
        }
        $resume = $choice['resume'];
        $this->followed = $resume['followed'];
        $this->pendingChoice = null;
        $source = $this->objects[$choice['object']];
        if ($option === 'accept' && $source->zone === GameObject::BATTLEFIELD && $source->incarnation === $choice['incarnation']) {
            $this->applyEffect(['type' => 'counters', 'amount' => 1, 'self' => true], $source, $source, $seat, 0, null, $choice['incarnation']);
        }
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /**
     * Answers the optional five-life gain for the original trigger controller.
     * This decision does not target a player.
     *
     * @param int    $seat
     * @param int    $choiceId Identity from choiceAwaiting().
     * @param string $option `accept` or `decline`.
     *
     * @return void
     */
    public function chooseOptionalLifeGain(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || ($choice['type'] ?? null) !== 'optional_life_gain' ||
            $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That optional life-gain choice is no longer available to you.');
        }
        $resume = $choice['resume'];
        $this->followed = $resume['followed'];
        $this->pendingChoice = null;
        if ($option === 'accept') {
            $this->applyEffect(['type' => 'gain_life', 'amount' => 5], $this->objects[$choice['object']], null, $seat, 0, null, $choice['incarnation']);
        }
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /**
     * Answers the optional targeted one-life loss when the ability resolves.
     * Its controller and target remain those chosen for the original trigger.
     *
     * @param int    $seat
     * @param int    $choiceId Identity from choiceAwaiting().
     * @param string $option `accept` or `decline`.
     *
     * @return void
     */
    public function chooseOptionalLifeLoss(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || ($choice['type'] ?? null) !== 'optional_life_loss' ||
            $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That optional life-loss choice is no longer available to you.');
        }
        $resume = $choice['resume'];
        $this->followed = $resume['followed'];
        $this->pendingChoice = null;
        if ($option === 'accept') {
            $this->applyEffect(['type' => 'lose_life', 'amount' => 1], $this->objects[$choice['object']], null, $seat, 0, $choice['target'], $choice['incarnation']);
        }
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /** Reuse ordinary token batching and its entry/creation observers. */
    private function fabricateServos(GameObject $source, int $controller, int $count): void
    {
        $this->applyEffect(['type' => 'token', 'amount' => $count, 'token' => [
            'name' => 'Servo Token', 'type' => 'Token Artifact Creature — Servo', 'types' => ['Artifact', 'Creature'],
            'subtypes' => ['Servo'], 'colors' => [], 'power' => '1', 'toughness' => '1', 'text' => '', 'manaCost' => null,
        ]], $source, null, $controller, 0, null);
    }

    /** Starts the next entry decision, or completes entries without choices. */
    private function advanceEntries(): void
    {
        while ($this->pendingEntries !== []) {
            $entry = $this->pendingEntries[0];
            $object = $this->objects[$entry['object']];
            $life = $entry['faceDown'] ? null : ($object->printed()->entersTappedUnless['life'] ?? null);
            if ($life !== null && $object->printed()->isLand()) {
                $this->pendingChoice = [
                    'type' => 'entry_life', 'id' => $this->nextChoiceId++, 'seat' => $entry['controller'],
                    'object' => $object->id, 'label' => $object->name(), 'life' => $life,
                    'options' => $this->players[$entry['controller']]->life >= $life ? ['pay', 'decline'] : ['decline'],
                    'min' => 1, 'max' => 1, 'tapped' => $entry['tapped'] || $object->printed()->entersTapped,
                ];

                return;
            }
            array_shift($this->pendingEntries);
            $this->finishEntry($entry, false);
        }
    }

    /** Applies one saved entry exactly once, after its replacement decision. */
    private function finishEntry(array $entry, bool $payLife): void
    {
        $before = "o:{$entry['object']}:{$entry['incarnation']}";
        $this->putOntoBattlefield(
            $this->objects[$entry['object']],
            $entry['controller'],
            $entry['triggers'],
            $entry['faceDown'],
            $entry['kicked'],
            $entry['x'],
            $entry['transformed'],
            $entry['tapped'],
            $payLife,
            $entry['entryState'] ?? []
        );
        foreach ($this->followed as $target => $replacement) {
            if ($replacement === $before) {
                $object = $this->objects[$entry['object']];
                $this->followed[$target] = "o:{$object->id}:{$object->incarnation}";
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
     * untapped. Optional life payment is supplied by the validated entry
     * decision, never selected by the rules engine.
     *
     * @param GameObject $object
     * @param int        $controller
     *
     * @return bool
     */
    private function entersUntapped(GameObject $object, int $controller, bool $payLife = false): bool
    {
        $unless = $object->definition()->entersTappedUnless;
        if (isset($unless['reveal'])) {
            return array_filter($this->players[$controller]->hand, fn (int $id) => $id !== $object->id && in_array($unless['reveal'], $this->objects[$id]->printed()->subtypes, true)) !== [];
        }
        if (isset($unless['player_life'])) {
            return min(array_map(fn (GamePlayer $player) => $player->life, $this->players)) <= $unless['player_life'];
        }
        if (isset($unless['life'])) {
            if (! $payLife) {
                return false;
            }
            $this->players[$controller]->life -= $unless['life'];
            $this->lostLife($controller);
            $this->log("{$this->players[$controller]->name} pays {$unless['life']} life as {$object->name()} enters.");

            return true;
        }
        $others = array_filter($this->permanents($controller), fn (GameObject $o) => $o->id !== $object->id);
        $lands = count(array_filter($others, fn (GameObject $o) => $o->definition()->isLand()));
        if (isset($unless['basics_min'])) {
            return count(array_filter($others, fn (GameObject $o) => $o->definition()->isLand() && in_array('Basic', $o->definition()->supertypes, true))) >= $unless['basics_min'];
        }
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
        // Keep this selected group's registry before record() consumes its death entry.
        $selectedCharacteristics = $this->deathObserverBatch?->characteristicsOf($object);
        $departureObservers = $selectedCharacteristics !== null ? $this->deathObserverBatch->observers : null;
        if ($object->unearthed && $object->zone === GameObject::BATTLEFIELD && $zone !== GameObject::BATTLEFIELD) {
            $zone = GameObject::EXILE;
        }
        if ($object->exileIfDies === $this->turn && $object->zone === GameObject::BATTLEFIELD && $zone === GameObject::GRAVEYARD && ($this->isCreature($object) || $object->definition()->is('Planeswalker'))) {
            $zone = GameObject::EXILE;
        }
        // `If a creature an opponent controls would die, exile it instead.`
        if ($object->zone === GameObject::BATTLEFIELD && $zone === GameObject::GRAVEYARD && $this->isCreature($object)
            && array_filter($this->permanents(), fn (GameObject $o) => $o->controller !== $object->controller && $this->hasKeyword($o, 'exile dying opponent creatures')) !== []) {
            $zone = GameObject::EXILE;
        }
        // A disturbed back face: `If CARDNAME would be put into a graveyard from anywhere, exile it instead.`
        if ($zone === GameObject::GRAVEYARD && in_array('exile instead of graveyard', $object->definition()->keywords, true)) {
            $zone = GameObject::EXILE;
        }
        $leaves = $object->zone === GameObject::BATTLEFIELD && $zone !== GameObject::BATTLEFIELD;
        if ($leaves && ! $object->definition()->is('Land')) {
            $this->nonlandLeftTurn = $this->turn;
        }
        $toGraveyard = $object->zone === GameObject::BATTLEFIELD && $zone === GameObject::GRAVEYARD;
        // Capture before movement clears animation, counters, attachments and transform/morph state.
        $departingCharacteristics = $leaves ? new DepartureCharacteristics($object->definition(), $this->isCreature($object), manaValue: $object->battlefieldManaValue()) : null;
        $departingDefinition = $departingCharacteristics?->definition;
        if ($leaves) {
            // Retain the departing incarnation even if this card returns before its ability resolves.
            $object->lastKnownStatistics[$incarnation] = [
                'power' => $selectedCharacteristics?->power ?? $this->power($object),
                'toughness' => $selectedCharacteristics?->toughness ?? $this->toughness($object),
            ];
        }
        $dies = $toGraveyard && $departingCharacteristics->isCreature;
        $plusCounters = $object->counter('+1/+1');
        $diedPower = $dies ? $this->power($object) : 0;
        $damagedBy = $object->damagedBy;
        $blitzed = $dies && $object->alt === 'blitz';
        // Persist and undying (rules 702.79 and 702.93) look at its counters as it died.
        $returns = ! $dies ? null : match (true) {
            $this->hasKeyword($object, 'persist') && $object->counter('-1/-1') === 0 => '-1/-1',
            $this->hasKeyword($object, 'undying') && $object->counter('+1/+1') === 0 => '+1/+1',
            default => null,
        };
        $earthbent = $object->earthbent && $object->zone === GameObject::BATTLEFIELD && in_array($zone, [GameObject::GRAVEYARD, GameObject::EXILE], true);
        $intended = $zone;
        if ($object->definition()->isToken()) {
            $zone = GameObject::GONE;
        }

        $held = $object->zone === GameObject::BATTLEFIELD && $zone !== GameObject::BATTLEFIELD ? $object->holding : [];
        // A mutated permanent's other cards go with it (rule 721.3).
        $merged = $object->zone === GameObject::BATTLEFIELD && $zone !== GameObject::BATTLEFIELD ? array_column($object->mutations, 'id') : [];
        // The Auras on it as it dies, before they come off.
        $enchanters = $dies ? array_filter($this->permanents(), fn (GameObject $aura) => $aura->attachedTo === $object->id) : [];
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
        foreach ($merged as $id) {
            $card = $this->objects[$id];
            $to = $card->printed()->isToken() ? GameObject::GONE : $intended;
            $card->moveTo($to);
            match ($to) {
                GameObject::HAND => $this->players[$card->owner]->hand[] = $id,
                GameObject::GRAVEYARD => $this->players[$card->owner]->graveyard[] = $id,
                GameObject::LIBRARY => $this->players[$card->owner]->library[] = $id,
                GameObject::EXILE => $this->exile[] = $id,
                GameObject::COMMAND => $this->command[] = $id,
                default => null,
            };
        }
        if ($dies) {
            [$this->died, $this->diedTurn] = [$this->diedTurn === $this->turn ? $this->died + 1 : 1, $this->turn];
            $this->diedPower = $diedPower;
            $this->diedWithCounter = $plusCounters > 0;
            $this->trigger($object, 'dies', $controller, $incarnation, $plusCounters, sourceDefinition: $departingDefinition);
            // `Whenever another creature (you control) dies, …`
            // $dies captured creature status before the move; animation is gone in the graveyard.
            $death = new CreatureDeath($object->id, $incarnation, $controller, $departingCharacteristics, $diedPower, $plusCounters > 0);
            if (! ($this->deathObserverBatch?->record($death) ?? false)) {
                $this->notifyCreatureDeath($death, $this->permanents());
            }
            // Recover (rule 702.59): paid at once whenever its owner can; otherwise exiled.
            foreach ($owner->graveyard as $other) {
                if ($other !== $object->id && isset($this->objects[$other]->printed()->altCosts['recover'])) {
                    $this->recover($this->objects[$other]);
                }
            }
            // Sengir Vampire: `Whenever a creature dealt damage by CARDNAME this turn dies, …`
            foreach ($damagedBy as $sourceId) {
                if (($this->objects[$sourceId] ?? null)?->zone === GameObject::BATTLEFIELD) {
                    $this->trigger($this->objects[$sourceId], 'damaged_dies', $this->objects[$sourceId]->controller);
                }
            }
            // Haunt (rule 702.55): `When the creature it haunts dies, …`
            foreach ($this->exile as $haunter) {
                if ($this->objects[$haunter]->exiledBy === "haunt:{$object->id}:{$incarnation}") {
                    $this->trigger($this->objects[$haunter], 'haunted_dies', $this->objects[$haunter]->owner);
                }
            }
            // `When enchanted land dies, return that card to its owner's hand.` (the Zendikons)
            foreach ($enchanters as $aura) {
                $this->trigger($aura, 'enchanted_dies', $aura->controller, null, $object->id);
                // `Whenever equipped creature dies, …`: the Equipment or Aura stays behind to see it.
                $this->trigger($aura, 'attached_dies', $aura->controller, null, $object->id);
            }
        }
        // Blitz (rule 702.152): `When this creature dies, draw a card.`
        if ($blitzed) {
            $this->log("Blitz: {$this->players[$controller]->name} draws a card.");
            $this->draw($controller, 1);
        }
        if ($toGraveyard) {
            $this->trigger($object, 'to_graveyard', $controller, $incarnation, sourceDefinition: $departingDefinition);
            // Preserve existing family order; all-player observers use their own controller.
            $observers = $departureObservers ?? [$object, ...$this->permanents()];
            $events = $departingDefinition?->is('Artifact') ? ['artifact_to_graveyard', 'permanent_to_graveyard_yours', 'artifact_to_graveyard_any'] : ['permanent_to_graveyard_yours'];
            foreach ($events as $event) {
                foreach ($observers as $permanent) {
                    $observerController = $departureObservers === null && $permanent === $object ? $controller : $permanent->controller;
                    if ($event !== 'artifact_to_graveyard_any' && $observerController !== $controller) {
                        continue;
                    }
                    $definition = $departureObservers !== null ? $permanent->definition() : ($permanent === $object ? $departingDefinition : null);
                    $sourceIncarnation = $departureObservers !== null ? $permanent->incarnation : ($permanent === $object ? $incarnation : null);
                    $context = $selectedCharacteristics ?? $departingCharacteristics;
                    $actual = $this->objects[$permanent->id];
                    if ($departureObservers !== null) {
                        $permanent->used = array_replace($permanent->used, $actual->used);
                    }
                    $this->trigger($permanent, $event, $observerController, $sourceIncarnation, $object->id, $context, $definition);
                    if ($departureObservers !== null && $actual->zone === GameObject::BATTLEFIELD && $actual->incarnation === $permanent->incarnation) {
                        $actual->used = array_replace($actual->used, $permanent->used);
                    }
                }
            }
        }
        // `When CARDNAME leaves the battlefield, …`
        if ($leaves) {
            $this->trigger($object, 'leaves', $controller, $incarnation, sourceDefinition: $departingDefinition);
            // `Whenever a creature you control leaves the battlefield, …`: itself included.
            foreach ([$object, ...$this->permanents($controller)] as $permanent) {
                $this->trigger($permanent, 'permanent_leaves_yours', $controller, null, $object->id, $departingCharacteristics, $permanent === $object ? $departingDefinition : null);
            }
        }
        // `When CARDNAME is put into a graveyard from anywhere, shuffle it into its owner's library.`: the card in the graveyard.
        if ($zone === GameObject::GRAVEYARD) {
            $this->trigger($object, 'to_graveyard_anywhere', $object->owner);
        }
        // What it exiled "until this leaves the battlefield" returns.
        foreach ($held as $id) {
            if (isset($this->objects[$id]) && $this->objects[$id]->zone === GameObject::EXILE && $this->objects[$id]->exiledBy === 'held:hand') {
                $this->moveTo($this->objects[$id], GameObject::HAND);
                $this->log("{$this->objects[$id]->name()} returns to its owner's hand.");
            } elseif (isset($this->objects[$id]) && $this->objects[$id]->zone === GameObject::EXILE) {
                $this->putOntoBattlefield($this->objects[$id], $this->objects[$id]->owner);
                $this->log("{$this->objects[$id]->name()} returns to the battlefield.");
            }
        }
        if ($returns !== null && $object->zone === GameObject::GRAVEYARD) {
            $this->putOntoBattlefield($object, $object->owner, entryState: ['counters' => [$returns => 1]]);
            $this->log("{$object->name()} returns to the battlefield with a {$returns} counter.");
        }
        if ($earthbent && in_array($object->zone, [GameObject::GRAVEYARD, GameObject::EXILE], true)) {
            $this->putOntoBattlefield($object, $object->owner, tapped: true);
            $this->log("{$object->name()} returns to the battlefield tapped (earthbend).");
        }
    }

    private function removeFromZone(GameObject $object): void
    {
        $remove = fn (array $ids) => array_values(array_filter($ids, fn (int $id) => $id !== $object->id));
        $owner = $this->players[$object->owner];
        switch ($object->zone) {
            case GameObject::BATTLEFIELD:
                $this->battlefield = $remove($this->battlefield);
                unset($this->attackers[$object->id], $this->blockers[$object->id], $this->extraBlocks[$object->id]);
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
     * Hideaway (rule 702.75): looks at the top N cards, exiles the best nonland one face down (else a land), and puts the rest on the bottom in a random order.
     *
     * @param GameObject $source
     * @param int        $seat
     * @param int        $n
     *
     * @return void
     */
    private function hideaway(GameObject $source, int $seat, int $n): void
    {
        $player = $this->players[$seat];
        $top = array_reverse(array_slice($player->library, -$n));
        if ($top === []) {
            return;
        }
        usort($top, fn (int $a, int $b) => [$this->objects[$b]->printed()->isLand() ? 0 : 1, $this->objects[$b]->printed()->cost->manaValue()] <=> [$this->objects[$a]->printed()->isLand() ? 0 : 1, $this->objects[$a]->printed()->cost->manaValue()]);
        $player->library = array_values(array_diff($player->library, $top));
        $hidden = $this->objects[array_shift($top)];
        $hidden->moveTo(GameObject::EXILE);
        $this->exile[] = $hidden->id;
        $hidden->exiledBy = 'hideaway';
        $source->hidden = $hidden->id;
        shuffle($top);
        foreach ($top as $id) {
            array_unshift($player->library, $id);
        }
        $this->log("{$player->name} exiles a card face down with {$source->name()} (hideaway).");
    }

    /**
     * Transfers one-shot draws to the ordinary trigger queue at the next
     * turn's upkeep, whoever is active. Removing each schedule now prevents
     * a countered trigger or a resumed game from scheduling it again.
     *
     * @return void
     */
    private function triggerDelayedDraws(): void
    {
        foreach ($this->delayedDraws as $index => $draw) {
            if ($draw['createdTurn'] >= $this->turn) {
                continue;
            }
            unset($this->delayedDraws[$index]);
            $this->pendingTriggers[] = [
                'source' => $draw['source'],
                'incarnation' => $draw['incarnation'],
                'controller' => $draw['controller'],
                'effects' => [['type' => 'draw', 'amount' => 1]],
                'kinds' => [],
                'label' => $draw['label'],
                'text' => 'Draw a card.',
            ];
        }
        $this->delayedDraws = array_values($this->delayedDraws);
    }

    /**
     * The Pacts: at the active player's upkeep after the turn each was cast, its cost is paid when it can be, or they lose the game.
     *
     * @return void
     */
    private function payPacts(): void
    {
        foreach ($this->pacts as $index => $pact) {
            if ($pact['seat'] !== $this->active || $pact['turn'] >= $this->turn) {
                continue;
            }
            unset($this->pacts[$index]);
            $player = $this->players[$pact['seat']];
            if (($payment = $this->payFor($pact['seat'], $pact['cost'])) !== null) {
                $this->pay($pact['seat'], $payment);
                $this->log("{$player->name} pays {$pact['cost']} for {$pact['name']}.");
            } elseif ($pact['lose']) {
                $this->lose($player, "could not pay {$pact['cost']} for {$pact['name']}");
            }
        }
        $this->pacts = array_values($this->pacts);
    }

    /**
     * The permanents a `… all creatures (artifacts, nonland permanents …)` effect covers, as the parser names them.
     *
     * @param string     $all        Such as `all creatures` or `all creatures with power 3 or less`.
     * @param GameObject $source
     * @param int        $controller
     *
     * @return array<int, GameObject>
     */
    private function allMatching(string $all, GameObject $source, int $controller): array
    {
        return array_filter($this->permanents(), fn (GameObject $o) => match ($all) {
            'all creatures' => $this->isCreature($o),
            'all other creatures' => $this->isCreature($o) && $o->id !== $source->id,
            'all creatures with flying' => $this->isCreature($o) && $this->hasKeyword($o, 'flying'),
            'all creatures without flying' => $this->isCreature($o) && ! $this->hasKeyword($o, 'flying'),
            'all tapped creatures' => $this->isCreature($o) && $o->tapped,
            'all attacking creatures' => $this->isCreature($o) && isset($this->attackers[$o->id]),
            "all creatures you don't control", 'all creatures your opponents control' => $this->isCreature($o) && $o->controller !== $controller,
            'all artifacts' => $o->definition()->is('Artifact'),
            'all enchantments' => $o->definition()->is('Enchantment'),
            'all artifacts and enchantments' => $o->definition()->is('Artifact') || $o->definition()->is('Enchantment'),
            'all artifacts, creatures, and lands' => $o->definition()->is('Artifact') || $this->isCreature($o) || $o->definition()->isLand(),
            // `Destroy all creatures with power 3 or less.`, or with mana value.
            default => preg_match('/^all creatures with (power|mana value) (\d+) or (less|greater)$/', $all, $m)
                ? $this->isCreature($o) && (($m[1] === 'power' ? $this->power($o) : $o->definition()->cost->manaValue()) <=> (int) $m[2]) !== ($m[3] === 'less' ? 1 : -1)
                : ! $o->definition()->isLand(),
        });
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
        // `Whenever CARDNAME (a creature you control) becomes tapped, …`: compared with the last check, wherever it was tapped.
        $tapped = [];
        foreach ($this->permanents() as $object) {
            if ($object->tapped) {
                $tapped[] = $object->id;
                if (! in_array($object->id, $this->seenTapped, true)) {
                    $this->trigger($object, 'becomes_tapped', $object->controller);
                    foreach ($this->isCreature($object) ? $this->permanents($object->controller) : [] as $permanent) {
                        $this->trigger($permanent, 'creature_becomes_tapped', $permanent->controller, null, $object->id);
                    }
                    // `Whenever a creature an opponent controls becomes tapped, …`
                    foreach ($this->isCreature($object) ? $this->permanents() : [] as $permanent) {
                        if ($permanent->controller !== $object->controller) {
                            $this->trigger($permanent, 'opponent_creature_becomes_tapped', $permanent->controller, null, $object->id);
                        }
                    }
                }
            }
        }
        $this->seenTapped = $tapped;
        // `Whenever one or more +1/+1 counters are put on CARDNAME (a creature you control), …`: more than at the last check.
        $counters = [];
        foreach ($this->permanents() as $object) {
            if (($now = $object->counter('+1/+1')) > 0) {
                $counters[$object->id] = $now;
            }
            if ($now > ($this->seenCounters[$object->id] ?? 0)) {
                $this->trigger($object, 'counters_put', $object->controller);
                foreach ($this->isCreature($object) ? $this->permanents($object->controller) : [] as $permanent) {
                    $this->trigger($permanent, 'counters_put_creature', $permanent->controller, null, $object->id);
                }
            }
        }
        $this->seenCounters = $counters;
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

            if ($toGraveyard !== []) {
                $this->withDeathObservers(array_map(fn (int $id) => $this->objects[$id], array_keys($toGraveyard)), function (GameObject $object) use ($toGraveyard): void {
                    $this->log("{$toGraveyard[$object->id]}.", null, '†'.$object->name());
                    $this->moveTo($object, GameObject::GRAVEYARD);
                });
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
    /**
     * A player's devotion to colors (rule 700.5): the mana symbols of those
     * colors among the mana costs of permanents they control.
     *
     * @param int      $seat
     * @param string[] $colors
     *
     * @return int
     */
    public function devotion(int $seat, array $colors): int
    {
        $count = 0;
        foreach ($this->permanents($seat) as $permanent) {
            if ($permanent->faceDown) {
                continue;
            }
            preg_match_all('/\{([^}]+)\}/', (string) ($permanent->definition()->card['manaCost'] ?? ''), $symbols);
            foreach ($symbols[1] as $symbol) {
                if (array_intersect(str_split($symbol), $colors) !== []) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function isCreature(GameObject $object): bool
    {
        // Impending: not a creature while it has a time counter (rule 702.176a).
        if ($object->alt === 'impending' && $object->zone === GameObject::BATTLEFIELD && $object->counter('time') > 0) {
            return false;
        }
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
        // A Theros god: not a creature below its devotion (rule 700.5).
        foreach ($object->definition()->keywords as $keyword) {
            if ($object->zone === GameObject::BATTLEFIELD && preg_match('/^devotion ([WUBRG]+) (\d+)$/', $keyword, $m) && $this->devotion($object->controller, str_split($m[1])) < (int) $m[2]) {
                return false;
            }
        }
        if ($object->definition()->isCreature() || $object->awakened) {
            return true;
        }
        // A Zendikon: `Enchanted land is a 2/2 white Knight creature with first strike. It's still a land.`
        if ($object->zone === GameObject::BATTLEFIELD && $object->definition()->isLand()) {
            foreach ($this->battlefield as $id) {
                if ($this->objects[$id]->attachedTo === $object->id && ($this->objects[$id]->definition()->aura['animates'] ?? false)) {
                    return true;
                }
            }
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
     * `Equipped creature gets +1/+1 for each creature you control.`: counted for the Equipment's or Aura's controller.
     *
     * @param GameObject $attached
     *
     * @return array{0: int, 1: int}
     */
    private function attachedPer(GameObject $attached): array
    {
        $bonus = $this->bonusOf($attached);
        if (! isset($bonus['per'])) {
            return [0, 0];
        }
        $n = $this->perCount($attached->controller, $bonus['per'], $attached);

        return [$bonus['perPower'] * $n, $bonus['perToughness'] * $n];
    }

    /**
     * `Whenever equipped (enchanted) creature attacks, …`: each Equipment and Aura on it sees the event, with the creature as context.
     *
     * @param int    $creature
     * @param string $event
     *
     * @return void
     */
    private function attachedTrigger(int $creature, string $event): void
    {
        foreach ($this->permanents() as $attached) {
            if ($attached->attachedTo === $creature) {
                $this->trigger($attached, $event, $attached->controller, null, $creature);
            }
        }
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
                $n = match (true) {
                    str_starts_with($m[3], 'attached:') => count(array_filter($this->attachments($object), fn (GameObject $o) => array_intersect(explode('|', substr($m[3], 9)), $o->definition()->subtypes) !== [])),
                    // `… for each card in your hand.` / `… for each creature card in your graveyard.`
                    $m[3] === 'hand' || str_starts_with($m[3], 'gy:') => $this->perCount($object->controller, $m[3], $object),
                    default => $this->countYours($object->controller, $m[3], $object),
                };
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
    /**
     * The count behind a `for each …` multiplier (see TextParser::sentenceEffects()).
     */
    private function perCount(int $seat, string $per, ?GameObject $source): int
    {
        return match (true) {
            $per === 'hand' => count($this->players[$seat]->hand),
            $per === 'opponents' => count($this->players) - 1,
            $per === 'attacking' => count($this->attackers),
            // `for each creature that died this turn`.
            $per === 'died' => $this->diedTurn === $this->turn ? $this->died : 0,
            $per === 'gy:card' => count($this->players[$seat]->graveyard),
            // `for each Aura attached to it`: the Auras (or Equipment) on the creature this is attached to.
            str_starts_with($per, 'attached:') => ($host = $this->objects[$source?->attachedTo ?? -1] ?? null) === null ? 0
                : count(array_filter($this->attachments($host), fn (GameObject $o) => array_intersect(explode('|', substr($per, 9)), $o->definition()->subtypes) !== [])),
            str_starts_with($per, 'gy:') => count(array_filter($this->players[$seat]->graveyard, fn (int $id) => $this->objects[$id]->definition()->is(ucfirst(substr($per, 3))))),
            default => $this->countYours($seat, substr($per, 6), $source),
        };
    }

    private function countYours(int $seat, string $kind, ?GameObject $source = null): int
    {
        return count(array_filter($this->permanents($seat), fn (GameObject $o) => match ($kind) {
            'artifact' => $o->definition()->is('Artifact'),
            'creature' => $this->isCreature($o),
            'other creature' => $o->id !== $source?->id && $this->isCreature($o),
            'countered creature' => $this->isCreature($o) && $o->counter('+1/+1') > 0,
            'land' => $o->definition()->isLand(),
            'enchantment' => $o->definition()->is('Enchantment'),
            // `for each Mountain you control` / `for each other Elf you control`.
            default => preg_match('/^(other )?sub:(\w+)$/', $kind, $m) && ($m[1] === '' || $o->id !== $source?->id)
                && (in_array($m[2], $o->definition()->subtypes, true) || ($this->isCreature($o) && in_array('changeling', $o->definition()->keywords, true))),
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
        $power = $this->levelBand($object)['power'] ?? $this->counted($object, 'power') ?? $this->prototype($object)[0] ?? $object->definition()->power ?? 0;
        foreach ($object->untilEndOfTurn as $effect) {
            // `has base power and toughness N/N until end of turn` replaces it; the newest one wins (rule 613.4b).
            $power = $effect['base'][0] ?? $power;
        }
        $power += $object->counter('+1/+1') - $object->counter('-1/-1');
        $power += $this->maxSpeedBonus($object)['power'] ?? 0;
        $power += $this->scaling($object)[0];
        foreach ($this->attachments($object) as $attached) {
            $power += $this->bonusOf($attached)['power'] + $this->attachedPer($attached)[0];
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
        $toughness = $this->levelBand($object)['toughness'] ?? $this->counted($object, 'toughness') ?? $this->prototype($object)[1] ?? $object->definition()->toughness ?? 0;
        foreach ($object->untilEndOfTurn as $effect) {
            // `has base power and toughness N/N until end of turn` replaces it; the newest one wins (rule 613.4b).
            $toughness = $effect['base'][1] ?? $toughness;
        }
        $toughness += $object->counter('+1/+1') - $object->counter('-1/-1');
        $toughness += $this->maxSpeedBonus($object)['toughness'] ?? 0;
        $toughness += $this->scaling($object)[1];
        foreach ($this->attachments($object) as $attached) {
            $toughness += $this->bonusOf($attached)['toughness'] + $this->attachedPer($attached)[1];
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
            if ($object->awakened || $object->alt === 'dash' || $object->alt === 'blitz' || ($object->alt === 'suspend' && $this->isCreature($object))) {
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
            if ($object->goadedBy !== null) {
                $keywords[] = 'attacks each combat if able';
            }
            foreach ($this->anthems($object) as $anthem) {
                array_push($keywords, ...$anthem['keywords']);
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * Soulbond (rule 702.95): as a creature enters, a soulbond creature and
     * another unpaired creature its controller controls pair up; its
     * controller always chooses to.
     *
     * @param GameObject $entered
     *
     * @return void
     */
    private function soulbond(GameObject $entered): void
    {
        if (! $this->isCreature($entered)) {
            return;
        }
        $unpaired = array_values(array_filter($this->permanents($entered->controller), fn (GameObject $o) => $o->id !== $entered->id && $this->isCreature($o) && $this->partner($o) === null));
        $bonded = in_array('soulbond', $entered->definition()->keywords, true)
            ? ($unpaired[0] ?? null)
            : (array_values(array_filter($unpaired, fn (GameObject $o) => in_array('soulbond', $o->definition()->keywords, true)))[0] ?? null);
        if ($bonded !== null) {
            $entered->pairedWith = $bonded->id;
            $bonded->pairedWith = $entered->id;
            $this->log("{$entered->name()} is paired with {$bonded->name()}.");
        }
    }

    /**
     * Soulbond: the creature it is paired with, while both stay creatures on the battlefield under the same control.
     *
     * @param GameObject $object
     *
     * @return GameObject|null
     */
    public function partner(GameObject $object): ?GameObject
    {
        $partner = $object->pairedWith === null ? null : ($this->objects[$object->pairedWith] ?? null);
        if ($partner === null || $partner->pairedWith !== $object->id || $partner->zone !== GameObject::BATTLEFIELD || $object->zone !== GameObject::BATTLEFIELD
            || $partner->controller !== $object->controller || ! $this->isCreature($partner) || ! $this->isCreature($object)) {
            return null;
        }

        return $partner;
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
            // "Creatures your opponents control get -1/-1."
            if ($source->controller !== $object->controller && ! $source->faceDown) {
                array_push($anthems, ...array_filter($source->definition()->anthem, fn (array $anthem) => ($anthem['opponents'] ?? false) && $this->gained($source, $anthem)));
            }
            // The Slivers: `All Sliver creatures get +1/+1.`, whoever controls them.
            if ($source->controller !== $object->controller && ! $source->faceDown) {
                array_push($anthems, ...array_filter($source->definition()->anthem, fn (array $anthem) => ($anthem['everyone'] ?? false) && $this->gained($source, $anthem)
                    && (in_array($anthem['subtype'], $object->definition()->subtypes, true) || in_array('changeling', $object->definition()->keywords, true))));
            }
            if ($source->controller !== $object->controller || $source->faceDown) {
                continue;
            }
            foreach ($source->definition()->anthem as $anthem) {
                if (($anthem['enchantedPlayer'] ?? false) || ($anthem['opponents'] ?? false) || ! $this->gained($source, $anthem) || (($anthem['withCounter'] ?? false) && ($anthem['withCounter'] === 'any' ? array_filter($object->counters) === [] : $object->counter('+1/+1') === 0))) {
                    continue;
                }
                if (($anthem['chosenType'] ?? false) && ! in_array($source->chosen, $object->definition()->subtypes, true) && ! in_array('changeling', $object->definition()->keywords, true)) {
                    continue;
                }
                // `CARDNAME gets +2/+2 as long as you control three or more artifacts.`
                if (($anthem['self'] ?? false) && ($source->id !== $object->id || ! $this->conditionMet($anthem['if'], $source, $source->controller))) {
                    continue;
                }
                // `Creatures you control get +1/+0 as long as it's your turn.`
                if (! ($anthem['self'] ?? false) && isset($anthem['if']) && ! $this->conditionMet($anthem['if'], $source, $source->controller)) {
                    continue;
                }
                if ((isset($anthem['color']) && ! in_array($anthem['color'], $object->definition()->colors, true)) || (($anthem['attacking'] ?? false) && ! isset($this->attackers[$object->id])) || (($anthem['artifact'] ?? false) && ! $object->definition()->is('Artifact'))
                    || (isset($anthem['token']) && $anthem['token'] !== $object->definition()->isToken())) {
                    continue;
                }
                // `Other creatures you control with flying get +1/+1.`: printed keywords, so anthems don't feed each other.
                if (isset($anthem['withKeyword']) && ! in_array($anthem['withKeyword'], $object->definition()->keywords, true)) {
                    continue;
                }
                // A lord: only creatures of its type.
                if (isset($anthem['subtype']) && ! in_array($anthem['subtype'], $object->definition()->subtypes, true) && ! in_array('changeling', $object->definition()->keywords, true)) {
                    continue;
                }
                // Soulbond: only it and the creature it is paired with.
                if (($anthem['paired'] ?? false) && ($object->id !== $source->id && $object->id !== $this->partner($source)?->id || $this->partner($source) === null)) {
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
            'schemaVersion' => self::STATE_VERSION,
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
            'pendingEntries' => $this->pendingEntries,
            'nextChoiceId' => $this->nextChoiceId,
            'exile' => $this->exile,
            'command' => $this->command,
            'commanders' => array_map(fn ($id, $casts) => [$id, $casts], array_keys($this->commanders), $this->commanders),
            'attackers' => array_keys($this->attackers),
            'blockers' => array_map(fn ($blocker, $attacker) => [$blocker, $attacker, ...$this->extraBlocks[$blocker] ?? []], array_keys($this->blockers), $this->blockers),
            'blocked' => array_keys($this->blocked),
            'struckFirst' => array_keys($this->struckFirst),
            'destroyAtEndOfCombat' => array_map(fn ($id, $incarnation) => [$id, $incarnation], array_keys($this->destroyAtEndOfCombat), $this->destroyAtEndOfCombat),
            'sacrificeAtEndOfCombat' => array_map(fn ($id, $incarnation) => [$id, $incarnation], array_keys($this->sacrificeAtEndOfCombat), $this->sacrificeAtEndOfCombat),
            'declared' => array_keys($this->declared),
            'autoPass' => $this->autoPass,
            'winner' => $this->winner,
            'log' => $this->log,
            'record' => $this->record,
            'nextId' => $this->nextId,
            'nextStackId' => $this->nextStackId,
            'shuffles' => $this->shuffles,
            'fogTurn' => $this->fogTurn,
            'unpreventableTurn' => $this->unpreventableTurn,
            'attackedWith' => $this->attackedWith,
            'spellsCast' => $this->spellsCast,
            'nonlandLeftTurn' => $this->nonlandLeftTurn,
            'warpedTurn' => $this->warpedTurn,
            'died' => $this->died,
            'diedTurn' => $this->diedTurn,
            'spellsTurn' => $this->spellsTurn,
            'spellsBefore' => $this->spellsBefore,
            'dayNight' => $this->dayNight,
            'extraTurns' => $this->extraTurns,
            'freerun' => $this->freerun,
            'prevent' => $this->prevent,
            'pacts' => $this->pacts,
            'delayedDraws' => $this->delayedDraws,
            'seenTapped' => $this->seenTapped,
            'seenCounters' => $this->seenCounters,
            'preventGain' => $this->preventGain,
        ];
    }

    public static function fromArray(array $data): self
    {
        $version = $data['schemaVersion'] ?? 1;
        if (! is_int($version) || $version < 1 || $version > self::STATE_VERSION) {
            throw new GameException('Unsupported saved game schema version.');
        }
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
            if ($version >= 6 && (! is_array($object) || ! array_key_exists('lastKnownStatistics', $object))) {
                throw new GameException('Missing saved source statistics.');
            }
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
            'kicks' => (int) ($item['kicks'] ?? 0),
            'flashback' => (bool) ($item['flashback'] ?? false),
            'faceDown' => (bool) ($item['faceDown'] ?? false),
            'fromHand' => (bool) ($item['fromHand'] ?? false),
            'bestowed' => (bool) ($item['bestowed'] ?? false),
            'alt' => (string) ($item['alt'] ?? ''),
            'sunburst' => (int) ($item['sunburst'] ?? 0),
            'spent' => (string) ($item['spent'] ?? ''),
            'spliced' => array_values((array) ($item['spliced'] ?? [])),
            'compleated' => (int) ($item['compleated'] ?? 0),
            'escaped' => (bool) ($item['escaped'] ?? false),
            'disturbed' => (bool) ($item['disturbed'] ?? false),
            'buyback' => (bool) ($item['buyback'] ?? false),
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
        $game->pendingEntries = array_values((array) ($data['pendingEntries'] ?? []));
        $game->nextChoiceId = (int) ($data['nextChoiceId'] ?? 1);
        if (($game->pendingChoice['type'] ?? null) === 'sacrifice') {
            $choice = $game->pendingChoice;
            $groups = $choice['groups'] ?? null;
            $index = $choice['group'] ?? null;
            $group = is_array($groups) && is_int($index) ? ($groups[$index] ?? null) : null;
            $resume = $choice['resume'] ?? null;
            if ($version < 5 || $game->stage !== self::PLAYING || ! is_array($group) || ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || $game->nextChoiceId <= $choice['id'] ||
                ! is_int($choice['count'] ?? null) || $choice['count'] < 1 ||
                ! array_key_exists('selected', $group) || $group['selected'] !== null || ($choice['seat'] ?? null) !== ($group['seat'] ?? null) ||
                ($choice['cards'] ?? null) !== ($group['cards'] ?? null) || ($choice['count'] ?? null) !== ($group['count'] ?? null) ||
                ($choice['min'] ?? null) !== $choice['count'] || ($choice['max'] ?? null) !== $choice['count'] ||
                ! is_array($resume) || ! is_array($resume['item'] ?? null) || ! is_array($resume['steps'] ?? null) || ! array_is_list($resume['steps']) || ! array_key_exists('self', $resume) ||
                ! is_int($resume['item']['object'] ?? null) || ! isset($game->objects[$resume['item']['object']]) ||
                ! is_int($resume['item']['incarnation'] ?? null) || ! is_int($resume['item']['controller'] ?? null) || ! isset($game->players[$resume['item']['controller']]) || ! is_int($resume['item']['x'] ?? null) ||
                ($resume['self'] !== null && (! is_array($resume['self']) || ! array_is_list($resume['self']) || count($resume['self']) !== 2 ||
                    ! is_int($resume['self'][0]) || ! isset($game->objects[$resume['self'][0]]) || ! is_int($resume['self'][1]))) || ! is_array($resume['followed'] ?? null)) {
                throw new GameException('Invalid saved sacrifice choice.');
            }
            foreach ($resume['steps'] as $step) {
                if (! is_array($step) || ! array_is_list($step) || count($step) !== 2 || ! is_array($step[0]) ||
                    ! is_string($step[0]['type'] ?? null) || ($step[1] !== null && ! is_string($step[1]))) {
                    throw new GameException('Invalid saved sacrifice continuation.');
                }
            }
            $game->validateEdictGroups($groups);
            foreach ($groups as $previous => $candidate) {
                if ($candidate['selected'] === null) {
                    if ($previous !== $index) {
                        throw new GameException('Invalid saved sacrifice choice order.');
                    }
                    break;
                }
            }
        }
        if (($game->pendingChoice['type'] ?? null) === 'fabricate') {
            $choice = $game->pendingChoice;
            $resume = $choice['resume'] ?? null;
            $source = is_int($choice['object'] ?? null) ? ($game->objects[$choice['object']] ?? null) : null;
            if ($version < 7 || $game->stage !== self::PLAYING || $source === null ||
                ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || ! is_int($data['nextChoiceId'] ?? null) || $game->nextChoiceId <= $choice['id'] ||
                ! is_int($choice['seat'] ?? null) || ! isset($game->players[$choice['seat']]) || ! is_int($choice['count'] ?? null) || $choice['count'] < 0 ||
                ! is_int($choice['incarnation'] ?? null) || $source->incarnation !== $choice['incarnation'] || $source->zone !== GameObject::BATTLEFIELD ||
                ($choice['options'] ?? null) !== ['counters', 'servos'] || ($choice['label'] ?? null) !== $source->name() ||
                ! is_array($resume) || ! is_array($resume['item'] ?? null) || ! is_array($resume['steps'] ?? null) || ! array_is_list($resume['steps']) ||
                ($resume['item']['object'] ?? null) !== $source->id || ($resume['item']['incarnation'] ?? null) !== $choice['incarnation'] ||
                ($resume['item']['controller'] ?? null) !== $choice['seat'] || ! is_int($resume['item']['x'] ?? null) || ! self::isAbility($resume['item']) ||
                ($resume['self'] ?? null) !== [$source->id, $choice['incarnation']] || ! is_array($resume['followed'] ?? null)) {
                throw new GameException('Invalid saved Fabricate choice.');
            }
            foreach ($resume['steps'] as $step) {
                if (! is_array($step) || ! array_is_list($step) || count($step) !== 2 || ! is_array($step[0]) ||
                    ! is_string($step[0]['type'] ?? null) || ($step[1] !== null && ! is_string($step[1]))) {
                    throw new GameException('Invalid saved Fabricate continuation.');
                }
            }
            foreach ($resume['followed'] as $from => $to) {
                if (! is_string($from) || ! is_string($to)) {
                    throw new GameException('Invalid saved Fabricate continuation.');
                }
            }
        }
        if (in_array($game->pendingChoice['type'] ?? null, ['optional_life_loss', 'optional_life_gain', 'optional_source_counter', 'optional_source_untap', 'optional_red_goblin'], true)) {
            $choice = $game->pendingChoice;
            $counter = $choice['type'] === 'optional_source_counter';
            $untap = $choice['type'] === 'optional_source_untap';
            $goblin = $choice['type'] === 'optional_red_goblin';
            $choiceKind = $goblin ? 'red-payment' : ($untap ? 'source-untap' : ($counter ? 'source-counter' : 'life'));
            $untargeted = $choice['type'] !== 'optional_life_loss';
            $resume = $choice['resume'] ?? null;
            $source = is_int($choice['object'] ?? null) ? ($game->objects[$choice['object']] ?? null) : null;
            if ($version < ($goblin ? 12 : ($untap ? 11 : ($counter ? 10 : ($untargeted ? 9 : 8)))) || $game->stage !== self::PLAYING || $source === null ||
                ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || ! is_int($data['nextChoiceId'] ?? null) || $game->nextChoiceId <= $choice['id'] ||
                ! is_int($choice['seat'] ?? null) || ! isset($game->players[$choice['seat']]) ||
                ! is_int($choice['incarnation'] ?? null) || $choice['incarnation'] < 0 ||
                ($untargeted ? array_key_exists('target', $choice) : (! is_string($choice['target'] ?? null) || ! in_array($choice['target'], array_map(fn (int $seat) => "p:{$seat}", array_diff(array_keys($game->players), [$choice['seat']])), true))) ||
                ($choice['options'] ?? null) !== ['accept', 'decline'] || ($choice['label'] ?? null) !== $source->name() ||
                ! is_array($resume) || ! is_array($resume['item'] ?? null) || ! is_array($resume['steps'] ?? null) || ! array_is_list($resume['steps']) ||
                ($resume['item']['object'] ?? null) !== $source->id || ($resume['item']['incarnation'] ?? null) !== $choice['incarnation'] ||
                ($resume['item']['controller'] ?? null) !== $choice['seat'] || ! is_int($resume['item']['x'] ?? null) || ! self::isAbility($resume['item']) ||
                ($resume['item']['targets'] ?? null) !== ($untargeted ? [] : [$choice['target']]) ||
                ! array_key_exists('self', $resume) || ($resume['self'] !== null && $resume['self'] !== [$source->id, $choice['incarnation']]) || ! is_array($resume['followed'] ?? null)) {
                throw new GameException('Invalid saved optional '.$choiceKind.' choice.');
            }
            foreach ($resume['steps'] as $step) {
                if (! is_array($step) || ! array_is_list($step) || count($step) !== 2 || ! is_array($step[0]) ||
                    ! is_string($step[0]['type'] ?? null) || ($step[1] !== null && ! is_string($step[1]))) {
                    throw new GameException('Invalid saved optional '.$choiceKind.' continuation.');
                }
            }
            foreach ($resume['followed'] as $from => $to) {
                if (! is_string($from) || ! is_string($to)) {
                    throw new GameException('Invalid saved optional '.$choiceKind.' continuation.');
                }
            }
        }
        if (($game->pendingChoice['type'] ?? null) === 'entry_life') {
            $choice = $game->pendingChoice;
            $entry = $game->pendingEntries[0] ?? null;
            if ($version < 2 || $entry === null || ! isset($game->objects[$entry['object'] ?? -1]) ||
                ($choice['object'] ?? null) !== $entry['object'] || ($choice['seat'] ?? null) !== ($entry['controller'] ?? null) ||
                ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || $game->nextChoiceId <= $choice['id']) {
                throw new GameException('Invalid saved entry choice.');
            }
        }
        $game->exile = array_map('intval', (array) $data['exile']);
        $game->command = array_map('intval', (array) ($data['command'] ?? []));
        foreach ((array) ($data['commanders'] ?? []) as [$id, $casts]) {
            $game->commanders[(int) $id] = (int) $casts;
        }
        $game->attackers = array_fill_keys(array_map('intval', (array) ($data['attackers'] ?? [])), true);
        foreach ((array) ($data['blockers'] ?? []) as $row) {
            [$blocker, $attacker] = $row;
            $game->blockers[(int) $blocker] = (int) $attacker;
            if (count($row) > 2) {
                $game->extraBlocks[(int) $blocker] = array_map('intval', array_slice($row, 2));
            }
        }
        $game->blocked = array_fill_keys(array_map('intval', (array) ($data['blocked'] ?? [])), true);
        $game->struckFirst = array_fill_keys(array_map('intval', (array) ($data['struckFirst'] ?? [])), true);
        foreach ((array) ($data['destroyAtEndOfCombat'] ?? []) as [$id, $incarnation]) {
            $game->destroyAtEndOfCombat[(int) $id] = (int) $incarnation;
        }
        foreach ((array) ($data['sacrificeAtEndOfCombat'] ?? []) as [$id, $incarnation]) {
            $game->sacrificeAtEndOfCombat[(int) $id] = (int) $incarnation;
        }
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
        $game->unpreventableTurn = (int) ($data['unpreventableTurn'] ?? 0);
        $game->attackedWith = (int) ($data['attackedWith'] ?? 0);
        $game->spellsCast = (int) ($data['spellsCast'] ?? 0);
        $game->nonlandLeftTurn = (int) ($data['nonlandLeftTurn'] ?? 0);
        $game->warpedTurn = (int) ($data['warpedTurn'] ?? 0);
        $game->died = (int) ($data['died'] ?? 0);
        $game->diedTurn = (int) ($data['diedTurn'] ?? 0);
        $game->spellsTurn = (int) ($data['spellsTurn'] ?? 0);
        $game->spellsBefore = (int) ($data['spellsBefore'] ?? 0);
        $game->dayNight = isset($data['dayNight']) ? (string) $data['dayNight'] : null;
        $game->extraTurns = array_map('intval', (array) ($data['extraTurns'] ?? []));
        $game->freerun = array_map('intval', (array) ($data['freerun'] ?? []));
        $game->prevent = array_map('intval', (array) ($data['prevent'] ?? []));
        $game->pacts = array_values((array) ($data['pacts'] ?? []));
        // Version 3 makes pending delayed draws mandatory so missing state cannot silently lose them.
        $delayedDraws = $data['delayedDraws'] ?? ($version < 3 ? [] : null);
        if (! is_array($delayedDraws) || ! array_is_list($delayedDraws) || ($version < 3 && $delayedDraws !== [])) {
            throw new GameException('Invalid saved delayed draws.');
        }
        foreach ($delayedDraws as $draw) {
            if (! is_array($draw) || ! is_int($draw['createdTurn'] ?? null) || $draw['createdTurn'] < 1 || $draw['createdTurn'] > $game->turn
                || ! is_int($draw['source'] ?? null) || ! isset($game->objects[$draw['source']])
                || ! is_int($draw['incarnation'] ?? null) || $draw['incarnation'] < 0 || $draw['incarnation'] > $game->objects[$draw['source']]->incarnation
                || ! is_int($draw['controller'] ?? null) || ! isset($game->players[$draw['controller']])
                || ! is_string($draw['label'] ?? null) || $draw['label'] === '') {
                throw new GameException('Invalid saved delayed draw.');
            }
            $game->delayedDraws[] = [
                'createdTurn' => $draw['createdTurn'],
                'source' => $draw['source'],
                'incarnation' => $draw['incarnation'],
                'controller' => $draw['controller'],
                'label' => $draw['label'],
            ];
        }
        $game->seenTapped = array_map('intval', (array) ($data['seenTapped'] ?? []));
        // Saved before counters were tracked: the counters already there are not new.
        $game->seenCounters = array_map('intval', (array) ($data['seenCounters'] ?? []));
        foreach (isset($data['seenCounters']) ? [] : $game->permanents() as $object) {
            if ($object->counter('+1/+1') > 0) {
                $game->seenCounters[$object->id] = $object->counter('+1/+1');
            }
        }
        $game->preventGain = array_map('intval', (array) ($data['preventGain'] ?? []));

        return $game;
    }
}
