<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The organiser sets the round's automatic reveal minutes in the round form: whole minutes 0..240, a change that reveals
 * secret puzzles earlier (shorter minutes, an earlier start) only on a yes for exactly the list shown - an unticked,
 * tampered or stale yes changes nothing - and a longer delay hides longer everywhere. The round's puzzles page names the
 * minutes and links to them.
 *
 * Every "changes nothing" check reads the round, the row and the puzzle from the database again.
 */
final class RoundRevealDelayFormTest extends WebTestCase
{
    private const string COMPETITION = CompetitionFixture::COMPETITION_UNAPPROVED;
    private const string ADD_URL = '/en/add-event-round/' . self::COMPETITION;
    private const string ROUNDS_URL = '/en/manage-event-rounds/' . self::COMPETITION;
    // 10:05 in Vienna (CEST, UTC+2) - the event is in Austria
    private const string START_LOCAL = '24.10.2030 10:05';
    private const string START_UTC = '2030-10-24 08:05:00';
    private const string DELAY_FIELD = 'competition_round_form[revealDelayMinutes]';
    private const string CONFIRM_FIELD = 'competition_round_form[confirmReveal]';

    public function testTheRoundFormsAskForTheRevealDelay(): void
    {
        $browser = $this->organiser();

        $crawler = $browser->request('GET', self::ADD_URL);
        $this->assertResponseIsSuccessful();
        $input = $crawler->filter('#reveal-delay input[name="' . self::DELAY_FIELD . '"]');
        self::assertCount(1, $input);
        self::assertSame('10', $input->attr('value'));
        self::assertSame('number', $input->attr('type'));
        self::assertSame('0', $input->attr('min'));
        self::assertSame((string) RoundPuzzleReveal::MAX_DELAY_MINUTES, $input->attr('max'));
        self::assertSame('1', $input->attr('step'));

        $roundId = $this->addRound($browser, 'Delay 25', '25');
        self::assertSame(25, $this->round($roundId)->revealDelayMinutes);

        $crawler = $browser->request('GET', $this->editUrl($roundId));
        $this->assertResponseIsSuccessful();
        self::assertSame('25', $crawler->filter('#reveal-delay input[name="' . self::DELAY_FIELD . '"]')->attr('value'));

        // Left at the default
        self::assertSame(RoundPuzzleReveal::DEFAULT_DELAY_MINUTES, $this->round($this->addRound($browser, 'Delay default'))->revealDelayMinutes);
    }

    public function testTheRevealDelayIsWholeMinutesFromZeroToMax(): void
    {
        $browser = $this->organiser();

        foreach (['-1', '241', '2.5', 'abc', '2,5'] as $invalid) {
            $browser->request('GET', self::ADD_URL);
            $crawler = $browser->submitForm('Add Round', $this->roundFields('Invalid ' . $invalid) + [self::DELAY_FIELD => $invalid]);
            $this->assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Enter whole minutes from 0 to 240.', $crawler->filter('#reveal-delay')->text(), $invalid);
            self::assertNull($this->roundNamedOrNull('Invalid ' . $invalid), $invalid);
        }

        self::assertSame(0, $this->round($this->addRound($browser, 'Delay 0', '0'))->revealDelayMinutes);
        $roundId = $this->addRound($browser, 'Delay 240', '240');
        self::assertSame(240, $this->round($roundId)->revealDelayMinutes);

        // The edit form too
        $browser->request('GET', $this->editUrl($roundId));
        $browser->submitForm('Save Changes', [self::DELAY_FIELD => '241']);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame(240, $this->round($roundId)->revealDelayMinutes);
    }

    public function testShorteningTheDelayOfARoundWithSecretPuzzlesNeedsAYes(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Shortened');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Shortened Secret');
        $before = $this->snapshot($roundId, $roundPuzzleId);
        self::assertSame($this->startPlus(10), $before['hideUntil']);

