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

namespace MTGPocket\Game\Mana;

/**
 * A mana cost as printed, e.g. `{2}{W}{U/B}{X}` (rule 202).
 *
 * Each symbol becomes a shard: the ways it can be paid. A plain symbol has
 * one way (`{W}` is one white, `{3}` is three generic); a hybrid symbol
 * has two (`{U/B}` is one blue or one black, `{2/W}` is two generic or one
 * white) and a Phyrexian one can be paid with 2 life instead (`{W/P}`).
 * `{X}` is generic mana chosen when the spell is cast. Snow mana `{S}` is
 * paid as generic until snow permanents are tracked.
 *
 * @since 0.3.0
 */
final class ManaCost implements \Stringable
{
    /**
     * The colors of mana, in WUBRG order, then colorless.
     *
     * @var string[]
     */
    public const array TYPES = ['W', 'U', 'B', 'R', 'G', 'C'];

    /**
     * @param string[]                     $symbols As printed, without braces.
     * @param array<int, array<int, array>> $shards  Per symbol, its ways of paying: `['W' => 1]`, `['generic' => 2]` or `['life' => 2]`.
     * @param int                          $xCount  How many `{X}` the cost has.
     */
    private function __construct(
        public readonly array $symbols,
        private readonly array $shards,
        public readonly int $xCount,
    ) {
    }

    /**
     * Reads a printed cost. An empty or null cost is no cost: lands, and
     * cards like Ancestral Vision that cannot be cast for mana.
     *
     * @param string|null $cost
     *
     * @throws \InvalidArgumentException On a symbol that is not mana.
     *
     * @return self
     */
    public static function parse(?string $cost): self
    {
        preg_match_all('/\{([^}]+)\}/', (string) $cost, $matches);
        $symbols = [];
        $shards = [];
        $x = 0;
        foreach ($matches[1] as $symbol) {
            $symbol = strtoupper($symbol);
            $symbols[] = $symbol;
            if ($symbol === 'X' || $symbol === 'Y' || $symbol === 'Z') {
                $x++;

                continue;
            }
            $shards[] = self::shard($symbol);
        }

        return new self($symbols, $shards, $x);
    }

    /**
     * Generic mana only, e.g. for a card whose cost was not imported.
     *
     * @param int $amount
     *
     * @return self
     */
    public static function generic(int $amount): self
    {
        return $amount > 0 ? self::parse('{'.$amount.'}') : self::parse('');
    }

    /**
     * The ways one symbol can be paid.
     *
     * @param string $symbol
     *
     * @return array<int, array<string, int>>
     */
    private static function shard(string $symbol): array
    {
        if (ctype_digit($symbol)) {
            return [['generic' => (int) $symbol]];
        }
        if ($symbol === 'S') {
            return [['generic' => 1]];
        }
        if (in_array($symbol, self::TYPES, true)) {
            return [[$symbol => 1]];
        }

        $parts = explode('/', $symbol);
        $ways = [];
        foreach ($parts as $part) {
            if ($part === 'P') {
                $ways[] = ['life' => 2];
            } elseif (ctype_digit($part)) {
                $ways[] = ['generic' => (int) $part];
            } elseif (in_array($part, self::TYPES, true)) {
                $ways[] = [$part => 1];
            } else {
                throw new \InvalidArgumentException("{{$symbol}} is not a mana symbol.");
            }
        }

        return $ways;
    }

    /**
     * The mana value (rule 202.3): generic counts its number, any colored
     * or hybrid symbol counts the most it could cost, X counts 0 except on
     * the stack.
     *
     * @param int $x
     *
     * @return int
     */
    public function manaValue(int $x = 0): int
    {
        $value = $this->xCount * $x;
        foreach ($this->shards as $ways) {
            $value += max(array_map(fn (array $way) => $way['generic'] ?? 1, $ways));
        }

        return $value;
    }

    /**
     * The colors in the cost.
     *
     * @return string[]
     */
    public function colors(): array
    {
        $colors = [];
        foreach ($this->shards as $ways) {
            foreach ($ways as $way) {
                foreach (array_keys($way) as $type) {
                    if (in_array($type, ['W', 'U', 'B', 'R', 'G'], true)) {
                        $colors[$type] = true;
                    }
                }
            }
        }

        return array_values(array_filter(['W', 'U', 'B', 'R', 'G'], fn ($color) => isset($colors[$color])));
    }

    /**
     * Whether the cost is empty: nothing to pay.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->symbols === [];
    }

    /**
     * Every way to pay the cost, cheapest in life first: each is the mana
     * of each type it takes (`generic` for any type) and the life it takes.
     *
     * @param int $x       The value of X.
     * @param int $maxWays Stop after this many ways; costs with many hybrid symbols have very many.
     *
     * @return array<int, array{mana: array<string, int>, life: int}>
     */
    public function payments(int $x = 0, int $maxWays = 256): array
    {
        $base = ['mana' => ['generic' => $this->xCount * $x], 'life' => 0];
        $fixed = [];
        $choices = [];
        foreach ($this->shards as $ways) {
            if (count($ways) === 1) {
                $fixed[] = $ways[0];
            } else {
                $choices[] = $ways;
            }
        }
        foreach ($fixed as $way) {
            $base = self::addWay($base, $way);
        }

        $payments = [$base];
        foreach ($choices as $ways) {
            $next = [];
            foreach ($payments as $payment) {
                foreach ($ways as $way) {
                    $next[] = self::addWay($payment, $way);
                    if (count($next) >= $maxWays) {
                        break 2;
                    }
                }
            }
            $payments = $next;
        }

        usort($payments, fn (array $a, array $b) => $a['life'] <=> $b['life']);

        return array_map(function (array $payment) {
            $payment['mana'] = array_filter($payment['mana']);

            return $payment;
        }, $payments);
    }

    /**
     * @param array{mana: array<string, int>, life: int} $payment
     * @param array<string, int>                         $way
     *
     * @return array{mana: array<string, int>, life: int}
     */
    private static function addWay(array $payment, array $way): array
    {
        foreach ($way as $type => $amount) {
            if ($type === 'life') {
                $payment['life'] += $amount;
            } else {
                $payment['mana'][$type] = ($payment['mana'][$type] ?? 0) + $amount;
            }
        }

        return $payment;
    }

    /**
     * The cost as printed.
     *
     * @return string
     */
    public function __toString(): string
    {
        return implode('', array_map(fn (string $symbol) => '{'.$symbol.'}', $this->symbols));
    }
}
