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

/**
 * A booster draft event: a pod of players drafting packs of one set, then
 * building decks and playing Swiss rounds with them.
 *
 * It moves through {@see SIGNUP}, {@see DRAFTING}, {@see BUILDING} and
 * {@see PLAYING} to {@see OVER}, or is {@see CANCELLED} before it starts.
 *
 * While drafting, each seat has a queue of packs passed to it; it takes a
 * card from the first and passes the rest on. A pairing is
 * `{a, b, match, result}`: `b` is null for a bye, `match` the game's id
 * once it has started, and `result` one of `a`, `b` or `draw` once it is
 * decided.
 *
 * @since 0.5.0
 */
final class Draft
{
    public const string SIGNUP = 'signup';
    public const string DRAFTING = 'drafting';
    public const string BUILDING = 'building';
    public const string PLAYING = 'playing';
    public const string OVER = 'over';
    public const string CANCELLED = 'cancelled';

    /** Match points for a win, a draw and a loss. */
    public const int WIN = 3;
    public const int DRAW = 1;

    /**
     * @param string          $id
     * @param string          $status
     * @param string          $setCode
     * @param string          $setName
     * @param string          $hostId       Who made the pod; they can start it early.
     * @param int             $fee          The entry fee when it was made.
     * @param int             $size         Players in a full pod.
     * @param int             $packs        Packs each player opens.
     * @param string|null     $channelId    Where the pod was made, for announcements.
     * @param list<DraftSeat> $seats        In seat order; packs pass to the next seat.
     * @param int             $packNumber   The pack round, from 1; 0 before the draft.
     * @param array<int, list<list<string>>> $queues Seat => packs waiting for it, each a list of card uuids.
     * @param array<int, int> $waitingSince Seat => when its first waiting pack reached it.
     * @param list<list<array{a: string, b: ?string, match: ?string, result: ?string}>> $rounds Pairings, round by round.
     * @param int             $totalRounds  Swiss rounds the event plays.
     * @param int             $createdAt
     * @param int             $deadline     When the current stage times out: signup, building or the round; 0 while drafting.
     * @param int             $endsAt       When the whole event times out; 0 before the draft starts.
     * @param list<string>    $news         Announcements not yet posted to the pod's channel.
     */
    public function __construct(
        public readonly string $id,
        public string $status,
        public readonly string $setCode,
        public readonly string $setName,
        public string $hostId,
        public readonly int $fee,
        public readonly int $size,
        public readonly int $packs = 3,
        public readonly ?string $channelId = null,
        public array $seats = [],
        public int $packNumber = 0,
        public array $queues = [],
        public array $waitingSince = [],
        public array $rounds = [],
        public int $totalRounds = 0,
        public int $createdAt = 0,
        public int $deadline = 0,
        public int $endsAt = 0,
        public array $news = [],
    ) {
    }

    /**
     * Whether it has not finished: signing up, drafting, building or playing.
     *
     * @return bool
     */
    public function isLive(): bool
    {
        return $this->status !== self::OVER && $this->status !== self::CANCELLED;
    }

    /**
     * A player's seat number.
     *
     * @param string $playerId
     *
     * @return int|null
     */
    public function seatOf(string $playerId): ?int
    {
        foreach ($this->seats as $seat => $entry) {
            if ($entry->id === $playerId) {
                return $seat;
            }
        }

        return null;
    }

    /**
     * A player's seat.
     *
     * @param string $playerId
     *
     * @throws \InvalidArgumentException When they are not in this draft.
     *
     * @return DraftSeat
     */
    public function seat(string $playerId): DraftSeat
    {
        $seat = $this->seatOf($playerId);

        return $seat === null ? throw new \InvalidArgumentException('You are not in this draft.') : $this->seats[$seat];
    }

    /**
     * The seats still in the event.
     *
     * @return array<int, DraftSeat> By seat number.
     */
    public function active(): array
    {
        return array_filter($this->seats, fn (DraftSeat $seat) => ! $seat->dropped);
    }

    /**
     * Which way packs pass this pack round: +1 to the left (the next seat),
     * -1 to the right. The second pack goes right, like at a real table.
     *
     * @return int
     */
    public function direction(): int
    {
        return $this->packNumber % 2 === 0 ? -1 : 1;
    }

    /**
     * The pack waiting for a seat, if any.
     *
     * @param int $seat
     *
     * @return list<string>|null
     */
    public function packFor(int $seat): ?array
    {
        return $this->queues[$seat][0] ?? null;
    }

    /**
     * The current round's pairings.
     *
     * @return list<array{a: string, b: ?string, match: ?string, result: ?string}>
     */
    public function currentRound(): array
    {
        return $this->rounds === [] ? [] : $this->rounds[array_key_last($this->rounds)];
    }

