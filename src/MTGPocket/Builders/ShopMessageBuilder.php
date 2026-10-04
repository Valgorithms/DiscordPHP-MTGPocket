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
use Discord\Builders\Components\MediaGallery;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use MTG\Builders\CardMessageBuilder;
use MTG\Helpers\Text;
use MTGPocket\Cards\CardPool;
use MTGPocket\Economy\PriceList;
use MTGPocket\Economy\Receipt;

/**
 * The shop's messages: a player's points and the price list, a card's
 * price, what a sale or purchase did, and the cards a bulk sale would sell.
 *
 * Custom id: `pocket:sellx:<playerId>:<keep>:<rarity letter>:<SET>` confirms
 * a bulk sale.
 *
 * @since 0.4.0
 */
class ShopMessageBuilder extends PocketMessageBuilder
{
    /**
     * A player's points, what cards cost, and what each set's packs cost.
     *
     * @param int                                                  $points
     * @param PriceList                                            $prices
     * @param array<string, array{name: string, price: int, colors: string[]}> $packs By set code, newest first.
     *
     * @return static
     */
    public static function balance(int $points, PriceList $prices, array $packs): static
    {
        $rarities = implode(' · ', array_map(fn (string $rarity) => ucfirst($rarity).' '.number_format($prices->buy[$rarity]), CardPool::RARITIES));
        $ages = [];
        foreach ($prices->age as $tier) {
            $ages[] = '×'.self::number($tier['multiplier']).' '.($tier['days'] === null ? 'older' : 'up to '.self::age($tier['days']).' old');
        }

        $lines = [];
        foreach ($packs as $code => $pack) {
            $lines[] = "`{$code}` {$pack['name']} — ".self::points($pack['price']);
        }

        return static::panel()->addComponent(Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS['multicolor'])
            ->addComponent(TextDisplay::new(sprintf(
                "### 🪙 Shop\nYou have **%s**.\n-# Earn points by selling cards you don't need (`/shop sell`, `/shop sell-extras`); spend them on cards (`/shop buy-card`) and packs (`/shop buy-pack`).",
                self::points($points),
            )))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(sprintf(
                "**Cards** %s\nNewer sets cost more: %s.\nSelling a card pays %d%% of what it costs to buy.",
                $rarities,
                implode(', ', $ages),
                (int) round($prices->sellRate * 100),
            )))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new($lines === [] ? 'No packs yet: no card pools have been imported.' : Text::clip("**Packs** (15 cards of one color, with a rare or better)\n".implode("\n", $lines), 3000))));
    }

    /**
     * What a card costs and pays.
     *
     * @param array{card: array, buy: int, sell: int} $quote
     * @param int                                     $owned   Copies the player owns.
     * @param int                                     $points  The player's points.
     *
     * @return static
     */
    public static function quote(array $quote, int $owned, int $points): static
    {
        $card = $quote['card'];
        $container = Container::new()
            ->setAccentColor(self::accent(CardPool::colorOf($card['colors'] ?? [])))
            ->addComponent(TextDisplay::new(sprintf(
                "%s\n-# %s · %s\nBuy for **%s** · sell for **%s**\n-# You own %d · you have %s.",
                self::cardLine($card),
                $card['setName'] ?? '',
                ucfirst($card['rarity']),
                self::points($quote['buy']),
                self::points($quote['sell']),
                $owned,
                self::points($points),
            )));
        if ($url = self::imageUrl($card)) {
            $container->addComponent(MediaGallery::new()->addItem($url, Text::clip($card['name'], 1024)));
        }

        return static::panel()->addComponent($container);
    }

    /**
     * What a sale or a card purchase did.
     *
     * @param Receipt $receipt
     * @param bool    $sold    A sale rather than a purchase.
     *
     * @return static
     */
    public static function receipt(Receipt $receipt, bool $sold): static
    {
        $lines = array_map(fn (array $line) => "×{$line['count']} ".self::cardLine($line['card']).' — '.self::points($line['points']), $receipt->lines);

        return static::panel()
            ->addComponent(Container::new()
                ->setAccentColor(CardMessageBuilder::ACCENTS[$sold ? 'G' : 'U'])
                ->addComponent(TextDisplay::new(sprintf(
                    "### %s\n-# You now have %s.",
                    $sold
                        ? 'Sold '.Text::plural($receipt->cards(), 'card').' for '.self::points($receipt->total)
                        : 'Bought '.Text::plural($receipt->cards(), 'card').' for '.self::points($receipt->total),
                    self::points($receipt->balance),
                )))
                ->addComponent(Separator::new())
                ->addComponent(TextDisplay::new(Text::clip(implode("\n", $lines), 3500))))
            ->addComponent(ActionRow::new()->addComponent(self::cardPicker(array_column($receipt->lines, 'card'))));
    }

    /**
     * The cards a bulk sale would sell, with a button to sell them.
     *
     * @param string                                         $playerId
     * @param array{cards: array<string, int>, points: int}  $extras
     * @param callable(string): array                        $card     Card data by uuid.
     * @param int                                            $keep
     * @param string                                         $rarity   '' for any.
     * @param string                                         $set      '' for any.
     *
     * @return static
     */
    public static function extras(string $playerId, array $extras, callable $card, int $keep, string $rarity, string $set): static
    {
        $what = trim(implode(' ', array_filter([$set, $rarity === '' ? '' : ucfirst($rarity)])).' cards');
        if ($extras['cards'] === []) {
            return static::notice("You have no {$what} to sell beyond {$keep} of each and what your decks use.");
        }

        $lines = [];
        foreach ($extras['cards'] as $uuid => $count) {
            $lines[] = "×{$count} ".self::cardLine($card((string) $uuid));
        }
        $copies = array_sum($extras['cards']);

        return static::panel()
            ->addComponent(Container::new()
                ->setAccentColor(CardMessageBuilder::ACCENTS['G'])
                ->addComponent(TextDisplay::new(sprintf(
                    "### Sell %s for %s?\n-# Every copy of your %s beyond %d of each and beyond what your decks use.",
                    Text::plural($copies, 'card'),
                    self::points($extras['points']),
                    $what,
                    $keep,
                )))
                ->addComponent(Separator::new())
                ->addComponent(TextDisplay::new(Text::clip(implode("\n", $lines), 3500))))
            ->addComponent(ActionRow::new()->addComponent(
                Button::new(Button::STYLE_DANGER, self::sellExtrasId($playerId, $keep, $rarity, $set))->setLabel('Sell '.Text::plural($copies, 'card'))
            ));
    }

    /**
     * The custom id of the bulk sale button.
     *
     * @param string $playerId
     * @param int    $keep
     * @param string $rarity
     * @param string $set
     *
     * @return string
     */
    public static function sellExtrasId(string $playerId, int $keep, string $rarity, string $set): string
    {
        return implode(':', [self::PREFIX, 'sellx', $playerId, $keep, $rarity === '' ? '' : $rarity[0], $set]);
    }

    /**
     * A multiplier without trailing zeros: 2, 1.5, 0.75.
     *
     * @param float $number
     *
     * @return string
     */
    private static function number(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * Days as years when they are whole years.
     *
     * @param int $days
     *
     * @return string
     */
    private static function age(int $days): string
    {
        return $days % 365 === 0 ? Text::plural(intdiv($days, 365), 'year') : Text::plural($days, 'day');
    }
}