        // Asked first: what comes out, and when instead of when
        $browser->request('GET', $this->editUrl($roundId));
        $crawler = $browser->submitForm('Save Changes', [self::DELAY_FIELD => '5']);
        $this->assertResponseStatusCodeSame(422);
        $list = $crawler->filter('[data-confirm-reveal]');
        self::assertStringContainsString('Shortened Secret', $list->text());
        $when = $list->filter('[data-revealed-when]')->text();
        self::assertStringContainsString('– on Thursday, October 24, 2030 at 10:10', $when);
        self::assertStringContainsString('instead of', $when);
        self::assertStringContainsString('October 24, 2030 at 10:15', $when);
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId));

        // A yes for another list does not count - and is not shown ticked
        $crawler = $browser->submitForm('Save Changes', [
            self::DELAY_FIELD => '5',
            self::CONFIRM_FIELD => '1',
            'confirm_reveal_hash' => 'tampered',
        ]);
        $this->assertResponseStatusCodeSame(422);
        self::assertNull($crawler->filter('input[name="' . self::CONFIRM_FIELD . '"]')->attr('checked'));
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId));

        // The yes for the list shown
        $browser->submitForm('Save Changes', [self::DELAY_FIELD => '5', self::CONFIRM_FIELD => '1']);
        $this->assertResponseRedirects(self::ROUNDS_URL);

        $after = $this->snapshot($roundId, $roundPuzzleId);
        self::assertSame(5, $after['delay']);
        self::assertSame(RoundPuzzleReveal::Automatic, $after['mode']);
        self::assertSame($this->startPlus(5), $after['hideUntil']);
        self::assertSame($this->startPlus(5), $after['hideImageUntil']);

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('will now be revealed at Thursday, October 24, 2030 at 10:10', $crawler->text());
    }

    public function testAStaleTickNeverConfirmsANewList(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Stale tick');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Stale Tick Secret');
        $before = $this->snapshot($roundId, $roundPuzzleId);

        // Shown for 5 minutes
        $browser->request('GET', $this->editUrl($roundId));
        $browser->submitForm('Save Changes', [self::DELAY_FIELD => '5']);
        $this->assertResponseStatusCodeSame(422);

        // Ticked, but the minutes changed to 3: another list - asked again, the box cleared
        $crawler = $browser->submitForm('Save Changes', [self::DELAY_FIELD => '3', self::CONFIRM_FIELD => '1']);
        $this->assertResponseStatusCodeSame(422);
        self::assertNull($crawler->filter('input[name="' . self::CONFIRM_FIELD . '"]')->attr('checked'));
        self::assertStringContainsString('not the list you confirmed any more', $crawler->filter('[data-confirm-reveal]')->text());
        self::assertStringContainsString('October 24, 2030 at 10:08', $crawler->filter('[data-revealed-when]')->text());
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId));

        // Saving the page as it is (the box cleared) still asks
        $browser->submitForm('Save Changes');
        $this->assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId));

        // The yes for the list now shown
        $crawler = $browser->submitForm('Save Changes', [self::DELAY_FIELD => '3', self::CONFIRM_FIELD => '1']);
        $this->assertResponseRedirects(self::ROUNDS_URL, null, (string) $crawler->text());
        self::assertSame(3, $this->round($roundId)->revealDelayMinutes);
        self::assertSame($this->startPlus(3), $this->snapshot($roundId, $roundPuzzleId)['hideUntil']);
    }

    /**
     * The yes is for the move it was shown: 25 -> 5 minutes. When the delay became 60 meanwhile (another tab, the
     * internal API), the same puzzles come out at the same moment - but from 60: the old page's tick and hash ask again
     * and change nothing.
     */
    public function testAYesForAMoveFromAnotherMomentAsksAgain(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Moved meanwhile', '25');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Moved Meanwhile Secret');

        // Asked for 25 -> 5
        $browser->request('GET', $this->editUrl($roundId));
        $crawler = $browser->submitForm('Save Changes', [self::DELAY_FIELD => '5']);
        $this->assertResponseStatusCodeSame(422);
        $when = $crawler->filter('[data-revealed-when]')->text();
        self::assertStringContainsString('October 24, 2030 at 10:10', $when);
        self::assertStringContainsString('instead of Thursday, October 24, 2030 at 10:30', $when);

        // Meanwhile another path lengthens the delay to 60 minutes
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new EditCompetitionRound(
            roundId: $roundId,
            name: 'kept',
            minutesLimit: 1,
            startsAt: new DateTimeImmutable(),
            timezone: 'UTC',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            keepFields: EditCompetitionRound::FIELDS,
            revealDelayMinutes: 60,
        ));
        $before = $this->snapshot($roundId, $roundPuzzleId);
        self::assertSame(60, $before['delay']);
        self::assertSame($this->startPlus(60), $before['hideUntil']);

        // The old page, ticked, with the hash it was given for 25 -> 5: asked again, nothing changed
        $crawler = $browser->submitForm('Save Changes', [self::DELAY_FIELD => '5', self::CONFIRM_FIELD => '1']);
        $this->assertResponseStatusCodeSame(422);
        self::assertNull($crawler->filter('input[name="' . self::CONFIRM_FIELD . '"]')->attr('checked'));
        self::assertStringContainsString('not the list you confirmed any more', $crawler->filter('[data-confirm-reveal]')->text());
        // Now from where it is: 11:05
        self::assertStringContainsString('instead of Thursday, October 24, 2030 at 11:05', $crawler->filter('[data-revealed-when]')->text());
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId));

        // A yes for the move now shown goes ahead
        $browser->submitForm('Save Changes', [self::DELAY_FIELD => '5', self::CONFIRM_FIELD => '1']);
        $this->assertResponseRedirects(self::ROUNDS_URL);
        self::assertSame(5, $this->round($roundId)->revealDelayMinutes);
        self::assertSame($this->startPlus(5), $this->snapshot($roundId, $roundPuzzleId)['hideUntil']);
    }

    /**
     * The round changes between the form's check and the handler's locks (simulated: the controller reads the round as
     * it was, the database already holds another delay). The handler refuses; the page asks for a yes - "required" when
     * none was given, "not what you confirmed" when the tick was for another list - and nothing changes.
     */
    public function testAChangeBetweenTheFormAndTheSaveAsksAccordingToTheTick(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Raced');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Raced Secret');
        $browser->disableReboot();
        $nextDelay = null;
        $this->changeDelayBehindTheControllersBack($browser, $roundId, $nextDelay);

        // Unticked, nothing to confirm as the form sees it (10 stays 10) - but the round has 25 by now
        $browser->request('GET', $this->editUrl($roundId));
        $nextDelay = 25;
        $before = $this->snapshot($roundId, $roundPuzzleId);
        $crawler = $browser->submitForm('Save Changes', ['competition_round_form[name]' => 'Raced renamed']);
        $this->assertResponseStatusCodeSame(422);
        $confirm = $crawler->filter('[data-confirm-reveal]');
        self::assertStringContainsString('This change reveals secret puzzles earlier than planned (Raced Secret)', $confirm->text());
        self::assertStringNotContainsString('not the list you confirmed', $confirm->text());
        self::assertStringContainsString('instead of Thursday, October 24, 2030 at 10:30', $confirm->filter('[data-revealed-when]')->text());
        self::assertSame(['delay' => 25] + $before, $this->snapshot($roundId, $roundPuzzleId));
        self::assertSame('Raced', $this->round($roundId)->name);

        // Ticked for 25 -> 10, but the round has 40 by now: not what was confirmed
        $nextDelay = 40;
        $before = $this->snapshot($roundId, $roundPuzzleId);
        $crawler = $browser->submitForm('Save Changes', ['competition_round_form[name]' => 'Raced renamed', self::CONFIRM_FIELD => '1']);
        $this->assertResponseStatusCodeSame(422);
        $confirm = $crawler->filter('[data-confirm-reveal]');
        self::assertStringContainsString('not the list you confirmed any more (Raced Secret)', $confirm->text());
        self::assertStringContainsString('instead of Thursday, October 24, 2030 at 10:45', $confirm->filter('[data-revealed-when]')->text());
        self::assertNull($crawler->filter('input[name="' . self::CONFIRM_FIELD . '"]')->attr('checked'));
        self::assertSame(['delay' => 40] + $before, $this->snapshot($roundId, $roundPuzzleId));
        self::assertSame('Raced', $this->round($roundId)->name);
    }

    public function testMovingTheStartEarlierStillInTheFutureNeedsAYes(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Earlier start');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Earlier Start Secret');
        $before = $this->snapshot($roundId, $roundPuzzleId);

        $browser->request('GET', $this->editUrl($roundId));
        $crawler = $browser->submitForm('Save Changes', ['competition_round_form[startsAt]' => '23.10.2030 10:05']);
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Earlier Start Secret', $crawler->filter('[data-confirm-reveal]')->text());
        self::assertStringContainsString('October 23, 2030 at 10:15', $crawler->filter('[data-revealed-when]')->text());
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId));

        $browser->submitForm('Save Changes', ['competition_round_form[startsAt]' => '23.10.2030 10:05', self::CONFIRM_FIELD => '1']);
        $this->assertResponseRedirects(self::ROUNDS_URL);
        $after = $this->snapshot($roundId, $roundPuzzleId);
        self::assertSame(new DateTimeImmutable('2030-10-23 08:05:00')->getTimestamp(), $after['startsAt']);
        self::assertSame(new DateTimeImmutable('2030-10-23 08:15:00')->getTimestamp(), $after['hideUntil']);
    }

    /**
     * A public catalogue puzzle the round keeps secret on its event pages only: the yes says it comes out on this event
     * page - elsewhere it was public all along - and the rest of the site stays as it was.
     */
    public function testAPublicPuzzleSecretOnTheEventPageOnlyIsListedForThisEventOnly(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Public secret');
        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: PuzzleFixture::PUZZLE_500_03,
            piecesCount: null,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));
        $before = $this->snapshot($roundId, $roundPuzzleId->toString());
        self::assertNull($before['hideUntil']);
        self::assertNull($before['hideImageUntil']);

        $browser->request('GET', $this->editUrl($roundId));
        $crawler = $browser->submitForm('Save Changes', [self::DELAY_FIELD => '5']);
        $this->assertResponseStatusCodeSame(422);
        $list = $crawler->filter('[data-confirm-reveal]');
        self::assertStringContainsString('Puzzle 3', $list->text());
        self::assertSame('– on this event page only – it was public elsewhere on MySpeedPuzzling already', $list->filter('[data-revealed-scope]')->text());
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId->toString()));

        $browser->submitForm('Save Changes', [self::DELAY_FIELD => '5', self::CONFIRM_FIELD => '1']);
        $this->assertResponseRedirects(self::ROUNDS_URL);
        $after = $this->snapshot($roundId, $roundPuzzleId->toString());
        self::assertSame(5, $after['delay']);
        self::assertNull($after['hideUntil']);
        self::assertNull($after['hideImageUntil']);
    }

    public function testLengtheningTheDelayNeedsNoYesAndHidesLonger(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Lengthened');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Lengthened Secret');

        $browser->request('GET', $this->editUrl($roundId));
        $browser->submitForm('Save Changes', [self::DELAY_FIELD => '45']);
        $this->assertResponseRedirects(self::ROUNDS_URL);

        $after = $this->snapshot($roundId, $roundPuzzleId);
        self::assertSame(45, $after['delay']);
        self::assertSame(RoundPuzzleReveal::Automatic, $after['mode']);
        self::assertSame($this->startPlus(45), $after['hideUntil']);
        self::assertSame($this->startPlus(45), $after['hideImageUntil']);

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('will now be revealed at Thursday, October 24, 2030 at 10:50', $crawler->text());
    }

    /**
     * The delay is kept unless the organiser changes it - also by a form posted without the field (a page rendered by a
     * release before it): never the default by accident, which would reveal a longer delay's puzzles early.
     */
    public function testARenameAndAFormWithoutTheFieldKeepTheDelay(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Kept delay', '25');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Kept Delay Secret');

        $browser->request('GET', $this->editUrl($roundId));
        $browser->submitForm('Save Changes', ['competition_round_form[name]' => 'Kept delay renamed']);
        $this->assertResponseRedirects(self::ROUNDS_URL);
        self::assertSame('Kept delay renamed', $this->round($roundId)->name);
        $before = $this->snapshot($roundId, $roundPuzzleId);
        self::assertSame(25, $before['delay']);
        self::assertSame($this->startPlus(25), $before['hideUntil']);

        $crawler = $browser->request('GET', $this->editUrl($roundId));
        $values = $crawler->selectButton('Save Changes')->form(['competition_round_form[name]' => 'Kept delay again'])->getPhpValues();
        self::assertIsArray($values['competition_round_form']);
        unset($values['competition_round_form']['revealDelayMinutes']);
        $browser->request('POST', $this->editUrl($roundId), $values);
        $this->assertResponseRedirects(self::ROUNDS_URL);
        self::assertSame('Kept delay again', $this->round($roundId)->name);
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId));
    }

    public function testThePuzzlesPageNamesTheDelay(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Named delay', '25');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Named Delay Secret');
        $card = '#round-puzzle-' . $roundPuzzleId;

        $crawler = $browser->request('GET', $this->puzzlesUrl($roundId));
        $this->assertResponseIsSuccessful();
        $line = $crawler->filter('[data-automatic-reveal]');
        self::assertStringContainsString('Automatic reveal: 25 minutes after the round starts – Thursday, October 24, 2030 at 10:30', $line->text());
        self::assertStringContainsString('Automatic: 25 minutes after the round starts.', $crawler->filter($card)->text());
        self::assertStringContainsString(
            'Automatically, 25 minutes after the round starts – Thursday, October 24, 2030 at 10:30',
            $crawler->filter('label[for="reveal-automatic-' . $roundPuzzleId . '"]')->text(),
        );
        // The exact reveal time per puzzle stays
        self::assertStringContainsString('October 24, 2030 at 10:30', $crawler->filter($card . ' [data-reveal-status]')->text());

        $href = $line->filter('[data-change-reveal-delay]')->attr('href');
        self::assertIsString($href);
        self::assertStringStartsWith($this->editUrl($roundId) . '?', $href);
        self::assertStringEndsWith('#reveal-delay', $href);
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        self::assertSame($this->puzzlesUrl($roundId), $query['return'] ?? null);
        // Also next to the automatic choice of each puzzle
        self::assertCount(1, $crawler->filter($card . ' [data-change-reveal-delay]'));

        // The repeated "Change" links say to screen readers what they change - the visible word included
        foreach ($crawler->filter('[data-change-reveal-delay]') as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertSame('Change', trim($link->textContent));
            self::assertSame('Change the round\'s reveal minutes', $link->getAttribute('aria-label'));
        }

        foreach ([1 => '1 minute after the round starts – Thursday, October 24, 2030 at 10:06', 0 => 'when the round starts – Thursday, October 24, 2030 at 10:05'] as $minutes => $expected) {
            $this->setDelayInDatabase($roundId, $minutes);
            $crawler = $browser->request('GET', $this->puzzlesUrl($roundId));
            self::assertStringContainsString('Automatic reveal: ' . $expected, $crawler->filter('[data-automatic-reveal]')->text());
            self::assertStringContainsString('Automatically, ' . $expected, $crawler->filter('label[for="reveal-automatic-' . $roundPuzzleId . '"]')->text());
        }

        self::assertStringContainsString('Automatic: when the round starts.', $crawler->filter($card)->text());
    }

    public function testThePuzzlesPageNamesNoDelayWithoutAnAutomaticSecret(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Nothing secret');

        $crawler = $browser->request('GET', $this->puzzlesUrl($roundId));
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-automatic-reveal]'));
    }

    public function testTheAddPuzzleFormNamesTheDelay(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Add puzzle help', '25');

        $crawler = $browser->request('GET', '/en/add-puzzle-to-round/' . $roundId);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString(
            'On your event page it is revealed automatically on Thursday, October 24, 2030 at 10:30',
            $crawler->text(),
        );
        self::assertStringContainsString('– 25 minutes after the round starts.', $crawler->text());
    }

    /**
     * "Automatic" on a puzzles page loaded before the round's automatic reveal moved (here: 25 -> 5 minutes in another
     * tab) is a yes to the moment it showed - never saved for the earlier one. The page comes back with the moment the
     * round has now; saved from there it goes through.
     */
    public function testAutomaticFromAStalePuzzlesPageIsAskedAgain(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Stale automatic', '25');
        $roundPuzzleId = $this->addSecretPuzzle($roundId, 'Stale Automatic Secret');
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual));
        $card = '#round-puzzle-' . $roundPuzzleId;

        // The page shows "Automatically, 25 minutes after the round starts - 10:30"
        $crawler = $browser->request('GET', $this->puzzlesUrl($roundId));
        $this->assertResponseIsSuccessful();
        $stalePage = $crawler->filter($card . ' form[action*="round-puzzle-reveal"]')->form(['hide_mode' => 'entirely', 'reveal_mode' => 'automatic']);

        // Meanwhile the round's delay becomes 5 minutes (the puzzle has a manual reveal - nothing to confirm)
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new EditCompetitionRound(
            roundId: $roundId,
            name: 'kept',
            minutesLimit: 1,
            startsAt: new DateTimeImmutable(),
            timezone: 'UTC',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            keepFields: EditCompetitionRound::FIELDS,
            revealDelayMinutes: 5,
        ));
        $before = $this->snapshot($roundId, $roundPuzzleId);
        self::assertSame(RoundPuzzleReveal::Manual, $before['mode']);

        $refused = $browser->submit($stalePage);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame(
            'The round\'s automatic reveal has changed meanwhile – it is now on Thursday, October 24, 2030 at 10:10 AM (Austria Time). Check it and save again.',
            str_replace("\u{202F}", ' ', $refused->filter($card . ' [data-reveal-error]')->text()),
        );
        self::assertSame($before, $this->snapshot($roundId, $roundPuzzleId));

        // The page as it is now: saved
        $browser->submit($refused->filter($card . ' form[action*="round-puzzle-reveal"]')->form(['hide_mode' => 'entirely', 'reveal_mode' => 'automatic']));
        $this->assertResponseRedirects($this->puzzlesUrl($roundId), 303);
        $after = $this->snapshot($roundId, $roundPuzzleId);
        self::assertSame(RoundPuzzleReveal::Automatic, $after['mode']);
        self::assertSame($this->startPlus(5), $after['hideUntil']);

        // A form without the moment it showed (a page from before it was sent along) is never saved as automatic
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual));
        $crawler = $browser->request('GET', $this->puzzlesUrl($roundId));
        $values = $crawler->filter($card . ' form[action*="round-puzzle-reveal"]')->form(['hide_mode' => 'entirely', 'reveal_mode' => 'automatic'])->getPhpValues();
        unset($values['automatic_reveal_at']);
        $browser->request('POST', '/en/round-puzzle-reveal/' . $roundPuzzleId, $values);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame(RoundPuzzleReveal::Manual, $this->snapshot($roundId, $roundPuzzleId)['mode']);
    }

    public function testTheChangeLinkReturnsToThePuzzlesPage(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Change link');
        $this->addSecretPuzzle($roundId, 'Change Link Secret');

        $crawler = $browser->request('GET', $this->puzzlesUrl($roundId));
        $crawler = $browser->click($crawler->filter('[data-automatic-reveal] [data-change-reveal-delay]')->link());
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#reveal-delay input[name="' . self::DELAY_FIELD . '"]'));
        // The back button leads to the puzzles page
        self::assertCount(1, $crawler->filter('a[href="' . $this->puzzlesUrl($roundId) . '"]'));

        $browser->submitForm('Save Changes', [self::DELAY_FIELD => '45']);
        $this->assertResponseRedirects($this->puzzlesUrl($roundId));
        self::assertSame(45, $this->round($roundId)->revealDelayMinutes);

        // Only back to this site - anything else falls back to the rounds list
        $browser->request('GET', $this->editUrl($roundId) . '?return=' . rawurlencode('//evil.example/x'));
        $browser->submitForm('Save Changes', [self::DELAY_FIELD => '50']);
        $this->assertResponseRedirects(self::ROUNDS_URL);
        self::assertSame(50, $this->round($roundId)->revealDelayMinutes);
    }

    private function organiser(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    /**
     * @return array<string, string>
     */
    private function roundFields(string $name): array
    {
        return [
            'competition_round_form[name]' => $name,
            'competition_round_form[minutesLimit]' => '90',
            'competition_round_form[startsAt]' => self::START_LOCAL,
            'competition_round_form[timezone]' => 'Europe/Vienna',
        ];
    }

    private function addRound(KernelBrowser $browser, string $name, null|string $revealDelayMinutes = null): string
    {
        $browser->request('GET', self::ADD_URL);
        $fields = $this->roundFields($name);

        if ($revealDelayMinutes !== null) {
            $fields[self::DELAY_FIELD] = $revealDelayMinutes;
        }

        $browser->submitForm('Add Round', $fields);
        $this->assertResponseRedirects(self::ROUNDS_URL);

        $round = $this->roundNamedOrNull($name);
        self::assertNotNull($round);
        self::assertSame(new DateTimeImmutable(self::START_UTC)->getTimestamp(), $round->startsAt->getTimestamp());

        return $round->id->toString();
    }

    /**
     * A new puzzle kept secret by the round - hidden on the whole site until the round's automatic reveal
     */
    private function addSecretPuzzle(string $roundId, string $name): string
    {
        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $name,
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));

        return $roundPuzzleId->toString();
    }

    /**
     * Before the next edit request's controller runs: the round is read as it is (so the controller works on that), then
     * the database gets $nextDelay - what another change committing between the form's check and the handler's locks
     * looks like. Needs disableReboot(): one container, one entity manager.
     */
    private function changeDelayBehindTheControllersBack(KernelBrowser $browser, string $roundId, null|int &$nextDelay): void
    {
        $container = $browser->getContainer();
        $eventDispatcher = $container->get('event_dispatcher');
        $entityManager = $container->get(EntityManagerInterface::class);
        $editUrl = $this->editUrl($roundId);

        $eventDispatcher->addListener(\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER, static function (\Symfony\Component\HttpKernel\Event\ControllerEvent $event) use ($entityManager, $roundId, $editUrl, &$nextDelay): void {
            if ($nextDelay === null || !$event->isMainRequest() || $event->getRequest()->getMethod() !== 'POST' || $event->getRequest()->getPathInfo() !== $editUrl) {
                return;
            }

            $entityManager->find(CompetitionRound::class, $roundId);
            $entityManager->getConnection()->executeStatement(
                'UPDATE competition_round SET reveal_delay_minutes = :delay WHERE id = :id',
                ['delay' => $nextDelay, 'id' => $roundId],
            );
            $nextDelay = null;
        });
    }

    /**
     * A delay set behind the form's back - only for what the pages say about it
     */
    private function setDelayInDatabase(string $roundId, int $minutes): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $round = $entityManager->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);
        $round->changeRevealDelay($minutes);
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @return array{delay: int, startsAt: int, mode: RoundPuzzleReveal, revealAt: null|int, hideUntil: null|int, hideImageUntil: null|int}
     */
    private function snapshot(string $roundId, string $roundPuzzleId): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $round = $entityManager->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);
        $row = $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($row);

        return [
            'delay' => $round->revealDelayMinutes,
            'startsAt' => $round->startsAt->getTimestamp(),
            'mode' => $row->revealMode,
            'revealAt' => $row->revealAt?->getTimestamp(),
            'hideUntil' => $row->puzzle->hideUntil?->getTimestamp(),
            'hideImageUntil' => $row->puzzle->hideImageUntil?->getTimestamp(),
        ];
    }

    private function startPlus(int $minutes): int
    {
        return new DateTimeImmutable(self::START_UTC)->modify("+{$minutes} minutes")->getTimestamp();
    }

    private function round(string $roundId): CompetitionRound
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $round = $entityManager->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);

        return $round;
    }

    private function roundNamedOrNull(string $name): null|CompetitionRound
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(CompetitionRound::class)->findOneBy([
            'name' => $name,
            'competition' => self::COMPETITION,
        ]);
    }

    private function editUrl(string $roundId): string
    {
        return '/en/edit-event-round/' . $roundId;
    }

    private function puzzlesUrl(string $roundId): string
    {
        return '/en/manage-round-puzzles/' . $roundId;
    }
}
