<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Cached Thoughtbind and Mind Spring; tests stack mana value with X. */
final class CounterXManaValueTest extends GameTestCase
{
    private function prepare(int $caster, int $x): array
    {
        $game = $this->newGame();
        if ($caster === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/counter-x-mana-value.json'));
        $this->assertSame('c7ca0c5747386ed3ad827b258279d9063cd8d09911d8b4a635978cd025002df0', hash('sha256', $bytes));
        $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'];
        $this->assertSame('df9fc4a1-8a2e-5b8e-8a46-17ac1ec559a1', $cards[0]['card']['uuid']);
        $this->assertSame('e6009c2f-c44f-5649-910a-42a7232c766f', $cards[1]['card']['uuid']);
        $this->lands($caster, 'Island', 2 + $x);
        $this->lands(1 - $caster, 'Island', 3);
        $spring = $game->addCard($caster, $cards[1]['card'], GameObject::HAND)->id;
        $counter = $game->addCard(1 - $caster, $cards[0]['card'], GameObject::HAND)->id;
        $game->cast($caster, $spring, $x);
        $this->assertSame($x, $game->stack[0]['x']);
        $stackId = $game->stack[0]['id'];
        $game->pass($caster);
        $this->assertSame(1 - $caster, $game->priority);

        return [$spring, $counter, $stackId];
    }

    private function restore(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @dataProvider seats */
    public function testFiveManaValueSpellIsNotOfferedAsTarget(int $caster): void
    {
        [, , $stackId] = $this->prepare($caster, 3);
        $this->restore();
        $this->assertNotContains('s:'.$stackId, $this->game->targetOptions(1 - $caster, 'spell_mv_le_4'));
    }

    /** @dataProvider seats */
    public function testFiveManaValueSpellRejectsPaidCounterAtomically(int $caster): void
    {
        [$spring, $counter, $stackId] = $this->prepare($caster, 3);
        $this->restore();
        $before = $this->game->toArray();
        try {
            $this->game->cast(1 - $caster, $counter, 0, ['s:'.$stackId]);
            $this->fail('Mind Spring has mana value five on the stack.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
        $this->assertSame(GameObject::STACK, $this->zone($spring));
        $this->assertSame(GameObject::HAND, $this->zone($counter));
    }

    /** @dataProvider legalCases */
    public function testLegalManaValueSpellIsCounteredAfterRestore(int $caster, int $x): void
    {
        [$spring, $counter, $stackId] = $this->prepare($caster, $x);
        $this->assertContains('s:'.$stackId, $this->game->targetOptions(1 - $caster, 'spell_mv_le_4'));
        $this->game->cast(1 - $caster, $counter, 0, ['s:'.$stackId]);
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spring));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($counter));
        $this->assertSame([], $this->game->stack);
    }

    public static function legalCases(): array
    {
        return [[0, 0], [0, 2], [1, 0], [1, 2]];
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
