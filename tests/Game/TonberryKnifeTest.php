<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

final class TonberryKnifeTest extends GameTestCase
{
    private function paidTonberry(int $seat): int
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/stun-entry-counters.json'));
        $this->assertSame('bec03f1b43174c16835a6978c4337c641a8108c3701bf1dab5c1d3a88d8ecf17', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'][0]['card'];
        $this->assertSame('2dfac2a6-15a3-5f36-9adc-eb005e756cf1', $card['uuid']);
        $this->lands($seat, 'Swamp', 1);
        $id = $game->addCard($seat, $card, GameObject::HAND)->id;
        $game->cast($seat, $id);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));

        return $id;
    }

    /** @dataProvider seats */
    public function testPaidTonberryHasKnifeKeywordsDuringControllersTurn(int $seat): void
    {
        $id = $this->paidTonberry($seat);
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], 'first strike'));
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], 'deathtouch'));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 2 : 3);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$id], 'first strike'));
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$id], 'deathtouch'));
    }

    /** @dataProvider seats */
    public function testFirstStrikeDeathtouchKillsTougherBlockerBeforeDamage(int $seat): void
    {
        $id = $this->paidTonberry($seat);
        $spider = $this->battlefield(1 - $seat, 'Giant Spider');
        $turn = $seat === 0 ? 5 : 6;
        $this->passUntil(Step::DeclareAttackers, $turn);
        $this->assertFalse($this->game->objects[$id]->tapped);
        $this->game->declareAttackers($seat, [$id]);
        $this->game->pass($seat);
        $this->game->pass(1 - $seat);
        $this->game->declareBlockers(1 - $seat, [$spider => $id]);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($seat);
        $this->game->pass(1 - $seat);
        $this->assertSame(Step::FirstStrikeDamage, $this->game->step);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spider));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame(0, $this->game->objects[$id]->damage);
    }

    /** @dataProvider seats */
    public function testOpponentsTurnBlockingDoesNotHaveKnifeKeywords(int $seat): void
    {
        $id = $this->paidTonberry($seat);
        $spider = $this->battlefield(1 - $seat, 'Giant Spider');
        $turn = $seat === 0 ? 6 : 7;
        $this->passUntil(Step::DeclareAttackers, $turn);
        $this->assertFalse($this->game->objects[$id]->tapped);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$id], 'first strike'));
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$id], 'deathtouch'));
        $this->game->declareAttackers(1 - $seat, [$spider]);
        $this->game->pass(1 - $seat);
        $this->game->pass($seat);
        $this->game->declareBlockers($seat, [$id => $spider]);
        $this->passUntil(Step::PostcombatMain, $turn);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($spider));
    }

    /** @dataProvider seats */
    public function testTurnKeywordsFollowNewControllerAfterPaidControlAura(int $seat): void
    {
        $id = $this->paidTonberry($seat);
        $opponent = 1 - $seat;
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 2 : 3);
        $this->lands($opponent, 'Island', 5);
        $aura = $this->game->addCard($opponent, ['name' => 'Control Fixture', 'manaCost' => '{3}{U}{U}', 'type' => 'Enchantment - Aura',
            'text' => "Enchant creature\nYou control enchanted creature."], GameObject::HAND)->id;
        $this->game->cast($opponent, $aura, 0, ["o:{$id}"]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame($opponent, $this->game->objects[$id]->controller);
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], 'first strike'));
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], 'deathtouch'));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        $this->assertSame($opponent, $this->game->objects[$id]->controller);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$id], 'first strike'));
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$id], 'deathtouch'));
    }

    public function testUnrelatedLabelsAndUnknownKeywordsRemainUnread(): void
    {
        foreach (["Other Label — During your turn, this creature has first strike and deathtouch.",
            "Chef's Knife — During your turn, this creature has first strike and imaginary ability."] as $text) {
            $definition = new CardDefinition(['name' => 'Control', 'type' => 'Creature - Bear', 'manaCost' => '{G}',
                'power' => '2', 'toughness' => '2', 'text' => $text]);
            $this->assertNotEmpty($definition->unsupported);
            $this->assertSame([], $definition->yourTurnKeywords);
        }
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
