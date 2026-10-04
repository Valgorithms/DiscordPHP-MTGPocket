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

use Discord\Parts\Interactions\Interaction;
use MTG\Modules\InteractionTrait;
use MTG\Modules\Module;
use MTG\MTG;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Pocket;

/**
 * Daily and weekly quests: `/quests`.
 *
 * @since 0.4.0
 */
final class Quests implements Module
{
    use InteractionTrait;
    use PocketTrait;

    public function __construct(protected Pocket $pocket)
    {
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'quests';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [self::command('quests', 'Your daily and weekly quests, which pay points.')->addOption(self::hidden($mtg))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $mtg->listenCommand('quests', function (Interaction $interaction, $options) use ($mtg) {
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, (bool) (self::values($options)['hidden'] ?? false), fn () => PocketMessageBuilder::quests(
                $this->pocket->quests->board($id),
                $this->pocket->shop->balance($id),
            ));
        });
    }
}
