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
use Discord\WebSockets\Event;
use MTG\Modules\InteractionTrait;
use MTG\Modules\Module;
use MTG\MTG;
use MTGPocket\Builders\MenuMessageBuilder;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Panels\PanelResult;
use MTGPocket\Panels\Panels;
use MTGPocket\Pocket;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * `/menu`, the home panel, and every panel's buttons, menus and forms
 * (custom ids `pocket:ui:<owner>:…`; see {@see Panels}).
 *
 * A panel's owner changes it in place. Anyone else who clicks gets the
 * same panel for themselves, privately, so a panel posted in public never
 * changes under its owner. What concerns other players (a challenge, a
 * trade offer, a draft pod, a board) is posted in the channel as well.
 *
 * @since 0.6.0
 */
final class Menu implements Module
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
        return 'menu';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [self::command('menu', 'Everything in one place: packs, collection, decks, games, drafts, the shop, trades and quests.')];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $mtg->listenCommand('menu', function (Interaction $interaction) use ($mtg) {
            [$id, $name] = self::caller($interaction);

            return self::reply($mtg, $interaction, true, fn () => $this->panels->home($id, $name));
        });

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if (! in_array($interaction->type, [Interaction::TYPE_MESSAGE_COMPONENT, Interaction::TYPE_MODAL_SUBMIT], true)) {
                return;
            }
            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== PocketMessageBuilder::PREFIX || ($parts[1] ?? '') !== MenuMessageBuilder::UI) {
                return;
            }
            $this->respond($mtg, $interaction);
        });
    }

    /**
     * Runs a panel action and answers with its panel, form or post.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     *
     * @return PromiseInterface
     */
    private function respond(MTG $mtg, Interaction $interaction): PromiseInterface
    {
        [$id, $name] = self::caller($interaction);
        $customId = (string) $interaction->data->custom_id;
        $modal = $interaction->type === Interaction::TYPE_MODAL_SUBMIT;
        $values = $modal ? [] : array_map('strval', (array) ($interaction->data->values ?? []));
        $fields = $modal ? self::fields($interaction) : [];

        // A form keeps the id of whoever opened it; the panel under it may be someone else's.
        $owner = $modal ? self::messageOwner($interaction) : (explode(':', $customId)[2] ?? null);
        $names = [];
        foreach ($values as $value) {
            if (ctype_digit($value)) {
                $names[$value] = self::userName($interaction, $value, '');
            }
        }
        $names = array_filter($names);

        return resolve(null)
            ->then(fn () => $this->panels->handle($customId, $id, $name, $values, $fields, $interaction->channel_id === null ? null : (string) $interaction->channel_id, $names))
            ->then(function (PanelResult $result) use ($interaction, $owner, $id) {
                if ($result->modal !== null) {
                    return $interaction->showModal($result->modal->title, $result->modal->customId, $result->modal->jsonSerialize()['components']);
                }
                $shown = $owner === $id && ! $result->separate
                    ? $interaction->updateMessage($result->panel)
                    : $interaction->respondWithMessage($result->panel, true);

                return $result->announce === null ? $shown : $shown->then(fn () => $interaction->sendFollowUpMessage($result->announce));
            })
            ->then(null, fn (\Throwable $e) => $interaction->respondWithMessage(self::failure($mtg, $e), true))
            ->then(null, fn (\Throwable $e) => $mtg->logger->warning('Could not answer a panel: '.$e->getMessage()));
    }

    /**
     * A sent form's fields by id: text as a string, menus as a list.
     *
     * @param Interaction $interaction
     *
     * @return array<string, string|string[]>
     */
    private static function fields(Interaction $interaction): array
    {
        $raw = json_decode((string) json_encode($interaction->data->getRawAttributes()['components'] ?? []), true);

        return self::collectFields(is_array($raw) ? $raw : []);
    }

    /**
     * @param array $components Raw components, nested in rows and labels.
     *
     * @return array<string, string|string[]>
     */
    public static function collectFields(array $components): array
    {
        $fields = [];
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            if (isset($component['custom_id']) && (array_key_exists('value', $component) || array_key_exists('values', $component))) {
                $fields[(string) $component['custom_id']] = $component['values'] ?? (string) $component['value'];
            }
            $fields += self::collectFields(array_filter([...($component['components'] ?? []), $component['component'] ?? null]));
        }

        return $fields;
    }

    /**
     * Whose panel a form was opened from: the owner in the first panel id
     * on the message.
     *
     * @param Interaction $interaction
     *
     * @return string|null
     */
    private static function messageOwner(Interaction $interaction): ?string
    {
        try {
            $raw = json_encode($interaction->message?->getRawAttributes()['components'] ?? []);
        } catch (\Throwable) {
            return null;
        }

        return is_string($raw) && preg_match('/"'.PocketMessageBuilder::PREFIX.':'.MenuMessageBuilder::UI.':(\d+):/', $raw, $m) ? $m[1] : null;
    }
}
