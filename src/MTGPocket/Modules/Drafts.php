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
use MTGPocket\Builders\DraftMessageBuilder;
use MTGPocket\Builders\MatchMessageBuilder;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Cards\BasicLands;
use MTGPocket\Cards\CardPool;
use MTGPocket\Drafts\Draft;
use MTGPocket\Pocket;
use React\Promise\PromiseInterface;

/**
 * Booster drafts: `/draft create|join|start|leave|pack|pool|add|remove|lands|auto|ready|play|status`.
 *
 * A pod is made in a channel, and what happens in it (the draft starting,
 * each round's pairings and results, the end) is announced there. Picking
 * and deck building are private to each player.
 *
 * Every few seconds the module brings every draft up to date, so the bot
 * picks for players who run out of time and rounds end on time even when
 * nobody is using a command.
 *
 * @since 0.5.0
 */
final class Drafts implements Module
{
    use InteractionTrait;
    use PocketTrait;

    /** Seconds between catching up with every draft. */
    public const int TICK_SECONDS = 15;

    public function __construct(protected Pocket $pocket)
    {
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'drafts';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        $rules = $this->pocket->drafts->rules;
        $count = fn (string $description) => self::option($mtg, Option::INTEGER, 'count', $description)->setMinValue(1)->setMaxValue(60);
        $lands = array_map(
            fn (string $land) => self::option($mtg, Option::INTEGER, strtolower($land), "How many {$land}.")->setMinValue(0)->setMaxValue(60),
            ['Plains', 'Island', 'Swamp', 'Mountain', 'Forest'],
        );

        return [self::command('draft', 'Booster drafts: pay in, draft packs with other players, play, and keep the cards.')
            ->addOption(self::subcommand(
                $mtg,
                'create',
                'Make a draft pod for a set. You pay the entry fee and take the first seat.',
                self::option($mtg, Option::STRING, 'set', 'Which set to draft.', true, true),
                self::option($mtg, Option::INTEGER, 'players', "Players in a full pod (default {$rules->podSize}).")->setMinValue($rules->minPlayers)->setMaxValue($rules->podSize),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'join',
                'Join a draft pod and pay its entry fee.',
                self::option($mtg, Option::STRING, 'draft', 'Which pod; defaults to the only open one.', false, true),
            ))
            ->addOption(self::subcommand($mtg, 'start', 'Start your pod\'s draft before it is full (its host only).'))
            ->addOption(self::subcommand($mtg, 'leave', 'Leave your draft. Before it starts you get your points back; after, you keep your picks.'))
            ->addOption(self::subcommand($mtg, 'pack', 'The pack in front of you: take a card.'))
            ->addOption(self::subcommand($mtg, 'pool', 'Your picks and your draft deck.'))
            ->addOption(self::subcommand(
                $mtg,
                'add',
                'Put drafted cards or basic lands in your draft deck.',
                self::option($mtg, Option::STRING, 'card', 'A card you drafted, or a basic land.', true, true),
                $count('How many copies (default 1).'),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'remove',
                'Take cards out of your draft deck; they go back to your side deck.',
                self::option($mtg, Option::STRING, 'card', 'A card in your deck.', true, true),
                $count('How many copies (default all).'),
            ))
            ->addOption(self::subcommand($mtg, 'lands', 'Set how many basic lands your draft deck has.', ...$lands))
            ->addOption(self::subcommand($mtg, 'auto', 'Have the bot build your draft deck from your picks.'))
            ->addOption(self::subcommand($mtg, 'ready', 'Send in your draft deck. Rounds start when everyone is ready.'))
            ->addOption(self::subcommand($mtg, 'play', 'Start your game this round, or show it.'))
            ->addOption(self::subcommand(
                $mtg,
                'status',
                'Your draft, or the open pods: players, pairings and standings.',
                self::option($mtg, Option::STRING, 'draft', 'Which draft; defaults to yours.', false, true),
                self::hidden($mtg),
            ))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $drafts = $this->pocket->drafts;
        $rules = $drafts->rules;

        $mtg->listenCommand(['draft', 'create'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => DraftMessageBuilder::pod(
            $drafts->create($id, $name, (string) $args['set'], $i->channel_id === null ? null : (string) $i->channel_id, isset($args['players']) ? (int) $args['players'] : null),
            $rules,
        ), false), $this->suggest(...));
        $mtg->listenCommand(['draft', 'join'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => DraftMessageBuilder::pod(
            $drafts->join($id, $name, $args['draft'] ?? null),
            $rules,
        ), false), $this->suggest(...));
        $mtg->listenCommand(['draft', 'start'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id) => DraftMessageBuilder::pod($drafts->start($id), $rules), false));
        $mtg->listenCommand(['draft', 'leave'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id) use ($drafts) {
            $draft = $drafts->leave($id);

            return PocketMessageBuilder::notice(match ($draft->status) {
                Draft::SIGNUP, Draft::CANCELLED => "You left the {$draft->setName} pod and got your ".PocketMessageBuilder::points($draft->fee).' back.',
                Draft::DRAFTING => "You left the {$draft->setName} draft. The bot picks for your seat until the packs are empty, then your picks go to your collection.",
                default => "You left the {$draft->setName} draft. Your ".$draft->seat($id)->picks->total().' drafted cards are in your collection.',
            });
        }));
        $mtg->listenCommand(['draft', 'pack'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id) => $this->pack($id)));
        $mtg->listenCommand(['draft', 'pool'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id) => $this->pool($id)));
        $mtg->listenCommand(['draft', 'add'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($drafts) {
            [, $note] = $drafts->addToDeck($id, (string) $args['card'], (int) ($args['count'] ?? 1));

            return $this->pool($id, $note);
        }), $this->suggest(...));
        $mtg->listenCommand(['draft', 'remove'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($drafts) {
            [, $note] = $drafts->removeFromDeck($id, (string) $args['card'], isset($args['count']) ? (int) $args['count'] : null);

            return $this->pool($id, $note);
        }), $this->suggest(...));
        $mtg->listenCommand(['draft', 'lands'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($drafts) {
            $lands = [];
            foreach (array_keys(BasicLands::NAMES) as $land) {
                if (isset($args[strtolower($land)])) {
                    $lands[$land] = (int) $args[strtolower($land)];
                }
            }
            if ($lands === []) {
                throw new \InvalidArgumentException('Say how many of at least one basic land.');
            }
            $drafts->setLands($id, $lands);

            return $this->pool($id, 'Lands set.');
        }));
        $mtg->listenCommand(['draft', 'auto'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id) use ($drafts) {
            $drafts->autoBuild($id);

            return $this->pool($id, 'The bot built your deck from your two strongest colors. Change it as you like, then `/draft ready`.');
        }));
        $mtg->listenCommand(['draft', 'ready'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id) use ($drafts) {
            $draft = $drafts->ready($id);

            return $this->pool($id, $draft->status === Draft::PLAYING ? 'Your deck is in. Round '.count($draft->rounds).' has started; see `/draft status`.' : 'Your deck is in. Rounds start when everyone is ready.');
        }));
        $mtg->listenCommand(['draft', 'play'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id) => MatchMessageBuilder::board($drafts->play($id), true), false));
        $mtg->listenCommand(['draft', 'status'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($drafts, $rules) {
            if (($args['draft'] ?? '') !== '') {
                return $this->status(strtolower(trim((string) $args['draft'])));
            }
            if (($draft = $drafts->last($id)) !== null && ($draft->isLive() || $drafts->open() === [])) {
                return DraftMessageBuilder::pod($draft, $rules);
            }
            $open = $drafts->open();
            if ($open === []) {
                return PocketMessageBuilder::notice('No draft pod is open. Make one with `/draft create`; it costs '.PocketMessageBuilder::points($rules->entryFee).' to enter.');
            }

            return DraftMessageBuilder::pod($open[0], $rules);
        }, false), $this->suggest(...));

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }
            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== PocketMessageBuilder::PREFIX || ! in_array($parts[1] ?? '', ['dj', 'ds', 'dv', 'dr', 'dq', 'dp'], true) || ! isset($parts[2])) {
                return;
            }
            $this->component($mtg, $interaction, $parts[1], $parts[2], $parts[3] ?? null);
        });

        $mtg->getLoop()->addPeriodicTimer(self::TICK_SECONDS, fn () => $this->catchUp($mtg));
    }

    /**
     * Handles a draft button or the pick menu.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param string      $action
     * @param string      $draftId
     * @param string|null $arg
     *
     * @return PromiseInterface
     */
    private function component(MTG $mtg, Interaction $interaction, string $action, string $draftId, ?string $arg): PromiseInterface
    {
        $drafts = $this->pocket->drafts;
        [$id, $name] = self::caller($interaction);

        return match ($action) {
            'dj' => self::answer($mtg, $interaction, fn () => DraftMessageBuilder::pod($drafts->join($id, $name, $draftId), $drafts->rules), true),
            'ds' => self::answer($mtg, $interaction, fn () => $this->status($draftId), true),
            'dv' => self::answer($mtg, $interaction, fn () => $this->status($draftId)),
            'dr' => self::answer($mtg, $interaction, fn () => $this->pack($id)),
            'dq' => self::answer($mtg, $interaction, fn () => $this->pack($id), true),
            // The pick menu is on the player's own pack message: it becomes the next pack.
            'dp' => self::answer($mtg, $interaction, function () use ($drafts, $id, $interaction, $arg) {
                $uuid = (string) (((array) ($interaction->data->values ?? []))[0] ?? '');
                try {
                    $drafts->pick($id, $uuid, $arg === null ? null : (int) $arg);
                } catch (\InvalidArgumentException $e) {
                    return $this->pack($id, '⚠️ '.$e->getMessage());
                }

                return $this->pack($id, '✅ You took **'.$this->pocket->deckBuilder->cardData($uuid)['name'].'**.');
            }, true),
            default => self::answer($mtg, $interaction, fn () => throw new \InvalidArgumentException('Unknown draft action.')),
        };
    }

    /**
     * A pod's message.
     *
     * @param string $draftId
     *
     * @return DraftMessageBuilder
     */
    private function status(string $draftId): DraftMessageBuilder
    {
        $drafts = $this->pocket->drafts;

        return DraftMessageBuilder::pod($drafts->find($draftId) ?? throw new \OutOfBoundsException('That draft no longer exists.'), $drafts->rules);
    }

    /**
     * The caller's pack message.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return DraftMessageBuilder
     */
    private function pack(string $playerId, ?string $note = null): DraftMessageBuilder
    {
        $drafts = $this->pocket->drafts;
        $draft = $drafts->current($playerId) ?? $drafts->last($playerId) ?? throw new \InvalidArgumentException('You are not in a draft. Join one with `/draft join`.');
        $seat = $draft->seat($playerId);

        return DraftMessageBuilder::draftPack($draft, $seat, $drafts->packCards($draft, $playerId), $drafts->pickDeadline($draft, $playerId), $note);
    }

    /**
     * The caller's picks and deck.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return DraftMessageBuilder
     */
    private function pool(string $playerId, ?string $note = null): DraftMessageBuilder
    {
        $drafts = $this->pocket->drafts;
        $draft = $drafts->current($playerId) ?? $drafts->last($playerId) ?? throw new \InvalidArgumentException('You are not in a draft. Join one with `/draft join`.');
        $seat = $draft->seat($playerId);

        return DraftMessageBuilder::pool($draft, $seat, $this->pocket->deckBuilder->cardData(...), $drafts->deckProblems($seat), $note);
    }

    /**
     * Brings every draft up to date and posts their news.
     *
     * @param MTG $mtg
     *
     * @return void
     */
    private function catchUp(MTG $mtg): void
    {
        try {
            $this->pocket->drafts->tickAll();
            foreach ($this->pocket->drafts->takeNews() as $entry) {
                $channel = $entry['draft']->channelId === null ? null : $mtg->getChannel($entry['draft']->channelId);
                if ($channel === null) {
                    continue;
                }
                $channel->sendMessage(DraftMessageBuilder::announcement($entry['draft'], $entry['news']))
                    ->then(null, fn (\Throwable $e) => $mtg->logger->warning('Could not post draft news: '.$e->getMessage()));
            }
        } catch (\Throwable $e) {
            $mtg->logger->warning('Could not bring the drafts up to date: '.$e->getMessage());
        }
    }

    /**
     * Runs a sub-command for the caller and answers with what it returns.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param iterable    $options
     * @param callable(string, string, array): \Discord\Builders\MessageBuilder $work
     * @param bool        $private     Answer only the caller; otherwise follow `hidden`.
     *
     * @return PromiseInterface
     */
    private function run(MTG $mtg, Interaction $interaction, iterable $options, callable $work, bool $private = true): PromiseInterface
    {
        $args = self::values($options);
        [$id, $name] = self::caller($interaction);

        return self::reply($mtg, $interaction, $private || (bool) ($args['hidden'] ?? false), fn () => $work($id, $name, $args));
    }

    /**
     * Autocomplete: `set` is the sets with packs, `draft` the open pods (and
     * the caller's own for `status`), `card` the caller's picks for `add`
     * and their deck for `remove`.
     *
     * @param Interaction $interaction
     * @param mixed       $option
     *
     * @return array
     */
    private function suggest(Interaction $interaction, $option): array
    {
        [$id] = self::caller($interaction);
        $typed = trim((string) ($option->value ?? ''));
        $subcommand = '';
        foreach ($interaction->data->options ?? [] as $sub) {
            $subcommand = (string) ($sub->name ?? '');
            break;
        }
        $drafts = $this->pocket->drafts;

        switch ($option->name ?? '') {
            case 'set':
                $sets = [];
                foreach ($this->pocket->pools->all() as $pool) {
                    if (array_filter(CardPool::COLORS, $pool->hasRareSlot(...)) !== []) {
                        $sets[$pool->setCode] = ['name' => $pool->setName];
                    }
                }

                return self::choices(self::setChoices($sets, $typed));

            case 'draft':
                $choices = [];
                $pods = $drafts->open();
                if ($subcommand === 'status' && ($mine = $drafts->last($id)) !== null) {
                    array_unshift($pods, $mine);
                }
                foreach ($pods as $draft) {
                    $label = sprintf('%s · %d/%d players · %s', $draft->setName, count($draft->seats), $draft->size, DraftMessageBuilder::STAGES[$draft->status] ?? $draft->status);
                    if ($typed === '' || stripos($label, $typed) !== false || str_starts_with($draft->id, strtolower($typed))) {
                        $choices[$draft->id] = $label;
                    }
                }

                return self::choices($choices);

            case 'card':
                $draft = $drafts->current($id);
                if ($draft === null) {
                    return [];
                }
                $seat = $draft->seat($id);
                $choices = [];
                if ($subcommand === 'add') {
                    foreach (array_keys(BasicLands::NAMES) as $basic) {
                        if ($typed !== '' && stripos($basic, $typed) === 0) {
                            $choices[$basic] = "{$basic} (basic land)";
                        }
                    }
                }
                $cards = $subcommand === 'add' ? $seat->picks : $seat->deck;
                foreach ($cards as $key => $count) {
                    $left = $subcommand === 'add' ? $count - $seat->deck->get((string) $key) : $count;
                    $data = $this->pocket->deckBuilder->cardData((string) $key);
                    if ($left > 0 && ($typed === '' || stripos($data['name'], $typed) !== false)) {
                        $choices[$data['name']] = "{$data['name']} ({$left})";
                    }
                }

                return self::choices($choices);
        }

        return [];
    }
}
