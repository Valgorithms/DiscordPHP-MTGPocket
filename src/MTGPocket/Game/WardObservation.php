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

/** Opt-in, ephemeral diagnostics only. No Ward queue, payment or chosen trigger order. */
trait WardObservation
{
    private bool $wardObservationEnabled = false;

    private array $wardObservationRecords = [];

    private int $nextWardObservationId = 1;

    /** One optional diagnostic snapshot; never serialized or used as gameplay truth. */
    private ?array $pendingPaidWardObservation = null;

    /** Not persisted or enabled by panels/practice; loading a Game disables observation. */
    public function setWardObservation(bool $enabled): void
    {
        $this->wardObservationEnabled = $enabled;
        if (! $enabled) {
            $this->pendingPaidWardObservation = null;
        }
    }

    private function takePaidWardObservation(): ?array
    {
        $prepared = $this->pendingPaidWardObservation;
        $this->pendingPaidWardObservation = null;

        return $prepared;
    }

    /** Latest 64 committed diagnostics, never an executable pending-trigger queue. */
    public function wardObservations(): array
    {
        return $this->wardObservationRecords;
    }

    /** Reads before costs. No event/ID/log mutation occurs here, including held cases. */
    private function prepareWardObservation(array $targets, int $payer): ?array
    {
        if (! $this->wardObservationEnabled) {
            return null;
        }
        $pins = array_map(fn (string $target) => preg_match('/^o:[0-9]+:[0-9]+$/D', $target)
            ? $target : $this->pinTarget($target), $targets);
        $prepared = ['targets' => $pins, 'instances' => [], 'held' => []];
        foreach (array_unique($pins) as $target) {
            $object = $this->targetObject($target);
            if ($object === null || $object->zone !== GameObject::BATTLEFIELD || $object->controller === $payer) {
                continue;
            }
            $definition = $object->definition();
            foreach ($definition->wardInstances as $index => $cost) {
                $this->observeWardCost($prepared, $object, "printed:{$index}", $cost, $payer);
            }
            if ($definition->wardInstances === [] && $definition->ward !== null && ! ($definition->ward['tax'] ?? false)) {
                $prepared['held'][] = "o:{$object->id}:{$object->incarnation}: legacy targeting clause needs its own payer semantics";
            }
            $words = [
                'level' => $this->levelBand($object)['keywords'] ?? [],
                'speed' => $this->maxSpeedBonus($object)['keywords'] ?? [],
                'turn' => $this->active === $object->controller ? $definition->yourTurnKeywords : [],
            ];
            foreach ($definition->stationBands as $index => $band) {
                if ($object->counter('charge') >= $band['min']) {
                    $words["station:{$index}"] = $band['keywords'];
                }
            }
            foreach ($this->attachments($object) as $attached) {
                $words["attachment:{$attached->id}:{$attached->incarnation}"] = $this->bonusOf($attached)['keywords'] ?? [];
            }
            foreach ($object->untilEndOfTurn as $index => $effect) {
                // Existing temporary effects have occurrence slots, not producer identities.
                $words["temporary-slot:{$index}"] = $effect['keywords'];
            }
            foreach ($this->anthems($object, true) as $anthem) {
                $words['anthem:'.$anthem['wardObservationOrigin']] = $anthem['keywords'];
            }
            foreach ($words as $origin => $keywords) {
                foreach ($keywords as $index => $keyword) {
                    if (preg_match('/^ward (.+)$/iD', $keyword, $match)) {
                        $this->observeWardCost($prepared, $object, "{$origin}:{$index}", ['mana' => $match[1]], $payer);
                    }
                }
            }
        }

        return $prepared;
    }

    private function observeWardCost(array &$prepared, GameObject $object, string $key, array $raw, int $payer): void
    {
        $cost = count($raw) === 1 && isset($raw['mana']) ? ['kind' => 'mana', 'symbols' => $raw['mana']]
            : (count($raw) === 1 && isset($raw['life']) ? ['kind' => 'life', 'amount' => $raw['life']] : ['kind' => 'unsupported']);
        try {
            if (count($prepared['instances']) >= 128) {
                throw new GameException('Ward observation instance bound exceeded.');
            }
            $prepared['instances'][] = WardInstance::capture($key, $object->id, $object->incarnation, $object->controller, $cost, $payer);
        } catch (GameException $e) {
            if (count($prepared['held']) < 128) {
                $prepared['held'][] = "o:{$object->id}:{$object->incarnation}:{$key}: ".$e->getMessage();
            }
        }
    }

    /** Bind only the actual new entry. A mismatch is held, never re-pinned or guessed. */
    private function commitWardObservation(?array $prepared, array $committed): void
    {
        if ($prepared === null || ($prepared['instances'] === [] && $prepared['held'] === [])) {
            return;
        }
        $id = $this->nextWardObservationId++;
        $record = ['id' => $id, 'status' => 'held', 'ordering' => 'unselected',
            'offending' => array_intersect_key($committed, array_flip(['id', 'object', 'incarnation', 'controller', 'kind'])),
            'targets' => array_slice($prepared['targets'], 0, 256),
            'instances' => array_map(fn (WardInstance $instance) => $instance->toArray(), $prepared['instances']),
            'reasons' => array_slice($prepared['held'], 0, 128)];
        if ($record['reasons'] === []) {
            try {
                $event = WardTargetingEvent::publish($id, $committed, $prepared['targets'], $prepared['instances']);
                if ($event !== null) {
                    $record['status'] = 'observed';
                    $record['event'] = $event->toArray();
                }
            } catch (GameException $e) {
                $record['reasons'][] = $e->getMessage();
            }
        }
        $this->wardObservationRecords = [...array_slice($this->wardObservationRecords, -63), $record];
    }
}
