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

use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Event;
use MTG\Builders\ListMessageBuilder;
use MTG\Helpers\Text;
use MTG\Modules\Cards;
use MTG\Modules\InteractionTrait;
use MTG\Modules\Module;
use MTG\MTG;
use MTG\Parts\Card;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Pocket;
use React\Promise\PromiseInterface;

/**
 * `/collection [set] [color] [rarity] [name] [player]`: the cards a player
 * owns, in pages, with a picker that opens any of them in DiscordPHP-MTG's
 * card view.
 *
 * The page buttons carry the whole query in their custom id
 * (`pocket:page:<query>:<page>`), so they keep working after a restart.
 * This module also opens the cards picked from any of the game's messages
 * (`pocket:card`).
 *
 * @since 0.2.0
 */
final class Collection implements Module
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
                'page' => self::answer($mtg, $interaction, fn () => $this->page($mtg, self::decode($parts[2] ?? ''), (int) ($parts[3] ?? 1)), true),
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
        $query = [
            'player' => (string) ($args['player'] ?? $caller),
            'set' => strtoupper(trim((string) ($args['set'] ?? ''))),
            'color' => (string) ($args['color'] ?? ''),
            'rarity' => (string) ($args['rarity'] ?? ''),
            // Kept short, so the query fits in the page buttons' custom ids.
            'name' => mb_strcut(trim((string) ($args['name'] ?? '')), 0, 24),
        ];

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $this->page($mtg, $query, 1));
    }

    /**
     * A page of a collection.
     *
     * @param MTG        $mtg
     * @param array|null $query As {@see show()} builds it; null when a custom id did not parse.
     * @param int        $page
     *
     * @return MessageBuilder
     */
    private function page(MTG $mtg, ?array $query, int $page): MessageBuilder
    {
        if ($query === null || $query['player'] === '') {
            return PocketMessageBuilder::notice('This collection can no longer be shown. Run `/collection` again.');
        }

        $inventory = $this->pocket->inventories->get($query['player']);
        $entries = $this->pocket->collection->entries($inventory, $query);
        $owner = $this->pocket->players->find($query['player'])?->name ?: 'Player';

        $filters = array_filter([
            $query['set'],
            PocketMessageBuilder::COLOR_NAMES[$query['color']] ?? '',
            ucfirst($query['rarity']),
            $query['name'] !== '' ? "“{$query['name']}”" : '',
        ]);
        $title = "{$owner}'s collection".($filters ? ' — '.implode(' · ', $filters) : '');

        if ($entries === []) {
            return PocketMessageBuilder::notice("### {$title}\n".($inventory->cards->total() === 0 ? 'No cards yet. Open a free pack with `/pack open`.' : 'No cards match.'));
        }

        $total = count($entries);
        $page = min(max(1, $page), ListMessageBuilder::pages($total));
        $copies = array_sum(array_column($entries, 'count'));
        $lines = $choices = [];
        foreach (array_slice($entries, ($page - 1) * ListMessageBuilder::PAGE_SIZE, ListMessageBuilder::PAGE_SIZE) as $entry) {
            $card = $entry['card'];
            $lines[] = "×{$entry['count']} ".PocketMessageBuilder::cardLine($card)." · `{$card['setCode']}`";
            $choices[] = ['label' => $card['name'], 'value' => $card['uuid'], 'description' => ucfirst($card['rarity'])." · {$card['setName']}"];
        }

        return ListMessageBuilder::page(
            PocketMessageBuilder::PREFIX,
            self::encode($query),
            $page,
            $total,
            "{$title} · ".Text::plural($copies, 'copy', 'copies'),
            $lines,
            $choices,
            PocketMessageBuilder::accent($query['color'] ?: CardPool::MULTICOLOR),
            'card',
        );
    }

    /**
     * A query as it rides in a custom id: `player.SET.C.r.name`, the name in
     * URL-safe base64 (no `:` or `.`).
     *
     * @param array $query
     *
     * @return string
     */
    private static function encode(array $query): string
    {
        return implode('.', [
            $query['player'],
            $query['set'],
            $query['color'],
            (string) array_search($query['rarity'], self::RARITY_CODES, true),
            rtrim(strtr(base64_encode($query['name']), '+/', '-_'), '='),
        ]);
    }

    /**
     * @param string $encoded
     *
     * @return array|null
     */
    private static function decode(string $encoded): ?array
    {
        $fields = explode('.', $encoded);
        if (count($fields) !== 5 || ! ctype_digit($fields[0])) {
            return null;
        }
        [$player, $set, $color, $rarity, $name] = $fields;

        return [
            'player' => $player,
            'set' => preg_match('/^[A-Z0-9]{0,8}$/', $set) ? $set : '',
            'color' => self::isColor($color) ? $color : '',
            'rarity' => self::RARITY_CODES[$rarity] ?? '',
            'name' => (string) base64_decode(strtr($name, '-_', '+/')),
        ];
    }
}
