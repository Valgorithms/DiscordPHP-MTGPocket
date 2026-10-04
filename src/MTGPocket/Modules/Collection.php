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
use MTG\Modules\Cards;
use MTG\Modules\InteractionTrait;
use MTG\Modules\Module;
use MTG\MTG;
use MTG\Parts\Card;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Collection\CollectionPages;
use MTGPocket\Pocket;
use React\Promise\PromiseInterface;

/**
 * `/collection [set] [color] [rarity] [name] [player]`: the cards a player
 * owns, in pages, with a picker that opens any of them in DiscordPHP-MTG's
 * card view.
 *
 * The pages are {@see CollectionPages}. This module also opens the cards picked from any of the game's messages
 * (`pocket:card`).
 *
 * @since 0.2.0
 */
final class Collection implements Module
{
    use InteractionTrait;
    use PocketTrait;

    private CollectionPages $pages;

    public function __construct(protected Pocket $pocket)
    {
        $this->pages = new CollectionPages($pocket);
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'collection';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [self::command('collection', 'The Magic: The Gathering cards you own.')
            ->addOption(self::option($mtg, Option::STRING, 'set', 'Only this set.', false, true))
            ->addOption(self::colorOption($mtg, 'Only this color.'))
            ->addOption(self::withChoices($mtg, self::option($mtg, Option::STRING, 'rarity', 'Only this rarity.'), array_combine(CardPool::RARITIES, array_map('ucfirst', CardPool::RARITIES))))
            ->addOption(self::option($mtg, Option::STRING, 'name', 'Only cards whose name contains this.'))
            ->addOption(self::option($mtg, Option::USER, 'player', 'Someone else\'s collection.'))
            ->addOption(self::hidden($mtg))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $mtg->listenCommand(
            'collection',
            fn (Interaction $i, $options) => $this->show($mtg, $i, self::values($options)),
            fn (Interaction $interaction, $option) => ($option->name ?? '') === 'set'
                ? self::choices(self::setChoices(array_map(fn (CardPool $pool) => ['name' => $pool->setName], $this->pocket->pools->all()), (string) ($option->value ?? '')))
                : [],
        );

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }

            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== PocketMessageBuilder::PREFIX) {
                return;
            }

            $selected = (string) ($interaction->data->values[0] ?? '');
            match ($parts[1] ?? '') {
                'card', 'open' => self::answer($mtg, $interaction, fn () => $mtg->cards->fetch($selected)->then(fn (Card $card) => Cards::view($mtg, $card))),
                'page' => self::answer($mtg, $interaction, fn () => $this->pages->page(CollectionPages::decode($parts[2] ?? ''), (int) ($parts[3] ?? 1)), true),
                default => null,
            };
        });
    }

    /**
     * `/collection`.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function show(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        [$caller] = self::caller($interaction);
        $query = CollectionPages::query(
            (string) ($args['player'] ?? $caller),
            (string) ($args['set'] ?? ''),
            (string) ($args['color'] ?? ''),
            (string) ($args['rarity'] ?? ''),
            (string) ($args['name'] ?? ''),
        );

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $this->pages->page($query, 1));
    }
}
