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
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Matches\PanelActions;
use MTGPocket\Pocket;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Matches between two players: `/match challenge|board|leave`, the
 * challenge's **Accept** and **Decline**, the board's buttons, and each
 * player's private action panel.
 *
 * The board is public and shows what both players may see. A player's
 * hand and choices are only ever in their own panel, a hidden reply to
 * **Your hand & actions**. Acting in the panel updates the panel and posts
 * a fresh board that mentions whoever the game now waits on.
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
        return [self::command('match', 'Play Magic against another player with your decks.')
            ->addOption(self::subcommand(
                $mtg,
                'challenge',
                'Challenge another player to a game.',
                self::option($mtg, Option::USER, 'opponent', 'Who to play against.', true),
                self::option($mtg, Option::STRING, 'deck', 'Which of your decks; defaults to your active deck.', false, true),
            ))
            ->addOption(self::subcommand($mtg, 'board', 'Show the board of your current game.', self::hidden($mtg)))
            ->addOption(self::subcommand($mtg, 'leave', 'Call off your challenge, or concede your game.'))];
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
                $matches->challenge($id, $name, $opponentId, self::userName($interaction, $opponentId), $args['deck'] ?? null)
            ));
        }, function (Interaction $interaction, $option): array {
            if (($option->name ?? '') !== 'deck') {
                return [];
            }
            [$id] = self::caller($interaction);
            $typed = trim((string) ($option->value ?? ''));
            $choices = [];
            foreach ($this->pocket->deckBuilder->list($id) as $deck) {
                if ($typed === '' || stripos($deck->name, $typed) !== false) {
                    $choices[$deck->id] = "{$deck->name} ({$deck->main->total()} cards)";
                }
            }

            return self::choices($choices);
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
                $match = $matches->leave($id);

                return $match->status === MatchRecord::OVER ? MatchMessageBuilder::board($match) : MatchMessageBuilder::closed($match);
            });
        });

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

    private function find(string $matchId): MatchRecord
    {
        return $this->pocket->matches->find($matchId) ?? throw new \OutOfBoundsException('That match no longer exists.');
    }

    /**
     * A user's display name from the command's resolved data.
     *
     * @param Interaction $interaction
     * @param string      $userId
     *
     * @return string
     */
    private static function userName(Interaction $interaction, string $userId): string
    {
        try {
            $resolved = $interaction->data->resolved ?? null;
            $member = $resolved?->members?->get('id', $userId);
            $user = $resolved?->users?->get('id', $userId);

            return (string) ($member?->nick ?? $user?->global_name ?? $user?->username ?? 'Opponent');
        } catch (\Throwable) {
            return 'Opponent';
        }
    }
}
