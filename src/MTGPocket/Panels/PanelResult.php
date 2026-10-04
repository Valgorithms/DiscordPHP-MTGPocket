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

namespace MTGPocket\Panels;

use Discord\Builders\MessageBuilder;

/**
 * What a click or a sent form leads to: the panel to show, or a form to
 * pop up, and a message for everyone in the channel (a challenge, a trade
 * offer, a board) when the action concerns other players too.
 *
 * @since 0.6.0
 */
final class PanelResult
{
    /**
     * @param MessageBuilder|null $panel    Replaces the clicked panel (or answers only the clicker when it is not theirs).
     * @param Modal|null          $modal    A form to pop up instead.
     * @param MessageBuilder|null $announce Posted in the channel for everyone.
     * @param bool                $separate Send the panel as a new private message instead of replacing the clicked one.
     */
    public function __construct(
        public readonly ?MessageBuilder $panel = null,
        public readonly ?Modal $modal = null,
        public readonly ?MessageBuilder $announce = null,
        public readonly bool $separate = false,
    ) {
    }
}