    /**
     * A player's pairing this round.
     *
     * @param string $playerId
     *
     * @return int|null Its index in {@see currentRound()}.
     */
    public function pairingOf(string $playerId): ?int
    {
        foreach ($this->currentRound() as $index => $pairing) {
            if ($pairing['a'] === $playerId || $pairing['b'] === $playerId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Each player's record, best first: match points, then wins, then the
     * match points of the opponents they beat and drew, then seat.
     *
     * @return list<array{id: string, name: string, points: int, wins: int, losses: int, draws: int, byes: int, opponents: list<string>, dropped: bool}>
     */
    public function standings(): array
    {
        $records = [];
        foreach ($this->seats as $seat) {
            $records[$seat->id] = ['id' => $seat->id, 'name' => $seat->name, 'points' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'byes' => 0, 'opponents' => [], 'dropped' => $seat->dropped];
        }
        foreach ($this->rounds as $round) {
            foreach ($round as $pairing) {
                $a = $pairing['a'];
                $b = $pairing['b'];
                if ($b === null) {
                    $records[$a]['byes']++;
                }
                if ($b !== null) {
                    $records[$a]['opponents'][] = $b;
                    $records[$b]['opponents'][] = $a;
                }
                switch ($pairing['result']) {
                    case 'a':
                        $records[$a]['points'] += self::WIN;
                        $records[$a]['wins']++;
                        if ($b !== null) {
                            $records[$b]['losses']++;
                        }
                        break;
                    case 'b':
                        $records[$b]['points'] += self::WIN;
                        $records[$b]['wins']++;
                        $records[$a]['losses']++;
                        break;
                    case 'draw':
                        $records[$a]['points'] += self::DRAW;
                        $records[$a]['draws']++;
                        if ($b !== null) {
                            $records[$b]['points'] += self::DRAW;
                            $records[$b]['draws']++;
                        }
                        break;
                }
            }
        }
        $order = array_flip(array_map(fn (DraftSeat $seat) => $seat->id, $this->seats));
        $strength = fn (array $record) => array_sum(array_map(fn (string $id) => $records[$id]['points'], $record['opponents']));
        $records = array_values($records);
        usort($records, fn (array $x, array $y) => [$y['points'], $y['wins'], $strength($y), $order[$x['id']]] <=> [$x['points'], $x['wins'], $strength($x), $order[$y['id']]]);

        return $records;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'setCode' => $this->setCode,
            'setName' => $this->setName,
            'hostId' => $this->hostId,
            'fee' => $this->fee,
            'size' => $this->size,
            'packs' => $this->packs,
            'channelId' => $this->channelId,
            'seats' => array_map(fn (DraftSeat $seat) => $seat->toArray(), $this->seats),
            'packNumber' => $this->packNumber,
            'queues' => (object) $this->queues,
            'waitingSince' => (object) $this->waitingSince,
            'rounds' => $this->rounds,
            'totalRounds' => $this->totalRounds,
            'createdAt' => $this->createdAt,
            'deadline' => $this->deadline,
            'endsAt' => $this->endsAt,
            'news' => $this->news,
        ];
    }

    public static function fromArray(array $data): self
    {
        $queues = [];
        foreach ((array) ($data['queues'] ?? []) as $seat => $packs) {
            $queues[(int) $seat] = array_values(array_map(fn ($pack) => array_values(array_map('strval', (array) $pack)), (array) $packs));
        }
        $waiting = [];
        foreach ((array) ($data['waitingSince'] ?? []) as $seat => $since) {
            $waiting[(int) $seat] = (int) $since;
        }

        return new self(
            (string) $data['id'],
            (string) $data['status'],
            (string) $data['setCode'],
            (string) ($data['setName'] ?? $data['setCode']),
            (string) $data['hostId'],
            (int) ($data['fee'] ?? 0),
            (int) ($data['size'] ?? 8),
            (int) ($data['packs'] ?? 3),
            isset($data['channelId']) ? (string) $data['channelId'] : null,
            array_values(array_map(fn ($seat) => DraftSeat::fromArray((array) $seat), (array) ($data['seats'] ?? []))),
            (int) ($data['packNumber'] ?? 0),
            $queues,
            $waiting,
            array_values(array_map(fn ($round) => array_values(array_map(fn ($pairing) => [
                'a' => (string) $pairing['a'],
                'b' => isset($pairing['b']) ? (string) $pairing['b'] : null,
                'match' => isset($pairing['match']) ? (string) $pairing['match'] : null,
                'result' => isset($pairing['result']) ? (string) $pairing['result'] : null,
            ], (array) $round)), (array) ($data['rounds'] ?? []))),
            (int) ($data['totalRounds'] ?? 0),
            (int) ($data['createdAt'] ?? 0),
            (int) ($data['deadline'] ?? 0),
            (int) ($data['endsAt'] ?? 0),
            array_values(array_map('strval', (array) ($data['news'] ?? []))),
        );
    }
}
