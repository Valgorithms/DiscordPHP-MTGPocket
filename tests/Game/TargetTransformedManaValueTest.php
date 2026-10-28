<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Cached Eliminate with a seeded transformed source; no transform-ability claim. */
final class TargetTransformedManaValueTest extends GameTestCase
{
    private function prepare(int $seat): array
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/target-transformed-mana-value.json'));
        $this->assertSame('4ed4f8be79e6153b2bfd3b184e9403e97fe5d1d20a423362f750a69a01be3bf3', hash('sha256', $bytes));
        $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'];
        $this->assertSame('85d83776-c1ca-5d29-9fc6-986a61504046', $cards[0]['card']['uuid']);
        $this->assertSame('d7eb7756-fc8f-5e70-b86d-db6446361748', $cards[1]['card']['uuid']);
        $target = $game->addCard(1 - $seat, $cards[1]['card'], GameObject::BATTLEFIELD)->id;
        $game->objects[$target]->transformed = true;
        $this->assertSame(4, $game->objects[$target]->battlefieldManaValue());
        $this->assertSame(0, $game->objects[$target]->definition()->cost->manaValue());
        $bear = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $this->lands($seat, 'Swamp', 2);
        $spell = $game->addCard($seat, $cards[0]['card'], GameObject::HAND)->id;

        return [$target, $bear, $spell];
    }

    private function restore(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @dataProvider kindsAndSeats */
    public function testTransformedValueExcludesItFromLimitedPermanentTargets(int $seat, string $kind): void
    {
        [$target, $bear] = $this->prepare($seat);
        $this->restore();
        $options = $this->game->targetOptions($seat, $kind);
        $this->assertContains('o:'.$bear, $options);
        $this->assertNotContains('o:'.$target, $options);
    }

    /** @dataProvider seats */
    public function testIllegalEliminateRejectsBeforeCosts(int $seat): void
    {
        [$target, , $spell] = $this->prepare($seat);
        $before = $this->game->toArray();
        try {
            $this->game->cast($seat, $spell, 0, ['o:'.$target]);
            $this->fail('The transformed target has mana value four.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    /** @dataProvider seats */
    public function testPaidEliminateDestroysLegalCreatureAfterRestore(int $seat): void
    {
        [, $bear, $spell] = $this->prepare($seat);
        $this->game->cast($seat, $spell, 0, ['o:'.$bear]);
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bear));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
    }

    /** @dataProvider seats */
    public function testFaceDownLegalTargetBecomingFaceUpIsRechecked(int $seat): void
    {
        [$target, , $spell] = $this->prepare($seat);
        $this->game->objects[$target]->transformed = false;
        $this->game->objects[$target]->faceDown = true;
        $this->assertSame(0, $this->game->objects[$target]->battlefieldManaValue());
        $this->game->cast($seat, $spell, 0, ['o:'.$target]);
        // Seed the face-up characteristic transition; its turn-up cost is outside this test.
        $this->game->objects[$target]->faceDown = false;
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($target));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
    }

    /** @dataProvider seats */
    public function testFaceDownCreatureRemainsLegalAndIsDestroyed(int $seat): void
    {
        [$target, , $spell] = $this->prepare($seat);
        $this->game->objects[$target]->transformed = false;
        $this->game->objects[$target]->faceDown = true;
        $this->assertContains('o:'.$target, $this->game->targetOptions($seat, 'creature_or_planeswalker_mv_le_3'));
        $this->game->cast($seat, $spell, 0, ['o:'.$target]);
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($target));
    }

    /** @dataProvider seats */
    public function testOpponentFiltersDoNotSelectYourCreature(int $seat): void
    {
        [, $opponentBear] = $this->prepare($seat);
        $ownBear = $this->battlefield($seat, 'Grizzly Bears');
        foreach (['creature_opp_mv_le_3', 'nonland_opp_mv_le_3'] as $kind) {
            $options = $this->game->targetOptions($seat, $kind);
            $this->assertContains('o:'.$opponentBear, $options);
            $this->assertNotContains('o:'.$ownBear, $options);
        }
    }

    public static function kindsAndSeats(): array
    {
        $out = [];
        foreach ([0, 1] as $seat) {
            foreach (['creature_mv_le_3', 'creature_opp_mv_le_3', 'creature_or_planeswalker_mv_le_3', 'nonland_mv_le_3', 'nonland_opp_mv_le_3'] as $kind) {
                $out[] = [$seat, $kind];
            }
        }

        return $out;
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
