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

/** Explicit opt-in fixed-cost Ward execution; diagnostics never supply gameplay state. */
trait WardExecution
{
    private bool $wardExecutionEnabled = false;

    private int $nextWardEventId = 1;

    public function setWardExecution(bool $enabled): void
    {
        if ($enabled === $this->wardExecutionEnabled) {
            return;
        }
        if ($this->stack !== [] || $this->pendingTriggers !== [] || $this->pendingChoice !== null || $this->pendingDamage !== null
            || ($this->triggerOrdering !== null && $this->triggerOrdering['remaining'] !== 0)) {
            throw new GameException('Ward execution can change only with no pending action, trigger or stack entry.');
        }
        $this->triggerOrdering = null;
        $this->wardExecutionEnabled = $enabled;
    }

    public function wardExecutionEnabled(): bool
    {
        return $this->wardExecutionEnabled;
    }

    /** Read-only pre-cost snapshot, separate from optional diagnostic records. */
    private function prepareWardExecution(array $targets, int $payer): ?array
    {
        if (! $this->wardExecutionEnabled) {
            return null;
        }
        foreach (array_unique($targets) as $target) {
            $object = $this->targetObject($target);
            if ($object !== null && $object->zone === GameObject::BATTLEFIELD && $object->controller !== $payer && $object->definition()->wardLegacyClauses !== []) {
                throw new GameException('Ward execution holds legacy offending-controller payer clauses; no costs were paid.');
            }
        }
        $snapshot = $this->captureWardSnapshot($targets, $payer);
        if ($snapshot['held'] !== []) {
            throw new GameException('Ward execution holds unsupported costs or instance identities; no costs were paid.');
        }
        $prepared = ['targets' => $targets, 'instances' => array_map(fn (WardInstance $instance) => $instance->toArray(), $snapshot['instances']),
            'labels' => array_map(fn (WardInstance $instance) => $this->objects[$instance->object]->name()."'s Ward ".$this->wardCostLabel($instance->cost), $snapshot['instances'])];
        $this->validateWardSnapshot($prepared);
        if ($prepared['instances'] !== [] && $this->nextWardEventId >= 2147483647) {
            throw new GameException('Ward targeting identity bound reached; no costs were paid.');
        }

        return $prepared;
    }

    private function wardCostLabel(array $cost): string
    {
        return $cost['kind'] === 'mana' ? $cost['symbols'] : $cost['amount'].' life';
    }

    private function validateWardSnapshot(array $snapshot): array
    {
        if (array_keys($snapshot) !== ['targets', 'instances', 'labels'] || ! is_array($snapshot['targets']) || ! array_is_list($snapshot['targets'])
            || count($snapshot['targets']) > 256 || ! is_array($snapshot['instances']) || ! array_is_list($snapshot['instances']) || count($snapshot['instances']) > 128
            || ! is_array($snapshot['labels']) || ! array_is_list($snapshot['labels']) || count($snapshot['labels']) !== count($snapshot['instances'])
            || array_filter($snapshot['labels'], fn ($label) => ! is_string($label) || strlen($label) > 512) !== []
            || strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new GameException('Invalid saved Ward targeting snapshot.');
        }
        $instances = [];
        foreach ($snapshot['instances'] as $raw) {
            if (! is_array($raw)) {
                throw new GameException('Invalid saved Ward instance list.');
            }
            $instance = WardInstance::fromArray($raw);
            $source = $this->objects[$instance->object] ?? null;
            if ($source === null || $instance->incarnation > $source->incarnation) {
                throw new GameException('Invalid saved Ward source incarnation.');
            }
            $instances[] = $instance;
        }

        return $instances;
    }

    /** Queue each distinct instance only after the exact action entry has committed. */
    private function commitWardExecution(?array $snapshot, array $committed): void
    {
        if ($snapshot === null) {
            return;
        }
        $instances = $this->validateWardSnapshot($snapshot);
        $event = WardTargetingEvent::publish($this->nextWardEventId, $committed, $snapshot['targets'], $instances);
        if ($event === null) {
            return;
        }
        $this->nextWardEventId++;
        foreach ($event->instances as $index => $instance) {
            $this->pendingTriggers[] = ['source' => $instance->object, 'incarnation' => $instance->incarnation, 'controller' => $instance->controller,
                'effects' => [['type' => 'ward_fixed', 'eventId' => $event->eventId, 'offending' => $event->offending, 'instance' => $instance->toArray()]],
                'kinds' => [], 'targets' => [], 'label' => $snapshot['labels'][$index], 'text' => 'Optional payment '.$this->wardCostLabel($instance->cost).'; otherwise counter the referenced entry.'];
        }
    }

    /** Unsupported automatic targeting is held visibly, without a legacy-tax fallback. */
    private function holdWardExecution(string $reason): void
    {
        $this->pendingChoice = ['type' => 'ward_unsupported', 'id' => $this->nextChoiceId++,
            'seat' => $this->pendingTriggers[0]['controller'] ?? $this->priority ?? $this->active, 'reason' => $reason];
    }

