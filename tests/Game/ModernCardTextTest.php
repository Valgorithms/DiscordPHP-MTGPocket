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
        'Steppe Lynx Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Cat', 'power' => '0', 'toughness' => '1', 'text' => 'Landfall — Whenever a land you control enters, this creature gets +2/+2 until end of turn.', 'colors' => ['W']],
        'Sword Lite' => ['manaCost' => '{2}', 'type' => 'Artifact — Equipment', 'text' => "When this Equipment enters, attach it to target creature you control.\nEquipped creature gets +2/+0.\nEquip {3}", 'colors' => []],
        'Amrou Kithkin' => ['manaCost' => '{W}{W}', 'type' => 'Creature — Kithkin', 'power' => '1', 'toughness' => '1', 'text' => "This creature can't be blocked by creatures with power 3 or greater.", 'colors' => ['W']],
        'Wall of Air Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Wall', 'power' => '0', 'toughness' => '5', 'text' => "Defender\nThis creature can block only creatures with flying.", 'colors' => ['U']],
        'Frogmite' => ['manaCost' => '{4}', 'type' => 'Artifact Creature — Frog', 'power' => '2', 'toughness' => '2', 'text' => 'Affinity for artifacts (This spell costs {1} less to cast for each artifact you control.)', 'colors' => []],
        'Hellspark Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Elemental', 'power' => '3', 'toughness' => '1', 'text' => "Trample\nUnearth {R} ({R}: Return this card from your graveyard to the battlefield. It gains haste. Exile it at the beginning of the next end step or if it would leave the battlefield. Unearth only as a sorcery.)", 'colors' => ['R']],
        'Staggershock' => ['manaCost' => '{2}{R}', 'type' => 'Instant', 'text' => "Staggershock deals 2 damage to any target.\nRebound (If you cast this spell from your hand, exile it as it resolves. At the beginning of your next upkeep, you may cast this card from exile without paying its mana cost.)", 'colors' => ['R']],
        'Longtusk Cub' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Cat', 'power' => '1', 'toughness' => '2', 'text' => "Whenever this creature deals combat damage to a player, you get {E}{E} (two energy counters).\nPay {E}{E}: Put a +1/+1 counter on this creature.", 'colors' => ['G']],
        'Thriving Rhino' => ['manaCost' => '{4}{G}', 'type' => 'Creature — Rhino', 'power' => '3', 'toughness' => '3', 'text' => "When this creature enters, you get {E}{E}.\nPay {E}{E}: This creature gets +2/+2 until end of turn.", 'colors' => ['G']],
        'Brightfield Mustang' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Horse Mount', 'power' => '3', 'toughness' => '3', 'text' => "Whenever this creature attacks while saddled, untap it and put a +1/+1 counter on it.\nSaddle 1 (Tap any number of other creatures you control with total power 1 or more: This Mount becomes saddled until end of turn. Saddle only as a sorcery.)", 'colors' => ['W']],
        'Brightfield Glider' => ['manaCost' => '{W}', 'type' => 'Creature — Possum Mount', 'power' => '1', 'toughness' => '1', 'text' => "Vigilance\nWhenever this creature attacks while saddled, it gets +1/+2 and gains flying until end of turn.\nSaddle 3 (Tap any number of other creatures you control with total power 3 or more: This Mount becomes saddled until end of turn. Saddle only as a sorcery.)", 'colors' => ['W']],
        'Grisly Salvage' => ['manaCost' => '{B}{G}', 'type' => 'Instant', 'text' => 'Reveal the top five cards of your library. You may put a creature or land card from among them into your hand. Put the rest into your graveyard.', 'colors' => ['B', 'G']],
        'Sleight of Hand' => ['manaCost' => '{U}', 'type' => 'Sorcery', 'text' => 'Look at the top two cards of your library. Put one of them into your hand and the other on the bottom of your library.', 'colors' => ['U']],
        "Dáin's Company Lite" => ['manaCost' => '{R}{W}', 'type' => 'Creature — Dwarf Warrior', 'power' => '2', 'toughness' => '2', 'text' => 'When this creature enters, look at the top four cards of your library. You may reveal a Dwarf or Equipment card from among them and put it into your hand. Put the rest on the bottom of your library in a random order.', 'colors' => ['R', 'W']],
        'Glimpse Lite' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => "Look at the top three cards of your library, then put them back in any order.\nDraw a card.", 'colors' => ['U']],
        'Hopeful Eidolon' => ['manaCost' => '{W}', 'type' => 'Enchantment Creature — Spirit', 'power' => '1', 'toughness' => '1', 'text' => "Bestow {3}{W} (If you cast this card for its bestow cost, it's an Aura spell with enchant creature. It becomes a creature again if it's not attached.)\nLifelink\nEnchanted creature gets +1/+1 and has lifelink.", 'colors' => ['W']],
        'Patchwork Banner' => ['manaCost' => '{3}', 'type' => 'Artifact', 'text' => "As this artifact enters, choose a creature type.\nCreatures you control of the chosen type get +1/+1.\n{T}: Add one mana of any color.", 'colors' => []],
        'Room of Refuge Lite' => ['manaCost' => null, 'type' => 'Land', 'text' => "This land enters tapped. As it enters, choose a color.\n{T}: Add one mana of the chosen color.", 'colors' => []],
        'Scuttling Death' => ['manaCost' => '{4}{B}', 'type' => 'Creature — Spirit', 'power' => '4', 'toughness' => '2', 'text' => "Sacrifice this creature: Target creature gets -1/-1 until end of turn.\nSoulshift 4 (When this creature dies, you may return target Spirit card with mana value 4 or less from your graveyard to your hand.)", 'colors' => ['B']],
        'Wicked Akuba Lite' => ['manaCost' => '{B}{B}', 'type' => 'Creature — Spirit', 'power' => '2', 'toughness' => '2', 'text' => '', 'colors' => ['B']],
        'Curse of the Pierced Heart' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment — Aura Curse', 'text' => "Enchant player\nAt the beginning of enchanted player's upkeep, Curse of the Pierced Heart deals 1 damage to that player or a planeswalker that player controls.", 'colors' => ['R']],
        "Curse of Death's Hold" => ['manaCost' => '{3}{B}{B}', 'type' => 'Enchantment — Aura Curse', 'text' => "Enchant player\nCreatures enchanted player controls get -1/-1.", 'colors' => ['B']],
        'Druid Class Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment — Class', 'text' => "(Gain the next level as a sorcery to add its ability.)\nWhenever a land you control enters, you gain 1 life.\n{2}{G}: Level 2\nCreatures you control get +1/+1.\n{4}{G}: Level 3\nWhen this Class becomes level 3, draw two cards.", 'colors' => ['G']],
        'Burnout Bashtronaut' => ['manaCost' => '{R}', 'type' => 'Creature — Goblin Warrior', 'power' => '1', 'toughness' => '1', 'text' => "Menace\nStart your engines! (If you have no speed, it starts at 1. It increases once on each of your turns when an opponent loses life. Max speed is 4.)\n{2}: This creature gets +1/+0 until end of turn.\nMax speed — This creature has double strike.", 'colors' => ['R']],
        'Speedway Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Pilot', 'power' => '2', 'toughness' => '2', 'text' => "Start your engines!\nMax speed — This creature gets +1/+1 and has menace.\nMax speed — {T}: Target creature gains haste until end of turn.", 'colors' => ['R']],
        'Outpace Oblivion' => ['manaCost' => '{2}{R}', 'type' => 'Enchantment', 'text' => "Start your engines!\nWhen this enchantment enters, it deals 5 damage to up to one target creature or planeswalker.", 'colors' => ['R']],
        'Frost Breath' => ['manaCost' => '{2}{U}', 'type' => 'Instant', 'text' => "Tap up to two target creatures. Those creatures don't untap during their controller's next untap step.", 'colors' => ['U']],
        'Stun Lite' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => 'Tap up to two target creatures. Put a stun counter on each of them. (If a permanent with a stun counter would become untapped, remove one from it instead.)', 'colors' => ['U']],
        'Lumen-Class Frigate' => ['manaCost' => '{1}{W}', 'type' => 'Artifact — Spacecraft', 'power' => '3', 'toughness' => '5', 'text' => "Station (Tap another creature you control: Put charge counters equal to its power on this Spacecraft. Station only as a sorcery. It's an artifact creature at 12+.)\n2+ | Other creatures you control get +1/+1.\n12+ | Flying, lifelink", 'colors' => ['W']],
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

    public function testLandfallAndAttachOnEnter(): void
    {
        $game = $this->newGame();
        $lynx = $this->put(0, 'Steppe Lynx Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::PrecombatMain, 3);
        $forest = $this->hand(0, 'Plains');
        $game->playLand(0, $forest);
        $this->resolve();
        $this->assertSame(2, $game->power($game->objects[$lynx]));

        $this->lands(0, 'Plains', 2);
        $sword = $this->put(0, 'Sword Lite', GameObject::HAND);
        $game->cast(0, $sword);
        $this->resolve();
        $this->resolve();
        $this->assertSame($lynx, $game->objects[$sword]->attachedTo);
        $this->assertSame(4, $game->power($game->objects[$lynx]));
    }

    public function testBlockingByPowerAndOnlyFlyers(): void
    {
        $game = $this->newGame();
        $kithkin = $this->put(0, 'Amrou Kithkin', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $giant = $this->battlefield(1, 'Hill Giant');
        $elves = $this->battlefield(1, 'Llanowar Elves');
        $wall = $this->put(1, 'Wall of Air Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$kithkin, $bears]);
        $this->assertFalse($game->canBlock($game->objects[$giant], $game->objects[$kithkin]));
        $this->assertTrue($game->canBlock($game->objects[$elves], $game->objects[$kithkin]));
        $this->assertTrue($game->canBlock($game->objects[$giant], $game->objects[$bears]));
        $this->assertFalse($game->canBlock($game->objects[$wall], $game->objects[$bears]));
    }

    public function testAffinityForArtifacts(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Ornithopter', GameObject::BATTLEFIELD);
        $this->put(0, 'Ornithopter', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 2);
        $frogmite = $this->put(0, 'Frogmite', GameObject::HAND);
        $this->assertTrue($game->canCast(0, $frogmite), 'Two artifacts and two lands pay {4}.');
        $game->cast(0, $frogmite);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($frogmite));
    }

    public function testUnearth(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $hellspark = $game->addCard(0, self::more('Hellspark Lite'), GameObject::GRAVEYARD)->id;
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertContains(['id' => $hellspark, 'how' => 'unearth'], $game->plays(0));
        $game->unearth(0, $hellspark);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($hellspark));
        $this->assertContains('haste', $game->keywords($game->objects[$hellspark]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::End, 3);
        $this->assertSame(GameObject::EXILE, $this->zone($hellspark), 'Exiled at the end step.');

        // Exiled instead of dying, too.
        $again = $game->addCard(0, self::more('Hellspark Lite'), GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Mountain', 1);
        $this->passUntil(Step::PrecombatMain, 5);
        $game->unearth(0, $again);
        $this->resolve();
        $this->lands(0, 'Mountain', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$again}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($again));
    }

    public function testRebound(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $shock = $this->put(0, 'Staggershock', GameObject::HAND);
        $game->cast(0, $shock, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(18, $this->life(1));
        $this->assertSame(GameObject::EXILE, $this->zone($shock));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame([], array_filter($game->plays(0), fn (array $play) => $play['id'] === $shock), 'Not until your next upkeep.');

        $this->passUntil(Step::Upkeep, 3);
        $this->assertContains(['id' => $shock, 'how' => 'rb'], $game->plays(0));
        $game->cast(0, $shock, 0, ['p:1'], 'rb');
        $this->resolve();
        $this->assertSame(16, $this->life(1));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($shock), 'Rebound only from the hand.');
    }

    public function testEnergy(): void
    {
        $game = $this->newGame();
        $cub = $this->put(0, 'Longtusk Cub', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 5);
        $rhino = $this->put(0, 'Thriving Rhino', GameObject::HAND);
        $game->cast(0, $rhino);
        $this->resolve();
        $this->resolve();
        $this->assertSame(2, $game->players[0]->energy);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(2, $game->players[0]->energy);
        $game->activate(0, $cub, 0);
        $this->resolve();
        $this->assertSame(0, $game->players[0]->energy);
        $this->assertSame(1, $game->objects[$cub]->counter('+1/+1'));
        $this->assertFalse($game->canActivate(0, $cub, 0), 'No energy left.');

        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$cub]);
        $this->passUntil(Step::CombatDamage, 3);
        $this->resolve();
        $this->assertSame(2, $game->players[0]->energy);
    }

    public function testSaddle(): void
    {
        $game = $this->newGame();
        $mustang = $this->put(0, 'Brightfield Mustang', GameObject::BATTLEFIELD);
        $squire = $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$mustang]);
        $this->resolve();
        $this->assertSame(0, $game->objects[$mustang]->counter('+1/+1'), 'Not saddled: no trigger.');

        $this->passUntil(Step::PrecombatMain, 5);
        $game->activate(0, $mustang, 0);
        $this->assertTrue($game->objects[$squire]->tapped);
        $this->resolve();
        $this->assertContains('saddled', $game->keywords($game->objects[$mustang]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::DeclareAttackers, 5);
        $game->declareAttackers(0, [$mustang]);
        $this->assertTrue($game->objects[$mustang]->tapped);
        $this->resolve();
        $this->assertFalse($game->objects[$mustang]->tapped, 'Untapped by its trigger.');
        $this->assertSame(1, $game->objects[$mustang]->counter('+1/+1'));
    }

    public function testBestow(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 5);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $eidolon = $this->put(0, 'Hopeful Eidolon', GameObject::HAND);
        $this->assertSame([], self::read('Hopeful Eidolon')->unsupported);
        $this->assertContains(['id' => $eidolon, 'how' => 'bestow'], $game->plays(0));
        $game->cast(0, $eidolon, 0, ["o:{$bears}"], 'bestow');
        $this->resolve();
        $this->assertSame($bears, $game->objects[$eidolon]->attachedTo);
        $this->assertFalse($game->isCreature($game->objects[$eidolon]));
        $this->assertSame(3, $game->power($game->objects[$bears]));
        $this->assertContains('lifelink', $game->keywords($game->objects[$bears]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));

        $this->lands(1, 'Mountain', 1);
        $bolt = $this->hand(1, 'Lightning Bolt');
        $game->pass(0);
        $game->cast(1, $bolt, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($eidolon), 'It stays as a creature.');
        $this->assertTrue($game->isCreature($game->objects[$eidolon]));
        $this->assertSame(1, $game->power($game->objects[$eidolon]));
    }

    public function testChosenTypeAndColor(): void
    {
        $game = $this->newGame();
        $elves = array_map(fn () => $this->battlefield(0, 'Llanowar Elves'), range(1, 3));
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->lands(0, 'Forest', 3);
        $banner = $this->put(0, 'Patchwork Banner', GameObject::HAND);
        $game->cast(0, $banner);
        $this->resolve();
        $this->assertSame('Elf', $game->objects[$banner]->chosen, 'The type most of their creatures have.');
        $this->assertSame(2, $game->power($game->objects[$elves[0]]));
        $this->assertSame(2, $game->power($game->objects[$bears]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame('Elf', $game->objects[$banner]->chosen);

        $room = $this->put(0, 'Room of Refuge Lite', GameObject::BATTLEFIELD);
        $this->assertSame('G', $game->objects[$room]->chosen);
        $this->assertSame(['G'], $game->manaSources(0)[$room]['colors']);
    }

    public function testSoulshift(): void
    {
        $game = $this->newGame();
        $death = $this->put(0, 'Scuttling Death', GameObject::BATTLEFIELD);
        $akuba = $game->addCard(0, self::more('Wicked Akuba Lite'), GameObject::GRAVEYARD)->id;
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD);
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ["o:{$death}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($akuba), 'The only Spirit with mana value 4 or less; not itself (5) or the Bears.');
    }

    public function testCurses(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Swamp', 5);
        $theirs = $this->battlefield(1, 'Llanowar Elves');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $curse = $this->put(0, 'Curse of the Pierced Heart', GameObject::HAND);
        $this->assertSame(['player'], self::read('Curse of the Pierced Heart')->targetKinds());
        $game->cast(0, $curse, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(1, $game->objects[$curse]->enchantedPlayer);
        $hold = $this->put(0, "Curse of Death's Hold", GameObject::HAND);
        $game->cast(0, $hold, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($theirs), 'A 1/1 dies.');
        $this->assertSame(1, $game->power($game->objects[$bears]));
        $this->assertSame(2, $game->power($game->objects[$mine]), 'Only their creatures.');
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(1, $game->objects[$curse]->enchantedPlayer);

        $this->passUntil(Step::Upkeep, 2);
        $this->resolve();
        $this->assertSame(19, $this->life(1));
        $this->assertSame(20, $this->life(0));
    }

    public function testClassLevels(): void
    {
        $game = $this->newGame();
        $class = $this->put(0, 'Druid Class Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->lands(0, 'Forest', 8);
        $this->assertSame(2, $game->power($game->objects[$bears]), 'Level 1 has no anthem.');
        $this->assertFalse($game->canActivate(0, $class, 1), 'Level 3 needs level 2 first.');
        $game->activate(0, $class, 0);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$bears]));
        $this->assertFalse($game->canActivate(0, $class, 0), 'Level 2 only once.');
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $hand = count($game->players[0]->hand);
        $game->activate(0, $class, 1);
        $this->resolve();
        $this->resolve();
        $this->assertCount($hand + 2, $game->players[0]->hand, 'Its level 3 trigger draws two.');
    }

    public function testStation(): void
    {
        $game = $this->newGame();
        $frigate = $this->put(0, 'Lumen-Class Frigate', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->assertSame([], self::read('Lumen-Class Frigate')->unsupported);
        $this->assertFalse($game->isCreature($game->objects[$frigate]));
        $this->assertSame(2, $game->power($game->objects[$bears]));
        $game->activate(0, $frigate, 0);
        $this->assertTrue($game->objects[$bears]->tapped);
        $this->resolve();
        $this->assertSame(2, $game->objects[$frigate]->counter('charge'));
        $this->assertSame(3, $game->power($game->objects[$bears]), 'Its 2+ ability.');
        $this->assertFalse($game->isCreature($game->objects[$frigate]));

        $game->objects[$frigate]->addCounters('charge', 10);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertTrue($game->isCreature($game->objects[$frigate]), 'A creature at 12+.');
        $this->assertContains('lifelink', $game->keywords($game->objects[$frigate]));
        $this->assertFalse($game->canActivate(0, $frigate, 0), 'No other untapped creature.');
    }

    public function testStartYourEnginesAndMaxSpeed(): void
    {
        $game = $this->newGame();
        $bash = $this->put(0, 'Burnout Bashtronaut', GameObject::BATTLEFIELD);
        $pilot = $this->put(0, 'Speedway Lite', GameObject::BATTLEFIELD);
        $this->assertSame([], self::read('Burnout Bashtronaut')->unsupported);
        $this->assertSame([], self::read('Speedway Lite')->unsupported);
        $bolt = function (): void {
            $this->lands(0, 'Mountain', 1);
            $this->game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ['p:1']);
            $this->resolve();
        };
        $bolt();
        $this->assertSame(2, $game->players[0]->speed, 'It starts at 1, then Bob lost life.');
        $bolt();
        $this->assertSame(2, $game->players[0]->speed, 'Once each turn.');
        $this->assertFalse($game->canActivate(0, $pilot, 0), 'Not before max speed.');

        $this->passUntil(Step::PrecombatMain, 2);
        $this->lands(1, 'Mountain', 1);
        $game->cast(1, $this->hand(1, 'Lightning Bolt'), 0, ['p:0']);
        $this->resolve();
        $this->assertSame(2, $game->players[0]->speed, 'Only on your own turns.');

        foreach ([3 => 3, 5 => 4, 7 => 4] as $turn => $speed) {
            $this->passUntil(Step::PrecombatMain, $turn);
            $bolt();
            $this->assertSame($speed, $game->players[0]->speed, 'Max speed is 4.');
        }
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(4, $game->players[0]->speed);
        $this->assertContains('double strike', $game->keywords($game->objects[$bash]));
        $this->assertSame(3, $game->power($game->objects[$pilot]));
        $this->assertContains('menace', $game->keywords($game->objects[$pilot]));
        $this->assertTrue($game->canActivate(0, $pilot, 0));
    }

    public function testUpToTargets(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Frost Breath')->unsupported);
        $this->assertSame(['?creature', '?creature'], self::read('Frost Breath')->targetKinds());
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $other = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Island', 5);
        $game->cast(0, $this->put(0, 'Frost Breath', GameObject::HAND), 0, ["o:{$bears}", '-']);
        $this->resolve();
        $this->assertTrue($game->objects[$bears]->tapped);
        $this->assertFalse($game->objects[$other]->tapped, 'Only one target was chosen.');
        $game->cast(0, $this->put(0, 'Stun Lite', GameObject::HAND), 0, ["o:{$other}", '-']);
        $this->resolve();
        $this->assertSame(1, $game->objects[$other]->counter('stun'));

        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertTrue($game->objects[$bears]->tapped, "It doesn't untap during Bob's next untap step.");
        $this->assertTrue($game->objects[$other]->tapped, 'A stun counter is removed instead.');
        $this->assertSame(0, $game->objects[$other]->counter('stun'));
        $this->passUntil(Step::PrecombatMain, 4);
        $this->assertFalse($game->objects[$bears]->tapped);
        $this->assertFalse($game->objects[$other]->tapped);
    }

    public function testUpToOneTargetWithNothingToTarget(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $oblivion = $this->put(0, 'Outpace Oblivion', GameObject::HAND);
        $game->cast(0, $oblivion, 0, []);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($oblivion));
        $this->assertSame(1, $game->players[0]->speed);
        $this->resolve(); // Its enters trigger, with no target.
        $this->assertSame([], $game->stack);
        $this->assertSame(20, $this->life(1));
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

    public function testMoreWaysToLookAtTheTopCards(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $this->lands(0, 'Forest', 4);
        $library = &$game->players[0]->library;
        $salvage = $this->put(0, 'Grisly Salvage', GameObject::HAND);
        $top = array_reverse(array_slice($library, -5));
        $game->cast(0, $salvage);
        $this->resolve();
        $look = $game->choiceAwaiting();
        $this->assertSame($top, $look['cards']);
        foreach ($top as $id) {
            $card = $game->objects[$id]->printed();
            $this->assertSame($card->isCreature() || $card->isLand(), in_array($id, $look['eligible'], true));
        }
        $game->take(0, []);
        foreach ($top as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }

        $this->assertSame('sub:Dwarf|sub:Equipment', self::read("Dáin's Company Lite")->triggered[0]['effects'][0]['filter']);
        $dwarf = $game->addCard(0, self::more("Dáin's Company Lite"), GameObject::LIBRARY)->id;
        $company = $this->put(0, "Dáin's Company Lite", GameObject::HAND);
        $this->lands(0, 'Mountain', 1);
        $this->lands(0, 'Plains', 1);
        $game->cast(0, $company);
        $this->resolve();
        $this->resolve();
        $this->assertSame([$dwarf], $game->choiceAwaiting()['eligible']);
        $game->take(0, [$dwarf]);
        $this->assertSame(GameObject::HAND, $this->zone($dwarf));

        $top = array_slice($library, -3);
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Glimpse Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(0, $game->choiceAwaiting()['take']);
        $game->take(0, []);
        $this->assertContains($top[2], $game->players[0]->hand, 'They stay on top, and then it draws one.');
        $this->assertSame(array_slice($top, 0, 2), array_slice($library, -2));
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
            'Divine Verdict' => 2, 'Mode Sprite' => 2, 'Steppe Lynx Lite' => 2, 'Sword Lite' => 1, 'Amrou Kithkin' => 2, 'Frogmite' => 2, 'Brightfield Mustang' => 2, 'Brightfield Glider' => 2, 'Hopeful Eidolon' => 2, 'Patchwork Banner' => 1, 'Lumen-Class Frigate' => 2, 'Burnout Bashtronaut' => 2, 'Frost Breath' => 1, 'Outpace Oblivion' => 1, "Curse of Death's Hold" => 1, 'Wicked Akuba Lite' => 2, 'Scuttling Death' => 2,
        ]);
        $gruul = $deck(['Mountain' => 9, 'Forest' => 8, 'Island' => 2], [
            'Strangleroot Geist' => 3, 'Stormblood Berserker' => 3, 'Strike It Rich' => 3, 'Act of Treason' => 3, 'Tormenting Voice' => 3,
            'Thought Scour' => 2, 'Bog Wraith' => 3, 'Impulse' => 2, 'Ornithopter' => 1, 'Wall of Air Lite' => 1, 'Hellspark Lite' => 2, 'Staggershock' => 2, 'Longtusk Cub' => 2, 'Thriving Rhino' => 1, 'Sleight of Hand' => 1, 'Glimpse Lite' => 1, 'Curse of the Pierced Heart' => 2, 'Druid Class Lite' => 2,
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
