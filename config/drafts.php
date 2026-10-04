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

/*
 * Booster drafts (`/draft`). Change them here; the bot reads this file when
 * it starts. Set MTGPOCKET_DRAFTS to use a copy kept somewhere else.
 *
 * Players pay the entry fee in points to join a pod. When the pod fills (or
 * its host starts it), everyone opens a pack, takes one card and passes the
 * rest, until every pack is empty. Then they build decks from what they
 * took, play Swiss rounds, and keep every card they drafted once their
 * matches are done or the event times out. The top finishers share a prize
 * pool made of the entry fees.
 */
return [
    // Points to join a pod; they make up the prize pool. Refunded when a
    // player leaves before the draft starts, or when a pod never gets
    // enough players.
    'entry_fee' => 1200,

    // Players in a full pod; the draft starts by itself when it fills.
    'pod_size' => 8,

    // Fewest players the host can start a pod with.
    'min_players' => 2,

    // Packs each player opens, one per pack round. Packs pass left, then
    // right, then left again. Each pack is one color of the pod's set.
    'packs' => 3,

    // Seconds a player has to take a card before the bot takes one for them.
    'pick_seconds' => 180,

    // Minutes to build a deck once the last pack is empty. Anyone who has
    // not sent in a deck by then plays one the bot builds from their picks.
    'build_minutes' => 60,

    // Fewest cards in a draft deck. Basic lands are free and unlimited.
    'deck_min' => 40,

    // Hours each Swiss round lasts. A game not finished by then is a draw.
    'round_hours' => 24,

    // Hours a pod waits for players before it starts with who it has, or,
    // with fewer than `min_players`, is called off and refunded.
    'signup_hours' => 24,

    // Days from the start of the draft until the event ends, whatever is
    // left unplayed. Everyone keeps the cards they drafted.
    'event_days' => 7,

    // The prize pool: shares of all the entry fees the pod paid, by final
    // place (1st, 2nd, ...), paid in points when the event ends. Players who
    // left before the end get none, and the next player moves up. What is
    // not paid out (10% by default, or more in a small pod) is spent.
    'prizes' => [0.4, 0.25, 0.15, 0.1],

    // The game mode whose rules the games use (starting life).
    'mode' => 'limited',
];
