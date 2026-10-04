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
 * A game's record as plain text, laid out like a chess game score:
 *
 * - tags: who played, the result and why the game ended
 * - the score sheet: one numbered line per turn with every move in short
 *   notation and both life totals when the turn ended
 * - the full log: every line of the game, step by step, with each
 *   player's position at the end of each turn
 *
 * The seed (from which the shuffles can be replayed) is only shown once
 * the game is over, since it gives away the order of both libraries.
 *
 * @since 0.5.0
 */
final class GameRecord
{
    /** Score sheet lines wrap at this width. */
    public const int WIDTH = 100;

    public const string LEGEND = "Notation: +Forest = plays a land · Shock > Bob = casts a spell (with its targets) · Card* = activates an ability\n"
        ."          atk = attacks with · blk Blocker:Attacker = blocks · Bob -3 = combat damage to a player · †Card = goes to the graveyard\n"
        .'          {Alice 20, Bob 17} = life totals at the end of the turn · 1-0 / 0-1 / ½-½ = the result, first player listed first';

    /**
     * @param Game                  $game
     * @param array<string, string> $tags Extra tags to show first, like the match id and the game mode.
     *
     * @return string
     */
    public static function render(Game $game, array $tags = []): string
    {
        $names = array_map(fn (GamePlayer $player) => $player->name, $game->players);
        $tags += [
            'Seat 1' => $names[0],
            'Seat 2' => $names[1],
            'Result' => self::result($game),
        ];
        if ($game->stage === Game::OVER) {
            $tags['Turns'] = (string) $game->turn;
            if (($ended = self::termination($game)) !== null) {
                $tags['Termination'] = $ended;
            }
            $tags['Seed'] = $game->seed;
        }

        $out = [];
        foreach ($tags as $name => $value) {
            $out[] = '['.$name.' "'.str_replace('"', "'", $value).'"]';
        }
        $out[] = '';

        if ($game->record === []) {
            // Games from before the record only kept the latest lines of the log.
            $out[] = 'This game was played before full match records; only its last '.count($game->log).' log lines were kept.';
            $out[] = '';
            array_push($out, ...array_map(fn (string $line) => "  {$line}", $game->log));

            return implode("\n", $out)."\n";
        }

        $out[] = self::LEGEND;
        $out[] = '';
        array_push($out, ...self::scoreSheet($game, $names));
        $out[] = '';
        $out[] = str_repeat('─', 40);
        $out[] = 'Full log';
        $out[] = str_repeat('─', 40);
        array_push($out, ...self::fullLog($game, $names));

        return implode("\n", $out)."\n";
    }

    /**
     * The result in chess style, the first seat first: `1-0`, `0-1`,
     * `½-½`, or `*` while the game goes on.
     *
     * @param Game $game
     *
     * @return string
     */
    public static function result(Game $game): string
    {
        if ($game->stage !== Game::OVER) {
            return '*';
        }

        return match ($game->winner) {
            0 => '1-0',
            1 => '0-1',
            default => '½-½',
        };
    }

    /**
     * Why the game ended: how each losing player lost.
     *
     * @param Game $game
     *
     * @return string|null
     */
    public static function termination(Game $game): ?string
    {
        $reasons = [];
        foreach ($game->players as $player) {
            if ($player->lost) {
                $reasons[] = "{$player->name} {$player->lossReason}";
            }
        }

        return $reasons === [] ? null : implode('; ', $reasons);
    }

    /**
     * Entries of the record grouped by turn (0 is the mulligan).
     *
     * @param Game $game
     *
     * @return array<int, list<array>>
     */
    private static function turns(Game $game): array
    {
        $turns = [];
        foreach ($game->record as $entry) {
            $turns[(int) $entry['t']][] = $entry;
        }
        ksort($turns);

        return $turns;
    }

