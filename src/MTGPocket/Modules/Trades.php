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
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Builders\TradeMessageBuilder;
use MTGPocket\Panels\Panels;
use MTGPocket\Pocket;
use MTGPocket\Trades\StaleOfferException;
use MTGPocket\Trades\TradeOffer;
use MTGPocket\Trades\TradeService;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Trades between players: `/trade offer|add|list|cancel`, and the
 * **Accept**, **Decline** and **Cancel offer** buttons on an offer.
 *
 * An offer is posted where it was made and mentions the player it is made
 * to. Its buttons carry the offer's revision, so accepting an offer that
 * has since changed shows the new one instead.
 *
 * Custom ids: `pocket:trade:<id>:<action>:<revision>` and `pocket:tradeview`.
 *
 * @since 0.4.0
 */
final class Trades implements Module
{
    use InteractionTrait;
    use PocketTrait;

    public function __construct(protected Pocket $pocket)
    {
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'trades';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        $count = fn (string $name, string $description) => self::option($mtg, Option::INTEGER, $name, $description)->setMinValue(1)->setMaxValue(99);
        $points = fn (string $name, string $description) => self::option($mtg, Option::INTEGER, $name, $description)->setMinValue(1)->setMaxValue(TradeService::MAX_POINTS);
        $offer = fn () => self::option($mtg, Option::STRING, 'offer', 'Which of your offers; defaults to your newest.', false, true);

        return [self::command('trade', 'Trade cards and points with other players.')
            ->addOption(self::subcommand(
                $mtg,
                'offer',
                'Offer another player a trade. Add more cards to it with /trade add.',
                self::option($mtg, Option::USER, 'player', 'Who to trade with.', true),
                self::option($mtg, Option::STRING, 'give', 'A card of yours to give.', false, true),
                $count('give_count', 'How many copies to give (default 1).'),
                self::option($mtg, Option::STRING, 'want', 'A card of theirs you want.', false, true),
                $count('want_count', 'How many copies you want (default 1).'),
                $points('give_points', 'Points you give.'),
                $points('want_points', 'Points you want.'),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'add',
                'Add a card or points to an offer you made.',
                self::withChoices($mtg, self::option($mtg, Option::STRING, 'side', 'Whether you give it or want it.', true), ['give' => 'I give', 'want' => 'I want']),
                self::option($mtg, Option::STRING, 'card', 'The card: one of yours to give, or one of theirs you want.', false, true),
                $count('count', 'How many copies (default 1).'),
                $points('points', 'Points to add.'),
                $offer(),
            ))
            ->addOption(self::subcommand($mtg, 'list', 'Your open trade offers, made and received.', self::hidden($mtg)))
            ->addOption(self::subcommand($mtg, 'cancel', 'Call off a trade offer you made.', $offer()))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $trades = $this->pocket->trades;
        $card = $trades->cardData(...);
        $suggest = fn (Interaction $interaction, $option) => $this->suggest($interaction, $option);

        $mtg->listenCommand(['trade', 'offer'], function (Interaction $interaction, $options) use ($mtg, $trades, $card) {
            $args = self::values($options);
            [$id, $name] = self::caller($interaction);
            $to = (string) ($args['player'] ?? '');

            return self::reply($mtg, $interaction, false, fn () => TradeMessageBuilder::offer($trades->offer(
                $id,
                $name,
                $to,
                self::userName($interaction, $to),
                isset($args['give']) ? [(string) $args['give'] => (int) ($args['give_count'] ?? 1)] : [],
                isset($args['want']) ? [(string) $args['want'] => (int) ($args['want_count'] ?? 1)] : [],
                (int) ($args['give_points'] ?? 0),
                (int) ($args['want_points'] ?? 0),
            ), $card));
        }, $suggest);

        $mtg->listenCommand(['trade', 'add'], function (Interaction $interaction, $options) use ($mtg, $trades, $card) {
            $args = self::values($options);
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, false, fn () => TradeMessageBuilder::offer($trades->add(
                $id,
                $args['offer'] ?? null,
                ($args['side'] ?? 'give') === 'want',
                $args['card'] ?? null,
                (int) ($args['count'] ?? 1),
                (int) ($args['points'] ?? 0),
            ), $card, 'The offer changed. Accept buttons on earlier copies of it no longer work.'));
        }, $suggest);

        $mtg->listenCommand(['trade', 'list'], function (Interaction $interaction, $options) use ($mtg) {
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, (bool) (self::values($options)['hidden'] ?? false), fn () => (new Panels($this->pocket))->trades($id));
        });

