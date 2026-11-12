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

namespace MTGPocket\Game;

/**
 * Every "you may …" decision a resolving ability can ask of its controller.
 *
 * Each kind is an effect type the parser emits and, unchanged, the `type` of
 * the pending choice it opens: accept or decline, then the rest of the ability
 * resolves. The engine, the saved-game validator, the Discord panel and the
 * practice bot all read this one table, so a new kind is one entry here plus
 * the parser wording that emits it.
 *
 * Entry fields:
 *  - `action`      Panel action in Discord custom ids; keep it stable so
 *                  buttons on already-sent messages still work.
 *  - `since`       First saved-game schema version that may hold the choice.
 *  - `name`        Noun used in saved-game validation errors.
 *  - `unavailable` Error for a stale, repeated or wrong-player answer.
 *  - `targeted`    Whether the choice carries the ability's target opponent.
 *  - `cost`        Mana paid on accepting, or null. `unpayable` is its error.
 *  - `sourceOnly`  Accepting only does something while the source is still
 *                  the same battlefield incarnation; it is then also `$self`.
 *  - `effects`     Ordinary effects applied on accepting, or
 *  - `steps`       ordinary effects resolved first in the rest of the ability
 *                  (for effects that open a decision of their own).
 *  - `status`, `prompt`, `accept`, `decline` Player-facing text. `:label` is
 *                  the source's name, `:counter` the counter kind and
 *                  `:target` the targeted player's name.
 *
 * In `effects`, the value `:counter` is replaced by the choice's counter kind.
 *
 * @since 0.8.0
 */
final class OptionalChoices
{
    public const array KINDS = [
        'optional_life_loss' => [
            'action' => 'maylife', 'since' => 8, 'name' => 'life', 'targeted' => true, 'cost' => null, 'sourceOnly' => false,
            'unavailable' => 'That optional life-loss choice is no longer available to you.',
            'effects' => [['type' => 'lose_life', 'amount' => 1]],
            'status' => 'choose whether the targeted opponent loses 1 life',
            'prompt' => '**:label**: you may have **:target** lose 1 life.',
            'accept' => 'Opponent loses 1 life', 'decline' => 'Decline',
        ],
        'optional_life_gain' => [
            'action' => 'maygain', 'since' => 9, 'name' => 'life', 'targeted' => false, 'cost' => null, 'sourceOnly' => false,
            'unavailable' => 'That optional life-gain choice is no longer available to you.',
            'effects' => [['type' => 'gain_life', 'amount' => 5]],
            'status' => 'choose whether to gain 5 life',
            'prompt' => '**:label**: you may gain 5 life. This does not target a player.',
            'accept' => 'Gain 5 life', 'decline' => 'Decline',
        ],
        'optional_source_counter' => [
            'action' => 'maycounter', 'since' => 10, 'name' => 'source-counter', 'targeted' => false, 'cost' => null, 'sourceOnly' => true,
            'unavailable' => 'That optional source-counter choice is no longer available to you.',
            'effects' => [['type' => 'counters', 'amount' => 1, 'self' => true, 'kind' => ':counter']],
            'status' => 'choose whether to put a :counter counter on :label',
            'prompt' => '**:label**: you may put a :counter counter on this permanent. Only the original battlefield incarnation can receive it; this does not target.',
            'accept' => 'Put a :counter counter', 'decline' => 'Decline',
        ],
        'optional_source_untap' => [
            'action' => 'mayuntap', 'since' => 11, 'name' => 'source-untap', 'targeted' => false, 'cost' => null, 'sourceOnly' => true,
            'unavailable' => 'That optional source-untap choice is no longer available to you.',
            'effects' => [['type' => 'untap', 'self' => true]],
            'status' => 'choose whether to untap :label',
            'prompt' => '**:label**: you may untap this artifact. Only the original battlefield incarnation can be untapped; this does not target.',
            'accept' => 'Untap', 'decline' => 'Decline',
        ],
        'optional_red_goblin' => [
            'action' => 'maygoblin', 'since' => 12, 'name' => 'red-payment', 'targeted' => false, 'cost' => '{R}', 'sourceOnly' => false,
            'unavailable' => 'That optional red-payment choice is no longer available to you.',
            'unpayable' => 'You cannot pay {R} for that Goblin.',
            'effects' => [['type' => 'token', 'amount' => 1, 'haste' => true, 'token' => [
                'name' => 'Goblin Token', 'type' => 'Token Creature — Goblin', 'types' => ['Creature'],
                'subtypes' => ['Goblin'], 'colors' => ['R'], 'power' => '1', 'toughness' => '1', 'text' => '', 'manaCost' => null,
            ]]],
            'status' => 'choose whether to pay {R} for a Goblin from :label',
            'prompt' => '**:label**: you may pay {R} to create a 1/1 red Goblin creature token. It gains haste until end of turn. This does not target.',
            'accept' => 'Pay {R}: create a Goblin', 'decline' => 'Decline',
        ],
        'optional_death_draw' => [
            'action' => 'maydraw', 'since' => 13, 'name' => 'death-draw', 'targeted' => false, 'cost' => null, 'sourceOnly' => false,
            'unavailable' => 'That optional death-draw choice is no longer available to you.',
            'effects' => [['type' => 'draw', 'amount' => 1]],
            'status' => 'choose whether to draw a card',
            'prompt' => '**:label**: you may draw a card. This does not target a player; declining does not draw.',
            'accept' => 'Draw a card', 'decline' => 'Decline',
        ],
        'optional_basic_land_search' => [
            'action' => 'maysearch', 'since' => 14, 'name' => 'basic-land-search', 'targeted' => false, 'cost' => null, 'sourceOnly' => false,
            'unavailable' => 'That optional basic-land search is no longer available to you.',
            'steps' => [['type' => 'tutor', 'filter' => 'basic_land', 'to' => 'tapped']],
            'status' => 'choose whether to search for a basic land',
            'prompt' => '**:label**: search for a basic land to enter tapped, then shuffle, or decline without searching or shuffling. If you search, you may fail to find.',
            'accept' => 'Search library', 'decline' => 'Decline search',
        ],
    ];

