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
 * A finite replacement/prevention decision, with no mutations of the game.
 * Replaying the selected operations derives the result and validates every
 * intermediate choice. Game commits the complete batch only after all choices.
 * This is deliberately limited to doubling and the existing prevention kinds.
 */
final class DamageBatch
{
    public const int MAX_EVENTS = 256;
    public const int MAX_OPERATIONS = 64;
    public const int MAX_BYTES = 262144;

    /** @param array[] $events @param array<string, int> $resources @param string[] $selections */
    private function __construct(
        private readonly array $events,
        private readonly array $resources,
        private readonly array $selections = [],
    ) {
    }

    public static function start(array $events, array $resources): self
    {
        return self::fromArray(['events' => $events, 'resources' => $resources, 'selections' => []]);
    }

    public static function fromArray(array $data): self
    {
        if (array_keys($data) !== ['events', 'resources', 'selections']
            || ! is_array($data['events']) || ! array_is_list($data['events'])
            || count($data['events']) > self::MAX_EVENTS || ! is_array($data['resources'])
            || count($data['resources']) > self::MAX_EVENTS * 2
            || ! is_array($data['selections']) || ! array_is_list($data['selections'])
            || count($data['selections']) > self::MAX_EVENTS * self::MAX_OPERATIONS
            || strlen(json_encode($data, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new GameException('Invalid saved damage batch.');
        }
        foreach ($data['resources'] as $key => $amount) {
            if (! is_string($key) || ! preg_match('/^(?:prevent:[po]:\d+|shield:\d+)$/D', $key)
                || ! is_int($amount) || $amount < 0) {
                throw new GameException('Invalid damage prevention resource.');
            }
        }
        foreach ($data['events'] as $event) {
            if (! is_array($event) || array_keys($event) !== ['source', 'incarnation', 'controller', 'target', 'amount', 'combat', 'operations']
                || ! is_int($event['source']) || $event['source'] < 0
                || ! is_int($event['incarnation']) || $event['incarnation'] < 0
                || ! in_array($event['controller'], [0, 1], true)
                || ! is_string($event['target']) || ! preg_match('/^(?:p:[01]|o:\d+:\d+)$/D', $event['target'])
                || ! is_int($event['amount']) || $event['amount'] < 0
                || ! is_bool($event['combat']) || ! is_array($event['operations'])
                || ! array_is_list($event['operations']) || count($event['operations']) > self::MAX_OPERATIONS) {
                throw new GameException('Invalid damage event.');
            }
            $ids = [];
            foreach ($event['operations'] as $op) {
                if (! is_array($op) || array_keys($op) !== ['id', 'kind', 'resource']
                    || ! is_string($op['id']) || strlen($op['id']) > 64 || isset($ids[$op['id']])
                    || ! in_array($op['kind'], ['double', 'prevent', 'shield', 'all'], true)
                    || ($op['kind'] === 'double' && ! preg_match('/^double:[1-9]\d*$/D', $op['id']))
                    || ($op['kind'] === 'all' && ! in_array($op['id'], ['source', 'fog', 'protection'], true))
                    || ($op['kind'] === 'prevent' && ($op['id'] !== 'prevent' || ! is_string($op['resource']) || ! preg_match('/^prevent:[po]:\d+$/D', $op['resource'])))
                    || ($op['kind'] === 'shield' && ($op['id'] !== 'shield' || ! is_string($op['resource']) || ! preg_match('/^shield:\d+$/D', $op['resource'])))
                    || (in_array($op['kind'], ['double', 'all'], true) ? $op['resource'] !== null
                        : (! is_string($op['resource']) || ! array_key_exists($op['resource'], $data['resources'])))) {
                    throw new GameException('Invalid damage operation.');
                }
                $ids[$op['id']] = true;
            }
        }
        foreach ($data['selections'] as $selection) {
            if (! is_string($selection) || strlen($selection) > 64) {
                throw new GameException('Invalid damage selection.');
            }
        }
        $batch = new self($data['events'], $data['resources'], $data['selections']);
        $batch->progress();

        return $batch;
    }

    public function toArray(): array
    {
        return ['events' => $this->events, 'resources' => $this->resources, 'selections' => $this->selections];
    }

    public function choose(string $option): self
    {
        $progress = $this->progress();
        if (! in_array($option, $progress['options'], true)) {
            throw new GameException('That damage replacement is no longer legal.');
        }

        return self::fromArray(['events' => $this->events, 'resources' => $this->resources, 'selections' => [...$this->selections, $option]]);
    }

    /** The result is derived, never trusted from serialized pending state. */
    public function progress(): array
    {
        $resources = $this->resources;
        $remaining = $this->selections;
        $results = [];
        foreach ($this->events as $index => $event) {
            $amount = $event['amount'];
            $operations = $event['operations'];
            $applied = [];
            while ($amount > 0 && $operations !== []) {
                $operations = array_values(array_filter($operations, fn (array $op) => $op['resource'] === null || $resources[$op['resource']] > 0));
                if ($operations === []) {
                    break;
                }
                $kinds = array_unique(array_column($operations, 'kind'));
                // A sole full-prevention family commutes with all doublings:
                // zero final damage, with the same shield-counter consumption.
                // Numbered shields and multiple prevention families can consume
                // different resources and therefore still require the player.
                if (! in_array('prevent', $kinds, true)
                    && (array_diff($kinds, ['double', 'all']) === []
                        || (array_diff($kinds, ['double', 'shield']) === [] && count(array_filter($operations, fn (array $op) => $op['kind'] === 'shield')) === 1))) {
                    $full = array_values(array_filter($operations, fn (array $op) => $op['kind'] !== 'double'));
                    if ($full !== []) {
                        $operations = $full;
                    }
                }
                // Doublings commute. Prevention and replacement generally do not,
                // including when damage is equal but shield consumption differs.
                $choice = count($operations) > 1 && array_unique(array_column($operations, 'kind')) !== ['double'];
                // Identical doublings are interchangeable for ordering, while
                // their identities still prevent reapplication. One representative
                // avoids a large UI option list without removing a legal outcome.
                $doubleOffered = false;
                $options = [];
                foreach ($operations as $operation) {
                    if ($operation['kind'] !== 'double' || ! $doubleOffered) {
                        $options[] = $operation['id'];
                    }
                    $doubleOffered = $doubleOffered || $operation['kind'] === 'double';
                }
                if ($choice && $remaining === []) {
                    return ['event' => $index, 'options' => $options, 'results' => [], 'resources' => $resources];
                }
                $id = $choice ? array_shift($remaining) : $options[0];
                $slot = array_search($id, array_column($operations, 'id'), true);
                if ($slot === false) {
                    throw new GameException('Invalid saved replacement order.');
                }
                $op = $operations[$slot];
                array_splice($operations, $slot, 1);
                $prevented = 0;
                switch ($op['kind']) {
                    case 'double':
                        if ($amount > intdiv(PHP_INT_MAX, 2)) {
                            throw new GameException('Damage exceeds the supported integer range.');
                        }
                        $amount *= 2;
                        break;
                    case 'prevent':
                        $prevented = min($amount, $resources[$op['resource']]);
                        $resources[$op['resource']] -= $prevented;
                        $amount -= $prevented;
                        break;
                    case 'shield':
                        $resources[$op['resource']]--;
                        // fall through
                        // no break
                    case 'all':
                        [$prevented, $amount] = [$amount, 0];
                        break;
                }
                $applied[] = $op + ['prevented' => $prevented];
            }
            $results[] = ['event' => $event, 'amount' => $amount, 'applied' => $applied];
        }
        if ($remaining !== []) {
            throw new GameException('Replayed damage choices after batch completion.');
        }

        return ['event' => null, 'options' => [], 'results' => $results, 'resources' => $resources];
    }
}
