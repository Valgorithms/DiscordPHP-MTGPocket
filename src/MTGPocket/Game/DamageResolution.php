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

/** Bounded, engine-owned continuation for the Pure Flame damage family. */
trait DamageResolution
{
    /** @var array<int, array{id: int, controller: int, expiresTurn: int, factor: int}> */
    private array $damageEffects = [];

    private int $nextDamageEffectId = 1;

    /** No damage or prevention resources commit until the complete batch is ready. */
    private ?array $pendingDamage = null;

    /** A source that left uses its old control and damage-relevant characteristics. */
    private function damageSourceSnapshot(GameObject $source, int $controller, int $incarnation): array
    {
        $lastKnown = $source->lastKnownDamage[$incarnation] ?? null;
        if ($lastKnown !== null && ($source->zone !== GameObject::BATTLEFIELD || $source->incarnation !== $incarnation)) {
            $snapshot = GameObject::fromArray($lastKnown['object']);
            // Its battlefield abilities no longer observe later damage events.
            $snapshot->zone = GameObject::GRAVEYARD;
            $keywords = $lastKnown['keywords'];
        } else {
            $snapshot = clone $source;
            if ($source->zone !== GameObject::BATTLEFIELD) {
                $snapshot->controller = $controller;
            }
            $keywords = array_values(array_intersect(['infect', 'wither', 'deathtouch', 'lifelink'], $this->keywords($source)));
        }
        $snapshot->lastKnownDamage = $snapshot->lastKnownStatistics = [];

        return ['object' => $snapshot->toArray(), 'keywords' => $keywords, 'actualIncarnation' => $source->incarnation];
    }

    /** Live incarnations retain their own trigger-use state; departed sources use LKI. */
    private function sourceFromDamageSnapshot(array $snapshot): GameObject
    {
        $frozen = GameObject::fromArray($snapshot['object']);
        $live = $this->objects[$frozen->id] ?? null;

        return $frozen->zone === GameObject::BATTLEFIELD && $live?->zone === GameObject::BATTLEFIELD
            && $live->incarnation === $frozen->incarnation ? $live : $frozen;
    }

    private function damageSourceCanTrigger(GameObject $source): bool
    {
        return $source->zone === GameObject::BATTLEFIELD || ($source->zone === GameObject::STACK
            && ($source->definition()->is('Instant') || $source->definition()->is('Sorcery')));
    }

    /** @return array<string, string> Legal UI values and human-readable labels. */
    public function damageOrderOptions(): array
    {
        $labels = [];
        foreach (($this->pendingChoice['type'] ?? null) === 'damage_order' ? $this->pendingChoice['options'] : [] as $option) {
            $labels[$option] = str_starts_with($option, 'double:') ? 'Double the damage' : match ($option) {
                'prevent' => 'Use the damage prevention shield',
                'shield' => 'Remove a shield counter to prevent damage',
                'protection' => 'Prevent damage using protection',
                'source' => 'Prevent all damage from this source',
                'fog' => 'Prevent combat damage',
            };
        }

        return $labels;
    }

