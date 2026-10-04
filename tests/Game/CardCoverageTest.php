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
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Painlands, Aura restrictions, modal spells, kicker, flashback, cycling,
 * morph, ward, prowess, regeneration, scry and surveil, level up and crew.
 *
 * @covers \MTGPocket\Game\CardDefinition
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\TextParser
 */
final class CardCoverageTest extends GameTestCase
{
    private const array MORE = [
        'Shivan Reef' => ['manaCost' => null, 'type' => 'Land', 'text' => "{T}: Add {C}.\n{T}: Add {U} or {R}. This land deals 1 damage to you."],
        'Mana Confluence' => ['manaCost' => null, 'type' => 'Land', 'text' => '{T}, Pay 1 life: Add one mana of any color.'],
        'Pacifism' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature\nEnchanted creature can't attack or block."],
        'Claustrophobia' => ['manaCost' => '{1}{U}{U}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature\nWhen Claustrophobia enters, tap enchanted creature.\nEnchanted creature doesn't untap during its controller's untap step."],
        'Arrest' => ['manaCost' => '{2}{W}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature\nEnchanted creature can't attack or block, and its activated abilities can't be activated."],
        'Goblin Bully' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goblin', 'power' => '2', 'toughness' => '1', 'text' => "This creature can't block."],
        'Izzet Charm' => ['manaCost' => '{U}{R}', 'type' => 'Instant', 'text' => "Choose one —\n• Counter target noncreature spell unless its controller pays {2}.\n• Izzet Charm deals 2 damage to target creature.\n• Draw two cards, then discard two cards."],
        'Fiery Charm' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Choose one —\n• Fiery Charm deals 2 damage to target creature.\n• Fiery Charm deals 1 damage to each opponent."],
        'Double Cleave' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => "Choose one or both —\n• Double Cleave deals 3 damage to target creature.\n• You gain 3 life."],
        'Burst Lightning' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Kicker {4} (You may pay an additional {4} as you cast this spell.)\nBurst Lightning deals 2 damage to any target. If this spell was kicked, it deals 4 damage instead."],
        'Into the Roil' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => "Kicker {1}{U}\nReturn target nonland permanent to its owner's hand. If this spell was kicked, draw a card."],
        'Gnarlid Colony' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Beast', 'power' => '2', 'toughness' => '2', 'text' => "Kicker {2}{G}\nIf this creature was kicked, it enters with two +1/+1 counters on it."],
        'Benalish Emissary' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Human Wizard', 'power' => '1', 'toughness' => '4', 'text' => "Kicker {1}{G}\nWhen this creature enters, if it was kicked, destroy target land."],
        'Think Twice' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => "Draw a card.\nFlashback {2}{U}"],
        'Tolarian Scholar' => ['manaCost' => '{2}{U}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '3', 'text' => 'Cycling {2}'],
        'Den Protector' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Human Warrior', 'power' => '2', 'toughness' => '1', 'text' => "Megamorph {1}{G}\nWhen this creature is turned face up, you gain 2 life."],
        'Iridescent Drake' => ['manaCost' => '{3}{U}', 'type' => 'Creature — Drake', 'power' => '2', 'toughness' => '2', 'text' => "Flying, ward {2}"],
        'Monastery Swiftspear' => ['manaCost' => '{R}', 'type' => 'Creature — Human Monk', 'power' => '1', 'toughness' => '2', 'text' => "Haste\nProwess"],
        'River Boa' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Snake', 'power' => '2', 'toughness' => '1', 'text' => "Islandwalk\n{G}: Regenerate this creature."],
        'Drudge Skeletons' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Skeleton', 'power' => '1', 'toughness' => '1', 'text' => '{B}: Regenerate this creature.'],
        'Opt' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => 'Scry 1. Draw a card.'],
        'Consider' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => 'Surveil 2. Draw a card.'],
        'Student of Warfare' => ['manaCost' => '{W}', 'type' => 'Creature — Human Knight', 'power' => '1', 'toughness' => '1', 'text' => "Level up {W}\nLEVEL 2-6\n3/3\nFirst strike\nLEVEL 7+\n4/4\nDouble strike"],
        'Smuggler\'s Copter' => ['manaCost' => '{2}', 'type' => 'Artifact — Vehicle', 'power' => '3', 'toughness' => '3', 'text' => "Flying\nCrew 1"],
        'Walking Ballista Lite' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Construct', 'power' => '0', 'toughness' => '0', 'text' => 'This creature enters with three +1/+1 counters on it.'],
        'Fling Lite' => ['manaCost' => '{R}', 'type' => 'Sorcery', 'text' => 'Fling Lite deals 1 damage to each opponent.'],
    ];

    private function put(int $seat, string $name, string $zone): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private static function more(string $name): array
    {
        return ['uuid' => 'uuid-'.strtolower(preg_replace('/\W+/', '-', $name)), 'name' => $name, 'rarity' => 'common', 'colors' => []] + self::MORE[$name];
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
            $expected = $name === 'River Boa' ? ['Islandwalk'] : ($name === 'Izzet Charm' ? 1 : []);
            $unsupported = self::read($name)->unsupported;
            is_int($expected) ? $this->assertCount($expected, $unsupported, $name) : $this->assertSame($expected, $unsupported, $name);
        }
    }

    public function testPainlandsHurtOnlyForColors(): void
    {
        $reef = self::read('Shivan Reef');
        $this->assertSame(['count' => 1, 'colors' => ['C', 'U', 'R'], 'pain' => ['U', 'R']], $reef->manaAbility);
        $this->assertSame(['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G'], 'pain' => ['W', 'U', 'B', 'R', 'G']], self::read('Mana Confluence')->manaAbility);

        $game = $this->newGame();
        $this->put(0, 'Shivan Reef', GameObject::BATTLEFIELD);
        $scholar = $this->put(0, 'Tolarian Scholar', GameObject::HAND);
        $this->lands(0, 'Island', 1);
        $this->assertTrue($game->canCycle(0, $scholar));
        $game->cycle(0, $scholar);
        $this->assertSame(20, $this->life(0), 'Generic mana comes from {C} or the Island, painlessly.');

        $bolt = $this->hand(0, 'Lightning Bolt');
        $this->resolve();
        $this->assertSame(0, $game->priority);
        $this->assertFalse($game->canCast(0, $bolt), 'Both lands are tapped.');
        $game = $this->newGame();
        $this->put(0, 'Shivan Reef', GameObject::BATTLEFIELD);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ['p:1']);
        $this->assertSame(19, $this->life(0), '{R} from Shivan Reef deals 1 damage.');
    }

    public function testPacifismAndClaustrophobia(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 2);
        $this->lands(0, 'Island', 3);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $giant = $this->battlefield(1, 'Hill Giant');
        $pacifism = $this->put(0, 'Pacifism', GameObject::HAND);
        $claustrophobia = $this->put(0, 'Claustrophobia', GameObject::HAND);

        $game->cast(0, $pacifism, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertTrue($game->hasKeyword($game->objects[$bears], "can't attack"));
        $game->cast(0, $claustrophobia, 0, ["o:{$giant}"]);
        $this->resolve();
        $this->assertSame('Claustrophobia\'s ability', $game->stack[0]['label']);
        $this->resolve();
        $this->assertTrue($game->objects[$giant]->tapped);

        // Bob's turn: neither creature can attack, and the Giant stays tapped.
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertTrue($game->objects[$giant]->tapped);
        $this->assertSame([], $game->attackCandidates());

        // Alice attacks; the pacified Bears cannot block.
        $this->battlefield(0, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, $game->attackCandidates());
        $this->assertSame([], $game->blockCandidates());
    }

    public function testCreaturesThatCannotBlockAndArrest(): void
    {
        $game = $this->newGame();
        $bully = self::read('Goblin Bully');
        $this->assertSame(["can't block"], $bully->keywords);

        $this->lands(0, 'Plains', 3);
        $pyromancer = $this->battlefield(1, 'Prodigal Pyromancer');
        $arrest = $this->put(0, 'Arrest', GameObject::HAND);
        $game->cast(0, $arrest, 0, ["o:{$pyromancer}"]);
        $this->resolve();
        $game->pass(0);
        $this->assertFalse($game->canActivate(1, $pyromancer, 0));
        $this->assertSame([], $game->activatableAbilities(1));
    }

    public function testChooseOneModes(): void
    {
        $charm = self::read('Izzet Charm');
        $this->assertCount(1, $charm->unsupported, 'The "unless" counter and the looting are not read, so the card is not modal yet.');
        $fiery = self::read('Fiery Charm');
        $this->assertSame([[0], [1]], $fiery->modeChoices());
        $this->assertSame(['creature'], $fiery->targetKinds([0]));
        $this->assertSame([], $fiery->targetKinds([1]));
        $cleave = self::read('Double Cleave');
        $this->assertSame([[0], [1], [0, 1]], $cleave->modeChoices());

        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $id = $this->put(0, 'Fiery Charm', GameObject::HAND);
        $this->assertSame([['id' => $id, 'how' => 'm1']], $game->plays(0), 'No creature to target, so only the second mode.');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $this->assertSame([['id' => $id, 'how' => 'm0'], ['id' => $id, 'how' => 'm1']], $game->plays(0));
        try {
            $game->cast(0, $id, 0, ["o:{$bears}"]);
            $this->fail('A modal spell needs its modes.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Choose 1', $e->getMessage());
        }
        $game->cast(0, $id, 0, ["o:{$bears}"], 'm0');
        $this->assertStringContainsString('choosing "Fiery Charm deals 2 damage to target creature"', end($game->log));
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));

        $cleave = $this->put(0, 'Double Cleave', GameObject::HAND);
        $giant = $this->battlefield(1, 'Hill Giant');
        $game->cast(0, $cleave, 0, ["o:{$giant}"], 'm0+1');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($giant));
        $this->assertSame(23, $this->life(0));
    }

    public function testKicker(): void
    {
        $burst = self::read('Burst Lightning');
        $this->assertSame('{4}', $burst->kicker);
        $this->assertSame([['type' => 'damage', 'amount' => 4, 'target' => 'any']], $burst->spellEffects([], true));
        $this->assertSame([['type' => 'damage', 'amount' => 2, 'target' => 'any']], $burst->spellEffects());

        $game = $this->newGame();
        $this->lands(0, 'Mountain', 5);
        $id = $this->put(0, 'Burst Lightning', GameObject::HAND);
        $this->assertSame(['', 'kick'], array_column($game->plays(0), 'how'));
        $game->cast(0, $id, 0, ['p:1'], 'kick');
        $this->resolve();
        $this->assertSame(16, $this->life(1));

        $game = $this->newGame();
        $this->lands(0, 'Forest', 5);
        $colony = $this->put(0, 'Gnarlid Colony', GameObject::HAND);
        $game->cast(0, $colony, 0, [], 'kick');
        $this->resolve();
        $this->assertSame(4, $game->power($game->objects[$colony]));

        $game = $this->newGame();
        $this->lands(0, 'Plains', 3);
        $emissary = $this->put(0, 'Benalish Emissary', GameObject::HAND);
        $game->cast(0, $emissary);
        $this->resolve();
        $this->assertSame([], $game->stack, 'Not kicked, so nothing triggers.');
    }

    public function testFlashback(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 5);
        $think = $this->put(0, 'Think Twice', GameObject::HAND);
        $game->cast(0, $think);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($think));
        $this->assertContains(['id' => $think, 'how' => 'fb'], $game->plays(0));
        $this->assertContains($think, $game->playableCards(0));
        $game->cast(0, $think, 0, [], 'fb');
        $this->assertStringContainsString('casts Think Twice with flashback', end($game->log));
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($think));
        $this->assertCount(2, $game->players[0]->hand);
    }

    public function testCycling(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 2);
        $scholar = $this->put(0, 'Tolarian Scholar', GameObject::HAND);
        $this->assertSame([['id' => $scholar, 'how' => 'cycle']], $game->plays(0), 'Three mana to cast it, two to cycle it.');
        $game->cycle(0, $scholar);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($scholar));
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testMorphAndMegamorph(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 5);
        $protector = $this->put(0, 'Den Protector', GameObject::HAND);
        $this->assertContains(['id' => $protector, 'how' => 'morph'], $game->plays(0));
        $game->cast(0, $protector, 0, [], 'morph');
        $this->assertSame('Alice casts Face-down creature face down.', end($game->log));
        $this->resolve();
        $object = $game->objects[$protector];
        $this->assertTrue($object->faceDown);
        $this->assertSame('Face-down creature', $object->name());
        $this->assertSame([2, 2], [$game->power($object), $game->toughness($object)]);

        $this->assertTrue($game->canActivate(0, $protector, 0));
        $game->activate(0, $protector, 0);
        $this->assertFalse($object->faceDown);
        $this->assertSame('Den Protector', $object->name());
        $this->assertSame(3, $game->power($object), 'Megamorph adds a +1/+1 counter.');
        $this->assertSame(1, count($game->stack), 'The "turned face up" trigger.');
        $this->resolve();
        $this->assertSame(22, $this->life(0));

        $saved = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame('Den Protector', $saved->objects[$protector]->name());
    }

    public function testWard(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $drake = $this->put(1, 'Iridescent Drake', GameObject::BATTLEFIELD);
        $this->assertSame(['mana' => '{2}'], $game->objects[$drake]->definition()->ward);
        $this->assertSame(['flying'], $game->objects[$drake]->definition()->keywords);
        $bolt = $this->hand(0, 'Lightning Bolt');
        try {
            $game->cast(0, $bolt, 0, ["o:{$drake}"]);
            $this->fail('Ward {2} cannot be paid with one more land.');
        } catch (GameException $e) {
            $this->assertStringContainsString('and ward', $e->getMessage());
        }
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $bolt, 0, ["o:{$drake}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($drake));
    }

    public function testProwess(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $monk = $this->put(0, 'Monastery Swiftspear', GameObject::BATTLEFIELD);
        $fling = $this->put(0, 'Fling Lite', GameObject::HAND);
        $game->cast(0, $fling);
        $this->assertCount(2, $game->stack, 'Prowess triggers above the spell.');
        $this->resolve();
        $this->assertSame([2, 3], [$game->power($game->objects[$monk]), $game->toughness($game->objects[$monk])]);
    }

    public function testRegenerate(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 3);
        $this->lands(1, 'Forest', 1);
        $boa = $this->put(1, 'River Boa', GameObject::BATTLEFIELD);
        $murder = $this->hand(0, 'Murder');
        $game->cast(0, $murder, 0, ["o:{$boa}"]);
        $game->pass(0);
        $game->activate(1, $boa, 0);
        $this->resolve();
        $this->assertSame(1, $game->objects[$boa]->shields);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($boa));
        $this->assertTrue($game->objects[$boa]->tapped);
        $this->assertSame(0, $game->objects[$boa]->shields);
        $this->assertStringContainsString('River Boa regenerates.', implode("\n", $game->log));

        // Lethal damage is regenerated too.
        $this->lands(1, 'Swamp', 1);
        $skeletons = $this->put(1, 'Drudge Skeletons', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$skeletons}"]);
        $game->pass(0);
        $game->activate(1, $skeletons, 0);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($skeletons));
        $this->assertSame(0, $game->objects[$skeletons]->damage);
    }

    public function testScryAndSurveilWaitForTheirPlayer(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 2);
        $library = $game->players[0]->library;
        $top = end($library);
        $opt = $this->put(0, 'Opt', GameObject::HAND);
        $game->cast(0, $opt);
        $this->resolve();
        $this->assertSame('scry', $game->decision(0));
        $this->assertNull($game->decision(1));
        $this->assertSame([$top], $game->choiceAwaiting()['cards']);

        // A saved game picks up where it waited.
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $game->arrange(0, [$top]);
        $this->assertSame($top, $game->players[0]->library[0], 'Scried to the bottom.');
        $this->assertNotContains($top, $game->players[0]->hand);
        $this->assertCount(1, $game->players[0]->hand, 'Then Opt draws.');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($opt));
        $this->assertSame('priority', $game->decision(0));

        $consider = $this->put(0, 'Consider', GameObject::HAND);
        $game->cast(0, $consider);
        $this->resolve();
        $cards = $game->choiceAwaiting()['cards'];
        $this->assertCount(2, $cards);
        $game->arrange(0, [$cards[0]]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($cards[0]));
        $this->assertSame(GameObject::HAND, $this->zone($cards[1]), 'The card kept on top is drawn.');
    }

    public function testLevelUp(): void
    {
        $student = self::read('Student of Warfare');
        $this->assertCount(2, $student->levels);
        $this->assertSame('level', $student->activated[0]['effects'][0]['type']);

        $game = $this->newGame();
        $this->lands(0, 'Plains', 2);
        $id = $this->put(0, 'Student of Warfare', GameObject::BATTLEFIELD);
        $game->activate(0, $id, 0);
        $this->resolve();
        $this->assertSame(1, $game->power($game->objects[$id]));
        $game->activate(0, $id, 0);
        $this->resolve();
        $object = $game->objects[$id];
        $this->assertSame([3, 3], [$game->power($object), $game->toughness($object)]);
        $this->assertTrue($game->hasKeyword($object, 'first strike'));
    }

    public function testCrew(): void
    {
        $game = $this->newGame();
        $copter = $this->put(0, 'Smuggler\'s Copter', GameObject::BATTLEFIELD);
        $this->assertFalse($game->isCreature($game->objects[$copter]));
        $this->assertFalse($game->canActivate(0, $copter, 0), 'Nobody to crew it.');
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $game->activate(0, $copter, 0);
        $this->assertTrue($game->objects[$bears]->tapped);
        $this->resolve();
        $this->assertTrue($game->isCreature($game->objects[$copter]));
        $this->passUntil(Step::DeclareAttackers);
        $this->assertSame([$copter], $game->attackCandidates());
        $game->declareAttackers(0, [$copter]);
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertSame(17, $this->life(1));
        $this->assertFalse($game->isCreature($game->objects[$copter]), 'Crewed only until end of turn.');
    }

    public function testEntersWithCounters(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 3);
        $id = $this->put(0, 'Walking Ballista Lite', GameObject::HAND);
        $game->cast(0, $id);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$id]));
    }
}
