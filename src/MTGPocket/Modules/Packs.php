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
use MTG\Modules\InteractionTrait;
use MTG\Modules\Module;
use MTG\MTG;
use MTGPocket\Packs\DailyPackUnavailableException;
use MTGPocket\Panels\Panels;
use MTGPocket\Pocket;
use React\Promise\PromiseInterface;

/**
 * Free daily packs: `/pack open [set] [color]` and `/pack list`.
 *
 * @since 0.2.0
 */
final class Packs implements Module
{
    use InteractionTrait;
    use PocketTrait;

    private Panels $panels;

    public function __construct(protected Pocket $pocket)
    {
        $this->panels = new Panels($pocket);
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'packs';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [self::command('pack', 'Open your free daily pack of Magic: The Gathering cards.')
            ->addOption(self::subcommand(
                $mtg,
                'open',
                'Open today\'s free pack: 15 cards of one color of one set, with a rare or better.',
                self::option($mtg, Option::STRING, 'set', 'Which set; leave empty for a surprise.', false, true),
                self::colorOption($mtg, 'Which color; leave empty for a surprise.'),
                self::hidden($mtg),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'list',
                'The sets and colors you can open, and when your next free pack is ready.',
                self::hidden($mtg),
            ))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $suggest = function (Interaction $interaction, $option): array {
            if (($option->name ?? '') !== 'set') {
                return [];
            }
            $choices = $this->pocket->dailyPacks->choices();
            $color = (string) (self::typed($interaction)['color'] ?? '');
            if ($color !== '') {
                $choices = array_filter($choices, fn (array $choice) => in_array($color, $choice['colors'], true));
            }

            return self::choices(self::setChoices($choices, (string) ($option->value ?? '')));
        };

        $mtg->listenCommand(['pack', 'open'], fn (Interaction $i, $options) => $this->open($mtg, $i, self::values($options)), $suggest);
        $mtg->listenCommand(['pack', 'list'], fn (Interaction $i, $options) => $this->list($mtg, $i, self::values($options)));
    }

    /**
     * `/pack open`.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function open(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        [$id, $name] = self::caller($interaction);

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), function () use ($id, $name, $args) {
            try {
                return $this->panels->opened($id, $this->pocket->dailyPacks->open($id, $name, $args['set'] ?? null, $args['color'] ?? null), '', '');
            } catch (DailyPackUnavailableException $e) {
                return $this->panels->packs($id, (string) ($args['set'] ?? ''), (string) ($args['color'] ?? ''), '⏳ '.$e->getMessage());
            }
        });
    }

    /**
     * `/pack list`.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function list(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        [$id] = self::caller($interaction);

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $this->panels->packs($id));
    }
}
