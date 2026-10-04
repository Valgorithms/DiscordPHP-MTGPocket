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

namespace MTGPocket\Builders;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Option;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Parts\Channel\Message\AllowedMentions;
use MTG\Builders\CardMessageBuilder;
use MTG\Helpers\Text;
use MTGPocket\Cards\BasicLands;
use MTGPocket\Drafts\Draft;
use MTGPocket\Drafts\DraftRules;
use MTGPocket\Drafts\DraftSeat;

/**
 * Booster draft messages: a pod and its standings, a player's pack to pick
 * from, their picks and deck, and the pod's announcements.
 *
 * Custom ids, each followed by the draft's id: `pocket:dj` (join),
 * `pocket:ds` (refresh the pod), `pocket:dv` (show the pod, from an
 * announcement), `pocket:dr` (show my pack), `pocket:dq` (refresh my pack)
 * and `pocket:dp:<draftId>:<pickNumber>` (the pick menu, valued with a uuid).
 *
 * @since 0.5.0
 */
final class DraftMessageBuilder extends PocketMessageBuilder
{
    /**
     * What each stage is called.
     *
     * @var array<string, string>
     */
    public const array STAGES = [
        Draft::SIGNUP => 'taking players',
        Draft::DRAFTING => 'drafting',
        Draft::BUILDING => 'building decks',
        Draft::PLAYING => 'playing',
        Draft::OVER => 'over',
        Draft::CANCELLED => 'called off',
    ];

    /**
     * A pod: who is in it, where it stands, and in play, the round's
     * pairings and the standings.
     *
     * @param Draft      $draft
     * @param DraftRules $rules
     * @param list<int>  $prizes Points for 1st, 2nd and so on; see {@see \MTGPocket\Drafts\DraftService::prizeTable()}.
     *
     * @return static
     */
    public static function pod(Draft $draft, DraftRules $rules, array $prizes = []): static
    {
        $heading = sprintf(
            "### 🎴 %s booster draft\n-# %s · %d/%d players · %s to enter · %s packs each",
            $draft->setName,
            self::STAGES[$draft->status] ?? $draft->status,
            count($draft->seats),
            $draft->size,
            self::points($draft->fee),
            $draft->packs,
        );
        $status = match ($draft->status) {
            Draft::SIGNUP => "Join with the button or `/draft join`. The draft starts when the pod is full, when its host uses `/draft start`, or <t:{$draft->deadline}:R> with at least {$rules->minPlayers} players.",
            Draft::DRAFTING => "Pack {$draft->packNumber} of {$draft->packs} is going round. Take your pick with `/draft pack`.",
            Draft::BUILDING => "Build a deck of at least {$rules->deckMin} cards from your picks, then `/draft ready`. Rounds start <t:{$draft->deadline}:R> at the latest.",
            Draft::PLAYING => 'Round '.count($draft->rounds)." of {$draft->totalRounds}; games not finished <t:{$draft->deadline}:R> are draws.",
            Draft::OVER => 'Every player has their drafted cards in their collection.',
            default => 'Entry fees were refunded.',
        };
        if ($prizes !== []) {
            $status .= "\n".($draft->prizes === []
                ? '🏆 Prizes'.($draft->status === Draft::SIGNUP ? ' when full' : '').': '.implode(' · ', array_map(fn (int $points, int $place) => self::ordinal($place + 1).' '.self::points($points), $prizes, array_keys($prizes)))
                : '🏆 Paid: '.implode(' · ', array_map(fn (array $prize) => self::ordinal($prize['place']).' '.$draft->seat($prize['id'])->name.' '.self::points($prize['points']), $draft->prizes)));
        }
        if ($rules->packsPerWin > 0) {
            $status .= "\n🎁 Every match win also opens ".($rules->packsPerWin === 1 ? 'a pack' : "{$rules->packsPerWin} packs")." of {$draft->setName}.";
        }
        if ($draft->endsAt > 0 && $draft->isLive()) {
            $status .= "\n-# The event ends <t:{$draft->endsAt}:R>, whatever is left unplayed; everyone keeps what they drafted.";
        }

        $container = Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS['multicolor'])
            ->addComponent(TextDisplay::new($heading."\n".$status))
            ->addComponent(Separator::new());

