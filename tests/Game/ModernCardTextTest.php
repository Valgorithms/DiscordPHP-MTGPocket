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

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Lines common among Modern-legal cards: persist, undying, exalted,
 * bushido, toxic, bloodthirst, landwalk, anthems, mill, Food, Clue and
 * Treasure, borrowing a creature, additional costs, Thoughtseize-style
 * discard, returning cards from the graveyard.
 *
 * @covers \MTGPocket\Game\CardDefinition
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\TextParser
 */
final class ModernCardTextTest extends GameTestCase
{
    private const array MORE = [
        'Kitchen Finks' => ['manaCost' => '{1}{G/W}{G/W}', 'type' => 'Creature — Ouphe', 'power' => '3', 'toughness' => '2', 'text' => "When this creature enters, you gain 2 life.\nPersist (When this creature dies, if it had no -1/-1 counters on it, return it to the battlefield under its owner's control with a -1/-1 counter on it.)", 'colors' => ['G', 'W']],
        'Strangleroot Geist' => ['manaCost' => '{G}{G}', 'type' => 'Creature — Spirit', 'power' => '2', 'toughness' => '1', 'text' => "Haste\nUndying", 'colors' => ['G']],
        'Akrasan Squire' => ['manaCost' => '{W}', 'type' => 'Creature — Human Soldier', 'power' => '1', 'toughness' => '1', 'text' => 'Exalted', 'colors' => ['W']],
        'Devoted Retainer' => ['manaCost' => '{W}', 'type' => 'Creature — Human Samurai', 'power' => '1', 'toughness' => '1', 'text' => 'Bushido 1', 'colors' => ['W']],
        'Toxic Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Phyrexian Rat', 'power' => '2', 'toughness' => '2', 'text' => 'Toxic 2', 'colors' => ['B']],
        'Stormblood Berserker' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Berserker', 'power' => '1', 'toughness' => '1', 'text' => "Bloodthirst 2\nMenace", 'colors' => ['R']],
        'Glorious Anthem' => ['manaCost' => '{1}{W}{W}', 'type' => 'Enchantment', 'text' => 'Creatures you control get +1/+1.', 'colors' => ['W']],
        'Benalish Marshal' => ['manaCost' => '{W}{W}{W}', 'type' => 'Creature — Human Knight', 'power' => '3', 'toughness' => '3', 'text' => 'Other creatures you control get +1/+1.', 'colors' => ['W']],
        'Thought Scour' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => "Target player mills two cards.\nDraw a card.", 'colors' => ['U']],
        'Bake into a Pie' => ['manaCost' => '{2}{B}{B}', 'type' => 'Instant', 'text' => 'Destroy target creature. Create a Food token.', 'colors' => ['B']],
        'Strike It Rich' => ['manaCost' => '{R}', 'type' => 'Sorcery', 'text' => "Create a Treasure token.\nFlashback {2}{R}", 'colors' => ['R']],
        'Thraben Inspector' => ['manaCost' => '{W}', 'type' => 'Creature — Human Soldier', 'power' => '1', 'toughness' => '2', 'text' => 'When this creature enters, investigate.', 'colors' => ['W']],
        'Act of Treason' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => 'Gain control of target creature until end of turn. Untap that creature. It gains haste until end of turn.', 'colors' => ['R']],
        'Village Rites' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => "As an additional cost to cast this spell, sacrifice a creature.\nDraw two cards.", 'colors' => ['B']],
        'Tormenting Voice' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => "As an additional cost to cast this spell, discard a card.\nDraw two cards.", 'colors' => ['R']],
        'Thoughtseize' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Target player reveals their hand. You choose a nonland card from it. That player discards that card. You lose 2 life.', 'colors' => ['B']],
        'Unburial Rites' => ['manaCost' => '{4}{B}', 'type' => 'Sorcery', 'text' => "Return target creature card from your graveyard to the battlefield.\nFlashback {3}{W}", 'colors' => ['B']],
        'Raise Dead' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Return target creature card from your graveyard to your hand.', 'colors' => ['B']],
        'Divine Verdict' => ['manaCost' => '{3}{W}', 'type' => 'Instant', 'text' => 'Destroy target attacking or blocking creature.', 'colors' => ['W']],
        'Bog Wraith' => ['manaCost' => '{3}{B}', 'type' => 'Creature — Wraith', 'power' => '3', 'toughness' => '3', 'text' => 'Swampwalk (This creature can\'t be blocked as long as defending player controls a Swamp.)', 'colors' => ['B']],
        'Ghostly Flicker Lite' => ['manaCost' => '{2}{U}', 'type' => 'Instant', 'text' => "Convoke.\nDraw two cards.", 'colors' => ['U']],
        'Impulse' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => 'Look at the top four cards of your library. Put one of them into your hand and the rest on the bottom of your library in any order.', 'colors' => ['U']],
        'Commune with Nature' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Look at the top five cards of your library. You may reveal a creature card from among them and put it into your hand. Put the rest on the bottom of your library in any order.', 'colors' => ['G']],
        'Mode Sprite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Faerie', 'power' => '2', 'toughness' => '2', 'text' => "When this creature enters, choose one —\n• Draw a card.\n• Destroy target artifact.", 'colors' => ['W']],
        'Ornithopter' => ['manaCost' => '{0}', 'type' => 'Artifact Creature — Thopter', 'power' => '0', 'toughness' => '2', 'text' => 'Flying', 'colors' => []],
    ];

    private function put(int $seat, string $name, string $zone): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private static function more(string $name): array
    {
        return ['uuid' => 'uuid-'.strtolower(preg_replace('/\W+/', '-', $name)), 'name' => $name, 'rarity' => 'common'] + self::MORE[$name];
    }

    private static function read(string $name): CardDefinition
    {
        return new CardDefinition(self::more($name));
    }

    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    public function testEveryCardHereIsReadWhole(): void
    {
        foreach (array_keys(self::MORE) as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->assertContains('convoke', self::read('Ghostly Flicker Lite')->keywords, 'A keyword with a period reads like one without.');
    }

    public function testPersistAndUndying(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $finks = $this->put(1, 'Kitchen Finks', GameObject::BATTLEFIELD);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$finks}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($finks));
        $this->assertSame(1, $game->objects[$finks]->counter('-1/-1'));
        $this->assertSame(2, $game->power($game->objects[$finks]));
        $this->resolve(); // Its enters trigger.
        $this->assertSame(22, $this->life(1));

        $shock = $this->hand(0, 'Shock');
        $game->cast(0, $shock, 0, ["o:{$finks}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($finks), 'Persist only once.');

        $geist = $this->put(1, 'Strangleroot Geist', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$geist}"]);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$geist]));
    }

    public function testExaltedBushidoToxicAndBloodthirst(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $toxic = $this->put(0, 'Toxic Lite', GameObject::BATTLEFIELD);
        $retainer = $this->put(1, 'Devoted Retainer', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$toxic]);
        $this->assertSame(3, $game->power($game->objects[$toxic]), 'Exalted: attacking alone.');
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, []);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(2, $game->players[1]->poison);
        $this->assertSame(17, $this->life(1));

        // Bob was dealt damage this turn: bloodthirst.
        $this->lands(0, 'Mountain', 2);
        $berserker = $this->put(0, 'Stormblood Berserker', GameObject::HAND);
        $game->cast(0, $berserker);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$berserker]));

        $this->passUntil(Step::DeclareAttackers, 5);
        $game->declareAttackers(0, [$berserker, $toxic]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, [$retainer => $toxic]);
        $this->assertSame(2, $game->power($game->objects[$retainer]), 'Bushido when it blocks.');
    }

    public function testLandwalkAndAttackingTargets(): void
    {
        $game = $this->newGame();
        $wraith = $this->put(0, 'Bog Wraith', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $this->battlefield(1, 'Swamp');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$wraith]);
        $this->assertFalse($game->canBlock($game->objects[$bears], $game->objects[$wraith]));

        $this->lands(1, 'Plains', 4);
        $verdict = $this->put(1, 'Divine Verdict', GameObject::HAND);
        $this->assertSame(["o:{$wraith}"], $game->targetOptions(1, 'attacking_or_blocking'));
        $game->pass(0);
        $game->cast(1, $verdict, 0, ["o:{$wraith}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($wraith));
    }

    public function testAnthems(): void
    {
        $game = $this->newGame();
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->put(0, 'Glorious Anthem', GameObject::BATTLEFIELD);
        $marshal = $this->put(0, 'Benalish Marshal', GameObject::BATTLEFIELD);
        $this->assertSame(4, $game->power($game->objects[$bears]));
        $this->assertSame(4, $game->power($game->objects[$marshal]), 'Only the Anthem, not its own bonus.');
        $this->assertSame(2, $game->power($game->objects[$theirs]));
    }

    public function testMillAndArtifactTokens(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $scour = $this->put(0, 'Thought Scour', GameObject::HAND);
        $game->cast(0, $scour, 0, ['p:1']);
        $this->resolve();
        $this->assertCount(2, $game->players[1]->graveyard);
        $this->assertCount(1, $game->players[0]->hand);

        $this->lands(0, 'Swamp', 4);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $pie = $this->put(0, 'Bake into a Pie', GameObject::HAND);
        $game->cast(0, $pie, 0, ["o:{$bears}"]);
        $this->resolve();
        $food = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Food'));
        $this->assertCount(1, $food);
        $this->assertCount(1, $food[0]->definition()->activated);

        $this->lands(0, 'Mountain', 1);
        $rich = $this->put(0, 'Strike It Rich', GameObject::HAND);
        $game->cast(0, $rich);
        $this->resolve();
        $treasure = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Treasure'))[0];
        $this->assertTrue($treasure->definition()->manaAbility['sacrifice']);
        // The Treasure is the only untapped source left, and it goes away once used.
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ['p:1']);
        $this->assertNotSame(GameObject::BATTLEFIELD, $treasure->zone);

        $this->lands(0, 'Plains', 1);
        $inspector = $this->put(0, 'Thraben Inspector', GameObject::HAND);
        $this->resolve();
        $game->cast(0, $inspector);
        $this->resolve();
        $this->resolve();
        $this->assertCount(1, array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Clue'));
    }

    public function testActOfTreason(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $game->objects[$bears]->tapped = true;
        $treason = $this->put(0, 'Act of Treason', GameObject::HAND);
        $game->cast(0, $treason, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(0, $game->objects[$bears]->controller);
        $this->assertFalse($game->objects[$bears]->tapped);
        // A saved game remembers whose it is.
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(1, $game->objects[$bears]->borrowedFrom);
        $this->passUntil(Step::DeclareAttackers);
        $this->assertContains($bears, $game->attackCandidates(), 'It gained haste.');
        $game->declareAttackers(0, [$bears]);
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame(1, $game->objects[$bears]->controller, 'Back at end of turn.');

        // Borrowed from seat 0 is saved too.
        $object = $game->objects[$bears];
        $object->borrowedFrom = 0;
        $this->assertSame(0, GameObject::fromArray($object->toArray())->borrowedFrom);
    }

    public function testAdditionalCosts(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 1);
        $rites = $this->put(0, 'Village Rites', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $rites), 'No creature to sacrifice.');
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $giant = $this->battlefield(0, 'Hill Giant');
        $game->cast(0, $rites);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears), 'The weakest creature.');
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($giant));
        $this->resolve();
        $this->assertCount(2, $game->players[0]->hand);

        $this->lands(0, 'Mountain', 2);
        $voice = $this->put(0, 'Tormenting Voice', GameObject::HAND);
        $game->cast(0, $voice);
        $this->assertCount(1, $game->players[0]->hand);
        $this->resolve();
        $this->assertCount(3, $game->players[0]->hand);
    }

    public function testThoughtseize(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 1);
        $bolt = $this->hand(1, 'Lightning Bolt');
        $bears = $this->hand(1, 'Grizzly Bears');
        $this->hand(1, 'Forest');
        $seize = $this->put(0, 'Thoughtseize', GameObject::HAND);
        $game->cast(0, $seize, 0, ['p:1']);
        $this->resolve();
        $this->assertSame('discard', $game->decision(0));
        $this->assertNull($game->decision(1));
        $this->assertEqualsCanonicalizing([$bolt, $bears], $game->choiceAwaiting()['cards']);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $game->discard(0, [$bears]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame(GameObject::HAND, $this->zone($bolt));
        $this->assertSame(18, $this->life(0));
    }

    public function testReturningCardsFromTheGraveyard(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 6);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $theirs = $game->addCard(1, self::card('Hill Giant'), GameObject::GRAVEYARD)->id;
        $rites = $this->put(0, 'Unburial Rites', GameObject::HAND);
        $this->assertSame(["o:{$bears}"], $game->targetOptions(0, 'creature_card_yours'));
        $game->cast(0, $rites, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($theirs));

        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::GRAVEYARD)->id;
        $raise = $this->put(0, 'Raise Dead', GameObject::HAND);
        $game->cast(0, $raise, 0, ["o:{$giant}"]);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($giant));
    }

    public function testConvoke(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $elves = $this->battlefield(0, 'Llanowar Elves');
        $game->objects[$elves]->sick = true;
        $flicker = $this->put(0, 'Ghostly Flicker Lite', GameObject::HAND);
        $this->assertTrue($game->canCast(0, $flicker), 'The Island and two creatures pay {2}{U}.');
        $game->cast(0, $flicker);
        $this->assertTrue($game->objects[$bears]->tapped);
        $this->assertTrue($game->objects[$elves]->tapped);
        $this->resolve();
        $this->assertCount(2, $game->players[0]->hand);
    }

    public function testLookingAtTheTopCards(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 2);
        $library = $game->players[0]->library;
        $top = array_reverse(array_slice($library, -4));
        $impulse = $this->put(0, 'Impulse', GameObject::HAND);
        $game->cast(0, $impulse);
        $this->resolve();
        $this->assertSame('look', $game->decision(0));
        $this->assertSame($top, $game->choiceAwaiting()['cards']);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        try {
            $game->take(0, []);
            $this->fail('Impulse must take one.');
        } catch (\MTGPocket\Game\GameException) {
        }
        $game->take(0, [$top[2]]);
        $this->assertSame(GameObject::HAND, $this->zone($top[2]));
        $this->assertCount(count($library) - 4, array_intersect($game->players[0]->library, array_slice($library, 0, -4)));
        $this->assertEqualsCanonicalizing([$top[0], $top[1], $top[3]], array_slice($game->players[0]->library, 0, 3), 'The rest go on the bottom.');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($impulse));

        // Only a creature, and only if you want to.
        $this->lands(0, 'Forest', 1);
        $commune = $this->put(0, 'Commune with Nature', GameObject::HAND);
        $this->passUntil(Step::PrecombatMain, 3);
        $game->cast(0, $commune);
        $this->resolve();
        $look = $game->choiceAwaiting();
        foreach ($look['eligible'] as $id) {
            $this->assertTrue($game->objects[$id]->definition()->isCreature());
        }
        $game->take(0, []);
        $this->assertNull($game->choiceAwaiting());
    }

    public function testModalEntersTriggers(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 4);
        $sprite = $this->put(0, 'Mode Sprite', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $sprite);
        $this->resolve();
        $this->resolve(); // Only "Draw a card" can be chosen with no artifact around.
        $this->assertCount($hand, $game->players[0]->hand);

        $thopter = $this->put(1, 'Ornithopter', GameObject::BATTLEFIELD);
        $again = $this->put(0, 'Mode Sprite', GameObject::HAND);
        $game->cast(0, $again);
        $this->resolve();
        $this->assertSame('mode', $game->decision(0));
        $this->assertSame(['Draw a card', 'Destroy target artifact'], $game->choiceAwaiting()['texts']);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $game->chooseMode(0, 1);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($thopter));
    }

    public function testDecksOfTheseCardsPlayToTheEnd(): void
    {
        $deck = function (array $basics, array $more): array {
            $cards = [];
            foreach ($basics as $name => $count) {
                array_push($cards, ...array_fill(0, $count, self::card($name)));
            }
            foreach ($more as $name => $count) {
                array_push($cards, ...array_fill(0, $count, self::more($name)));
            }

            return $cards;
        };
        $orzhov = $deck(['Plains' => 9, 'Swamp' => 8], [
            'Kitchen Finks' => 3, 'Akrasan Squire' => 3, 'Devoted Retainer' => 3, 'Toxic Lite' => 3, 'Glorious Anthem' => 2, 'Benalish Marshal' => 2,
            'Bake into a Pie' => 2, 'Thraben Inspector' => 3, 'Village Rites' => 2, 'Thoughtseize' => 3, 'Unburial Rites' => 2, 'Raise Dead' => 1,
            'Divine Verdict' => 2, 'Mode Sprite' => 2,
        ]);
        $gruul = $deck(['Mountain' => 9, 'Forest' => 8, 'Island' => 2], [
            'Strangleroot Geist' => 3, 'Stormblood Berserker' => 3, 'Strike It Rich' => 3, 'Act of Treason' => 3, 'Tormenting Voice' => 3,
            'Thought Scour' => 2, 'Bog Wraith' => 3, 'Impulse' => 2, 'Ornithopter' => 1,
        ]);
        array_push($gruul, ...array_fill(0, 4, self::card('Grizzly Bears')), ...array_fill(0, 3, self::card('Lightning Bolt')));

        $games = (int) (getenv('MTGPOCKET_SIM_GAMES') ?: 10);
        for ($seed = 0; $seed < $games; $seed++) {
            $game = Game::start("modern-{$seed}", [
                ['id' => '1', 'name' => 'Alice', 'cards' => $orzhov],
                ['id' => '2', 'name' => 'Bob', 'cards' => $gruul],
            ], "modern-{$seed}");
            for ($moves = 0; $game->stage !== Game::OVER; $moves++) {
                $this->assertLessThan(5000, $moves, "Seed {$seed} never ended.");
                $waiting = $game->waitingOn();
                $this->assertNotSame([], $waiting, "Seed {$seed}: nobody can move in {$game->step->label()} of turn {$game->turn}.");
                $this->assertTrue(AutoPlayer::act($game, $waiting[0]), "Seed {$seed}: seat {$waiting[0]} could not make a {$game->decision($waiting[0])} move.");
                $game = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
            }
            $this->assertSame(Game::OVER, $game->stage);
            if (getenv('MTGPOCKET_SIM_LOG')) {
                file_put_contents(getenv('MTGPOCKET_SIM_LOG'), implode("\n", $game->log)."\n", FILE_APPEND);
            }
        }
    }
}
