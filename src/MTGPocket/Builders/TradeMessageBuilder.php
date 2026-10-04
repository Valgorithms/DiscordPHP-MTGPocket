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

namespace MTGPocket\Builders;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Option;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Parts\Channel\Message\AllowedMentions;
use MTG\Builders\CardMessageBuilder;
use MTG\Helpers\Text;
use MTGPocket\Models\CardCounts;
use MTGPocket\Trades\TradeOffer;

/**
 * Trade messages: an open offer with **Accept**, **Decline** and
 * **Cancel**, a finished one, and a player's open offers.
 *
 * Custom ids: `pocket:trade:<id>:<accept|decline|cancel>:<revision>` on an
 * offer, and `pocket:tradeview` for the picker that opens one.
 *
 * @since 0.4.0
 */
class TradeMessageBuilder extends PocketMessageBuilder
{
    /**
     * A custom id for a button on an offer.
     *
     * @param TradeOffer $offer
     * @param string     $action
     *
     * @return string
     */
    public static function id(TradeOffer $offer, string $action): string
    {
        return implode(':', [self::PREFIX, 'trade', $offer->id, $action, $offer->revision]);
    }

    /**
     * An open offer. It mentions the player it is made to.
     *
     * @param TradeOffer              $offer
     * @param callable(string): array $card  Card data by uuid.
     * @param string|null             $note  What just changed, shown above the offer.
     *
     * @return static
     */
    public static function offer(TradeOffer $offer, callable $card, ?string $note = null): static
    {
        $container = Container::new()->setAccentColor(CardMessageBuilder::ACCENTS['U']);
        if ($note !== null) {
            $container->addComponent(TextDisplay::new($note))->addComponent(Separator::new());
        }
        $container
            ->addComponent(TextDisplay::new(sprintf(
                "### 🤝 Trade offer\n<@%s>, **%s** offers you a trade.\n-# Open until <t:%d:f>. Cards a deck uses can't be traded.",
                $offer->to['id'],
                $offer->from['name'],
                $offer->expiresAt,
            )))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(self::sides($offer, $card)));

        $message = static::panel()
            ->setAllowedMentions(AllowedMentions::none()->addUser($offer->to['id']))
            ->addComponent($container);

        $cards = array_map(fn ($uuid) => $card((string) $uuid), [...array_keys($offer->give->toArray()), ...array_keys($offer->want->toArray())]);
        if ($cards !== []) {
            $message->addComponent(ActionRow::new()->addComponent(self::cardPicker($cards)));
        }

        return $message->addComponent(ActionRow::new()
            ->addComponent(Button::new(Button::STYLE_SUCCESS, self::id($offer, 'accept'))->setLabel('Accept'))
            ->addComponent(Button::new(Button::STYLE_DANGER, self::id($offer, 'decline'))->setLabel('Decline'))
            ->addComponent(Button::new(Button::STYLE_SECONDARY, self::id($offer, 'cancel'))->setLabel('Cancel offer')));
    }

    /**
     * An offer that is no longer open: what happened, and what changed hands.
     *
     * @param TradeOffer              $offer
     * @param callable(string): array $card
     *
     * @return static
     */
    public static function closed(TradeOffer $offer, callable $card): static
    {
        $what = match ($offer->status) {
            TradeOffer::ACCEPTED => "### ✅ Trade done\n**{$offer->to['name']}** accepted **{$offer->from['name']}**'s offer.",
            TradeOffer::DECLINED => "### Trade declined\n**{$offer->to['name']}** declined **{$offer->from['name']}**'s offer.",
            TradeOffer::CANCELLED => "### Trade cancelled\n**{$offer->from['name']}** called off the offer to **{$offer->to['name']}**.",
            default => "### Trade expired\n**{$offer->from['name']}**'s offer to **{$offer->to['name']}** ran out of time.",
        };

        return static::panel()->addComponent(Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS[$offer->status === TradeOffer::ACCEPTED ? 'G' : 'colorless'])
            ->addComponent(TextDisplay::new($what))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(self::sides($offer, $card))));
    }

    /**
     * A player's open offers, with a picker to open one.
     *
     * @param string                                             $playerId
     * @param array{sent: TradeOffer[], received: TradeOffer[]}  $offers
     * @param callable(string): array                            $card
     *
     * @return static
     */
    public static function list(string $playerId, array $offers, callable $card): static
    {
        $summary = function (TradeOffer $offer) use ($card): string {
            $count = fn (CardCounts $cards, int $points) => implode(' + ', array_filter([
                $cards->total() > 0 ? Text::plural($cards->total(), 'card') : '',
                $points > 0 ? self::points($points) : '',
            ])) ?: 'nothing';

            return $count($offer->give, $offer->givePoints).' for '.$count($offer->want, $offer->wantPoints);
        };

        $text = [];
        if ($offers['received'] !== []) {
            $text[] = "**Offers to you**\n".implode("\n", array_map(fn (TradeOffer $offer) => "From **{$offer->from['name']}**: ".$summary($offer)." · ends <t:{$offer->expiresAt}:R>", $offers['received']));
        }
        if ($offers['sent'] !== []) {
            $text[] = "**Your offers**\n".implode("\n", array_map(fn (TradeOffer $offer) => "To **{$offer->to['name']}**: ".$summary($offer)." · ends <t:{$offer->expiresAt}:R>", $offers['sent']));
        }

        $message = static::panel()->addComponent(Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS['U'])
            ->addComponent(TextDisplay::new("### Trades\n-# Make an offer with `/trade offer`; add to it with `/trade add`."))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new($text === [] ? 'You have no open trade offers.' : Text::clip(implode("\n\n", $text), 3500))));

        $all = [...$offers['received'], ...$offers['sent']];
        if ($all !== []) {
            $select = StringSelect::new(self::PREFIX.':tradeview')->setPlaceholder('Open an offer');
            foreach (array_slice($all, 0, 25) as $offer) {
                $other = $offer->from['id'] === $playerId ? "To {$offer->to['name']}" : "From {$offer->from['name']}";
                $select->addOption(Option::new(Text::clip($other, 100), $offer->id)->setDescription(Text::clip($summary($offer), 100)));
            }
            $message->addComponent(ActionRow::new()->addComponent($select));
        }

        return $message;
    }

    /**
     * Both sides of an offer.
     *
     * @param TradeOffer              $offer
     * @param callable(string): array $card
     *
     * @return string
     */
    private static function sides(TradeOffer $offer, callable $card): string
    {
        $side = function (CardCounts $cards, int $points) use ($card): string {
            $lines = [];
            foreach ($cards as $uuid => $count) {
                $data = $card((string) $uuid);
                $lines[] = "×{$count} ".self::cardLine($data).(isset($data['setCode']) ? " · `{$data['setCode']}`" : '');
            }
            if ($points > 0) {
                $lines[] = '🪙 '.self::points($points);
            }

            return $lines === [] ? '-# Nothing' : implode("\n", $lines);
        };

        return Text::clip(sprintf(
            "**%s gives**\n%s\n\n**%s gives**\n%s",
            $offer->from['name'],
            $side($offer->give, $offer->givePoints),
            $offer->to['name'],
            $side($offer->want, $offer->wantPoints),
        ), 3500);
    }
}