    /**
     * One line per turn: the moves in order, each run of moves under the
     * name of the player who made it, then the life totals.
     *
     * @param Game     $game
     * @param string[] $names
     *
     * @return string[]
     */
    private static function scoreSheet(Game $game, array $names): array
    {
        $turns = self::turns($game);
        $last = array_key_last($turns);
        $width = strlen((string) $last) + 2;
        $lines = [];
        foreach ($turns as $turn => $entries) {
            $active = null;
            $life = null;
            $parts = [];
            $mover = null;
            foreach ($entries as $entry) {
                if (isset($entry['pos'])) {
                    $life = '{'.implode(', ', array_map(fn ($seat) => "{$names[$seat]} {$entry['pos'][$seat]['life']}", array_keys($entry['pos']))).'}';

                    continue;
                }
                if ($active === null && $turn > 0 && isset($entry['p'])) {
                    $active = (int) $entry['p'];
                    $mover = $active;
                }
                if (! isset($entry['m'])) {
                    continue;
                }
                $seat = isset($entry['p']) ? (int) $entry['p'] : null;
                if ($seat !== null && $seat !== $mover) {
                    $parts[] = ($parts === [] ? '' : '— ')."{$names[$seat]}:";
                    $mover = $seat;
                }
                $parts[] = $entry['m'];
            }
            $head = str_pad($turn === 0 ? '0.' : "{$turn}.", $width).($turn === 0 ? '' : ($active === null ? '' : "{$names[$active]}: "));
            $text = self::joinMoves($parts);
            if ($turn === 0 && $text === '') {
                continue;
            }
            if ($text === '') {
                $text = '(no moves)';
            }
            if ($life !== null) {
                $text .= "   {$life}";
            }
            if ($turn === $last && $game->stage === Game::OVER) {
                $text .= '   '.self::result($game);
            }
            array_push($lines, ...self::wrap($head, $text, str_repeat(' ', $width + 2)));
        }

        return $lines;
    }

    /**
     * Joins moves with ` · `, except straight after a player's name.
     *
     * @param string[] $parts
     *
     * @return string
     */
    private static function joinMoves(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            if ($text === '') {
                $text = $part;
            } elseif (str_ends_with($text, ':')) {
                $text .= " {$part}";
            } else {
                $text .= str_ends_with($part, ':') ? " {$part}" : " · {$part}";
            }
        }

        return $text;
    }

    /**
     * Wraps a score sheet line at ` · ` with a hanging indent.
     *
     * @param string $head
     * @param string $text
     * @param string $indent
     *
     * @return string[]
     */
    private static function wrap(string $head, string $text, string $indent): array
    {
        $lines = [];
        $line = $head;
        foreach (explode(' · ', $text) as $index => $chunk) {
            $piece = ($index === 0 ? '' : ' · ').$chunk;
            if ($index > 0 && mb_strlen($line.$piece) > self::WIDTH) {
                $lines[] = $line.' ·';
                $line = $indent.$chunk;
            } else {
                $line .= $piece;
            }
        }
        $lines[] = $line;

        return $lines;
    }

    /**
     * Every line of the game under its turn and step, with the position at
     * the end of each turn.
     *
     * @param Game     $game
     * @param string[] $names
     *
     * @return string[]
     */
    private static function fullLog(Game $game, array $names): array
    {
        $lines = [];
        $positions = count(array_filter($game->record, fn (array $entry) => isset($entry['pos'])));
        $seen = 0;
        foreach (self::turns($game) as $turn => $entries) {
            $lines[] = '';
            $step = null;
            if ($turn === 0) {
                $lines[] = 'Before the game';
            }
            foreach ($entries as $entry) {
                if (isset($entry['pos'])) {
                    $lines[] = '  '.($game->stage === Game::OVER && ++$seen === $positions ? 'Final position' : 'End of turn').':';
                    foreach ($entry['pos'] as $seat => $side) {
                        $lines[] = sprintf('    %s: %d life · %d in hand · %d in library · %d in graveyard', $names[$seat], $side['life'], $side['hand'], $side['library'], $side['graveyard']);
                        $lines[] = '      '.($side['board'] === [] ? 'no permanents' : implode(', ', $side['board']));
                    }

                    continue;
                }
                if ($turn > 0 && $step === null && isset($entry['p']) && str_starts_with((string) $entry['x'], "Turn {$turn}:")) {
                    $lines[] = "Turn {$turn} · {$names[(int) $entry['p']]}";
                    $step = '';

                    continue;
                }
                if ($turn > 0 && $entry['s'] !== $step) {
                    $step = $entry['s'];
                    $label = Step::tryFrom((string) $step)?->label() ?? (string) $step;
                    $lines[] = "  {$label}";
                }
                $lines[] = '    '.$entry['x'];
            }
        }

        return $lines;
    }
}