        if ($draft->rounds === []) {
            $lines = array_map(fn (DraftSeat $seat) => sprintf(
                '%s%s%s%s',
                $seat->name,
                $seat->id === $draft->hostId && $draft->status === Draft::SIGNUP ? ' · host' : '',
                $draft->status === Draft::DRAFTING || $draft->status === Draft::BUILDING ? ' · '.Text::plural($seat->picks->total(), 'pick') : '',
                $seat->dropped ? ' · left' : ($draft->status === Draft::BUILDING && $seat->ready ? ' · ✅ ready' : ''),
            ), $draft->seats);
            $container->addComponent(TextDisplay::new("**Players**\n".implode("\n", $lines)));
        } else {
            $names = [];
            foreach ($draft->seats as $seat) {
                $names[$seat->id] = $seat->name;
            }
            $pairings = array_map(fn (array $pairing) => match (true) {
                $pairing['b'] === null => "{$names[$pairing['a']]} has a bye",
                $pairing['result'] === 'a' => "**{$names[$pairing['a']]}** beat {$names[$pairing['b']]}",
                $pairing['result'] === 'b' => "**{$names[$pairing['b']]}** beat {$names[$pairing['a']]}",
                $pairing['result'] === 'draw' => "{$names[$pairing['a']]} drew with {$names[$pairing['b']]}",
                $pairing['match'] !== null => "{$names[$pairing['a']]} vs {$names[$pairing['b']]} · playing",
                default => "{$names[$pairing['a']]} vs {$names[$pairing['b']]} · waiting to start (`/draft play`)",
            }, $draft->currentRound());
            $standings = [];
            foreach ($draft->standings() as $place => $record) {
                $standings[] = sprintf(
                    '%d. %s · %d points · %d-%d%s%s',
                    $place + 1,
                    $record['name'],
                    $record['points'],
                    $record['wins'],
                    $record['losses'],
                    $record['draws'] > 0 ? "-{$record['draws']}" : '',
                    $record['dropped'] ? ' · left' : '',
                );
            }
            $container
                ->addComponent(TextDisplay::new('**Round '.count($draft->rounds)."**\n".implode("\n", $pairings)))
                ->addComponent(TextDisplay::new("**Standings**\n".implode("\n", $standings)));
        }

        $message = self::panel()->addComponent($container);
        $row = ActionRow::new();
        if ($draft->status === Draft::SIGNUP) {
            $row->addComponent(Button::new(Button::STYLE_SUCCESS, self::PREFIX.":dj:{$draft->id}")->setLabel('Join ('.self::points($draft->fee).')'));
        }
        if ($draft->status === Draft::DRAFTING) {
            $row->addComponent(Button::new(Button::STYLE_PRIMARY, self::PREFIX.":dr:{$draft->id}")->setLabel('🎴 My pack'));
        }
        if ($draft->isLive()) {
            $row->addComponent(Button::new(Button::STYLE_SECONDARY, self::PREFIX.":ds:{$draft->id}")->setLabel('Refresh'));
        }

