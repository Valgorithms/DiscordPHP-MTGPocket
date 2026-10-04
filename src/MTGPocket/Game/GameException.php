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
 * An action the rules do not allow right now. The message says why and is
 * shown to the player; the game is left as it was.
 *
 * @since 0.3.0
 */
class GameException extends \InvalidArgumentException
{
}
