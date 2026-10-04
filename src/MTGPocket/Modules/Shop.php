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
use MTGPocket\Builders\ShopMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Economy\Shop as PointsShop;
use MTGPocket\Pocket;
use React\Promise\PromiseInterface;

/**
 * The points shop: `/shop balance|price|buy-card|buy-pack|sell|sell-extras`.
 *
 * Sales and card purchases answer only the player; `balance`, `price` and
 * `buy-pack` take `hidden` like every other command, so a bought pack can be
 * opened in front of everyone like a free one.
 *
 * Custom id: `pocket:sellx:<playerId>:<keep>:<rarity letter>:<SET>` for the
 * button that confirms a bulk sale.
 *
 * @since 0.4.0
 */
final class Shop implements Module
{
    use InteractionTrait;
    use PocketTrait;

    /**
     * Rarity letters for the custom id.
     *
     * @var array<string, string>
     */
    private const array RARITY_CODES = ['c' => 'common', 'u' => 'uncommon', 'r' => 'rare', 'm' => 'mythic'];

    public function __construct(protected Pocket $pocket)
    {
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'shop';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        $count = fn (string $description) => self::option($mtg, Option::INTEGER, 'count', $description)->setMinValue(1)->setMaxValue(PointsShop::MAX_COUNT);
        $rarities = array_combine(CardPool::RARITIES, array_map('ucfirst', CardPool::RARITIES));

        return [self::command('shop', 'Sell cards you don\'t need for points, and spend points on cards and packs.')
            ->addOption(self::subcommand($mtg, 'balance', 'Your points, and what cards and packs cost.', self::hidden($mtg)))
            ->addOption(self::subcommand(
                $mtg,
                'price',
                'What a card costs to buy and pays to sell.',
                self::option($mtg, Option::STRING, 'card', 'Any card from the game\'s sets.', true, true),
                self::hidden($mtg),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'buy-card',
                'Buy a card with points.',
                self::option($mtg, Option::STRING, 'card', 'Any card from the game\'s sets.', true, true),
                $count('How many copies (default 1).'),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'buy-pack',
                'Buy a pack with points: 15 cards of one color of one set, with a rare or better.',
                self::option($mtg, Option::STRING, 'set', 'Which set; leave empty for a surprise.', false, true),
                self::colorOption($mtg, 'Which color; leave empty for a surprise.'),
                self::hidden($mtg),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'sell',
                'Sell cards you own for points. Cards your decks use are kept.',
                self::option($mtg, Option::STRING, 'card', 'A card you own.', true, true),
                $count('How many copies (default 1).'),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'sell-extras',
                'Sell every copy beyond a number of each card. Shows what sells before it does.',
                self::option($mtg, Option::INTEGER, 'keep', 'Copies of each card to keep (default 4).')->setMinValue(0)->setMaxValue(PointsShop::MAX_COUNT),
                self::withChoices($mtg, self::option($mtg, Option::STRING, 'rarity', 'Only this rarity.'), $rarities),
                self::option($mtg, Option::STRING, 'set', 'Only this set.', false, true),
            ))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $shop = $this->pocket->shop;
        $suggest = fn (Interaction $interaction, $option) => $this->suggest($interaction, $option);

        $mtg->listenCommand(['shop', 'balance'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id) => ShopMessageBuilder::balance(
            $shop->balance($id),
            $shop->prices,
            $shop->packPrices(),
        ), false));
        $mtg->listenCommand(['shop', 'price'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($shop) {
            $quote = $shop->quote((string) $args['card']);

            return ShopMessageBuilder::quote($quote, $this->pocket->inventories->get($id)->cards->get($quote['card']['uuid']), $shop->balance($id));
        }, false), $suggest);
        $mtg->listenCommand(['shop', 'buy-card'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => ShopMessageBuilder::receipt(
            $shop->buyCard($id, $name, (string) $args['card'], (int) ($args['count'] ?? 1)),
            false,
        )), $suggest);
        $mtg->listenCommand(['shop', 'buy-pack'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => PocketMessageBuilder::pack(
            $shop->buyPack($id, $name, $args['set'] ?? null, $args['color'] ?? null),
        ), false), $suggest);
        $mtg->listenCommand(['shop', 'sell'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => ShopMessageBuilder::receipt(
            $shop->sell($id, $name, (string) $args['card'], (int) ($args['count'] ?? 1)),
            true,
        )), $suggest);
        $mtg->listenCommand(['shop', 'sell-extras'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($shop) {
            $keep = (int) ($args['keep'] ?? 4);
            $rarity = (string) ($args['rarity'] ?? '');
            $set = strtoupper(trim((string) ($args['set'] ?? '')));

            return ShopMessageBuilder::extras($id, $shop->extras($id, $keep, $rarity, $set), $this->pocket->trades->cardData(...), $keep, $rarity, $set);
        }), $suggest);

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg, $shop): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }
            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== PocketMessageBuilder::PREFIX || ($parts[1] ?? '') !== 'sellx') {
                return;
            }

            [$id, $name] = self::caller($interaction);
            self::answer($mtg, $interaction, function () use ($shop, $parts, $id, $name) {
                if (($parts[2] ?? '') !== $id) {
                    throw new \InvalidArgumentException('Only the player who asked can sell these cards.');
                }

                return ShopMessageBuilder::receipt($shop->sellExtras(
                    $id,
                    $name,
                    (int) ($parts[3] ?? 4),
                    self::RARITY_CODES[$parts[4] ?? ''] ?? null,
                    preg_match('/^[A-Z0-9]{1,8}$/', $parts[5] ?? '') ? $parts[5] : null,
                ), true);
            }, true);
        });
    }

    /**
     * Runs a sub-command for the caller and answers with what it returns.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param iterable    $options
     * @param callable(string, string, array): \Discord\Builders\MessageBuilder $work Gets the caller's id, name and the option values.
     * @param bool        $private     Always answer only the caller; otherwise follow `hidden`.
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
     * Autocomplete: `card` is any card for `price` and `buy-card` and the
     * caller's own cards for `sell`; `set` is the sets with packs for
     * `buy-pack` and every set for `sell-extras`.
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
        $subcommand = '';
        foreach ($interaction->data->options ?? [] as $sub) {
            $subcommand = (string) ($sub->name ?? '');
            break;
        }

        if (($option->name ?? '') === 'set') {
            $sets = $subcommand === 'buy-pack'
                ? array_map(fn (array $pack) => ['name' => "{$pack['name']} · ".number_format($pack['price']).' points'], array_reverse($this->pocket->shop->packPrices(), true))
                : array_map(fn (CardPool $pool) => ['name' => $pool->setName], $this->pocket->pools->all());

            return self::choices(self::setChoices($sets, $typed));
        }
        if (($option->name ?? '') !== 'card') {
            return [];
        }

        return self::choices($subcommand === 'sell'
            ? $this->pocket->collection->suggest($this->pocket->inventories->get($id), $typed)
            : $this->pocket->shop->suggest($typed));
    }
}
