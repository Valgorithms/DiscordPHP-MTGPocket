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

namespace MTGPocket\Panels;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\MessageBuilder;
use MTG\Helpers\Text;
use MTGPocket\Builders\DraftMessageBuilder;
use MTGPocket\Builders\MatchMessageBuilder;
use MTGPocket\Builders\MenuMessageBuilder as Menu;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Builders\ShopMessageBuilder;
use MTGPocket\Builders\TradeMessageBuilder;
use MTGPocket\Cards\BasicLands;
use MTGPocket\Cards\CardPool;
use MTGPocket\Collection\CollectionPages;
use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Drafts\Draft;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Models\Deck;
use MTGPocket\Packs\DailyPackUnavailableException;
use MTGPocket\Packs\OpenedPack;
use MTGPocket\Pocket;
use MTGPocket\Rentals\RentalDeck;
use MTGPocket\Trades\TradeService;
use MTGPocket\Tutorial\Tutorial;

/**
 * Everything a player does, as panels of buttons, menus and forms: the
 * home panel (`/menu`) and a panel for each part of the game. Slash
 * commands open the same panels.
 *
 * Each action is a custom id `pocket:ui:<owner>:<action>[:<arg>…]` (see
 * {@see Menu::id()}); a form sent back keeps the id of the action that
 * opened it. The actions:
 *
 * - `home`; `quests`.
 * - How to play: `tut:<page>`, `tjump` (menu of pages), `tplay` (a practice game).
 * - Packs: `packs:<set>:<color>`, `pset:<color>` and `pcolor:<set>` (menus),
 *   `popen:<set>:<color>` (the free pack), `pbuy:<set>:<color>`. An empty set
 *   or color is a surprise.
 * - Collection: `coll`, `cfilt:<filters>` → form `mcoll`.
 * - Decks: `decks`, `dopen` (menu), `dnew` → `mnew`, `deck:<deck>`,
 *   `dbrowse:<deck>:<page>:<color>` (cards to add) with `dqadd:<deck>:<page>:<color>`
 *   and `dbcol:<deck>` (menus), `dqrem:<deck>` (menu), `dtype:<deck>` → `madd`,
 *   `dremv:<deck>` → `mrem`, `duse:<deck>`, `dren:<deck>` → `mren`,
 *   `dfmt:<deck>` (menu), `dcmd:<deck>` → `mcmd`, `dlands:<deck>` → `mlands`,
 *   `ddel:<deck>` and `ddelok:<deck>`; `rentals`, `rent` (menu).
 * - Play: `play`, `pqueue` (menu of modes), `pchal` (menu of players), `tplay`,
 *   `pleave`, `pconc` and `pconcok`, `pboard`, `pladder` (menu), `plog`.
 * - Drafts: `draft`, `djoin` and `dnewpod` (menus), `dstart`, `dleave` and
 *   `dleaveok`, `dpack`, `dpool`, `dplay`; on the pool, `dpadd` and `dprem`
 *   (menus), `dplands` → `mplands`, `dpauto`, `dpready`.
 * - Shop: `shop`, `sbuy` → `mbuy`, `ssell` → `msell`, `sextra` → `mextra`,
 *   `sprice` → `mprice`.
 * - Trades: `trades`, `tnew` (menu of players) → `mtrade:<player>:<name>`,
 *   `tadd` (menu of offers) → `mtadd:<offer>`, `tcancel` (menu).
 *
 * @since 0.6.0
 */
final class Panels
{
    /** Cards per page when picking cards to add to a deck. */
    public const int BROWSE_PAGE = 25;

    /** The basic lands the land forms ask about (Wastes go in by name). */
    public const array LANDS = ['Plains', 'Island', 'Swamp', 'Mountain', 'Forest'];

    /** The choice that stands for "any" in menus. */
    public const string ANY = '*';

    public readonly CollectionPages $pages;

    public function __construct(private Pocket $pocket)
    {
        $this->pages = new CollectionPages($pocket);
    }

    /**
     * Runs an action.
     *
     * @param string                         $customId
     * @param string                         $userId    Who clicked or sent the form.
     * @param string                         $userName
     * @param string[]                       $values    What they picked in a menu.
     * @param array<string, string|string[]> $fields    A sent form's fields by id.
     * @param string|null                    $channelId Where it happened, for drafts' news.
     * @param array<string, string>          $names     Display names of the users picked in a menu, by id.
     *
     * @return PanelResult
     */
    public function handle(string $customId, string $userId, string $userName, array $values = [], array $fields = [], ?string $channelId = null, array $names = []): PanelResult
    {
        $parts = explode(':', $customId);
        if (($parts[0] ?? '') !== PocketMessageBuilder::PREFIX || ($parts[1] ?? '') !== Menu::UI || ! isset($parts[3])) {
            return new PanelResult(Menu::text('This button no longer works. Open `/menu` again.'));
        }
        $action = $parts[3];
        $args = array_slice($parts, 4);
        $arg = fn (int $index) => (string) ($args[$index] ?? '');
        $value = (string) ($values[0] ?? '');
        $u = $userId;

        // What an action shows when it is refused: the panel it came from, with why.
        $back = fn (string $error): MessageBuilder => match (true) {
            in_array($action, ['popen', 'pbuy', 'pset', 'pcolor'], true) => $this->packs($u, $arg(0), $arg(1), $error),
            $action === 'mcoll' => Menu::withNote($this->pages->page(CollectionPages::query($u), 1), $error),
            in_array($action, ['dqadd', 'dbcol'], true) => $this->browse($u, $arg(0), (int) $arg(1), $arg(2), $error),
            in_array($action, ['dnew', 'mnew', 'dopen'], true) => $this->decks($u, $error),
            $action === 'rent' => $this->rentals($u, $error),
            in_array($action, ['deck', 'dbrowse', 'dqrem', 'dtype', 'dremv', 'duse', 'dren', 'dfmt', 'dcmd', 'dlands', 'ddel', 'ddelok', 'madd', 'mrem', 'mren', 'mcmd', 'mlands'], true) => $this->deckOrList($u, $arg(0), $error),
            in_array($action, ['dpadd', 'dprem', 'dplands', 'mplands', 'dpauto', 'dpready'], true) => $this->draftPool($u, $error),
            in_array($action, ['djoin', 'dnewpod', 'dstart', 'dleave', 'dleaveok', 'dpack', 'dpool', 'dplay'], true) => $this->draft($u, $error),
            in_array($action, ['pqueue', 'pchal', 'pleave', 'pconc', 'pconcok', 'pboard', 'pladder', 'plog', 'tplay'], true) => $this->play($u, $error),
            in_array($action, ['sbuy', 'ssell', 'sextra', 'sprice', 'mbuy', 'msell', 'mextra', 'mprice'], true) => $this->shop($u, $error),
            in_array($action, ['tnew', 'tadd', 'tcancel', 'mtrade', 'mtadd'], true) => $this->trades($u, $error),
            default => $this->home($u, $userName, $error),
        };

        try {
            return $this->run($action, $arg, $value, $values, $fields, $u, $userName, $channelId, $names);
        } catch (\InvalidArgumentException|\OutOfBoundsException|DailyPackUnavailableException $e) {
            return new PanelResult($back('⚠️ '.$e->getMessage()));
        }
    }

