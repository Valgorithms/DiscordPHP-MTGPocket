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

namespace MTGPocket\Drafts;

use MTGPocket\Cards\BasicLands;
use MTGPocket\Cards\CardPool;
use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Matches\MatchService;
use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Player;
use MTGPocket\Packs\PackGenerator;
use MTGPocket\Repository\CardPoolRepository;
use MTGPocket\Repository\DraftRepository;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\PlayerRepository;
use Random\Randomizer;

/**
 * Booster drafts, from sign-up to the last round.
 *
 * 1. **Sign-up.** A player makes a pod for one set and pays the entry fee
 *    in points; others join and pay too. The draft starts when the pod is
 *    full, when its host starts it, or when sign-up time runs out with
 *    enough players. A pod that never gets enough players is called off
 *    and everyone gets their points back.
 * 2. **Drafting.** Each player opens a pack of one color of the set (the
 *    pod's packs spread over its colors), takes a card and passes the rest
 *    on: left, then right, then left. Picks are not in lockstep: a player
 *    takes from the first pack waiting for them whenever they like. A
 *    player who waits too long, or has left, has a card taken for them.
 * 3. **Building.** Players build a deck from their picks and free basic
 *    lands. When everyone is ready, or time runs out, the rounds start;
 *    anyone without a legal deck plays one the bot builds.
 * 4. **Playing.** Swiss rounds, one game per pairing, as many rounds as
 *    it takes to find one undefeated player. A game not finished when its
 *    round runs out of time is a draw.
 *
 * Every player keeps the cards they drafted: they go to their collection
 * once their matches are done (when the event ends, or when they leave
 * it after the draft), or when the whole event times out.
 *
 * Everything that happens on its own (auto-picks, the next stage, timeouts)
 * happens the next time anyone touches the draft or {@see tickAll()} runs;
 * the bot runs it every few seconds.
 *
 * @since 0.5.0
 */
final class DraftService
{
    /**
     * Pick order when the bot picks: rarer first.
     *
     * @var array<string, int>
     */
    private const array RARITY_RANK = ['common' => 0, 'uncommon' => 1, 'rare' => 2, 'mythic' => 3];

    /** Spells an auto-built deck aims for; basic lands make up the rest. */
    public const int AUTO_SPELLS = 23;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @var \Closure(): string */
    private \Closure $random;

    private Randomizer $shuffler;