        return $row->getComponents() === [] ? $message : $message->addComponent($row);
    }

    /**
     * The pack in front of a player, with a menu to take a card.
     *
     * @param Draft        $draft
     * @param DraftSeat    $seat
     * @param array[]|null $cards    Card data; null when no pack is waiting.
     * @param int|null     $deadline When the bot picks for them.
     * @param string|null  $note     What just happened.
     *
     * @return static
     */
    public static function draftPack(Draft $draft, DraftSeat $seat, ?array $cards, ?int $deadline, ?string $note = null): static
    {
        $container = Container::new()->setAccentColor(CardMessageBuilder::ACCENTS['multicolor']);
        if ($note !== null) {
            $container->addComponent(TextDisplay::new($note))->addComponent(Separator::new());
        }
        $message = self::panel();
        $refresh = Button::new(Button::STYLE_SECONDARY, self::PREFIX.":dq:{$draft->id}")->setLabel('Refresh');

        if ($draft->status !== Draft::DRAFTING) {
            $container->addComponent(TextDisplay::new(match ($draft->status) {
                Draft::SIGNUP => "### {$draft->setName} draft\nThe draft has not started yet.",
                Draft::BUILDING, Draft::PLAYING => "### Every pack is empty\nYou drafted ".Text::plural($seat->picks->total(), 'card').'. Build your deck: `/draft pool`.',
                default => "### {$draft->setName} draft\nThis draft is ".(self::STAGES[$draft->status] ?? $draft->status).'.',
            }));

            return $message->addComponent($container);
        }

        $pick = count($seat->pickLog) + 1;
        if ($cards === null) {
            $container->addComponent(TextDisplay::new("### Pack {$draft->packNumber} · waiting\nYour next pack comes when your neighbor takes a card. You have ".Text::plural($seat->picks->total(), 'pick').' so far.'));

            return $message->addComponent($container)->addComponent(ActionRow::new()->addComponent($refresh));
        }

        $lines = array_map(fn (array $card) => self::cardLine($card), $cards);
        $container
            ->addComponent(TextDisplay::new(sprintf(
                "### Pack %d · pick %d\n-# %s left · the bot picks for you <t:%d:R> · packs pass %s",
                $draft->packNumber,
                $pick,
                Text::plural(count($cards), 'card'),
                (int) $deadline,
                $draft->direction() > 0 ? 'left ⬅️' : 'right ➡️',
            )))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(Text::clip(implode("\n", $lines), 3500)));

        $select = StringSelect::new(self::PREFIX.":dp:{$draft->id}:".($pick - 1))->setPlaceholder('Take a card');
        $seen = [];
        foreach ($cards as $card) {
            if (isset($seen[$card['uuid']]) || count($seen) >= 25) {
                continue;
            }
            $seen[$card['uuid']] = true;
            $select->addOption(Option::new(Text::clip($card['name'], 100), $card['uuid'])
                ->setDescription(Text::clip(ucfirst((string) $card['rarity']).(empty($card['type']) ? '' : " · {$card['type']}"), 100)));
        }

        return $message
            ->addComponent($container)
            ->addComponent(ActionRow::new()->addComponent($select))
            ->addComponent(ActionRow::new()->addComponent(self::cardPicker($cards)))
            ->addComponent(ActionRow::new()->addComponent($refresh));
    }

    /**
     * A player's picks: their deck, then the rest as their side deck.
     *
     * @param Draft                   $draft
     * @param DraftSeat               $seat
     * @param callable(string): array $card     Card data by key.
     * @param string[]                $problems
     * @param string|null             $note
     *
     * @return static
     */
    public static function pool(Draft $draft, DraftSeat $seat, callable $card, array $problems, ?string $note = null): static
    {
        $side = [];
        foreach ($seat->picks as $uuid => $count) {
            $left = $count - $seat->deck->get((string) $uuid);
            if ($left > 0) {
                $side[] = [$left, $card((string) $uuid)];
            }
        }
        $main = [];
        foreach ($seat->deck as $key => $count) {
            $main[] = [$count, $card((string) $key)];
        }
        $list = function (array $entries): string {
            usort($entries, fn (array $a, array $b) => [BasicLands::isBasic($a[1]['uuid']), $a[1]['name']] <=> [BasicLands::isBasic($b[1]['uuid']), $b[1]['name']]);

            return implode("\n", array_map(fn (array $entry) => "{$entry[0]} ".(Text::RARITIES[$entry[1]['rarity']] ?? '▫️')." {$entry[1]['name']}", $entries));
        };

        $heading = sprintf(
            "### Your %s draft deck\n-# deck %d · side deck %d · drafted %d%s",
            $draft->setName,
            $seat->deck->total(),
            $seat->picks->total() - ($seat->deck->total() - self::basics($seat)),
            $seat->picks->total(),
            $seat->ready ? ' · ✅ ready' : '',
        );
        $heading .= $problems === [] ? "\n✅ Ready to play." : "\n⚠️ ".implode("\n⚠️ ", $problems);
        $help = $draft->status === Draft::BUILDING || $draft->status === Draft::PLAYING
            ? "\n-# `/draft add` and `/draft remove` move cards between deck and side deck, `/draft lands` sets basic lands, `/draft auto` builds one for you, `/draft ready` sends it in."
            : '';

        $container = Container::new()->setAccentColor(CardMessageBuilder::ACCENTS['multicolor']);
        if ($note !== null) {
            $container->addComponent(TextDisplay::new($note))->addComponent(Separator::new());
        }
        $container
            ->addComponent(TextDisplay::new($heading.$help))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(Text::clip("**Deck**\n".($main === [] ? 'Empty.' : $list($main)), 1900)))
            ->addComponent(TextDisplay::new(Text::clip("**Side deck**\n".($side === [] ? 'Empty.' : $list($side)), 1900)));

        $message = self::panel()->addComponent($container);
        $cards = array_map(fn (string $uuid) => $card($uuid), array_map('strval', array_keys($seat->picks->toArray())));

        return $cards === [] ? $message : $message->addComponent(ActionRow::new()->addComponent(self::cardPicker($cards)));
    }

    /**
     * News from a pod, for its channel, mentioning whoever it names.
     *
     * @param Draft    $draft
     * @param string[] $news
     *
     * @return static
     */
    public static function announcement(Draft $draft, array $news): static
    {
        $mentions = AllowedMentions::none();
        foreach ($draft->seats as $seat) {
            $mentions->addUser($seat->id);
        }

        return self::panel()
            ->setAllowedMentions($mentions)
            ->addComponent(Container::new()
                ->setAccentColor(CardMessageBuilder::ACCENTS['multicolor'])
                ->addComponent(TextDisplay::new(Text::clip(implode("\n\n", $news), 3800))))
            ->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_SECONDARY, self::PREFIX.":dv:{$draft->id}")->setLabel('Show the draft')));
    }

    /**
     * `1st`, `2nd`, `3rd`, `4th` and so on.
     *
     * @param int $place
     *
     * @return string
     */
    private static function ordinal(int $place): string
    {
        return $place.(in_array($place % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$place % 10] ?? 'th'));
    }

    /**
     * Basic lands in a seat's deck.
     *
     * @param DraftSeat $seat
     *
     * @return int
     */
    private static function basics(DraftSeat $seat): int
    {
        $total = 0;
        foreach ($seat->deck as $key => $count) {
            if (BasicLands::isBasic((string) $key)) {
                $total += $count;
            }
        }

        return $total;
    }
}