    /**
     * @param string                         $action
     * @param callable(int): string          $arg
     * @param string                         $value
     * @param string[]                       $values
     * @param array<string, string|string[]> $fields
     * @param string                         $u
     * @param string                         $name
     * @param string|null                    $channelId
     * @param array<string, string>          $names
     *
     * @return PanelResult
     */
    private function run(string $action, callable $arg, string $value, array $values, array $fields, string $u, string $name, ?string $channelId, array $names): PanelResult
    {
        $panel = fn (MessageBuilder $message, ?MessageBuilder $announce = null) => new PanelResult($message, announce: $announce);
        $field = fn (string $id): string => trim(is_array($fields[$id] ?? null) ? (string) ($fields[$id][0] ?? '') : (string) ($fields[$id] ?? ''));
        $builder = $this->pocket->deckBuilder;
        $drafts = $this->pocket->drafts;
        $matches = $this->pocket->matches;
        $shop = $this->pocket->shop;
        $trades = $this->pocket->trades;

        switch ($action) {
            case 'home':
                return $panel($this->home($u, $name));
            case 'quests':
                return $panel($this->quests($u));

                // How to play.
            case 'tut':
                return $panel($this->tutorial($u, (int) $arg(0)));
            case 'tjump':
                return $panel($this->tutorial($u, (int) $value));
            case 'tplay':
                return $panel(MatchMessageBuilder::board($matches->practice($u, $name)));

                // Packs.
            case 'packs':
                return $panel($this->packs($u, $arg(0), $arg(1)));
            case 'pset':
                return $panel($this->packs($u, $value === self::ANY ? '' : $value, $arg(0)));
            case 'pcolor':
                return $panel($this->packs($u, $arg(0), $value === self::ANY ? '' : $value));
            case 'popen':
                return $panel($this->opened($u, $this->pocket->dailyPacks->open($u, $name, $arg(0) ?: null, $arg(1) ?: null), $arg(0), $arg(1)));
            case 'pbuy':
                return $panel($this->opened($u, $shop->buyPack($u, $name, $arg(0) ?: null, $arg(1) ?: null), $arg(0), $arg(1)));

                // Collection.
            case 'coll':
                return $panel($this->pages->page(CollectionPages::query($u), 1));
            case 'cfilt':
                $query = CollectionPages::decode($u.'.'.$arg(0)) ?? CollectionPages::query($u);

                return new PanelResult(modal: (new Modal('Filter your collection', Menu::id($u, 'mcoll')))
                    ->text('set', 'Set code', $query['set'], false, placeholder: 'e.g. DMU; empty for every set', max: 8)
                    ->choice('color', 'Color', [self::ANY => 'Any color'] + PocketMessageBuilder::COLOR_NAMES, $query['color'] ?: self::ANY)
                    ->choice('rarity', 'Rarity', [self::ANY => 'Any rarity'] + array_combine(CardPool::RARITIES, array_map('ucfirst', CardPool::RARITIES)), $query['rarity'] ?: self::ANY)
                    ->text('name', 'Name contains', $query['name'], false, max: CollectionPages::NAME_BYTES));
            case 'mcoll':
                return $panel($this->pages->page(CollectionPages::query($u, $field('set'), (string) self::orNone($field('color')), (string) self::orNone($field('rarity')), $field('name')), 1));

                // Decks.
            case 'decks':
                return $panel($this->decks($u));
            case 'dopen':
                return $panel(RentalDeck::isRental($value) ? $this->rentalView($u, $value) : $this->deck($u, $value));
            case 'dnew':
                return new PanelResult(modal: (new Modal('New deck', Menu::id($u, 'mnew')))
                    ->text('name', 'Name', null, true, max: DeckBuilder::MAX_NAME)
                    ->choice('format', 'Format', DeckBuilder::FORMATS, 'standard'));
            case 'mnew':
                $deck = $builder->create($u, $name, $field('name'), $field('format') ?: 'standard');

                return $panel($this->deck($u, $deck->id, "Started **{$deck->name}**. Add cards with **➕ Add cards**."));
            case 'deck':
                return $panel($this->deck($u, $arg(0)));
            case 'dbrowse':
                return $panel($this->browse($u, $arg(0), (int) $arg(1), $arg(2)));
            case 'dbcol':
                return $panel($this->browse($u, $arg(0), 0, $value === self::ANY ? '' : $value));
            case 'dqadd':
                $notes = [];
                foreach ($values as $uuid) {
                    [, $card] = $builder->add($u, $arg(0), (string) $uuid, 1);
                    $notes[] = $card['name'];
                }

                return $panel($this->browse($u, $arg(0), (int) $arg(1), $arg(2), '✅ Added '.implode(', ', $notes).'.'));
            case 'dqrem':
                $notes = [];
                foreach ($values as $key) {
                    [, $card] = $builder->remove($u, $arg(0), (string) $key, 1);
                    $notes[] = $card['name'];
                }

                return $panel($this->deck($u, $arg(0), 'Took out one '.implode(', one ', $notes).'.'));
            case 'dtype':
            case 'dremv':
                $adding = $action === 'dtype';

                return new PanelResult(modal: (new Modal($adding ? 'Add cards by name' : 'Take cards out', Menu::id($u, $adding ? 'madd' : 'mrem', $arg(0))))
                    ->text('card', 'Card name', null, true, placeholder: $adding ? 'A card you own, or a basic land' : 'A card in the deck')
                    ->text('count', 'How many', $adding ? '1' : null, false, placeholder: $adding ? '1' : 'Empty takes out every copy', max: 3)
                    ->choice('side', 'Where', ['main' => 'Main deck', 'side' => 'Side deck'], 'main'));
            case 'madd':
                $count = self::number($field('count'), 1, 'How many');
                $side = $field('side') === 'side';
                [$deck, $card] = $builder->add($u, $arg(0), $field('card'), $count, $side);

                return $panel($this->deck($u, $deck->id, "Added {$count} **{$card['name']}** to the ".($side ? 'side' : 'main').' deck.'));
            case 'mrem':
                $side = $field('side') === 'side';
                $before = $builder->find($u, $arg(0));
                [$deck, $card] = $builder->remove($u, $before->id, $field('card'), $field('count') === '' ? null : self::number($field('count'), 1, 'How many'), $side);
                $removed = ($side ? $before->side : $before->main)->total() - ($side ? $deck->side : $deck->main)->total();

                return $panel($this->deck($u, $deck->id, "Took {$removed} **{$card['name']}** out of the ".($side ? 'side' : 'main').' deck.'));
            case 'duse':
                $builder->activate($u, $name, $arg(0));

                return $panel($this->deck($u, $arg(0), 'You now play with this deck.'));
            case 'dren':
                return new PanelResult(modal: (new Modal('Rename the deck', Menu::id($u, 'mren', $arg(0))))
                    ->text('name', 'New name', $builder->find($u, $arg(0))->name, true, max: DeckBuilder::MAX_NAME));
            case 'mren':
                $builder->rename($u, $arg(0), $field('name'));

                return $panel($this->deck($u, $arg(0), 'Renamed.'));
            case 'dfmt':
                $deck = $builder->setFormat($u, $arg(0), $value);

                return $panel($this->deck($u, $deck->id, 'It is now a '.DeckBuilder::FORMATS[$deck->format].' deck.'));
            case 'dcmd':
                $deck = $builder->find($u, $arg(0));

                return new PanelResult(modal: (new Modal('Pick a commander', Menu::id($u, 'mcmd', $deck->id)))
                    ->text('card', 'Legendary creature you own', $deck->commander === null ? null : $builder->cardData($deck->commander)['name'], false, placeholder: 'Empty takes the commander out'));
            case 'mcmd':
                [$deck, $card] = $builder->setCommander($u, $arg(0), $field('card') === '' ? null : $field('card'));

                return $panel($this->deck($u, $deck->id, $card === null ? 'This deck has no commander now.' : "**{$card['name']}** now leads this deck."));
            case 'dlands':
                $deck = $builder->find($u, $arg(0));
                $modal = new Modal('Basic lands in the main deck', Menu::id($u, 'mlands', $deck->id));
                foreach (self::LANDS as $land) {
                    $modal->text(strtolower($land), $land, (string) $deck->main->get(BasicLands::PREFIX.$land), false, max: 3);
                }

                return new PanelResult(modal: $modal);
            case 'mlands':
                $deck = $builder->find($u, $arg(0));
                $changed = [];
                foreach (self::LANDS as $land) {
                    if ($field(strtolower($land)) === '') {
                        continue;
                    }
                    $want = self::number($field(strtolower($land)), 0, $land, 0);
                    $have = $deck->main->get(BasicLands::PREFIX.$land);
                    if ($want > $have) {
                        $builder->add($u, $deck->id, BasicLands::PREFIX.$land, $want - $have);
                    } elseif ($want < $have) {
                        $builder->remove($u, $deck->id, BasicLands::PREFIX.$land, $have - $want);
                    }
                    if ($want !== $have) {
                        $changed[] = "{$want} {$land}";
                    }
                }

                return $panel($this->deck($u, $deck->id, $changed === [] ? 'No lands changed.' : 'Lands set: '.implode(', ', $changed).'.'));
            case 'ddel':
                $deck = $builder->find($u, $arg(0));

                return $panel(Menu::text("### Delete **{$deck->name}**?\nIts cards stay in your collection. This can't be undone.", accent: PocketMessageBuilder::accent('R'))
                    ->addComponent(ActionRow::new()
                        ->addComponent(Menu::styled(Button::STYLE_DANGER, $u, 'Delete it', 'ddelok', $deck->id))
                        ->addComponent(Menu::button($u, 'Keep it', 'deck', $deck->id))));
            case 'ddelok':
                return $panel($this->decks($u, 'Deleted **'.$builder->delete($u, $arg(0))->name.'**.'));
            case 'rentals':
                return $panel($this->rentals($u));
            case 'rent':
                $rental = $this->pocket->rentals->rent($u, $name, $value);

                return $panel($this->rentalView($u, RentalDeck::PREFIX.$rental->id, "You now play with **{$rental->name}** until you pick one of your own decks. You have ".Text::plural($this->pocket->rentals->gamesLeft($u), 'rental game').' left today.'));

                // Play.
            case 'play':
                return $panel($this->play($u));
            case 'pqueue':
                $result = $matches->queue($u, $name, $value);
                if ($result['match'] !== null) {
                    return $panel($this->play($u, '⚔️ Found an opponent! The board is posted in the channel.'), MatchMessageBuilder::board($result['match'], true));
                }

                return $panel($this->play($u, "🔎 You are in the {$result['mode']->label} queue with **{$result['deck']->name}**."));
            case 'pchal':
                $opponent = $value;
                $match = $matches->challenge($u, $name, $opponent, $names[$opponent] ?? $this->pocket->players->find($opponent)?->name ?: 'Opponent');

                return $panel($this->play($u, '⚔️ Challenge sent; it waits for them in the channel.'), MatchMessageBuilder::challenge($match));
            case 'pleave':
                $mode = $matches->unqueue($u);

                return $panel($this->play($u, $mode === null ? 'You were not in a queue.' : "You left the {$mode->label} queue."));
            case 'pconc':
                $match = $matches->current($u) ?? throw new \InvalidArgumentException('You are not in a match.');
                $what = $match->status === MatchRecord::PENDING ? 'Call off your challenge?' : 'Concede your game? It counts as a loss.';

                return $panel(Menu::text("### {$what}", accent: PocketMessageBuilder::accent('R'))
                    ->addComponent(ActionRow::new()
                        ->addComponent(Menu::styled(Button::STYLE_DANGER, $u, $match->status === MatchRecord::PENDING ? 'Call it off' : 'Concede', 'pconcok'))
                        ->addComponent(Menu::button($u, 'Keep playing', 'play'))));
            case 'pconcok':
                $match = $matches->leave($u);

                return $panel($this->play($u, $match->status === MatchRecord::OVER ? 'You conceded.' : 'Called off.'), match (true) {
                    $match->practice => null,
                    $match->status === MatchRecord::OVER => MatchMessageBuilder::board($match),
                    default => MatchMessageBuilder::closed($match),
                });
            case 'pboard':
                $match = $matches->current($u) ?? throw new \InvalidArgumentException('You are not in a match.');
                if ($match->practice) {
                    return $panel(MatchMessageBuilder::board($match));
                }

                return $panel($this->play($u, 'The board is posted in the channel.'), $match->status === MatchRecord::PENDING ? MatchMessageBuilder::challenge($match) : MatchMessageBuilder::board($match));
            case 'pladder':
                $mode = $matches->modes->get($value);

                return $panel(MatchMessageBuilder::ladder($mode, $matches->ladder->standings($mode->id), $u)->addComponent(Menu::nav($u, 'Play', 'play')));
            case 'plog':
                return new PanelResult(MatchMessageBuilder::log($matches->forReview($u)), separate: true);

                // Drafts.
            case 'draft':
                return $panel($this->draft($u));
            case 'dnewpod':
                $draft = $drafts->create($u, $name, $value, $channelId);

                return $panel($this->draft($u, 'Your pod is open; its message is posted in the channel.'), $this->podMessage($draft));
            case 'djoin':
                $draft = $drafts->join($u, $name, $value);

                return $panel($this->draft($u, 'You joined the pod.'), $this->podMessage($draft));
            case 'dstart':
                return $panel($this->draft($u, 'The draft has started.'), $this->podMessage($drafts->start($u)));
            case 'dleave':
                $draft = $drafts->current($u) ?? throw new \InvalidArgumentException('You are not in a draft.');

                return $panel(Menu::text('### Leave the '.$draft->setName." draft?\n".($draft->status === Draft::SIGNUP
                    ? 'You get your '.PocketMessageBuilder::points($draft->fee).' back.'
                    : 'You keep the cards you drafted, but you are out of the event and its prizes.'), accent: PocketMessageBuilder::accent('R'))
                    ->addComponent(ActionRow::new()
                        ->addComponent(Menu::styled(Button::STYLE_DANGER, $u, 'Leave', 'dleaveok'))
                        ->addComponent(Menu::button($u, 'Stay', 'draft'))));
            case 'dleaveok':
                $draft = $drafts->leave($u);

                return $panel($this->draft($u, match ($draft->status) {
                    Draft::SIGNUP, Draft::CANCELLED => "You left the {$draft->setName} pod and got your ".PocketMessageBuilder::points($draft->fee).' back.',
                    Draft::DRAFTING => "You left the {$draft->setName} draft. The bot picks for your seat until the packs are empty, then your picks go to your collection.",
                    default => "You left the {$draft->setName} draft. Your ".$draft->seat($u)->picks->total().' drafted cards are in your collection.',
                }));
            case 'dpack':
                return $panel($this->draftPack($u));
            case 'dpool':
                return $panel($this->draftPool($u));
            case 'dplay':
                return $panel($this->draft($u, 'Your game is posted in the channel.'), MatchMessageBuilder::board($drafts->play($u), true));
            case 'dpadd':
            case 'dprem':
                $notes = [];
                foreach ($values as $card) {
                    [, $notes[]] = $action === 'dpadd' ? $drafts->addToDeck($u, (string) $card, 1) : $drafts->removeFromDeck($u, (string) $card, 1);
                }

                return $panel($this->draftPool($u, implode(' ', $notes)));
            case 'dplands':
                $seat = ($drafts->current($u) ?? throw new \InvalidArgumentException('You are not in a draft.'))->seat($u);
                $modal = new Modal('Basic lands in your draft deck', Menu::id($u, 'mplands'));
                foreach (self::LANDS as $land) {
                    $modal->text(strtolower($land), $land, (string) $seat->deck->get(BasicLands::PREFIX.$land), false, max: 2);
                }

                return new PanelResult(modal: $modal);
            case 'mplands':
                $lands = [];
                foreach (self::LANDS as $land) {
                    if ($field(strtolower($land)) !== '') {
                        $lands[$land] = self::number($field(strtolower($land)), 0, $land, 0);
                    }
                }
                if ($lands === []) {
                    throw new \InvalidArgumentException('Say how many of at least one basic land.');
                }
                $drafts->setLands($u, $lands);

                return $panel($this->draftPool($u, 'Lands set.'));
            case 'dpauto':
                $drafts->autoBuild($u);

                return $panel($this->draftPool($u, 'The bot built your deck from your two strongest colors. Change it as you like, then **Ready**.'));
            case 'dpready':
                $draft = $drafts->ready($u);

                return $panel($this->draftPool($u, $draft->status === Draft::PLAYING ? 'Your deck is in. Round '.count($draft->rounds).' has started.' : 'Your deck is in. Rounds start when everyone is ready.'));

                // Shop.
            case 'shop':
                return $panel($this->shop($u));
            case 'sbuy':
            case 'ssell':
                $buying = $action === 'sbuy';

                return new PanelResult(modal: (new Modal($buying ? 'Buy a card' : 'Sell a card', Menu::id($u, $buying ? 'mbuy' : 'msell')))
                    ->text('card', 'Card name', null, true, placeholder: $buying ? 'Any card from the game\'s sets' : 'A card you own')
                    ->text('count', 'How many', '1', false, max: 2));
            case 'mbuy':
                return $panel(ShopMessageBuilder::receipt($shop->buyCard($u, $name, $field('card'), self::number($field('count'), 1, 'How many')), false)->addComponent(Menu::nav($u, 'Shop', 'shop')));
            case 'msell':
                return $panel(ShopMessageBuilder::receipt($shop->sell($u, $name, $field('card'), self::number($field('count'), 1, 'How many')), true)->addComponent(Menu::nav($u, 'Shop', 'shop')));
            case 'sextra':
                return new PanelResult(modal: (new Modal('Sell extra copies', Menu::id($u, 'mextra')))
                    ->text('keep', 'Copies of each card to keep', '4', false, max: 2)
                    ->choice('rarity', 'Rarity', [self::ANY => 'Any rarity'] + array_combine(CardPool::RARITIES, array_map('ucfirst', CardPool::RARITIES)), self::ANY)
                    ->text('set', 'Set code', null, false, placeholder: 'Empty for every set', max: 8));
            case 'mextra':
                $keep = self::number($field('keep'), 4, 'Copies to keep', 0);
                $rarity = self::orNone($field('rarity'));
                $set = strtoupper($field('set'));

                return $panel(ShopMessageBuilder::extras($u, $shop->extras($u, $keep, $rarity, $set ?: null), $trades->cardData(...), $keep, (string) $rarity, $set)->addComponent(Menu::nav($u, 'Shop', 'shop')));
            case 'sprice':
                return new PanelResult(modal: (new Modal('Check a price', Menu::id($u, 'mprice')))->text('card', 'Card name', null, true));
            case 'mprice':
                $quote = $shop->quote($field('card'));

                return $panel(ShopMessageBuilder::quote($quote, $this->pocket->inventories->get($u)->cards->get($quote['card']['uuid']), $shop->balance($u))->addComponent(Menu::nav($u, 'Shop', 'shop')));

                // Trades.
            case 'trades':
                return $panel($this->trades($u));
            case 'tnew':
                if ($value === $u) {
                    throw new \InvalidArgumentException('You cannot trade with yourself.');
                }
                $to = $names[$value] ?? $this->pocket->players->find($value)?->name ?: 'Player';

                return new PanelResult(modal: self::offerForm("Trade with {$to}", Menu::id($u, 'mtrade', $value, self::packName($to))));
            case 'mtrade':
                $offer = $trades->offer(
                    $u,
                    $name,
                    $arg(0),
                    self::unpackName($arg(1)),
                    self::cardLines($field('give')),
                    self::cardLines($field('want')),
                    self::number($field('gp'), 0, 'Points you give', 0),
                    self::number($field('wp'), 0, 'Points you want', 0),
                );

                return $panel($this->trades($u, 'Offer sent; it waits for them in the channel.'), TradeMessageBuilder::offer($offer, $trades->cardData(...)));
            case 'tadd':
                $offer = $trades->find($value) ?? throw new \OutOfBoundsException('That trade offer is no longer open.');

                return new PanelResult(modal: self::offerForm("Add to your offer to {$offer->to['name']}", Menu::id($u, 'mtadd', $offer->id)));
            case 'mtadd':
                $offer = null;
                foreach (self::cardLines($field('give')) as $card => $count) {
                    $offer = $trades->add($u, $arg(0), false, (string) $card, $count);
                }
                foreach (self::cardLines($field('want')) as $card => $count) {
                    $offer = $trades->add($u, $arg(0), true, (string) $card, $count);
                }
                foreach (['gp' => false, 'wp' => true] as $key => $want) {
                    if (($points = self::number($field($key), 0, 'Points', 0)) > 0) {
                        $offer = $trades->add($u, $arg(0), $want, null, 1, $points);
                    }
                }
                if ($offer === null) {
                    throw new \InvalidArgumentException('Add a card or some points.');
                }

                return $panel($this->trades($u, 'Offer changed; the new version is posted in the channel.'), TradeMessageBuilder::offer($offer, $trades->cardData(...), 'The offer changed. Accept buttons on earlier copies of it no longer work.'));
            case 'tcancel':
                $offer = $trades->cancel($u, $value);

                return $panel($this->trades($u, "Called off your offer to **{$offer->to['name']}**."));
        }

        return $panel($this->home($u, $name, 'That button is from an older version of the bot.'));
    }