    /**
     * Whether an effect or choice type is one of these decisions.
     *
     * @param mixed $type
     *
     * @return bool
     */
    public static function has(mixed $type): bool
    {
        return is_string($type) && isset(self::KINDS[$type]);
    }

    /**
     * The kind a panel action answers, or null when it is not one of these.
     *
     * @param string $action
     *
     * @return string|null
     */
    public static function kindForAction(string $action): ?string
    {
        foreach (self::KINDS as $type => $kind) {
            if ($kind['action'] === $action) {
                return $type;
            }
        }

        return null;
    }

    /**
     * One of a kind's player-facing texts, filled in for a pending choice.
     *
     * @param array  $choice The pending choice, as from Game::choiceAwaiting().
     * @param string $field  `status`, `prompt`, `accept` or `decline`.
     * @param string $target The targeted player's name, for targeted kinds.
     *
     * @return string
     */
    public static function text(array $choice, string $field, string $target = ''): string
    {
        return strtr(self::KINDS[$choice['type']][$field], [
            ':label' => (string) ($choice['label'] ?? ''),
            ':counter' => (string) ($choice['counterKind'] ?? '+1/+1'),
            ':target' => $target,
        ]);
    }

    /**
     * A kind's effects with the choice's counter kind filled in.
     *
     * @param array $choice The pending choice.
     *
     * @return array[]
     */
    public static function effects(array $choice): array
    {
        return array_map(
            fn (array $effect) => array_map(fn (mixed $value) => $value === ':counter' ? ($choice['counterKind'] ?? '+1/+1') : $value, $effect),
            self::KINDS[$choice['type']]['effects'] ?? []
        );
    }
}
