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

/** Pure snapshot factory; not a trigger queue, payment action or chosen trigger order. */
final readonly class WardTargetingEvent
{
    /** @param WardInstance[] $instances */
    private function __construct(
        public int $eventId,
        public array $offending,
        public array $targets,
        public array $instances,
    ) {
    }

    /**
     * The caller supplies frozen pre-cost targets/instances and the actual committed
     * stack entry, or null on failure. This method never reads or mutates a Game.
     * Collection order has no stack-order meaning; controller choice is still required.
     *
     * @param WardInstance[] $instances
     */
    public static function publish(int $eventId, ?array $committed, array $beforeTargets, array $instances): ?self
    {
        if ($committed === null) {
            return null;
        }
        if ($eventId < 1 || $eventId > 2147483647 || ! is_int($committed['id'] ?? null) || $committed['id'] < 1 || $committed['id'] > 2147483647
            || ! is_int($committed['object'] ?? null) || $committed['object'] < 1 || $committed['object'] > 2147483647
            || ! is_int($committed['incarnation'] ?? null) || $committed['incarnation'] < 0 || $committed['incarnation'] > 2147483647
            || ! in_array($committed['controller'] ?? null, [0, 1], true)
            || (array_key_exists('kind', $committed) && ! in_array($committed['kind'], ['spell', 'ability'], true))
            || ! is_array($committed['targets'] ?? null) || ! array_is_list($instances) || count($instances) > 128) {
            throw new GameException('Invalid committed Ward targeting identity.');
        }
        $targets = self::objectTargets($beforeTargets);
        $committedTargets = self::objectTargets($committed['targets']);
        if (count($targets) !== count($committedTargets) || array_diff($targets, $committedTargets) !== []) {
            throw new GameException('Ward snapshot does not match committed pinned targets.');
        }
        $seen = [];
        foreach ($instances as $instance) {
            if (! $instance instanceof WardInstance || $instance->payer !== $committed['controller']
                || ! in_array("o:{$instance->object}:{$instance->incarnation}", $targets, true)) {
                throw new GameException('Ward instance does not match this targeting event.');
            }
            $identity = "{$instance->object}:{$instance->incarnation}:{$instance->key}";
            if (isset($seen[$identity])) {
                throw new GameException('Duplicate Ward instance identity; distinct grants need distinct keys.');
            }
            $seen[$identity] = true;
        }
        if ($instances === []) {
            return null;
        }

        return new self(
            $eventId,
            ['id' => $committed['id'], 'object' => $committed['object'],
            'incarnation' => $committed['incarnation'], 'controller' => $committed['controller'], 'kind' => $committed['kind'] ?? 'spell'],
            $targets,
            $instances
        );
    }

    private static function objectTargets(array $slots): array
    {
        if (! array_is_list($slots) || count($slots) > 256) {
            throw new GameException('Invalid Ward targeting slot list.');
        }
        $targets = [];
        foreach ($slots as $slot) {
            if (! is_string($slot) || strlen($slot) > 64) {
                throw new GameException('Invalid pinned Ward targeting slot.');
            }
            if (preg_match('/^o:([1-9][0-9]{0,9}):(0|[1-9][0-9]{0,9})$/D', $slot, $m)
                && (int) $m[1] <= 2147483647 && (int) $m[2] <= 2147483647) {
                $targets[$slot] = $slot;
            } elseif (! preg_match('/^(?:p:[01]|s:[1-9][0-9]{0,9}|-)$/D', $slot)) {
                throw new GameException('Ward snapshots require pinned object target incarnations.');
            }
        }

        return array_values($targets);
    }

    public function toArray(): array
    {
        return ['version' => 1, 'eventId' => $this->eventId, 'offending' => $this->offending, 'targets' => $this->targets,
            'instances' => array_map(fn (WardInstance $instance) => $instance->toArray(), $this->instances), 'ordering' => 'unselected'];
    }

    public static function fromArray(array $data): self
    {
        $offending = $data['offending'] ?? null;
        if (count($data) !== 6 || array_diff(array_keys($data), ['version', 'eventId', 'offending', 'targets', 'instances', 'ordering']) !== []
            || ($data['version'] ?? null) !== 1 || ($data['ordering'] ?? null) !== 'unselected' || ! is_int($data['eventId'] ?? null)
            || ! is_array($offending) || count($offending) !== 5 || array_diff(array_keys($offending), ['id', 'object', 'incarnation', 'controller', 'kind']) !== []
            || ! is_array($data['targets'] ?? null) || ! is_array($data['instances'] ?? null) || ! array_is_list($data['instances']) || count($data['instances']) > 128
            || array_filter($data['instances'], fn ($instance) => ! is_array($instance)) !== []) {
            throw new GameException('Invalid saved Ward targeting event.');
        }
        $event = self::publish(
            $data['eventId'],
            $offending + ['targets' => $data['targets']],
            $data['targets'],
            array_map(fn (array $instance) => WardInstance::fromArray($instance), $data['instances'])
        );
        if ($event === null || $event->targets !== $data['targets']) {
            throw new GameException('Empty or noncanonical saved Ward targeting event.');
        }

        return $event;
    }
}
