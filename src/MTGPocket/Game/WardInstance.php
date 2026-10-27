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

/** One observed true Ward instance; static taxes and unresolved costs are held. */
final readonly class WardInstance
{
    /** @param array{kind: 'mana', symbols: string}|array{kind: 'life', amount: int} $cost */
    private function __construct(
        public string $key,
        public int $object,
        public int $incarnation,
        public int $controller,
        public array $cost,
        public int $payer,
    ) {
    }

    /** Capture before costs can remove the source; no live Game reference is retained. */
    public static function capture(string $key, int $object, int $incarnation, int $controller, array $cost, int $payer): self
    {
        if (! preg_match('/^[A-Za-z0-9:._\/-]{1,128}$/D', $key) || $object < 1 || $object > 2147483647
            || $incarnation < 0 || $incarnation > 2147483647 || ! in_array($controller, [0, 1], true)
            || ! in_array($payer, [0, 1], true) || $controller === $payer) {
            throw new GameException('Invalid Ward instance identity or opposing payer.');
        }
        $keys = array_keys($cost);
        sort($keys);
        if (($cost['kind'] ?? null) === 'mana' && $keys === ['kind', 'symbols'] && is_string($cost['symbols'])
            && strlen($cost['symbols']) <= 256 && preg_match('/^(?:\{(?:0|[1-9][0-9]{0,3}|[WUBRGC])\})+$/D', $cost['symbols'])) {
            $cost = ['kind' => 'mana', 'symbols' => $cost['symbols']];
        } elseif (($cost['kind'] ?? null) === 'life' && $keys === ['amount', 'kind'] && is_int($cost['amount'])
            && $cost['amount'] >= 0 && $cost['amount'] <= 1000000) {
            $cost = ['kind' => 'life', 'amount' => $cost['amount']];
        } else {
            throw new GameException('Ward cost held: only bounded fixed mana or life is represented; no targeting tax or unresolved cost.');
        }

        return new self($key, $object, $incarnation, $controller, $cost, $payer);
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'object' => $this->object, 'incarnation' => $this->incarnation,
            'controller' => $this->controller, 'cost' => $this->cost, 'payer' => $this->payer];
    }

    public static function fromArray(array $data): self
    {
        if (count($data) !== 6 || array_diff(array_keys($data), ['key', 'object', 'incarnation', 'controller', 'cost', 'payer']) !== []
            || ! is_string($data['key'] ?? null) || ! is_int($data['object'] ?? null) || ! is_int($data['incarnation'] ?? null)
            || ! is_int($data['controller'] ?? null) || ! is_array($data['cost'] ?? null) || ! is_int($data['payer'] ?? null)) {
            throw new GameException('Invalid saved Ward instance.');
        }

        return self::capture($data['key'], $data['object'], $data['incarnation'], $data['controller'], $data['cost'], $data['payer']);
    }
}