    /**
     * The home panel: where the player stands in every part of the game.
     *
     * @param string      $playerId
     * @param string      $name
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function home(string $playerId, string $name = '', ?string $note = null): MessageBuilder
    {
        $player = $this->pocket->players->find($playerId);
        $name = $player?->name ?: ($name ?: 'Player');
        $next = $this->pocket->dailyPacks->nextPackAt($player);

        $status = ['🪙 '.PocketMessageBuilder::points($this->pocket->shop->balance($playerId))];
        $status[] = $next === null ? '🎁 Your free pack is **ready**.' : "🎁 Next free pack <t:{$next}:R>.";
        $status[] = '🃏 '.$this->playingWith($playerId);

        $matches = $this->pocket->matches;
        if (($match = $matches->current($playerId)) !== null) {
            $status[] = $match->status === MatchRecord::PENDING ? '⚔️ A challenge is waiting for an answer.' : '⚔️ You are in a game: **Play** shows the board.';
        } elseif (($queued = $matches->queued($playerId)) !== null) {
            $status[] = "🔎 Waiting in the {$queued['mode']->label} queue.";
        }
        if (($draft = $this->pocket->drafts->current($playerId)) !== null) {
            $status[] = "🎴 In the {$draft->setName} draft (".(DraftMessageBuilder::STAGES[$draft->status] ?? $draft->status).').';
        }
        if (($received = count($this->pocket->trades->forPlayer($playerId)['received'])) > 0) {
            $status[] = '🤝 '.Text::plural($received, 'trade offer').' waiting for you.';
        }
        $board = $this->pocket->quests->board($playerId);
        $done = count(array_filter($board['daily'], fn (array $entry) => $entry['progress'] >= $entry['quest']->goal));
        $status[] = "🎯 Daily quests {$done}/".count($board['daily']).' done.';
        if ($match === null && $matches->history($playerId) === []) {
            $status[] = '📖 New to Magic? **How to play** teaches the basics in a few minutes, then lets you practice against a bot.';
        }

        return Menu::home($playerId, $name, $status, $note);
    }

    /**
     * A page of How to play, with buttons to turn the pages and start a
     * practice game.
     *
     * @param string      $playerId
     * @param int         $page     From 0.
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function tutorial(string $playerId, int $page = 0, ?string $note = null): MessageBuilder
    {
        $u = $playerId;
        [$page, $title, $text] = Tutorial::page($page);
        $last = Tutorial::count() - 1;
        $message = Menu::text(sprintf("### 📖 How to play · %s\n-# Page %d of %d\n%s", $title, $page + 1, $last + 1, $text), $note, PocketMessageBuilder::accent('G'));

        $pages = [];
        foreach (array_keys(Tutorial::PAGES) as $number => $name) {
            $pages[(string) $number] = ($number + 1).'. '.$name;
        }
        $message->addComponent(Menu::select(Menu::id($u, 'tjump'), 'Go to a page', $pages, default: (string) $page));

        $row = ActionRow::new()
            ->addComponent(Menu::button($u, '◀ Back', 'tut', (string) ($page - 1))->setDisabled($page === 0));
        if ($page < $last) {
            $row->addComponent(Menu::styled(Button::STYLE_PRIMARY, $u, 'Next ▶', 'tut', (string) ($page + 1)))
                ->addComponent(Menu::button($u, '🤖 Practice game', 'tplay'));
        } else {
            $row->addComponent(Menu::styled(Button::STYLE_SUCCESS, $u, '🤖 Start a practice game', 'tplay'));
        }

        return $message->addComponent($row->addComponent(Menu::menuButton($u)));
    }

    /**
     * The packs panel: pick a set and a color, then open the free pack or buy one.
     *
     * @param string      $playerId
     * @param string      $set   Empty for a surprise.
     * @param string      $color Empty for a surprise.
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function packs(string $playerId, string $set = '', string $color = '', ?string $note = null): MessageBuilder
    {
        $choices = $this->pocket->dailyPacks->choices();
        $prices = $this->pocket->shop->packPrices();
        $set = isset($choices[$set]) ? $set : '';
        $color = in_array($color, CardPool::COLORS, true) ? $color : '';
        $next = $this->pocket->dailyPacks->nextPackAt($this->pocket->players->find($playerId));

        $pick = sprintf(
            '**%s** · **%s**',
            $set === '' ? '🎲 Any set' : "{$choices[$set]['name']} ({$set})",
            $color === '' ? '🎲 Any color' : PocketMessageBuilder::COLOR_NAMES[$color],
        );
        $text = "### 🎁 Packs\n".($next === null ? 'Your free pack is ready.' : "Your next free pack is ready <t:{$next}:R>.")
            ."\nOpening: {$pick}\n-# 15 cards of one color of one set, with at least one rare or mythic rare. You have ".PocketMessageBuilder::points($this->pocket->shop->balance($playerId)).'.';
        if ($choices === []) {
            return Menu::text("### 🎁 Packs\nNo packs yet: no card pools have been imported.", $note)->addComponent(Menu::nav($playerId));
        }

        $message = Menu::text($text, $note, PocketMessageBuilder::accent($color ?: CardPool::MULTICOLOR));

        $sets = [self::ANY => '🎲 Any set'];
        foreach (array_reverse($choices, true) as $code => $choice) {
            if ($color === '' || in_array($color, $choice['colors'], true)) {
                $sets[(string) $code] = ['label' => "{$choice['name']} ({$code})", 'description' => number_format($prices[$code]['price'] ?? 0).' points to buy'];
            }
        }
        $colors = [self::ANY => '🎲 Any color'];
        foreach (CardPool::COLORS as $letter) {
            if ($set === '' || in_array($letter, $choices[$set]['colors'], true)) {
                $colors[$letter] = PocketMessageBuilder::COLOR_NAMES[$letter];
            }
        }
        $message
            ->addComponent(Menu::select(Menu::id($playerId, 'pset', $color), 'Pick a set', $sets, default: $set ?: self::ANY))
            ->addComponent(Menu::select(Menu::id($playerId, 'pcolor', $set), 'Pick a color', $colors, default: $color ?: self::ANY));

        $price = $set !== '' ? ' ('.PocketMessageBuilder::points($prices[$set]['price'] ?? 0).')' : '';

        return $message->addComponent(ActionRow::new()
            ->addComponent(Menu::styled(Button::STYLE_SUCCESS, $playerId, 'Open free pack', 'popen', $set, $color)->setDisabled($next !== null))
            ->addComponent(Menu::styled(Button::STYLE_PRIMARY, $playerId, "Buy a pack{$price}", 'pbuy', $set, $color))
            ->addComponent(Menu::menuButton($playerId)));
    }

    /**
     * A player's decks, with a menu to open one.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function decks(string $playerId, ?string $note = null): MessageBuilder
    {
        $builder = $this->pocket->deckBuilder;
        $decks = $builder->list($playerId);
        $rental = $this->pocket->rentals->activeRental($playerId);
        $message = Menu::withNote(PocketMessageBuilder::deckList($decks, $builder->activeDeckId($playerId), $rental === null ? null : $this->rentalName($rental)), $note);

        $choices = [];
        if ($rental !== null) {
            $choices[$rental] = ['label' => $this->rentalName($rental), 'description' => 'Rental · active'];
        }
        foreach ($decks as $deck) {
            $choices[$deck->id] = ['label' => $deck->name, 'description' => (DeckBuilder::FORMATS[$deck->format] ?? $deck->format)." · {$deck->main->total()} + {$deck->side->total()} cards"];
        }
        if (($select = Menu::select(Menu::id($playerId, 'dopen'), 'Open a deck', $choices)) !== null) {
            $message->addComponent($select);
        }

        return $message->addComponent(ActionRow::new()
            ->addComponent(Menu::styled(Button::STYLE_SUCCESS, $playerId, '➕ New deck', 'dnew'))
            ->addComponent(Menu::button($playerId, '🎟️ Rental decks', 'rentals'))
            ->addComponent(Menu::menuButton($playerId)));
    }

    /**
     * One of the player's decks, with everything to edit it.
     *
     * @param string      $playerId
     * @param string      $deckId   Id or name.
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function deck(string $playerId, string $deckId, ?string $note = null): MessageBuilder
    {
        $builder = $this->pocket->deckBuilder;
        $deck = $builder->find($playerId, $deckId);
        $active = $this->pocket->rentals->activeRental($playerId) === null && $builder->activeDeckId($playerId) === $deck->id;
        $message = PocketMessageBuilder::deck($deck, $builder->cardData(...), $active, $note, $this->pocket->matches->problems($deck));
        $u = $playerId;

        $message->addComponent(ActionRow::new()
            ->addComponent(Menu::styled(Button::STYLE_PRIMARY, $u, '➕ Add cards', 'dbrowse', $deck->id, '0', ''))
            ->addComponent(Menu::button($u, '⌨️ Add by name', 'dtype', $deck->id))
            ->addComponent(Menu::button($u, '➖ Take out', 'dremv', $deck->id))
            ->addComponent(Menu::styled(Button::STYLE_SUCCESS, $u, $active ? '✅ Playing with it' : 'Play with it', 'duse', $deck->id)->setDisabled($active))
            ->addComponent(Menu::button($u, '✏️ Rename', 'dren', $deck->id)));
        $message->addComponent(Menu::select(Menu::id($u, 'dfmt', $deck->id), 'Format', DeckBuilder::FORMATS, default: $deck->format));

        $inDeck = [];
        foreach ($deck->main as $key => $count) {
            $card = $builder->cardData((string) $key);
            $inDeck[(string) $key] = ['label' => "{$card['name']} ×{$count}", 'description' => BasicLands::isBasic((string) $key) ? 'Basic land' : ucfirst((string) $card['rarity'])];
        }
        uasort($inDeck, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));
        if (($select = Menu::select(Menu::id($u, 'dqrem', $deck->id), 'Take out one copy of…', $inDeck, 25)) !== null) {
            $message->addComponent($select);
        }

        $row = ActionRow::new()->addComponent(Menu::button($u, '🏔️ Basic lands', 'dlands', $deck->id));
        if ($deck->format === 'commander' || $deck->commander !== null) {
            $row->addComponent(Menu::button($u, '👑 Commander', 'dcmd', $deck->id));
        }

        return $message->addComponent($row
            ->addComponent(Menu::styled(Button::STYLE_DANGER, $u, '🗑️ Delete', 'ddel', $deck->id))
            ->addComponent(Menu::button($u, '◀ Decks', 'decks'))
            ->addComponent(Menu::menuButton($u)));
    }

    /**
     * Cards the player owns with copies to spare for a deck, a page at a
     * time, with a menu to add them.
     *
     * @param string      $playerId
     * @param string      $deckId
     * @param int         $page     0-based.
     * @param string      $color    Only this pack color; empty for all.
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function browse(string $playerId, string $deckId, int $page, string $color, ?string $note = null): MessageBuilder
    {
        $builder = $this->pocket->deckBuilder;
        $deck = $builder->find($playerId, $deckId);
        $color = in_array($color, CardPool::COLORS, true) ? $color : '';
        $used = $deck->allCards();

        $spare = [];
        foreach ($this->pocket->collection->entries($this->pocket->inventories->get($playerId), ['color' => $color ?: null]) as $entry) {
            $left = $entry['count'] - $used->get($entry['card']['uuid']);
            if ($left > 0) {
                $spare[] = [$entry['card'], $left];
            }
        }
        $pages = max(1, (int) ceil(count($spare) / self::BROWSE_PAGE));
        $page = min(max(0, $page), $pages - 1);
        $shown = array_slice($spare, $page * self::BROWSE_PAGE, self::BROWSE_PAGE);

        $lines = array_map(fn (array $entry) => PocketMessageBuilder::cardLine($entry[0])." · {$entry[1]} to spare", $shown);
        $text = "### ➕ Add to **{$deck->name}**\n-# ".($color === '' ? 'Every color' : PocketMessageBuilder::COLOR_NAMES[$color]).' · '.Text::plural(count($spare), 'card').' with copies to spare'
            .($pages > 1 ? ' · page '.($page + 1)." of {$pages}" : '')." · main deck {$deck->main->total()}\n"
            .($lines === [] ? 'No cards to add here. Basic lands are under **🏔️ Basic lands** on the deck.' : implode("\n", $lines));
        $message = Menu::text($text, $note, PocketMessageBuilder::accent($color ?: CardPool::MULTICOLOR));

        $choices = [];
        foreach ($shown as [$card, $left]) {
            $choices[$card['uuid']] = ['label' => $card['name'], 'description' => ucfirst((string) $card['rarity'])." · {$card['setCode']} · {$left} to spare"];
        }
        if (($select = Menu::select(Menu::id($playerId, 'dqadd', $deck->id, (string) $page, $color), 'Add one copy of…', $choices, 25)) !== null) {
            $message->addComponent($select);
        }
        $message->addComponent(Menu::select(Menu::id($playerId, 'dbcol', $deck->id), 'Only one color', [self::ANY => 'Every color'] + PocketMessageBuilder::COLOR_NAMES, default: $color ?: self::ANY));

        $row = ActionRow::new();
        if ($pages > 1) {
            $row->addComponent(Menu::button($playerId, '◀ Page', 'dbrowse', $deck->id, (string) ($page - 1), $color)->setDisabled($page === 0))
                ->addComponent(Menu::button($playerId, 'Page ▶', 'dbrowse', $deck->id, (string) ($page + 1), $color)->setDisabled($page >= $pages - 1));
        }

        return $message->addComponent($row
            ->addComponent(Menu::styled(Button::STYLE_PRIMARY, $playerId, '◀ Back to the deck', 'deck', $deck->id))
            ->addComponent(Menu::menuButton($playerId)));
    }

    /**
     * The rental decks, with a menu to rent one.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function rentals(string $playerId, ?string $note = null): MessageBuilder
    {
        $rentals = $this->pocket->rentals;
        $available = $rentals->available();
        $message = Menu::withNote(PocketMessageBuilder::rentalList($available, $rentals->gamesLeft($playerId), $rentals->rules->rentalGamesPerDay, $rentals->mode()->label, $this->pocket->players->find($playerId)?->activeRental), $note);

        $choices = [];
        foreach ($available as $deck) {
            $choices[RentalDeck::PREFIX.$deck->id] = ['label' => $deck->name, 'description' => ($deck->setName !== '' ? $deck->setName : $deck->setCode)." · {$deck->mainCount()} cards"];
        }
        if (($select = Menu::select(Menu::id($playerId, 'rent'), 'Play with a rental deck', $choices)) !== null) {
            $message->addComponent($select);
        }

        return $message->addComponent(Menu::nav($playerId, 'Decks', 'decks'));
    }

    /**
     * A rental deck the player can see.
     *
     * @param string      $playerId
     * @param string      $rentalId With {@see RentalDeck::PREFIX}.
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function rentalView(string $playerId, string $rentalId, ?string $note = null): MessageBuilder
    {
        $rentals = $this->pocket->rentals;
        $rental = $rentals->find($rentalId);
        $deck = $rental->deck($playerId, $rentals->mode()->id);

        return PocketMessageBuilder::deck($deck, $rental->card(...), $rentals->activeRental($playerId) === $deck->id, $note, $this->pocket->matches->problems($deck), false)
            ->addComponent(Menu::nav($playerId, 'Rental decks', 'rentals'));
    }

    /**
     * The play panel: the player's game or queue, and ways to start one.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function play(string $playerId, ?string $note = null): MessageBuilder
    {
        $matches = $this->pocket->matches;
        $u = $playerId;
        $match = $matches->current($playerId);
        $queued = $match === null ? $matches->queued($playerId) : null;
        $sizes = $matches->queueSizes();

        $lines = ['### ⚔️ Play', '🃏 '.$this->playingWith($playerId)];
        if ($match !== null) {
            $lines[] = $match->status === MatchRecord::PENDING
                ? 'You have a challenge waiting for an answer.'
                : 'You are in a game. **Show the board** posts it in the channel; act from **Your hand & actions** on it.';
        } elseif ($queued !== null) {
            $lines[] = "🔎 You are waiting in the {$queued['mode']->label} queue with **{$queued['deckName']}** (rating {$queued['rating']}) since <t:{$queued['since']}:R>.";
        } else {
            $lines[] = 'Find a **ranked** game in a mode, or **challenge** a player you pick to a friendly game. Your active deck plays.';
        }
        $modes = [];
        foreach ($matches->modes->all() as $id => $mode) {
            $modes[] = "**{$mode->label}** · ".($mode->playable ? Text::plural($sizes[$id] ?? 0, 'player').' waiting' : 'coming soon')."\n-# {$mode->summary()}";
        }
        $message = Menu::text(implode("\n", $lines)."\n\n".implode("\n", $modes), $note, PocketMessageBuilder::accent('R'));

        if ($match === null && $queued === null) {
            $playable = [];
            foreach ($matches->modes->playable() as $id => $label) {
                $playable[(string) $id] = ['label' => $label, 'description' => Text::plural($sizes[$id] ?? 0, 'player').' waiting'];
            }
            if (($select = Menu::select(Menu::id($u, 'pqueue'), 'Find a ranked game in…', $playable)) !== null) {
                $message->addComponent($select);
            }
            $message->addComponent(Menu::userSelect(Menu::id($u, 'pchal'), 'Challenge a player to a friendly game…'));
        }

        $ladders = [];
        foreach ($matches->modes->all() as $id => $mode) {
            $ladders[(string) $id] = $mode->label;
        }
        $message->addComponent(Menu::select(Menu::id($u, 'pladder'), 'See a ladder', $ladders));

        $row = ActionRow::new();
        if ($match !== null) {
            $row->addComponent(Menu::styled(Button::STYLE_PRIMARY, $u, 'Show the board', 'pboard'))
                ->addComponent(Menu::styled(Button::STYLE_DANGER, $u, $match->status === MatchRecord::PENDING ? 'Call off' : 'Concede', 'pconc'));
        } elseif ($queued !== null) {
            $row->addComponent(Menu::styled(Button::STYLE_DANGER, $u, 'Leave the queue', 'pleave'));
        }
        if (($match !== null && $match->game !== null) || $matches->history($playerId) !== []) {
            $row->addComponent(Menu::button($u, '📜 Game record', 'plog'));
        }
        if ($match === null && $queued === null) {
            $row->addComponent(Menu::button($u, '🤖 Practice vs bot', 'tplay'));
        }
        $row->addComponent(Menu::button($u, '🃏 Decks', 'decks'));

        return $message->addComponent($row->addComponent(Menu::menuButton($u)));
    }

    /**
     * The draft panel: the player's draft, or the open pods and a menu to make one.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function draft(string $playerId, ?string $note = null): MessageBuilder
    {
        $drafts = $this->pocket->drafts;
        $u = $playerId;
        $draft = $drafts->current($playerId);

        if ($draft !== null) {
            $message = Menu::withNote($this->podMessage($draft), $note);
            $row = ActionRow::new();
            match ($draft->status) {
                Draft::SIGNUP => $draft->hostId === $playerId ? $row->addComponent(Menu::styled(Button::STYLE_PRIMARY, $u, 'Start the draft', 'dstart')) : null,
                Draft::DRAFTING => $row->addComponent(Menu::styled(Button::STYLE_PRIMARY, $u, '🎴 My pack', 'dpack')),
                default => $row->addComponent(Menu::styled(Button::STYLE_PRIMARY, $u, '🃏 My deck', 'dpool')),
            };
            if ($draft->status === Draft::PLAYING) {
                $row->addComponent(Menu::styled(Button::STYLE_SUCCESS, $u, '⚔️ Play my game', 'dplay'));
            }

            return $message->addComponent($row
                ->addComponent(Menu::styled(Button::STYLE_DANGER, $u, 'Leave', 'dleave'))
                ->addComponent(Menu::menuButton($u)));
        }

        $rules = $drafts->rules;
        $open = $drafts->open();
        $lines = array_map(fn (Draft $pod) => "**{$pod->setName}** · ".count($pod->seats)."/{$pod->size} players · starts <t:{$pod->deadline}:R> at the latest", $open);
        $message = Menu::text(
            "### 🎴 Booster drafts\nPay ".PocketMessageBuilder::points($rules->entryFee).' to enter, draft '.$rules->packs." packs with the table, build a deck, play Swiss rounds, and keep every card you draft.\n-# You have ".PocketMessageBuilder::points($this->pocket->shop->balance($playerId)).".\n\n"
            .($lines === [] ? 'No pod is open. Make one for a set below.' : "**Open pods**\n".implode("\n", $lines)),
            $note,
        );

        $pods = [];
        foreach ($open as $pod) {
            $pods[$pod->id] = ['label' => "{$pod->setName} ({$pod->setCode})", 'description' => count($pod->seats)."/{$pod->size} players"];
        }
        if (($select = Menu::select(Menu::id($u, 'djoin'), 'Join a pod', $pods)) !== null) {
            $message->addComponent($select);
        }
        $sets = [];
        foreach (array_reverse($this->pocket->pools->all(), true) as $pool) {
            if (array_filter(CardPool::COLORS, $pool->hasRareSlot(...)) !== []) {
                $sets[$pool->setCode] = "{$pool->setName} ({$pool->setCode})";
            }
        }
        if (($select = Menu::select(Menu::id($u, 'dnewpod'), 'Make a pod for…', $sets)) !== null) {
            $message->addComponent($select);
        }

        return $message->addComponent(Menu::nav($u));
    }

    /**
     * The pack in front of a drafting player.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function draftPack(string $playerId, ?string $note = null): MessageBuilder
    {
        $drafts = $this->pocket->drafts;
        $draft = $drafts->current($playerId) ?? $drafts->last($playerId) ?? throw new \InvalidArgumentException('You are not in a draft. Join one from the **Draft** menu.');

        return DraftMessageBuilder::draftPack($draft, $draft->seat($playerId), $drafts->packCards($draft, $playerId), $drafts->pickDeadline($draft, $playerId), $note);
    }

    /**
     * A drafting player's picks and deck, with everything to build it.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function draftPool(string $playerId, ?string $note = null): MessageBuilder
    {
        $drafts = $this->pocket->drafts;
        $draft = $drafts->current($playerId) ?? $drafts->last($playerId) ?? throw new \InvalidArgumentException('You are not in a draft. Join one from the **Draft** menu.');
        $seat = $draft->seat($playerId);

        return DraftMessageBuilder::pool($draft, $seat, $this->pocket->deckBuilder->cardData(...), $drafts->deckProblems($seat), $note);
    }

    /**
     * The shop panel.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function shop(string $playerId, ?string $note = null): MessageBuilder
    {
        $shop = $this->pocket->shop;
        $u = $playerId;

        return Menu::withNote(ShopMessageBuilder::balance($shop->balance($playerId), $shop->prices, $shop->packPrices()), $note)
            ->addComponent(ActionRow::new()
                ->addComponent(Menu::styled(Button::STYLE_PRIMARY, $u, 'Buy a card', 'sbuy'))
                ->addComponent(Menu::styled(Button::STYLE_PRIMARY, $u, '🎁 Buy a pack', 'packs'))
                ->addComponent(Menu::button($u, 'Sell a card', 'ssell'))
                ->addComponent(Menu::button($u, 'Sell extras', 'sextra'))
                ->addComponent(Menu::button($u, 'Check a price', 'sprice')))
            ->addComponent(Menu::nav($u));
    }

    /**
     * The trades panel: open offers, and menus to make, add to or call off one.
     *
     * @param string      $playerId
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    public function trades(string $playerId, ?string $note = null): MessageBuilder
    {
        $trades = $this->pocket->trades;
        $offers = $trades->forPlayer($playerId);
        $message = Menu::withNote(TradeMessageBuilder::list($playerId, $offers, $trades->cardData(...)), $note)
            ->addComponent(Menu::userSelect(Menu::id($playerId, 'tnew'), 'Offer a trade to…'));

        $sent = [];
        foreach ($offers['sent'] as $offer) {
            $sent[$offer->id] = ['label' => "To {$offer->to['name']}", 'description' => 'Ends '.date('M j', $offer->expiresAt)];
        }
        if ($sent !== []) {
            $message->addComponent(Menu::select(Menu::id($playerId, 'tadd'), 'Add cards or points to an offer…', $sent));
            $message->addComponent(Menu::select(Menu::id($playerId, 'tcancel'), 'Call off an offer…', $sent));
        }

        return $message->addComponent(Menu::nav($playerId));
    }

    /**
     * The quests panel.
     *
     * @param string $playerId
     *
     * @return MessageBuilder
     */
    public function quests(string $playerId): MessageBuilder
    {
        return PocketMessageBuilder::quests($this->pocket->quests->board($playerId), $this->pocket->shop->balance($playerId))
            ->addComponent(Menu::nav($playerId));
    }

