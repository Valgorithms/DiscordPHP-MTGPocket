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

use Discord\Parts\Interactions\Command\Choice;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use MTG\MTG;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Cards\CardPool;

/**
 * What the game's modules share on top of DiscordPHP-MTG's
 * {@see \MTG\Modules\InteractionTrait}.
 *
 * @since 0.2.0
 */
trait PocketTrait
{
    /**
     * The caller's Discord user id and display name.
     *
     * @param Interaction $interaction
     *
     * @return array{0: string, 1: string}
     */
    protected static function caller(Interaction $interaction): array
    {
        $user = $interaction->user;

        return [(string) $user?->id, (string) ($user?->displayname ?? '')];
    }

    /**
     * Adds fixed choices to an option.
     *
     * @param MTG                   $mtg
     * @param Option                $option
     * @param array<string, string> $choices Value => label.
     *
     * @return Option
     */
    protected static function withChoices(MTG $mtg, Option $option, array $choices): Option
    {
        foreach ($choices as $value => $label) {
            $option->addChoice(Choice::new($mtg, $label, (string) $value));
        }

        return $option;
    }

    /**
     * A `color` option with the seven pack colors.
     *
     * @param MTG    $mtg
     * @param string $description
     *
     * @return Option
     */
    protected static function colorOption(MTG $mtg, string $description): Option
    {
        return self::withChoices($mtg, self::option($mtg, Option::STRING, 'color', $description), PocketMessageBuilder::COLOR_NAMES);
    }

    /**
     * Autocomplete choices for imported sets: code => "Name (CODE)".
     *
     * @param array<string, array{name: string}> $sets By code.
     * @param string                             $typed
     *
     * @return array<string, string>
     */
    protected static function setChoices(array $sets, string $typed): array
    {
        $typed = mb_strtolower(trim($typed));
        $choices = [];
        foreach (array_reverse($sets, true) as $code => $set) {
            if ($typed === '' || str_contains(mb_strtolower((string) $code), $typed) || str_contains(mb_strtolower($set['name']), $typed)) {
                $choices[(string) $code] = "{$set['name']} ({$code})";
            }
        }

        return $choices;
    }

    /**
     * Whether a color letter is one of {@see CardPool::COLORS}.
     *
     * @param string $color
     *
     * @return bool
     */
    protected static function isColor(string $color): bool
    {
        return in_array($color, CardPool::COLORS, true);
    }

    /**
     * A user's display name from the command's resolved data.
     *
     * @param Interaction $interaction
     * @param string      $userId
     * @param string      $fallback    When the name is not there.
     *
     * @return string
     */
    protected static function userName(Interaction $interaction, string $userId, string $fallback = 'Player'): string
    {
        try {
            $resolved = $interaction->data->resolved ?? null;
            $member = $resolved?->members?->get('id', $userId);
            $user = $resolved?->users?->get('id', $userId);

            return (string) ($member?->nick ?? $user?->global_name ?? $user?->username ?? $fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