    private function readWardEffect(array $effect): WardInstance
    {
        if (! $this->wardExecutionEnabled || array_keys($effect) !== ['type', 'eventId', 'offending', 'instance'] || $effect['type'] !== 'ward_fixed'
            || ! is_int($effect['eventId']) || $effect['eventId'] < 1 || $effect['eventId'] >= $this->nextWardEventId
            || ! is_array($effect['offending']) || array_keys($effect['offending']) !== ['id', 'object', 'incarnation', 'controller', 'kind']
            || ! is_array($effect['instance'])) {
            throw new GameException('Invalid fixed Ward effect.');
        }
        $instance = WardInstance::fromArray($effect['instance']);
        // Validate scalar identity types before looking up an array offset from saved data.
        WardTargetingEvent::publish(
            $effect['eventId'],
            $effect['offending'] + ['targets' => ["o:{$instance->object}:{$instance->incarnation}"]],
            ["o:{$instance->object}:{$instance->incarnation}"],
            [$instance]
        );
        $source = $this->objects[$instance->object] ?? null;
        $offending = $this->objects[$effect['offending']['object']] ?? null;
        if ($source === null || $instance->incarnation > $source->incarnation || $offending === null
            || $effect['offending']['incarnation'] > $offending->incarnation || $effect['offending']['id'] >= $this->nextStackId) {
            throw new GameException('Invalid fixed Ward referenced identity.');
        }

        return $instance;
    }

    private function beginWardPayment(array $effect): void
    {
        $instance = $this->readWardEffect($effect);
        $this->pendingChoice = ['type' => 'ward_payment', 'id' => $this->nextChoiceId++, 'seat' => $instance->payer,
            'eventId' => $effect['eventId'], 'offending' => $effect['offending'], 'instance' => $effect['instance'],
            'label' => 'Ward '.$this->wardCostLabel($instance->cost), 'options' => ['accept', 'decline']];
    }

    private function paymentForWard(int $seat, array $cost): ?array
    {
        return $this->payFor($seat, $cost['kind'] === 'mana' ? $cost['symbols'] : '', $cost['kind'] === 'life' ? $cost['amount'] : 0);
    }

    public function canPayWard(int $seat): bool
    {
        $choice = $this->pendingChoice;

        return $this->wardExecutionEnabled && $this->stage === self::PLAYING && ($choice['type'] ?? null) === 'ward_payment'
            && $choice['seat'] === $seat && $this->paymentForWard($seat, $choice['instance']['cost']) !== null;
    }