    /**
     * A pod's public message.
     *
     * @param Draft $draft
     *
     * @return MessageBuilder
     */
    public function podMessage(Draft $draft): MessageBuilder
    {
        return DraftMessageBuilder::pod($draft, $this->pocket->drafts->rules, $this->pocket->drafts->prizeTable($draft));
    }

    /**
     * Card counts typed one per line: `2 Shock`, `2x Shock`, `Shock x2` or `Shock`.
     *
     * @param string $text
     *
     * @return array<string, int> Card name => copies.
     */
    public static function cardLines(string $text): array
    {
        $cards = [];
        foreach (preg_split('/[\r\n]+/', $text) ?: [] as $line) {
            $line = trim($line, " \t,;");
            if ($line === '') {
                continue;
            }
            $count = 1;
            if (preg_match('/^(\d+)\s*[x×]?\s+(.+)$/iu', $line, $m)) {
                [$count, $line] = [(int) $m[1], $m[2]];
            } elseif (preg_match('/^(.+?)\s+[x×]\s*(\d+)$/iu', $line, $m)) {
                [$line, $count] = [$m[1], (int) $m[2]];
            }
            $line = trim($line);
            $cards[$line] = ($cards[$line] ?? 0) + $count;
        }
        if (count($cards) > TradeService::MAX_CARDS) {
            throw new \InvalidArgumentException('An offer holds at most '.TradeService::MAX_CARDS.' different cards a side.');
        }

        return $cards;
    }

