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

namespace MTGPocket\Modules;

use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Event;
use MTG\Modules\InteractionTrait;
use MTG\Modules\Module;
use MTG\MTG;
use MTGPocket\Builders\MatchMessageBuilder;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Matches\MatchService;
use MTGPocket\Matches\PanelActions;
use MTGPocket\Pocket;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Matches between two players: `/match queue|challenge|board|leave|modes|ladder|log`,
 * the challenge's **Accept** and **Decline**, the board's buttons, and
 * each player's private action panel.
 *
 * `/match queue` waits for an opponent in a game mode, or plays the one
 * already waiting; those games are ranked. A challenge is a friendly game
 * against a player you name.
 *
 * The board is public and shows what both players may see. A player's
 * hand and choices are only ever in their own panel, a hidden reply to
 * **Your hand & actions**. Acting in the panel updates the panel and posts
 * a fresh board that mentions whoever the game now waits on.
 *
 * Every game keeps a full record, turn by turn; `/match log` (or the
 * finished board's **Game record** button) sends it as a text file.
 *
 * @since 0.3.0
 */
final class Matches implements Module
{
    use InteractionTrait;
    use PocketTrait;

    private PanelActions $actions;

    public function __construct(protected Pocket $pocket)
    {
        $this->actions = new PanelActions($pocket->matches);
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'matches';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        $mode = fn (string $description) => self::withChoices($mtg, self::option($mtg, Option::STRING, 'mode', $description), DeckBuilder::FORMATS);
        $deck = fn () => self::option($mtg, Option::STRING, 'deck', 'Which of your decks or a rental deck; defaults to your active deck.', false, true);

        return [self::command('match', 'Play Magic against another player with your decks.')
            ->addOption(self::subcommand($mtg, 'queue', 'Find an opponent for a ranked game.', $mode('The game mode; defaults to your deck\'s format.'), $deck()))
            ->addOption(self::subcommand(
                $mtg,
                'challenge',
                'Challenge another player to a friendly game.',
                self::option($mtg, Option::USER, 'opponent', 'Who to play against.', true),
                $deck(),
                $mode('The game mode; defaults to your deck\'s format.'),
            ))
            ->addOption(self::subcommand($mtg, 'modes', 'The game modes, their deck rules and who is waiting.', self::hidden($mtg)))
            ->addOption(self::subcommand($mtg, 'ladder', 'A game mode\'s ratings.', $mode('Which mode; defaults to your active deck\'s format.'), self::hidden($mtg)))
            ->addOption(self::subcommand($mtg, 'board', 'Show the board of your current game.', self::hidden($mtg)))
            ->addOption(self::subcommand($mtg, 'leave', 'Leave the queue, call off your challenge, or concede your game.'))
            ->addOption(self::subcommand(
                $mtg,
                'log',
                'The full record of a game, turn by turn, to review.',
                self::option($mtg, Option::STRING, 'match', 'Which game; defaults to your current or last one.', false, true),
                self::hidden($mtg),
            ))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $matches = $this->pocket->matches;

        $mtg->listenCommand(['match', 'challenge'], function (Interaction $interaction, $options) use ($mtg, $matches) {
            $args = self::values($options);
            [$id, $name] = self::caller($interaction);
            $opponentId = (string) ($args['opponent'] ?? '');

            return self::reply($mtg, $interaction, false, fn () => MatchMessageBuilder::challenge(
                $matches->challenge($id, $name, $opponentId, self::userName($interaction, $opponentId, 'Opponent'), $args['deck'] ?? null, $args['mode'] ?? null)
            ));
        }, $this->deckChoices(...));

        $mtg->listenCommand(['match', 'queue'], function (Interaction $interaction, $options) use ($mtg, $matches) {
            $args = self::values($options);
            [$id, $name] = self::caller($interaction);

            return self::reply($mtg, $interaction, false, function () use ($matches, $id, $name, $args) {
                $result = $matches->queue($id, $name, $args['mode'] ?? null, $args['deck'] ?? null);

                return $result['match'] !== null
                    ? MatchMessageBuilder::board($result['match'], true)
                    : MatchMessageBuilder::queued($result['mode'], $result['deck']->name, $matches->ladder->entry($result['mode']->id, $id)['rating'], intdiv(MatchService::QUEUE_WAIT, 60));
            });
        }, $this->deckChoices(...));

        $mtg->listenCommand(['match', 'modes'], function (Interaction $interaction, $options) use ($mtg, $matches) {
            return self::reply($mtg, $interaction, (bool) (self::values($options)['hidden'] ?? false), fn () => MatchMessageBuilder::modes($matches->modes, $matches->queueSizes()));
        });

        $mtg->listenCommand(['match', 'ladder'], function (Interaction $interaction, $options) use ($mtg, $matches) {
            $args = self::values($options);
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), function () use ($matches, $id, $args) {
                $active = $this->pocket->deckBuilder->activeDeckId($id);
                $format = $args['mode']
                    ?? ($this->pocket->rentals->activeRental($id) !== null ? $this->pocket->rentals->mode()->id : null)
                    ?? ($active === null ? null : $this->pocket->decks->find($id, $active)?->format)
                    ?? 'standard';
                $mode = $matches->modes->get($format);

                return MatchMessageBuilder::ladder($mode, $matches->ladder->standings($mode->id), $id);
            });
        });

        $mtg->listenCommand(['match', 'board'], function (Interaction $interaction, $options) use ($mtg, $matches) {
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, (bool) (self::values($options)['hidden'] ?? false), function () use ($matches, $id) {
                $match = $matches->current($id) ?? throw new \InvalidArgumentException('You are not in a match. Start one with `/match challenge`.');

                return $match->status === MatchRecord::PENDING ? MatchMessageBuilder::challenge($match) : MatchMessageBuilder::board($match);
            });
        });

        $mtg->listenCommand(['match', 'leave'], function (Interaction $interaction) use ($mtg, $matches) {
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, false, function () use ($matches, $id) {
                if ($matches->current($id) === null && ($mode = $matches->unqueue($id)) !== null) {
                    return MatchMessageBuilder::notice("You left the {$mode->label} queue.");
                }
                $match = $matches->leave($id);

                return $match->status === MatchRecord::OVER ? MatchMessageBuilder::board($match) : MatchMessageBuilder::closed($match);
            });
        });

        $mtg->listenCommand(['match', 'log'], function (Interaction $interaction, $options) use ($mtg, $matches) {
            $args = self::values($options);
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => MatchMessageBuilder::log($matches->forReview($id, $args['match'] ?? null)));
        }, $this->historyChoices(...));

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }
            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== PocketMessageBuilder::PREFIX || ($parts[1] ?? '') !== 'm' || ! isset($parts[2], $parts[3])) {
                return;
            }
            $this->component($mtg, $interaction, $parts[2], $parts[3], array_slice($parts, 4));
        });
    }

    /**
     * Handles a match button or menu.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param string      $matchId
     * @param string      $action
     * @param string[]    $args
     *
     * @return PromiseInterface
     */
    private function component(MTG $mtg, Interaction $interaction, string $matchId, string $action, array $args): PromiseInterface
    {
        $matches = $this->pocket->matches;
        [$id, $name] = self::caller($interaction);

        return match ($action) {
            // The challenge message becomes the board, or says it is off.
            'accept' => self::answer($mtg, $interaction, fn () => MatchMessageBuilder::board($matches->accept($matchId, $id, $name)), true),
            'decline' => self::answer($mtg, $interaction, fn () => MatchMessageBuilder::closed($matches->decline($matchId, $id)), true),

            // Board buttons.
            'refresh' => self::answer($mtg, $interaction, fn () => MatchMessageBuilder::board($this->find($matchId)), true),
            'hand' => self::answer($mtg, $interaction, fn () => MatchMessageBuilder::actions($this->find($matchId), $id)),
            'log' => self::answer($mtg, $interaction, fn () => MatchMessageBuilder::log($this->find($matchId))),
            'bpass' => self::answer($mtg, $interaction, fn () => MatchMessageBuilder::board($this->actions->run($matchId, $id, 'pass')[0]), true),

            // Everything else comes from a player's own panel.
            default => $this->panel($mtg, $interaction, $matchId, $id, $action, $args),
        };
    }

    /**
     * Runs a panel action: the panel shows the result (or why it was not
     * allowed), and when the game changed a fresh board is posted.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param string      $matchId
     * @param string      $playerId
     * @param string      $action
     * @param string[]    $args
     *
     * @return PromiseInterface
     */
    private function panel(MTG $mtg, Interaction $interaction, string $matchId, string $playerId, string $action, array $args): PromiseInterface
    {
        $values = array_map('strval', (array) ($interaction->data->values ?? []));

        return resolve(null)
            ->then(fn () => $this->actions->run($matchId, $playerId, $action, $args, $values))
            ->then(
                fn (array $result) => $interaction->updateMessage(MatchMessageBuilder::actions($result[0], $playerId))
                    ->then(fn () => $result[1] ? $interaction->sendFollowUpMessage(MatchMessageBuilder::board($result[0], true)) : null),
                function (\Throwable $e) use ($mtg, $interaction, $matchId, $playerId) {
                    $match = $this->pocket->matches->find($matchId);
                    if ($match === null || ! ($e instanceof \InvalidArgumentException)) {
                        return $interaction->respondWithMessage(self::failure($mtg, $e), true);
                    }

                    return $interaction->updateMessage(MatchMessageBuilder::actions($match, $playerId, '⚠️ '.$e->getMessage()));
                }
            )
            ->then(null, fn (\Throwable $e) => $mtg->logger->warning('Could not answer a match action: '.$e->getMessage()));
    }

    /**
     * Autocomplete for `deck`: the caller's decks, then the rental decks.
     *
     * @param Interaction $interaction
     * @param mixed       $option
     *
     * @return array
     */
    private function deckChoices(Interaction $interaction, $option): array
    {
        if (($option->name ?? '') !== 'deck') {
            return [];
        }
        [$id] = self::caller($interaction);
        $typed = trim((string) ($option->value ?? ''));
        $choices = [];
        foreach ($this->pocket->deckBuilder->list($id) as $deck) {
            if ($typed === '' || stripos($deck->name, $typed) !== false) {
                $choices[$deck->id] = "{$deck->name} ({$deck->main->total()} cards, ".DeckBuilder::FORMATS[$deck->format].')';
            }
        }
        $choices += $this->pocket->rentals->suggest($typed);

        return self::choices($choices);
    }

    /**
     * Autocomplete for `match`: the caller's live game, then the games they finished.
     *
     * @param Interaction $interaction
     * @param mixed       $option
     *
     * @return array
     */
    private function historyChoices(Interaction $interaction, $option): array
    {
        if (($option->name ?? '') !== 'match') {
            return [];
        }
        [$id] = self::caller($interaction);
        $typed = trim((string) ($option->value ?? ''));
        $matches = $this->pocket->matches->history($id);
        if (($live = $this->pocket->matches->current($id)) !== null && $live->game !== null) {
            array_unshift($matches, $live);
        }
        $choices = [];
        foreach ($matches as $match) {
            $label = MatchMessageBuilder::historyLine($match, $id);
            if ($typed === '' || stripos($label, $typed) !== false || str_starts_with($match->id, strtolower($typed))) {
                $choices[$match->id] = $label;
            }
        }

        return self::choices($choices);
    }

    private function find(string $matchId): MatchRecord
    {
        return $this->pocket->matches->find($matchId) ?? throw new \OutOfBoundsException('That match no longer exists.');
    }
}