    /** @param array[] $assignments Each [source, target, amount, combat, controller?, incarnation?]. */
    private function prepareDamageBatch(array $assignments, string $completion = 'effect'): array
    {
        $events = $sources = $resources = [];
        foreach ($assignments as $assignment) {
            [$source, $target, $amount, $combat] = $assignment;
            $target = $this->pinTarget($target);
            $hurt = $this->targetObject($target);
            if ($amount <= 0 || ($hurt === null && ! preg_match('/^p:[01]$/D', $target))
                || ($hurt !== null && $hurt->zone !== GameObject::BATTLEFIELD)) {
                continue;
            }
            $incarnation = $assignment[5] ?? $source->incarnation;
            $snapshot = $this->damageSourceSnapshot($source, $assignment[4] ?? $source->controller, $incarnation);
            $source = GameObject::fromArray($snapshot['object']);
            $controller = $source->controller;
            $operations = [];
            foreach ($this->damageEffects as $effect) {
                if ($effect['expiresTurn'] === $this->turn && $effect['controller'] === $controller) {
                    $operations[] = ['id' => 'double:'.$effect['id'], 'kind' => 'double', 'resource' => null];
                }
            }
            if ($this->unpreventableTurn !== $this->turn) {
                if ($source->preventDealing === $this->turn) {
                    $operations[] = ['id' => 'source', 'kind' => 'all', 'resource' => null];
                }
                if ($combat && $this->fogTurn === $this->turn) {
                    $operations[] = ['id' => 'fog', 'kind' => 'all', 'resource' => null];
                }
                $key = implode(':', array_slice(explode(':', $target), 0, 2));
                if (($this->prevent[$key] ?? 0) > 0) {
                    $resources['prevent:'.$key] = $this->prevent[$key];
                    $operations[] = ['id' => 'prevent', 'kind' => 'prevent', 'resource' => 'prevent:'.$key];
                }
                if ($hurt !== null && $this->protectedFrom($hurt, $source)) {
                    $operations[] = ['id' => 'protection', 'kind' => 'all', 'resource' => null];
                }
                if ($hurt !== null && $hurt->counter('shield') > 0) {
                    $resources['shield:'.$hurt->id] = $hurt->counter('shield');
                    $operations[] = ['id' => 'shield', 'kind' => 'shield', 'resource' => 'shield:'.$hurt->id];
                }
            }
            $events[] = ['source' => $source->id, 'incarnation' => $incarnation,
                'controller' => $controller, 'target' => $target, 'amount' => $amount,
                'combat' => $combat, 'operations' => $operations];
            $sources[] = $snapshot;
        }

        $pending = ['batch' => DamageBatch::start($events, $resources)->toArray(), 'sources' => $sources, 'completion' => $completion];
        if (strlen(json_encode($pending, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new GameException('Damage continuation exceeds the supported size.');
        }

        return $pending;
    }

    private function startDamageBatch(array $assignments, string $completion = 'effect'): bool
    {
        if ($this->pendingDamage !== null) {
            throw new GameException('A damage batch is already pending.');
        }
        if ($this->damageEffects === []) {
            $dealt = [];
            foreach ($assignments as $assignment) {
                [$source, $target, $amount, $combat] = $assignment;
                $snapshot = $this->damageSourceSnapshot($source, $assignment[4] ?? $source->controller, $assignment[5] ?? $source->incarnation);
                $source = $this->sourceFromDamageSnapshot($snapshot);
                $actual = $this->applyDamage($source, $target, $amount, $combat, damageKeywords: $snapshot['keywords']);
                if ($actual > 0) {
                    $dealt[] = [$source, $target, $actual];
                }
            }
            if ($completion === 'combat') {
                $this->finishCombatDamage($dealt);
            }

            return false;
        }
        $this->pendingDamage = $this->prepareDamageBatch($assignments, $completion);

        return $this->continueDamageBatch();
    }

    /** Returns true only when the batch needs a persisted player decision. */
    private function continueDamageBatch(): bool
    {
        $pending = $this->pendingDamage;
        $progress = DamageBatch::fromArray($pending['batch'])->progress();
        if ($progress['options'] !== []) {
            $event = $pending['batch']['events'][$progress['event']];
            $seat = str_starts_with($event['target'], 'p:') ? (int) substr($event['target'], 2) : $this->targetObject($event['target'])->controller;
            $this->pendingChoice = ['type' => 'damage_order', 'id' => $this->nextChoiceId++, 'seat' => $seat,
                'options' => $progress['options'], 'target' => $event['target']];

            return true;
        }
        $this->pendingDamage = null;
        $assignments = [];
        foreach ($progress['results'] as $index => $result) {
            $event = $result['event'];
            $source = $this->sourceFromDamageSnapshot($pending['sources'][$index]);
            foreach ($result['applied'] as $op) {
                if ($op['kind'] === 'prevent') {
                    $key = substr($op['resource'], strlen('prevent:'));
                    $this->prevent[$key] -= $op['prevented'];
                    if (isset($this->preventGain[$key])) {
                        $this->gainLife($this->preventGain[$key], $op['prevented']);
                    }
                } elseif ($op['kind'] === 'shield') {
                    $this->objects[(int) substr($op['resource'], strlen('shield:'))]->addCounters('shield', -1);
                }
                if ($op['prevented'] > 0) {
                    $this->log("{$op['prevented']} damage from {$source->name()} is prevented.");
                }
            }
            $this->applyDamage($source, $event['target'], $result['amount'], $event['combat'], prevention: false, damageKeywords: $pending['sources'][$index]['keywords']);
            if ($result['amount'] > 0) {
                $assignments[] = [$source, $event['target'], $result['amount']];
            }
        }
        if ($pending['completion'] === 'combat') {
            $this->finishCombatDamage($assignments);
        } elseif ($pending['completion'] === 'payment') {
            match ($pending['after']['kind']) {
                'cast' => $this->finishPaidCast($pending['after']),
                'activate' => $this->finishPaidActivation($pending['after']),
                default => $this->finishPaidUtility($pending['after']),
            };
        }

        return false;
    }

    /** Engine action shared by Discord panels and the practice policy. */
    public function chooseDamageOrder(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || ($choice['type'] ?? null) !== 'damage_order'
            || $choice['seat'] !== $seat || $choice['id'] !== $choiceId || $this->pendingDamage === null
            || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That damage choice is no longer available.');
        }
        $this->validateDamageState();
        $batch = DamageBatch::fromArray($this->pendingDamage['batch'])->choose($option);
        $this->pendingDamage['batch'] = $batch->toArray();
        $this->pendingChoice = null;
        if ($this->continueDamageBatch()) {
            if (isset($choice['resume'])) {
                $this->pendingChoice['resume'] = $choice['resume'];
            }
            $this->validateDamageState();

            return;
        }
        if (isset($choice['resume'])) {
            $resume = $choice['resume'];
            $this->followed = $resume['followed'];
            $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        }
        $this->settle();
    }

    /** Validate a fully attached continuation before returning it to persistence/UI. */
    private function sealDamageChoice(): void
    {
        if (($this->pendingChoice['type'] ?? null) === 'damage_order') {
            $this->validateDamageState();
        }
    }

    /** Validates both serialized input and live answer preconditions before mutation. */
    private function validateDamageState(): void
    {
        $ids = [];
        if (! array_is_list($this->damageEffects) || count($this->damageEffects) > 32
            || $this->nextDamageEffectId < 1 || $this->nextDamageEffectId > PHP_INT_MAX - 32) {
            throw new GameException('Invalid saved damage effects.');
        }
        foreach ($this->damageEffects as $effect) {
            if (! is_array($effect) || array_keys($effect) !== ['id', 'controller', 'expiresTurn', 'factor']
                || ! is_int($effect['id']) || $effect['id'] < 1 || $effect['id'] >= $this->nextDamageEffectId || isset($ids[$effect['id']])
                || ! in_array($effect['controller'], [0, 1], true) || $effect['expiresTurn'] !== $this->turn || $effect['factor'] !== 2) {
                throw new GameException('Invalid saved damage effect.');
            }
            $ids[$effect['id']] = true;
        }
        if ($this->pendingDamage === null) {
            if (($this->pendingChoice['type'] ?? null) === 'damage_order') {
                throw new GameException('Missing saved damage continuation.');
            }

            return;
        }
        $pending = $this->pendingDamage;
        $keys = ($pending['completion'] ?? null) === 'payment' ? ['batch', 'sources', 'completion', 'after'] : ['batch', 'sources', 'completion'];
        if (array_keys($pending) !== $keys || ! is_array($pending['batch'])
            || ! is_array($pending['sources']) || ! array_is_list($pending['sources'])
            || ! in_array($pending['completion'], ['effect', 'combat', 'payment'], true)
            || strlen(json_encode($pending, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new GameException('Invalid saved damage continuation.');
        }
        $progress = DamageBatch::fromArray($pending['batch'])->progress();
        $events = $pending['batch']['events'];
        if (count($events) !== count($pending['sources']) || $progress['event'] === null) {
            throw new GameException('Invalid saved pending damage events.');
        }
        $assignments = [];
        foreach ($events as $i => $event) {
            $source = $this->objects[$event['source']] ?? null;
            if ($source === null || $event['incarnation'] > $source->incarnation) {
                throw new GameException('Invalid saved damage source.');
            }
            $snapshot = $this->damageSourceSnapshot($source, $event['controller'], $event['incarnation']);
            if ($snapshot !== $pending['sources'][$i]) {
                throw new GameException('Stale saved damage source.');
            }
            $assignments[] = [$source, $event['target'], $event['amount'], $event['combat'], $event['controller'], $event['incarnation']];
        }
        $expected = $this->prepareDamageBatch($assignments, $pending['completion']);
        if ($expected['batch']['events'] !== $events || $expected['batch']['resources'] !== $pending['batch']['resources']) {
            throw new GameException('Stale saved damage recipients or prevention resources.');
        }
        $event = $events[$progress['event']];
        $seat = str_starts_with($event['target'], 'p:') ? (int) substr($event['target'], 2) : $this->targetObject($event['target'])->controller;
        $choice = $this->pendingChoice;
        if (($choice['type'] ?? null) !== 'damage_order' || ($choice['seat'] ?? null) !== $seat
            || ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || $choice['id'] >= $this->nextChoiceId
            || ($choice['options'] ?? null) !== $progress['options'] || ($choice['target'] ?? null) !== $event['target']) {
            throw new GameException('Invalid saved damage ordering choice.');
        }
        if (strlen(json_encode($choice, JSON_THROW_ON_ERROR)) > 1048576 || $this->stage !== self::PLAYING) {
            throw new GameException('Invalid saved damage choice size or stage.');
        }
        $resume = $choice['resume'] ?? null;
        if ($pending['completion'] === 'payment') {
            if ($resume !== null) {
                throw new GameException('A payment action cannot resume a resolving spell.');
            }
            $this->validatePaidAction($pending['after']);
        } elseif ($pending['completion'] === 'combat') {
            if ($resume !== null || ! in_array($this->step, [Step::FirstStrikeDamage, Step::CombatDamage], true)
                || array_filter($events, fn (array $event) => ! $event['combat']) !== []) {
                throw new GameException('Invalid saved combat damage continuation.');
            }
        } else {
            $item = is_array($resume) ? ($resume['item'] ?? null) : null;
            if (! is_array($resume) || ! is_array($item) || ! is_int($item['object'] ?? null) || ! isset($this->objects[$item['object']])
                || ! is_int($item['incarnation'] ?? null) || $item['incarnation'] < 0
                || $item['incarnation'] > $this->objects[$item['object']]->incarnation
                || ! in_array($item['controller'] ?? null, [0, 1], true) || ! is_int($item['x'] ?? null) || $item['x'] < 0
                || ! is_int($item['id'] ?? null) || $item['id'] < 1 || ! is_array($item['targets'] ?? null)
                || ! is_array($resume['steps'] ?? null) || ! array_is_list($resume['steps']) || count($resume['steps']) > 256
                || ! array_key_exists('self', $resume) || ($resume['self'] !== null && $resume['self'] !== [$item['object'], $item['incarnation']])
                || ! is_array($resume['followed'] ?? null) || count($resume['followed']) > 256
                || (! self::isAbility($item) && ($this->objects[$item['object']]->zone !== GameObject::STACK
                    || $this->objects[$item['object']]->incarnation !== $item['incarnation']))) {
                throw new GameException('Invalid saved damage resolution continuation.');
            }
            foreach ($resume['steps'] as $step) {
                if (! is_array($step) || ! array_is_list($step) || count($step) !== 2 || ! is_array($step[0])
                    || ! is_string($step[0]['type'] ?? null) || strlen($step[0]['type']) > 64
                    || (isset($step[0]['amount']) && ! is_int($step[0]['amount']) && ! is_string($step[0]['amount']))
                    || ($step[1] !== null && ! is_string($step[1]))) {
                    throw new GameException('Invalid saved remaining damage-resolution step.');
                }
            }
            foreach ($resume['followed'] as $from => $to) {
                if (! is_string($from) || ! is_string($to) || strlen($from) > 64 || strlen($to) > 64) {
                    throw new GameException('Invalid saved damage target continuation.');
                }
            }
            if (array_filter($events, fn (array $event) => $event['combat']) !== []) {
                throw new GameException('Combat damage in a spell-resolution continuation.');
            }
        }
    }

    /** Only explicit cast/activation continuations are accepted, never method names. */
    private function validatePaidAction(mixed $after): void
    {
        if (is_array($after) && ($after['kind'] ?? null) === 'ward_trigger') {
            $this->validatePaidTrigger($after);

            return;
        }
        if (! is_array($after) || ! in_array($after['kind'] ?? null, ['cast', 'activate', ...self::PAID_UTILITY_ACTIONS], true)
            || ! in_array($after['seat'] ?? null, [0, 1], true) || $this->priority !== $after['seat']
            || ! is_int($after['object'] ?? null) || ! isset($this->objects[$after['object']])
            || ! is_int($after['incarnation'] ?? null) || $this->objects[$after['object']]->incarnation !== $after['incarnation']) {
            throw new GameException('Invalid saved paid-action continuation.');
        }
        if (in_array($after['kind'], self::PAID_UTILITY_ACTIONS, true)) {
            $this->validatePaidUtility($after);

            return;
        }
        if (! is_int($after['x'] ?? null) || $after['x'] < 0
            || ! is_array($after['targets'] ?? null) || ! array_is_list($after['targets']) || count($after['targets']) > 256
            || array_filter($after['targets'], fn ($target) => ! is_string($target)) !== []
            || ! is_int($after['wardDiscard'] ?? null) || $after['wardDiscard'] < 0) {
            throw new GameException('Invalid saved paid-action continuation.');
        }
        $object = $this->objects[$after['object']];
        if ($after['kind'] === 'cast') {
            if (array_keys($after) !== ['kind', 'seat', 'object', 'incarnation', 'x', 'targets', 'options', 'payment', 'revealed', 'wardDiscard', 'wardLife', 'xCount']
                || ! is_array($after['options']) || self::castOptions($after['options']) !== $after['options']
                || ! is_array($after['payment']) || ! is_int($after['payment']['life'] ?? null) || $after['payment']['life'] < 0
                || ! is_int($after['wardLife']) || $after['wardLife'] < 0 || ! is_int($after['xCount']) || $after['xCount'] < 0
                || ($after['revealed'] !== null && (! is_int($after['revealed']) || ! isset($this->objects[$after['revealed']])))
                || ! in_array($object->zone, [GameObject::HAND, GameObject::GRAVEYARD, GameObject::EXILE, GameObject::COMMAND, GameObject::LIBRARY], true)) {
                throw new GameException('Invalid saved paid cast.');
            }
            foreach (['tap', 'pool', 'float'] as $field) {
                if (! is_array($after['payment'][$field] ?? null) || count($after['payment'][$field]) > 256
                    || array_filter($after['payment'][$field], fn ($n) => ! is_int($n) || $n < 0) !== []) {
                    throw new GameException('Invalid saved completed mana payment.');
                }
            }
            $options = $after['options'];
            foreach (self::castOptions('') as $key => $default) {
                if (get_debug_type($options[$key]) !== get_debug_type($default)) {
                    throw new GameException('Invalid saved cast option.');
                }
            }
            foreach (['modes', 'splice'] as $list) {
                if (! array_is_list($options[$list]) || count($options[$list]) > 256
                    || array_filter($options[$list], fn ($id) => ! is_int($id) || $id < 0) !== []) {
                    throw new GameException('Invalid saved cast mode or splice selection.');
                }
            }
            $card = $object->printed();
            $effects = $options['faceDown'] || $options['bestowed'] || $options['alt'] === 'mutate' || $card->aura !== null ? []
                : [...$card->spellEffects($options['modes'], $options['kicked']), ...$this->splicedEffects($options['splice'])];
            $this->checkTargets($this->castKinds($card, $options), $after['targets'], $after['seat'], $card->name, $object, $effects);
        } else {
            $ability = is_int($after['index'] ?? null) ? ($object->definition()->activated[$after['index']] ?? null) : null;
            if (array_keys($after) !== ['kind', 'seat', 'object', 'incarnation', 'index', 'targets', 'x', 'wardDiscard', 'crew']
                || $ability === null || $object->zone !== GameObject::BATTLEFIELD || $object->controller !== $after['seat']
                || ! is_array($after['crew']) || ! array_is_list($after['crew']) || count($after['crew']) > 256
                || array_filter($after['crew'], fn ($id) => ! is_int($id) || ! isset($this->objects[$id]) || $this->objects[$id]->tapped
                    || $this->objects[$id]->zone !== GameObject::BATTLEFIELD || $this->objects[$id]->controller !== $after['seat']) !== []) {
                throw new GameException('Invalid saved paid activation.');
            }
            $this->checkTargets(self::targetKindsOf($ability['effects']), $after['targets'], $after['seat'], $object->name(), $object, $ability['effects']);
        }
    }
}