    /**
     * A whole number typed in a form.
     *
     * @param string $typed
     * @param int    $default When empty.
     * @param string $what    The field, for the error.
     * @param int    $min
     *
     * @return int
     */
    public static function number(string $typed, int $default, string $what, int $min = 1): int
    {
        $typed = str_replace([',', ' '], '', trim($typed));
        if ($typed === '') {
            return $default;
        }
        if (! ctype_digit($typed) || (int) $typed < $min || strlen($typed) > 9) {
            throw new \InvalidArgumentException("**{$what}** must be a whole number of at least {$min}.");
        }

        return (int) $typed;
    }

    /**
     * A name squeezed into a custom id.
     *
     * @param string $name
     *
     * @return string
     */
    public static function packName(string $name): string
    {
        return rtrim(strtr(base64_encode(mb_strcut($name, 0, 27)), '+/', '-_'), '=');
    }

    public static function unpackName(string $packed): string
    {
        $name = (string) base64_decode(strtr($packed, '-_', '+/'), true);

        return mb_check_encoding($name, 'UTF-8') && $name !== '' ? $name : 'Player';
    }

    /**
     * The trade form: cards and points each way.
     *
     * @param string $title
     * @param string $customId
     *
     * @return Modal
     */
    private static function offerForm(string $title, string $customId): Modal
    {
        return (new Modal($title, $customId))
            ->text('give', 'Cards you give, one per line', null, false, true, "2 Lightning Bolt\nShock", 1000)
            ->text('want', 'Cards you want from them, one per line', null, false, true, 'Llanowar Elves', 1000)
            ->text('gp', 'Points you give', null, false, max: 8)
            ->text('wp', 'Points you want', null, false, max: 8);
    }