    public function chooseWardPayment(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if (! $this->wardExecutionEnabled || $this->stage !== self::PLAYING || ($choice['type'] ?? null) !== 'ward_payment'
            || $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That Ward payment choice is no longer available.');
        }
        $this->validateWardExecutionState();
        $payment = $option === 'accept' ? $this->paymentForWard($seat, $choice['instance']['cost']) : null;
        if ($option === 'accept' && $payment === null) {
            throw new GameException('You cannot pay the Ward cost.');
        }
        $resume = $choice['resume'];
        $this->followed = $resume['followed'];
        $this->pendingChoice = null;
        if ($payment !== null && $this->pay($seat, $payment, ['kind' => 'resolution'])) {
            $resume['steps'] = [[['type' => 'ward_paid'], null], ...$resume['steps']];
            $this->pendingChoice['resume'] = $resume;
            $this->sealDamageChoice();

            return;
        }
        if ($payment === null) {
            foreach ($this->stack as $entry) {
                if ($entry['id'] === $choice['offending']['id'] && $entry['object'] === $choice['offending']['object']
                    && $entry['incarnation'] === $choice['offending']['incarnation'] && ($entry['kind'] ?? 'spell') === $choice['offending']['kind']) {
                    if ($this->counterStackItem($entry['id']) === null) {
                        $this->log('Ward cannot counter that entry.');
                    }
                    break;
                }
            }
        }
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    private function wardExecutionState(): array
    {
        return ['enabled' => $this->wardExecutionEnabled, 'nextEventId' => $this->nextWardEventId,
            'nextPendingId' => $this->nextPendingTriggerId, 'triggerGroup' => $this->triggerOrdering];
    }

    private function validatePaidWardSnapshot(mixed $after): void
    {
        if (! is_array($after) || ! in_array($after['kind'] ?? null, ['cast', 'activate'], true)) {
            return;
        }
        if (! $this->wardExecutionEnabled) {
            if (array_key_exists('wardExecution', $after)) {
                throw new GameException('Unexpected Ward snapshot in a legacy paid action.');
            }

            return;
        }
        if (! is_array($after['wardExecution'] ?? null) || ! is_array($after['targets'] ?? null)
            || ($after['wardExecution']['targets'] ?? null) !== $after['targets']) {
            throw new GameException('Missing original paid Ward targeting snapshot.');
        }
        $instances = $this->validateWardSnapshot($after['wardExecution']);
        WardTargetingEvent::publish(1, ['id' => $this->nextStackId, 'object' => $after['object'] ?? null,
            'incarnation' => $after['incarnation'] ?? null, 'controller' => $after['seat'] ?? null,
            'kind' => $after['kind'] === 'cast' ? 'spell' : 'ability', 'targets' => $after['targets']], $after['targets'], $instances);
    }

    private function restoreWardExecutionState(mixed $state, int $version): void
    {
        $empty = ['enabled' => false, 'nextEventId' => 1, 'nextPendingId' => 1, 'triggerGroup' => null];
        if ($version < 20) {
            if ($state !== null && $state !== $empty) {
                throw new GameException('Ward execution requires saved game schema 20.');
            }
            $state = $empty;
        }
        if (! is_array($state) || array_keys($state) !== array_keys($empty) || ! is_bool($state['enabled'])
            || ! is_int($state['nextEventId']) || $state['nextEventId'] < 1 || $state['nextEventId'] > 2147483647
            || ! is_int($state['nextPendingId']) || ($state['triggerGroup'] !== null && ! is_array($state['triggerGroup']))) {
            throw new GameException('Invalid saved Ward execution state.');
        }
        $this->wardExecutionEnabled = $state['enabled'];
        $this->nextWardEventId = $state['nextEventId'];
        $this->nextPendingTriggerId = $state['nextPendingId'];
        $this->triggerOrdering = $state['triggerGroup'];
        $this->validateWardExecutionState();
    }

    private function validateWardEntry(array $item): WardInstance
    {
        $effect = $item['effects'][0] ?? [];
        $instance = $this->readWardEffect($effect);
        if (count($item['effects']) !== 1 || ($item['targets'] ?? null) !== [] || ($item['kinds'] ?? null) !== []
            || ($item['object'] ?? $item['source'] ?? null) !== $instance->object || ($item['incarnation'] ?? null) !== $instance->incarnation
            || ($item['controller'] ?? null) !== $instance->controller) {
            throw new GameException('Invalid fixed Ward ability source or targets.');
        }

        return $instance;
    }

    private function validateWardExecutionState(): void
    {
        $this->validateTriggerOrdering();
        foreach ([...$this->pendingTriggers, ...$this->stack] as $entry) {
            if (($entry['effects'][0]['type'] ?? null) === 'ward_fixed') {
                $this->validateWardEntry($entry);
            }
        }
        $choice = $this->pendingChoice;
        if (($choice['type'] ?? null) === 'ward_unsupported') {
            if (! $this->wardExecutionEnabled || ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || $choice['id'] >= $this->nextChoiceId
                || ! in_array($choice['seat'] ?? null, [0, 1], true) || ! is_string($choice['reason'] ?? null) || strlen($choice['reason']) > 512) {
                throw new GameException('Invalid held Ward execution state.');
            }
        }
        if (($choice['type'] ?? null) !== 'ward_payment') {
            return;
        }
        $resume = $choice['resume'] ?? null;
        $item = is_array($resume) ? ($resume['item'] ?? null) : null;
        if (! is_array($item) || ! self::isAbility($item) || ! is_int($item['id'] ?? null) || $item['id'] < 1 || $item['id'] >= $this->nextStackId) {
            throw new GameException('Invalid saved Ward payment resume entry.');
        }
        $instance = $this->validateWardEntry($item);
        $effect = $item['effects'][0];
        if (array_keys($choice) !== ['type', 'id', 'seat', 'eventId', 'offending', 'instance', 'label', 'options', 'resume']
            || array_keys($resume) !== ['item', 'steps', 'self', 'followed']
            || ! $this->wardExecutionEnabled || $this->stage !== self::PLAYING || ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || $choice['id'] >= $this->nextChoiceId
            || ($choice['seat'] ?? null) !== $instance->payer || ($choice['eventId'] ?? null) !== $effect['eventId']
            || ($choice['offending'] ?? null) !== $effect['offending'] || ($choice['instance'] ?? null) !== $effect['instance']
            || ($choice['label'] ?? null) !== 'Ward '.$this->wardCostLabel($instance->cost) || ($choice['options'] ?? null) !== ['accept', 'decline']
            || ($resume['steps'] ?? null) !== [] || ! array_key_exists('self', $resume) || ! in_array($resume['self'], [null, [$instance->object, $instance->incarnation]], true)
            || ! is_array($resume['followed'] ?? null) || count($resume['followed']) > 256 || strlen(json_encode($choice, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new GameException('Invalid saved Ward payment choice.');
        }
        foreach ($resume['followed'] as $from => $to) {
            if (! is_string($from) || ! is_string($to) || strlen($from) > 64 || strlen($to) > 64) {
                throw new GameException('Invalid saved Ward target continuation.');
            }
        }
    }

    private function validateWardDamageResume(array $resume): void
    {
        if (($resume['item']['effects'][0]['type'] ?? null) !== 'ward_fixed') {
            return;
        }
        $this->validateWardEntry($resume['item']);
        if (! self::isAbility($resume['item']) || ($resume['steps'] ?? null) !== [[['type' => 'ward_paid'], null]]) {
            throw new GameException('Invalid already-paid Ward completion.');
        }
    }
}