        $mtg->listenCommand(['trade', 'cancel'], function (Interaction $interaction, $options) use ($mtg, $trades, $card) {
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, false, fn () => TradeMessageBuilder::closed($trades->cancel($id, self::values($options)['offer'] ?? null), $card));
        }, $suggest);

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }
            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== PocketMessageBuilder::PREFIX) {
                return;
            }
            if (($parts[1] ?? '') === 'trade' && isset($parts[2], $parts[3])) {
                $this->button($mtg, $interaction, $parts[2], $parts[3], (int) ($parts[4] ?? 0));
            } elseif (($parts[1] ?? '') === 'tradeview') {
                self::answer($mtg, $interaction, fn () => TradeMessageBuilder::offer($this->find((string) ($interaction->data->values[0] ?? '')), $this->pocket->trades->cardData(...)));
            }
        });
    }

    /**
     * Handles a button on an offer. The offer message becomes the finished
     * trade; a refused click answers only the player who clicked.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param string      $tradeId
     * @param string      $action
     * @param int         $revision
     *
     * @return PromiseInterface
     */
    private function button(MTG $mtg, Interaction $interaction, string $tradeId, string $action, int $revision): PromiseInterface
    {
        $trades = $this->pocket->trades;
        $card = $trades->cardData(...);
        [$id, $name] = self::caller($interaction);

        return resolve(null)
            ->then(fn () => match ($action) {
                'accept' => $trades->accept($tradeId, $id, $name, $revision),
                'decline' => $trades->decline($tradeId, $id),
                'cancel' => $trades->cancel($id, $tradeId),
                default => throw new \InvalidArgumentException('Unknown trade action.'),
            })
            ->then(
                fn (TradeOffer $offer) => $interaction->updateMessage(TradeMessageBuilder::closed($offer, $card)),
                fn (\Throwable $e) => $e instanceof StaleOfferException
                    ? $interaction->updateMessage(TradeMessageBuilder::offer($e->offer, $card, '⚠️ '.$e->getMessage()))
                    : $interaction->respondWithMessage(self::failure($mtg, $e), true)
            )
            ->then(null, fn (\Throwable $e) => $mtg->logger->warning('Could not answer a trade button: '.$e->getMessage()));
    }

    private function find(string $tradeId): TradeOffer
    {
        return $this->pocket->trades->find($tradeId) ?? throw new \OutOfBoundsException('That trade offer is no longer open.');
    }

    /**
     * Autocomplete: `give` from the caller's cards, `want` from the other
     * player's, `card` for `/trade add` from whichever side is picked, and
     * `offer` from the caller's open offers.
     *
     * @param Interaction $interaction
     * @param mixed       $option
     *
     * @return array
     */
    private function suggest(Interaction $interaction, $option): array
    {
        [$id] = self::caller($interaction);
        $typed = (string) ($option->value ?? '');
        $args = self::typed($interaction);
        $trades = $this->pocket->trades;
        $owned = fn (string $playerId) => self::choices($this->pocket->collection->suggest($this->pocket->inventories->get($playerId), $typed));

        switch ($option->name ?? '') {
            case 'give':
                return $owned($id);
            case 'want':
                return isset($args['player']) ? $owned((string) $args['player']) : [];
            case 'card':
                if (($args['side'] ?? 'give') !== 'want') {
                    return $owned($id);
                }
                $sent = $trades->forPlayer($id)['sent'];
                $offer = null;
                foreach ($sent as $candidate) {
                    if ($candidate->id === ($args['offer'] ?? $candidate->id)) {
                        $offer = $candidate;
                        break;
                    }
                }

                return $offer === null ? [] : $owned($offer->to['id']);
            case 'offer':
                $choices = [];
                foreach ($trades->forPlayer($id)['sent'] as $offer) {
                    $choices[$offer->id] = "To {$offer->to['name']} · ".date('M j', $offer->createdAt);
                }

                return self::choices($choices);
            default:
                return [];
        }
    }
}