    /**
     * @param DraftRepository           $drafts
     * @param PlayerRepository          $players     For the entry fee.
     * @param InventoryRepository       $inventories Where drafted cards go.
     * @param CardPoolRepository        $pools
     * @param DeckBuilder               $decks       For card data.
     * @param MatchService              $matches     Where the games are played.
     * @param PackGenerator             $generator
     * @param DraftRules                $rules
     * @param (\Closure(): int)|null    $clock       The current Unix time; defaults to {@see time()}.
     * @param (\Closure(): string)|null $random      Random hex for draft ids; defaults to {@see random_bytes()}.
     * @param Randomizer|null           $shuffler    Deals pack colors; a seeded one for tests.
     */
    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly PlayerRepository $players,
        private readonly InventoryRepository $inventories,
        private readonly CardPoolRepository $pools,
        private readonly DeckBuilder $decks,
        private readonly MatchService $matches,
        private readonly PackGenerator $generator,
        public readonly DraftRules $rules,
        ?\Closure $clock = null,
        ?\Closure $random = null,
        ?Randomizer $shuffler = null,
    ) {
        $this->clock = $clock ?? fn () => time();
        $this->random = $random ?? fn () => bin2hex(random_bytes(16));
        $this->shuffler = $shuffler ?? new Randomizer();
        $this->matches->onFinished(function (MatchRecord $match): void {
            if ($match->event !== null) {
                $this->tick($match->event);
            }
        });
    }

    // ----------------------------------------------------------------------
    // Sign-up
    // ----------------------------------------------------------------------

    /**
     * Makes a pod for a set, with its maker in the first seat.
     *
     * @param string      $playerId
     * @param string      $playerName
     * @param string      $setCode
     * @param string|null $channelId Where to announce what happens in it.
     * @param int|null    $size      Players in a full pod; defaults to {@see DraftRules::$podSize}.
     *
     * @return Draft
     */
    public function create(string $playerId, string $playerName, string $setCode, ?string $channelId = null, ?int $size = null): Draft
    {
        $this->checkFree($playerId);
        $pool = $this->pools->find(strtoupper(trim($setCode))) ?? throw new \OutOfBoundsException("There is no set **{$setCode}**. See `/pack list`.");
        if ($this->packColors($pool) === []) {
            throw new \InvalidArgumentException("{$pool->setName} has no packs to draft: none of its colors has a rare.");
        }
        $size ??= $this->rules->podSize;
        if ($size < $this->rules->minPlayers || $size > $this->rules->podSize) {
            throw new \InvalidArgumentException("A pod holds {$this->rules->minPlayers} to {$this->rules->podSize} players.");
        }

        $this->charge($playerId, $playerName, $this->rules->entryFee);
        $now = ($this->clock)();
        $draft = new Draft(
            substr(($this->random)(), 0, 12),
            Draft::SIGNUP,
            $pool->setCode,
            $pool->setName,
            $playerId,
            $this->rules->entryFee,
            $size,
            $this->rules->packs,
            $channelId,
            [new DraftSeat($playerId, $playerName, $this->rules->entryFee)],
            createdAt: $now,
            deadline: $now + $this->rules->signupHours * 3600,
        );
        $this->drafts->save($draft);
        $this->drafts->setEntry($playerId, $draft->id);

        return $draft;
    }

    /**
     * Joins a pod and pays its fee. A pod that fills starts drafting.
     *
     * @param string      $playerId
     * @param string      $playerName
     * @param string|null $draftId    Defaults to the only open pod.
     *
     * @return Draft
     */
    public function join(string $playerId, string $playerName, ?string $draftId = null): Draft
    {
        $this->checkFree($playerId);
        if ($draftId === null || $draftId === '') {
            $open = $this->open();
            if (count($open) !== 1) {
                throw new \InvalidArgumentException($open === [] ? 'No pod is open. Make one with `/draft create`.' : 'Several pods are open; pick one with `draft`.');
            }
            $draftId = $open[0]->id;
        }
        $draft = $this->drafts->find($draftId) ?? throw new \OutOfBoundsException('There is no such draft.');
        $this->checkOpen($draft);

        $this->charge($playerId, $playerName, $draft->fee);
        try {
            $draft = $this->drafts->modify($draft->id, function (Draft $draft) use ($playerId, $playerName): void {
                $this->advance($draft);
                $this->checkOpen($draft);
                if ($draft->seatOf($playerId) !== null) {
                    throw new \InvalidArgumentException('You are already in this pod.');
                }
                $draft->seats[] = new DraftSeat($playerId, $playerName, $draft->fee);
                $this->advance($draft);
            });
        } catch (\Throwable $e) {
            $this->pay($playerId, $draft->fee);

            throw $e;
        }
        $this->drafts->setEntry($playerId, $draft->id);

        return $draft;
    }

    /**
     * Starts drafting with who has joined, for the pod's host.
     *
     * @param string $playerId
     *
     * @return Draft
     */
    public function start(string $playerId): Draft
    {
        $draft = $this->current($playerId) ?? throw new \InvalidArgumentException('You are not in a draft.');

        return $this->drafts->modify($draft->id, function (Draft $draft) use ($playerId): void {
            $this->advance($draft);
            if ($draft->status !== Draft::SIGNUP) {
                throw new \InvalidArgumentException('This draft has already started.');
            }
            if ($draft->hostId !== $playerId) {
                throw new \InvalidArgumentException('Only the player who made the pod can start it early.');
            }
            if (count($draft->seats) < $this->rules->minPlayers) {
                throw new \InvalidArgumentException("A draft needs at least {$this->rules->minPlayers} players; this pod has ".count($draft->seats).'.');
            }
            $this->beginDrafting($draft);
            $this->advance($draft);
        });
    }

    /**
     * Leaves a draft. Before it starts, the fee is refunded. After, the
     * player drops: the bot picks for them until the packs are empty, they
     * play no more games (a game in progress is conceded), and their picks
     * go to their collection.
     *
     * @param string $playerId
     *
     * @return Draft
     */
    public function leave(string $playerId): Draft
    {
        $draft = $this->current($playerId) ?? throw new \InvalidArgumentException('You are not in a draft.');
        // Concede first, outside the draft's lock: the game's end reports
        // back to the draft.
        $index = $draft->pairingOf($playerId);
        $match = $index === null ? null : $draft->currentRound()[$index]['match'];
        if ($draft->status === Draft::PLAYING && $match !== null && $this->matches->find($match)?->isLive()) {
            try {
                $this->matches->act($match, $playerId, fn ($game, int $seat) => $game->concede($seat));
            } catch (\InvalidArgumentException) {
            }
        }

        $refund = 0;
        $draft = $this->drafts->modify($draft->id, function (Draft $draft) use ($playerId, &$refund): void {
            $this->advance($draft);
            $seat = $draft->seatOf($playerId) ?? throw new \InvalidArgumentException('You are not in this draft.');
            if (! $draft->isLive() || $draft->seats[$seat]->dropped) {
                throw new \InvalidArgumentException('You have already left this draft.');
            }
            if ($draft->status === Draft::SIGNUP) {
                $refund = $draft->seats[$seat]->paid;
                array_splice($draft->seats, $seat, 1);
                if ($draft->seats === []) {
                    $draft->status = Draft::CANCELLED;
                } elseif ($draft->hostId === $playerId) {
                    $draft->hostId = $draft->seats[0]->id;
                }

                return;
            }

            $entry = $draft->seats[$seat];
            $entry->dropped = true;
            $draft->news[] = "**{$entry->name}** left the draft.";
            if ($draft->status === Draft::PLAYING && ($index = $draft->pairingOf($playerId)) !== null) {
                $pairing = &$draft->rounds[array_key_last($draft->rounds)][$index];
                if ($pairing['result'] === null) {
                    $pairing['result'] = $pairing['a'] === $playerId ? ($pairing['b'] === null ? 'draw' : 'b') : 'a';
                }
                unset($pairing);
            }
            if ($draft->status !== Draft::DRAFTING) {
                $this->collect($entry);
            }
            $this->advance($draft);
        });
        if ($refund > 0) {
            $this->pay($playerId, $refund);
        }
        if ($draft->status === Draft::SIGNUP || $draft->status === Draft::CANCELLED) {
            $this->drafts->setEntry($playerId, null);
        }

        return $draft;
    }

    // ----------------------------------------------------------------------
    // Drafting
    // ----------------------------------------------------------------------

    /**
     * Takes a card from the first pack waiting for the player and passes
     * the rest on.
     *
     * @param string   $playerId
     * @param string   $uuid
     * @param int|null $pickNumber How many cards they had taken when they chose, so a stale click is refused.
     *
     * @return Draft
     */
    public function pick(string $playerId, string $uuid, ?int $pickNumber = null): Draft
    {
        $draft = $this->current($playerId) ?? throw new \InvalidArgumentException('You are not in a draft.');

        return $this->drafts->modify($draft->id, function (Draft $draft) use ($playerId, $uuid, $pickNumber): void {
            $this->advance($draft);
            if ($draft->status !== Draft::DRAFTING) {
                throw new \InvalidArgumentException('Cards are not being picked in this draft right now.');
            }
            $seat = $draft->seatOf($playerId) ?? throw new \InvalidArgumentException('You are not in this draft.');
            if ($draft->seats[$seat]->dropped) {
                throw new \InvalidArgumentException('You have left this draft.');
            }
            if ($pickNumber !== null && $pickNumber !== count($draft->seats[$seat]->pickLog)) {
                throw new \InvalidArgumentException('That pack has already moved on; here is the one in front of you now.');
            }
            $pack = $draft->packFor($seat) ?? throw new \InvalidArgumentException('No pack is waiting for you yet; one comes as soon as your neighbor picks.');
            if (! in_array($uuid, $pack, true)) {
                throw new \InvalidArgumentException('That card is not in your pack.');
            }
            $this->take($draft, $seat, $uuid);
            $this->advance($draft);
        });
    }

    /**
     * The cards in the first pack waiting for a player.
     *
     * @param Draft  $draft
     * @param string $playerId
     *
     * @return array[]|null Card data; null when no pack is waiting.
     */
    public function packCards(Draft $draft, string $playerId): ?array
    {
        $seat = $draft->seatOf($playerId);
        $pack = $seat === null ? null : $draft->packFor($seat);

        return $pack === null ? null : array_map($this->decks->cardData(...), $pack);
    }

    /**
     * When the bot picks for a player who has not.
     *
     * @param Draft  $draft
     * @param string $playerId
     *
     * @return int|null Unix time; null when no pack is waiting.
     */
    public function pickDeadline(Draft $draft, string $playerId): ?int
    {
        $seat = $draft->seatOf($playerId);
        if ($seat === null || $draft->packFor($seat) === null) {
            return null;
        }

        return ($draft->waitingSince[$seat] ?? ($this->clock)()) + $this->rules->pickSeconds;
    }

    // ----------------------------------------------------------------------
    // Deck building
    // ----------------------------------------------------------------------

    /**
     * Puts drafted cards or basic lands in the player's deck.
     *
     * @param string $playerId
     * @param string $card     A uuid, basic land or name.
     * @param int    $count
     *
     * @return array{0: Draft, 1: string} The draft and what changed.
     */
    public function addToDeck(string $playerId, string $card, int $count = 1): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Add at least one card.');
        }
        $note = '';
        $draft = $this->editDeck($playerId, function (DraftSeat $seat) use ($card, $count, &$note): void {
            if ($basic = BasicLands::key($card)) {
                $seat->deck->add($basic, $count);
                $note = "Added {$count} ".substr($basic, strlen(BasicLands::PREFIX)).'.';

                return;
            }
            $best = null;
            $spare = 0;
            foreach ($seat->picks as $uuid => $owned) {
                $uuid = (string) $uuid;
                $left = $owned - $seat->deck->get($uuid);
                if (($uuid === trim($card) || strcasecmp($this->decks->cardData($uuid)['name'], trim($card)) === 0) && $left > $spare) {
                    [$best, $spare] = [$uuid, $left];
                }
            }
            if ($best === null) {
                throw new \InvalidArgumentException("You have no **{$card}** left to add; see `/draft pool`.");
            }
            $added = min($count, $spare);
            $seat->deck->add($best, $added);
            $note = "Added {$added} ".$this->decks->cardData($best)['name'].'.'.($added < $count ? " You only drafted {$added} more." : '');
        });

        return [$draft, $note];
    }

    /**
     * Takes cards out of the player's deck; they stay in their side deck.
     *
     * @param string   $playerId
     * @param string   $card
     * @param int|null $count    Null for every copy.
     *
     * @return array{0: Draft, 1: string}
     */
    public function removeFromDeck(string $playerId, string $card, ?int $count = null): array
    {
        $note = '';
        $draft = $this->editDeck($playerId, function (DraftSeat $seat) use ($card, $count, &$note): void {
            $basic = BasicLands::key($card);
            foreach ($seat->deck as $key => $copies) {
                $key = (string) $key;
                if ($key === trim($card) || $key === $basic || strcasecmp($this->decks->cardData($key)['name'], trim($card)) === 0) {
                    $removed = min($copies, $count ?? $copies);
                    $seat->deck->remove($key, $removed);
                    $note = "Took out {$removed} ".$this->decks->cardData($key)['name'].'.';

                    return;
                }
            }

            throw new \InvalidArgumentException("**{$card}** is not in your deck.");
        });

        return [$draft, $note];
    }

    /**
     * Sets how many of each basic land the player's deck has.
     *
     * @param string             $playerId
     * @param array<string, int> $lands    Basic land name => copies; lands not named keep their count.
     *
     * @return Draft
     */
    public function setLands(string $playerId, array $lands): Draft
    {
        return $this->editDeck($playerId, function (DraftSeat $seat) use ($lands): void {
            foreach ($lands as $name => $count) {
                $key = BasicLands::key((string) $name) ?? throw new \InvalidArgumentException("{$name} is not a basic land.");
                if ($count < 0 || $count > 60) {
                    throw new \InvalidArgumentException('Pick between 0 and 60 of each basic land.');
                }
                if ($seat->deck->get($key) > 0) {
                    $seat->deck->remove($key, $seat->deck->get($key));
                }
                $seat->deck->add($key, $count);
            }
        });
    }

    /**
     * Has the bot build the player's deck from their picks: their two
     * strongest colors, the rarest and cheapest spells first, and basic
     * lands to match.
     *
     * @param string $playerId
     *
     * @return Draft
     */
    public function autoBuild(string $playerId): Draft
    {
        return $this->editDeck($playerId, fn (DraftSeat $seat) => $this->buildFor($seat));
    }

    /**
     * Sends in the player's deck. Rounds start once everyone is ready.
     *
     * @param string $playerId
     *
     * @return Draft
     */
    public function ready(string $playerId): Draft
    {
        return $this->editDeck($playerId, function (DraftSeat $seat): void {
            if ($seat->deck->total() < $this->rules->deckMin) {
                throw new \InvalidArgumentException("Your deck has {$seat->deck->total()} cards; it needs at least {$this->rules->deckMin}. Add basic lands with `/draft lands`, or let the bot build one with `/draft auto`.");
            }
            $seat->ready = true;
        });
    }

    /**
     * The problems with a deck, for showing it.
     *
     * @param DraftSeat $seat
     *
     * @return string[]
     */
    public function deckProblems(DraftSeat $seat): array
    {
        return $seat->deck->total() < $this->rules->deckMin ? ["It has {$seat->deck->total()} cards; draft decks need at least {$this->rules->deckMin}."] : [];
    }

    /**
     * Changes a player's deck, while they are building or between games.
     *
     * @param string                   $playerId
     * @param callable(DraftSeat): void $change
     *
     * @return Draft
     */
    private function editDeck(string $playerId, callable $change): Draft
    {
        $draft = $this->current($playerId) ?? throw new \InvalidArgumentException('You are not in a draft.');

        return $this->drafts->modify($draft->id, function (Draft $draft) use ($playerId, $change): void {
            $this->advance($draft);
            if ($draft->status !== Draft::BUILDING && $draft->status !== Draft::PLAYING) {
                throw new \InvalidArgumentException($draft->status === Draft::DRAFTING ? 'Decks are built once every pack is empty.' : 'This draft is not building decks.');
            }
            $seat = $draft->seat($playerId);
            if ($seat->dropped) {
                throw new \InvalidArgumentException('You have left this draft.');
            }
            $change($seat);
            $this->advance($draft);
        });
    }

    // ----------------------------------------------------------------------
    // Playing
    // ----------------------------------------------------------------------

    /**
     * Starts the player's game this round, if it is waiting on someone who
     * was busy in another match.
     *
     * @param string $playerId
     *
     * @return MatchRecord The game.
     */
    public function play(string $playerId): MatchRecord
    {
        $draft = $this->current($playerId) ?? throw new \InvalidArgumentException('You are not in a draft.');
        $match = null;
        $this->drafts->modify($draft->id, function (Draft $draft) use ($playerId, &$match): void {
            $this->advance($draft);
            if ($draft->status !== Draft::PLAYING) {
                throw new \InvalidArgumentException('No round is being played in this draft right now.');
            }
            $index = $draft->pairingOf($playerId) ?? throw new \InvalidArgumentException('You have no game this round.');
            $pairing = $draft->currentRound()[$index];
            if ($pairing['b'] === null) {
                throw new \InvalidArgumentException('You have a bye this round: a free win.');
            }
            if ($pairing['result'] !== null) {
                throw new \InvalidArgumentException('Your game this round is over; the next round starts when every game is.');
            }
            if ($pairing['match'] === null) {
                $this->startGame($draft, $index, true);
            }
            $match = $this->matches->find((string) $draft->currentRound()[$index]['match']);
        });

        return $match ?? throw new \OutOfBoundsException('That game no longer exists.');
    }

    // ----------------------------------------------------------------------
    // Looking up
    // ----------------------------------------------------------------------

    /**
     * The live draft a player is in and has not left.
     *
     * @param string $playerId
     *
     * @return Draft|null
     */
    public function current(string $playerId): ?Draft
    {
        $draft = $this->last($playerId);

        return $draft !== null && $draft->isLive() && $draft->seatOf($playerId) !== null && ! $draft->seat($playerId)->dropped ? $draft : null;
    }

    /**
     * The draft a player last joined, live or not, brought up to date.
     *
     * @param string $playerId
     *
     * @return Draft|null
     */
    public function last(string $playerId): ?Draft
    {
        $id = $this->drafts->entry($playerId);

        return $id === null ? null : $this->tick($id);
    }

    public function find(string $draftId): ?Draft
    {
        return $this->tick($draftId);
    }

    /**
     * Pods still taking players, oldest first.
     *
     * @return Draft[]
     */
    public function open(): array
    {
        $open = array_values(array_filter($this->tickAll(), fn (Draft $draft) => $draft->status === Draft::SIGNUP));
        usort($open, fn (Draft $a, Draft $b) => $a->createdAt <=> $b->createdAt);

        return $open;
    }

    /**
     * Brings a draft up to date: picks for players who ran out of time,
     * moves to the next stage, records finished games and ends it when
     * its time is up.
     *
     * @param string $draftId
     *
     * @return Draft|null
     */
    public function tick(string $draftId): ?Draft
    {
        $draft = $this->drafts->find($draftId);
        if ($draft === null || ! $draft->isLive()) {
            return $draft;
        }

        return $this->drafts->modify($draftId, fn (Draft $draft) => $this->advance($draft));
    }

    /**
     * Brings every live draft up to date.
     *
     * @return Draft[] The live drafts.
     */
    public function tickAll(): array
    {
        $live = [];
        foreach ($this->drafts->ids() as $id) {
            $draft = $this->drafts->find($id);
            if ($draft !== null && $draft->isLive() && ($draft = $this->tick($id)) !== null && $draft->isLive()) {
                $live[] = $draft;
            }
        }

        return $live;
    }

    /**
     * Takes the announcements of every draft that has some, for posting
     * in their channels.
     *
     * @return list<array{draft: Draft, news: list<string>}>
     */
    public function takeNews(): array
    {
        $taken = [];
        foreach ($this->drafts->ids() as $id) {
            if (($this->drafts->find($id)?->news ?? []) === []) {
                continue;
            }
            $news = [];
            $draft = $this->drafts->modify($id, function (Draft $draft) use (&$news): void {
                $news = $draft->news;
                $draft->news = [];
            });
            if ($news !== []) {
                $taken[] = ['draft' => $draft, 'news' => $news];
            }
        }

        return $taken;
    }

    // ----------------------------------------------------------------------
    // Under the draft's lock
    // ----------------------------------------------------------------------

    /**
     * Moves a draft along as far as it can go now.
     *
     * @param Draft $draft
     *
     * @return void
     */
    private function advance(Draft $draft): void
    {
        $now = ($this->clock)();
        for ($steps = 0; $steps < 1000 && $draft->isLive(); $steps++) {
            $status = $draft->status;
            if ($draft->endsAt > 0 && $now >= $draft->endsAt) {
                $this->finish($draft, 'The event has run out of time.');

                return;
            }
            match ($status) {
                Draft::SIGNUP => $this->advanceSignup($draft, $now),
                Draft::DRAFTING => $this->advanceDrafting($draft, $now),
                Draft::BUILDING => $this->advanceBuilding($draft, $now),
                Draft::PLAYING => $this->advancePlaying($draft, $now),
                default => null,
            };
            if ($draft->status === $status) {
                return;
            }
        }
    }

    private function advanceSignup(Draft $draft, int $now): void
    {
        if (count($draft->seats) >= $draft->size) {
            $this->beginDrafting($draft);
        } elseif ($now >= $draft->deadline) {
            if (count($draft->seats) >= $this->rules->minPlayers) {
                $this->beginDrafting($draft);
            } else {
                $draft->status = Draft::CANCELLED;
                foreach ($draft->seats as $seat) {
                    $this->pay($seat->id, $seat->paid);
                    $seat->paid = 0;
                    if ($this->drafts->entry($seat->id) === $draft->id) {
                        $this->drafts->setEntry($seat->id, null);
                    }
                }
                $draft->news[] = "Not enough players joined the {$draft->setName} draft, so it is off. Entry fees have been refunded.";
            }
        }
    }

    private function beginDrafting(Draft $draft): void
    {
        $now = ($this->clock)();
        $draft->status = Draft::DRAFTING;
        $draft->deadline = 0;
        $draft->endsAt = $now + $this->rules->eventDays * 86400;
        $draft->news[] = sprintf(
            "The %s draft has started with %d players: %s. Open your pack with `/draft pack`; it ends <t:%d:R>.",
            $draft->setName,
            count($draft->seats),
            implode(', ', array_map(fn (DraftSeat $seat) => "<@{$seat->id}>", $draft->seats)),
            $draft->endsAt,
        );
        $this->openPacks($draft);
    }

    /**
     * Gives every seat a new pack, each of one color of the set, the colors
     * dealt round the table so neighbors open different ones.
     *
     * @param Draft $draft
     *
     * @return void
     */
    private function openPacks(Draft $draft): void
    {
        $pool = $this->pools->find($draft->setCode) ?? throw new \OutOfBoundsException("The {$draft->setName} cards are no longer imported.");
        $colors = $this->packColors($pool);
        $colors = $this->shuffler->shuffleArray($colors);
        $now = ($this->clock)();
        $draft->packNumber++;
        $draft->queues = [];
        $draft->waitingSince = [];
        foreach (array_keys($draft->seats) as $seat) {
            $pack = $this->generator->generate($pool, $colors[$seat % count($colors)]);
            $draft->queues[$seat] = [array_column($pack->cards, 'uuid')];
            $draft->waitingSince[$seat] = $now;
        }
    }

    private function advanceDrafting(Draft $draft, int $now): void
    {
        // A pick passes a pack on, which may give the next seat a pick to
        // make at once; keep going until nobody is owed one.
        do {
            $picked = false;
            foreach ($draft->seats as $seat => $entry) {
                if ($draft->packFor($seat) !== null && ($entry->dropped || $now - ($draft->waitingSince[$seat] ?? $now) >= $this->rules->pickSeconds)) {
                    $this->take($draft, $seat, $this->autoPick($entry, $draft->packFor($seat)));
                    $picked = true;
                }
            }
        } while ($picked);

        foreach ($draft->queues as $packs) {
            if ($packs !== []) {
                return;
            }
        }
        if ($draft->packNumber < $draft->packs) {
            $this->openPacks($draft);

            return;
        }

        $draft->status = Draft::BUILDING;
        $draft->queues = [];
        $draft->waitingSince = [];
        $draft->deadline = $now + $this->rules->buildMinutes * 60;
        foreach ($draft->seats as $seat) {
            if ($seat->dropped) {
                $this->collect($seat);
            }
        }
        $draft->news[] = "Every pack in the {$draft->setName} draft is empty. Build a deck of at least {$this->rules->deckMin} cards from your picks (`/draft pool`, `/draft add`, `/draft lands`, or `/draft auto`), then `/draft ready`. Rounds start <t:{$draft->deadline}:R> at the latest.";
    }

    private function advanceBuilding(Draft $draft, int $now): void
    {
        $active = $draft->active();
        foreach ($active as $seat) {
            if (! $seat->ready && $now < $draft->deadline) {
                return;
            }
        }
        foreach ($active as $seat) {
            if ($seat->deck->total() < $this->rules->deckMin) {
                $this->buildFor($seat);
            }
            $seat->ready = true;
        }
        if (count($active) < 2) {
            $this->finish($draft, 'Too few players are left to play.');

            return;
        }
        $draft->status = Draft::PLAYING;
        $draft->totalRounds = (int) ceil(log(count($active), 2));
        $this->startRound($draft);
    }

    private function advancePlaying(Draft $draft, int $now): void
    {
        $round = array_key_last($draft->rounds);
        foreach ($draft->rounds[$round] as $index => &$pairing) {
            if ($pairing['result'] !== null) {
                continue;
            }
            if ($pairing['match'] !== null) {
                $match = $this->matches->find($pairing['match']);
                if ($match === null) {
                    $pairing['result'] = 'draw';
                } elseif (! $match->isLive()) {
                    $winner = $match->game?->winner === null ? null : ($match->players[$match->game->winner]['id'] ?? null);
                    $pairing['result'] = $winner === $pairing['a'] ? 'a' : ($winner === $pairing['b'] ? 'b' : 'draw');
                    $draft->news[] = self::resultLine($draft, $pairing);
                }
            }
            if ($pairing['result'] === null && $now >= $draft->deadline) {
                if ($pairing['match'] !== null) {
                    $this->matches->stop($pairing['match'], 'The draft round is out of time.');
                }
                $pairing['result'] = 'draw';
                $draft->news[] = self::resultLine($draft, $pairing).' (out of time)';
            }
        }
        unset($pairing);
        foreach (array_keys($draft->rounds[$round]) as $index) {
            if ($draft->rounds[$round][$index]['result'] === null && $draft->rounds[$round][$index]['match'] === null) {
                $this->startGame($draft, $index);
            }
        }

        foreach ($draft->rounds[$round] as $pairing) {
            if ($pairing['result'] === null) {
                return;
            }
        }
        if (count($draft->rounds) >= $draft->totalRounds || count($draft->active()) < 2) {
            $this->finish($draft, 'The last round is over.');

            return;
        }
        $this->startRound($draft);
    }

    /**
     * Pairs the next Swiss round and starts its games. The first round
     * pairs players across the table; later ones pair players with the
     * same record who have not met, and give the odd one out a bye.
     *
     * @param Draft $draft
     *
     * @return void
     */
    private function startRound(Draft $draft): void
    {
        $standings = array_values(array_filter($draft->standings(), fn (array $record) => ! $record['dropped']));
        if ($draft->rounds === []) {
            $ids = array_values(array_map(fn (DraftSeat $seat) => $seat->id, $draft->active()));
            $half = (int) ceil(count($ids) / 2);
            $order = [];
            for ($i = 0; $i < $half; $i++) {
                $order[] = $ids[$i];
                if (isset($ids[$i + $half])) {
                    $order[] = $ids[$i + $half];
                }
            }
        } else {
            $order = array_column($standings, 'id');
        }
        $records = array_column($standings, null, 'id');

        $bye = null;
        if (count($order) % 2 === 1) {
            // The lowest-placed player who has not had a bye gets one.
            foreach (array_reverse($order) as $id) {
                if ($records[$id]['byes'] === 0) {
                    $bye = $id;
                    break;
                }
            }
            $bye ??= end($order);
            $order = array_values(array_diff($order, [$bye]));
        }
        $pairings = [];
        while ($order !== []) {
            $a = array_shift($order);
            $pick = 0;
            foreach ($order as $i => $b) {
                if (! in_array($b, $records[$a]['opponents'], true)) {
                    $pick = $i;
                    break;
                }
            }
            $pairings[] = ['a' => $a, 'b' => $order[$pick], 'match' => null, 'result' => null];
            array_splice($order, $pick, 1);
        }
        if ($bye !== null) {
            $pairings[] = ['a' => $bye, 'b' => null, 'match' => null, 'result' => 'a'];
        }

        $draft->rounds[] = $pairings;
        $draft->deadline = ($this->clock)() + $this->rules->roundHours * 3600;
        $lines = array_map(fn (array $pairing) => $pairing['b'] === null ? "<@{$pairing['a']}> has a bye" : "<@{$pairing['a']}> vs <@{$pairing['b']}>", $pairings);
        $draft->news[] = sprintf("**Round %d of %d** of the %s draft; games not finished <t:%d:R> are draws.\n%s\nYour game starts by itself; if it waits on someone busy in another match, `/draft play` starts it.", count($draft->rounds), $draft->totalRounds, $draft->setName, $draft->deadline, implode("\n", $lines));
        foreach (array_keys($pairings) as $index) {
            if ($pairings[$index]['b'] !== null) {
                $this->startGame($draft, $index);
            }
        }
    }

    /**
     * Starts a pairing's game with both players' decks.
     *
     * @param Draft $draft
     * @param int   $index In the current round.
     * @param bool  $loud  Throw when a player is busy; otherwise the game waits.
     *
     * @return void
     */
    private function startGame(Draft $draft, int $index, bool $loud = false): void
    {
        $round = array_key_last($draft->rounds);
        $pairing = $draft->rounds[$round][$index];
        if ($pairing['b'] === null) {
            return;
        }
        $seats = [];
        foreach ([$pairing['a'], $pairing['b']] as $id) {
            $seat = $draft->seat($id);
            $seats[] = ['id' => $seat->id, 'name' => $seat->name, 'deckName' => "{$draft->setName} draft deck", 'cards' => $this->gameCards($seat)];
        }
        try {
            $match = $this->matches->startEventGame($draft->id, $seats, $this->matches->modes->get($this->rules->mode));
        } catch (\InvalidArgumentException $e) {
            if ($loud) {
                throw $e;
            }

            return;
        }
        $draft->rounds[$round][$index]['match'] = $match->id;
    }

    /**
     * A seat's deck as card data, one entry per copy. A deck that is too
     * small plays as the bot would build it.
     *
     * @param DraftSeat $seat
     *
     * @return array[]
     */
    private function gameCards(DraftSeat $seat): array
    {
        $deck = $seat->deck;
        if ($deck->total() < $this->rules->deckMin) {
            $copy = new DraftSeat($seat->id, $seat->name, picks: $seat->picks);
            $this->buildFor($copy);
            $deck = $copy->deck;
        }
        $cards = [];
        foreach ($deck as $key => $count) {
            $key = (string) $key;
            $count = BasicLands::isBasic($key) ? $count : min($count, $seat->picks->get($key));
            array_push($cards, ...array_fill(0, max(0, $count), $this->decks->cardData($key)));
        }

        return $cards;
    }

    /**
     * Ends the event: stops games still going (draws), and gives everyone
     * their picks.
     *
     * @param Draft  $draft
     * @param string $why
     *
     * @return void
     */
    private function finish(Draft $draft, string $why): void
    {
        if ($draft->rounds !== []) {
            $round = array_key_last($draft->rounds);
            foreach ($draft->rounds[$round] as &$pairing) {
                if ($pairing['result'] === null) {
                    if ($pairing['match'] !== null) {
                        $this->matches->stop($pairing['match'], 'The draft is over.');
                    }
                    $pairing['result'] = 'draw';
                }
            }
            unset($pairing);
        }
        $draft->status = Draft::OVER;
        $draft->queues = [];
        $draft->waitingSince = [];
        $draft->deadline = 0;
        foreach ($draft->seats as $seat) {
            $this->collect($seat);
        }

        $line = '';
        if ($draft->rounds !== []) {
            $top = $draft->standings()[0];
            $line = " **{$top['name']}** finishes first ({$top['wins']}-{$top['losses']}".($top['draws'] > 0 ? "-{$top['draws']}" : '').').';
        }
        $draft->news[] = "The {$draft->setName} draft is over. {$why}{$line} Every player's drafted cards are now in their collection (`/collection`).";
    }

    /**
     * Takes a card from the seat's first pack and passes the rest on.
     *
     * @param Draft  $draft
     * @param int    $seat
     * @param string $uuid
     *
     * @return void
     */
    private function take(Draft $draft, int $seat, string $uuid): void
    {
        $now = ($this->clock)();
        $pack = array_shift($draft->queues[$seat]);
        array_splice($pack, (int) array_search($uuid, $pack, true), 1);
        $entry = $draft->seats[$seat];
        $entry->picks->add($uuid);
        $entry->pickLog[] = $uuid;
        if ($draft->queues[$seat] === []) {
            unset($draft->waitingSince[$seat]);
        } else {
            $draft->waitingSince[$seat] = $now;
        }
        if ($pack === []) {
            return;
        }
        $next = ($seat + $draft->direction() + count($draft->seats)) % count($draft->seats);
        if (($draft->queues[$next] ?? []) === []) {
            $draft->waitingSince[$next] = $now;
        }
        $draft->queues[$next][] = $pack;
    }

    /**
     * The card the bot takes for a player: the rarest, preferring their
     * colors so far.
     *
     * @param DraftSeat    $seat
     * @param list<string> $pack
     *
     * @return string
     */
    private function autoPick(DraftSeat $seat, array $pack): string
    {
        $colors = $this->topColors($seat->picks);
        $best = $pack[0];
        $score = PHP_INT_MIN;
        foreach ($pack as $uuid) {
            $card = $this->decks->cardData($uuid);
            $cardColors = (array) ($card['colors'] ?? []);
            $value = 10 * (self::RARITY_RANK[$card['rarity'] ?? 'common'] ?? 0)
                + ($cardColors !== [] && array_diff($cardColors, $colors) === [] ? 4 : 0)
                + ($cardColors === [] ? 2 : 0);
            if ($value > $score) {
                [$best, $score] = [$uuid, $value];
            }
        }

        return $best;
    }

    /**
     * Builds a seat's deck from its picks: up to {@see AUTO_SPELLS} spells
     * in its two strongest colors (and colorless ones), rarest and cheapest
     * first, then basic lands split by color up to the minimum deck size.
     *
     * @param DraftSeat $seat
     *
     * @return void
     */
    private function buildFor(DraftSeat $seat): void
    {
        foreach ($seat->deck->toArray() as $key => $count) {
            $seat->deck->remove((string) $key, $count);
        }
        $colors = $this->topColors($seat->picks, 2);
        $spells = [];
        foreach ($seat->picks as $uuid => $count) {
            $card = $this->decks->cardData((string) $uuid);
            $cardColors = (array) ($card['colors'] ?? []);
            if (str_contains((string) ($card['type'] ?? ''), 'Land') || array_diff($cardColors, $colors) !== []) {
                continue;
            }
            for ($i = 0; $i < $count; $i++) {
                $spells[] = $card;
            }
        }
        usort($spells, fn (array $a, array $b) => [self::RARITY_RANK[$b['rarity']] ?? 0, $a['manaValue'] ?? 0] <=> [self::RARITY_RANK[$a['rarity']] ?? 0, $b['manaValue'] ?? 0]);
        $pips = array_fill_keys($colors, 0);
        foreach (array_slice($spells, 0, self::AUTO_SPELLS) as $card) {
            $seat->deck->add($card['uuid']);
            foreach ((array) ($card['colors'] ?? []) as $color) {
                if (isset($pips[$color])) {
                    $pips[$color]++;
                }
            }
        }

        $lands = max(0, $this->rules->deckMin - $seat->deck->total());
        $names = array_flip(array_filter(BasicLands::NAMES, fn (string $color) => $color !== 'C'));
        if (array_sum($pips) === 0) {
            $pips = $colors === [] ? ['G' => 1] : array_fill_keys($colors, 1);
        }
        $given = 0;
        $total = array_sum($pips);
        arsort($pips);
        foreach ($pips as $color => $weight) {
            $share = $color === array_key_last($pips) ? $lands - $given : (int) round($lands * $weight / $total);
            $seat->deck->add(BasicLands::PREFIX.$names[$color], max(0, $share));
            $given += max(0, $share);
        }
    }

    /**
     * The colors a player has drafted most of, rarer cards counting more.
     *
     * @param CardCounts $picks
     * @param int        $limit
     *
     * @return string[] Color letters.
     */
    private function topColors(CardCounts $picks, int $limit = 2): array
    {
        $weights = [];
        foreach ($picks as $uuid => $count) {
            $card = $this->decks->cardData((string) $uuid);
            foreach ((array) ($card['colors'] ?? []) as $color) {
                if (in_array($color, ['W', 'U', 'B', 'R', 'G'], true)) {
                    $weights[$color] = ($weights[$color] ?? 0) + $count * (1 + (self::RARITY_RANK[$card['rarity'] ?? 'common'] ?? 0));
                }
            }
        }
        arsort($weights);

        return array_slice(array_keys($weights), 0, $limit);
    }

    /**
     * Gives a seat its picks, once.
     *
     * @param DraftSeat $seat
     *
     * @return void
     */
    private function collect(DraftSeat $seat): void
    {
        if ($seat->collected) {
            return;
        }
        if ($seat->picks->total() > 0) {
            $this->players->findOrCreate($seat->id, $seat->name);
            $this->inventories->addCards($seat->id, $seat->picks->toArray());
        }
        $seat->collected = true;
    }

    /**
     * A result for the pod's channel.
     *
     * @param Draft $draft
     * @param array $pairing
     *
     * @return string
     */
    private static function resultLine(Draft $draft, array $pairing): string
    {
        $name = fn (?string $id) => $id === null ? '' : $draft->seat($id)->name;

        return match ($pairing['result']) {
            'a' => "Round ".count($draft->rounds).": **{$name($pairing['a'])}** beat {$name($pairing['b'])}.",
            'b' => "Round ".count($draft->rounds).": **{$name($pairing['b'])}** beat {$name($pairing['a'])}.",
            default => "Round ".count($draft->rounds).": {$name($pairing['a'])} and {$name($pairing['b'])} drew.",
        };
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    /**
     * The colors of a set that have packs.
     *
     * @param CardPool $pool
     *
     * @return string[]
     */
    private function packColors(CardPool $pool): array
    {
        return array_values(array_filter(CardPool::COLORS, fn (string $color) => $pool->hasRareSlot($color)));
    }

    /**
     * @param string $playerId
     *
     * @throws \InvalidArgumentException When they are in a live draft.
     */
    private function checkFree(string $playerId): void
    {
        if ($this->current($playerId) !== null) {
            throw new \InvalidArgumentException('You are already in a draft. See it with `/draft status`, or leave it with `/draft leave`.');
        }
    }

    /**
     * @param Draft $draft
     *
     * @throws \InvalidArgumentException When it is not taking players.
     */
    private function checkOpen(Draft $draft): void
    {
        if ($draft->status !== Draft::SIGNUP) {
            throw new \InvalidArgumentException('That draft is not taking players any more.');
        }
        if (count($draft->seats) >= $draft->size) {
            throw new \InvalidArgumentException('That pod is full.');
        }
    }

    /**
     * Takes the entry fee.
     *
     * @param string $playerId
     * @param string $playerName
     * @param int    $fee
     *
     * @throws \InvalidArgumentException When they have too few points.
     */
    private function charge(string $playerId, string $playerName, int $fee): void
    {
        $this->players->findOrCreate($playerId, $playerName);
        if ($fee <= 0) {
            return;
        }
        $this->players->modify($playerId, function (Player $player) use ($fee): void {
            if ($player->points < $fee) {
                throw new \InvalidArgumentException(sprintf(
                    'A draft costs %s points to enter and you have %s. Win ranked games, finish quests or sell cards you don\'t need (`/shop sell-extras`) to earn more.',
                    number_format($fee),
                    number_format($player->points),
                ));
            }
            $player->points -= $fee;
        });
    }

    /**
     * Gives points back.
     *
     * @param string $playerId
     * @param int    $points
     *
     * @return void
     */
    private function pay(string $playerId, int $points): void
    {
        if ($points > 0) {
            $this->players->modify($playerId, fn (Player $player) => $player->points += $points);
        }
    }
}
