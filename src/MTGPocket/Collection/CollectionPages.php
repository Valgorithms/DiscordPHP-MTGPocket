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

namespace MTGPocket\Collection;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\MessageBuilder;
use MTG\Builders\ListMessageBuilder;
use MTG\Helpers\Text;
use MTGPocket\Builders\MenuMessageBuilder;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Pocket;

/**
 * Pages of a player's collection, filtered by set, color, rarity and name.
 *
 * A page's buttons carry the whole query in their custom id
 * (`pocket:page:<query>:<page>`), so they keep working after a restart.
 * Under the page sit **Filter** (a form for the query) and **Menu**.
 *
 * @since 0.6.0
 */
final class CollectionPages
{
    /**
     * Rarity letters for the custom id.
     *
     * @var array<string, string>
     */
    public const array RARITY_CODES = ['c' => 'common', 'u' => 'uncommon', 'r' => 'rare', 'm' => 'mythic'];

    /** Longest name filter kept, so the query fits in custom ids. */
    public const int NAME_BYTES = 24;

    public function __construct(private Pocket $pocket)
    {
    }

    /**
     * A query from loose values.
     *
     * @param string $player
     * @param string $set
     * @param string $color
     * @param string $rarity
     * @param string $name
     *
     * @return array{player: string, set: string, color: string, rarity: string, name: string}
     */
    public static function query(string $player, string $set = '', string $color = '', string $rarity = '', string $name = ''): array
    {
        $set = strtoupper(trim($set));

        return [
            'player' => $player,
            'set' => preg_match('/^[A-Z0-9]{0,8}$/', $set) ? $set : '',
            'color' => in_array($color, CardPool::COLORS, true) ? $color : '',
            'rarity' => in_array($rarity, CardPool::RARITIES, true) ? $rarity : '',
            'name' => mb_strcut(trim($name), 0, self::NAME_BYTES),
        ];
    }

    /**
     * A page of a collection.
     *
     * @param array|null $query As {@see query()} builds it; null when a custom id did not parse.
     * @param int        $page
     *
     * @return MessageBuilder
     */
    public function page(?array $query, int $page): MessageBuilder
    {
        if ($query === null || $query['player'] === '') {
            return PocketMessageBuilder::notice('This collection can no longer be shown. Run `/collection` again.');
        }

        $inventory = $this->pocket->inventories->get($query['player']);
        $entries = $this->pocket->collection->entries($inventory, $query);
        $owner = $this->pocket->players->find($query['player'])?->name ?: 'Player';
        $tools = ActionRow::new()
            ->addComponent(MenuMessageBuilder::button($query['player'], '🔍 Filter', 'cfilt', self::filters($query)))
            ->addComponent(MenuMessageBuilder::menuButton($query['player']));

        $filters = array_filter([
            $query['set'],
            PocketMessageBuilder::COLOR_NAMES[$query['color']] ?? '',
            ucfirst($query['rarity']),
            $query['name'] !== '' ? "“{$query['name']}”" : '',
        ]);
        $title = "{$owner}'s collection".($filters ? ' — '.implode(' · ', $filters) : '');

        if ($entries === []) {
            return PocketMessageBuilder::notice("### {$title}\n".($inventory->cards->total() === 0 ? 'No cards yet. Open a free pack with `/pack open` or the **Packs** menu.' : 'No cards match.'))
                ->addComponent($tools);
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
        )->addComponent($tools);
    }

    /**
     * A query as it rides in a custom id: `player.SET.C.r.name`, the name in
     * URL-safe base64 (no `:` or `.`).
     *
     * @param array $query
     *
     * @return string
     */
    public static function encode(array $query): string
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
     * A query without its player, for ids that already carry the player.
     *
     * @param array $query
     *
     * @return string
     */
    public static function filters(array $query): string
    {
        return substr(self::encode($query), strlen($query['player']) + 1);
    }

    /**
     * @param string $encoded
     *
     * @return array|null
     */
    public static function decode(string $encoded): ?array
    {
        $fields = explode('.', $encoded);
        if (count($fields) !== 5 || ! ctype_digit($fields[0])) {
            return null;
        }
        [$player, $set, $color, $rarity, $name] = $fields;

        return self::query($player, $set, $color, self::RARITY_CODES[$rarity] ?? '', (string) base64_decode(strtr($name, '-_', '+/')));
    }
}