    /**
     * A menu choice, or null for "any".
     *
     * @param string $value
     *
     * @return string|null
     */
    private static function orNone(string $value): ?string
    {
        return $value === '' || $value === self::ANY ? null : $value;
    }

    /**
     * An opened pack, with the way back to the packs panel.
     *
     * @param string                          $playerId
     * @param OpenedPack $opened
     * @param string                          $set
     * @param string                          $color
     *
     * @return MessageBuilder
     */
    public function opened(string $playerId, OpenedPack $opened, string $set, string $color): MessageBuilder
    {
        return PocketMessageBuilder::pack($opened)->addComponent(ActionRow::new()
            ->addComponent(Menu::button($playerId, '◀ Packs', 'packs', $set, $color))
            ->addComponent(Menu::button($playerId, '📚 Collection', 'coll'))
            ->addComponent(Menu::menuButton($playerId)));
    }

    /**
     * A deck, or the deck list when it has gone.
     *
     * @param string $playerId
     * @param string $deckId
     * @param string $note
     *
     * @return MessageBuilder
     */
    private function deckOrList(string $playerId, string $deckId, string $note): MessageBuilder
    {
        try {
            return $this->deck($playerId, $deckId, $note);
        } catch (\OutOfBoundsException) {
            return $this->decks($playerId, $note);
        }
    }

    /**
     * What the player plays with.
     *
     * @param string $playerId
     *
     * @return string
     */
    private function playingWith(string $playerId): string
    {
        if (($rental = $this->pocket->rentals->activeRental($playerId)) !== null) {
            return 'Playing with **'.$this->rentalName($rental).'**.';
        }
        $active = $this->pocket->deckBuilder->activeDeckId($playerId);
        $deck = $active === null ? null : $this->pocket->decks->find($playerId, $active);

        return $deck instanceof Deck
            ? "Playing with **{$deck->name}** (".(DeckBuilder::FORMATS[$deck->format] ?? $deck->format).", {$deck->main->total()} cards)."
            : 'No deck yet: make one under **Decks**, or play a rental deck.';
    }

    /**
     * A rental's name, or its id if it has gone.
     *
     * @param string $deckId
     *
     * @return string
     */
    private function rentalName(string $deckId): string
    {
        try {
            return $this->pocket->rentals->find($deckId)->name.' (rental)';
        } catch (\OutOfBoundsException) {
            return substr($deckId, strlen(RentalDeck::PREFIX)).' (rental, no longer offered)';
        }
    }
}
