<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use ApiPlatform\Metadata\Get;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Api\V1\CompetitionDetailResponseProvider;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetCompetitionPuzzles;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSummary;
use SpeedPuzzling\Web\Query\GetRoundPuzzlesForManagement;
use SpeedPuzzling\Web\Results\RoundPuzzleForManagement;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One reveal moment per secret puzzle, and every surface obeys it: a puzzle created for a round is out nowhere -
 * search, the brand picker, the event pages, the competitions it is "used at", its own page - one second before the
 * moment the organiser is shown, and out everywhere at that moment.
 */
final class SecretPuzzleRevealInvariantTest extends WebTestCase
{
    private const string SECRET_NAME = 'Midnight Lighthouse Secret';
    private const string SECRET_EAN = '4005556175512';
    private const string SECRET_BRAND_CODE = 'MLS-17551';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNoSurfaceShowsTheSecretPuzzleBeforeItsRevealMoment(): void
    {
        $revealAt = new DateTimeImmutable('@' . (intdiv(time(), 60) * 60 + 7200));
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::Entirely);
        $this->dispatch(new ChangeRoundPuzzleReveal($roundPuzzle->id->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Scheduled, $revealAt));

        $roundPuzzle = $this->roundPuzzle($roundPuzzle->id->toString());
        $puzzleId = $roundPuzzle->puzzle->id->toString();

        // The moment the organiser is shown is the one the whole site uses
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->revealsAt()?->getTimestamp());
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->puzzle->hideUntil?->getTimestamp());
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->puzzle->hideImageUntil?->getTimestamp());

        $oneSecondBefore = new MockClock($revealAt->modify('-1 second'));
        self::assertFalse($this->foundBySearch($oneSecondBefore));
        self::assertFalse($this->foundByBrandPicker($oneSecondBefore, $puzzleId));
        self::assertFalse($this->onEventPage($oneSecondBefore, $puzzleId));
        self::assertFalse($this->amongCompetitionPuzzles($oneSecondBefore, $puzzleId));
        self::assertFalse($this->usedAtTheCompetition($oneSecondBefore, $puzzleId));

        $atTheMoment = new MockClock($revealAt);
        self::assertTrue($this->foundBySearch($atTheMoment));
        self::assertTrue($this->foundByBrandPicker($atTheMoment, $puzzleId));
        self::assertTrue($this->onEventPage($atTheMoment, $puzzleId));
        self::assertTrue($this->amongCompetitionPuzzles($atTheMoment, $puzzleId));
        self::assertTrue($this->usedAtTheCompetition($atTheMoment, $puzzleId));
    }

    public function testAutomaticRevealIsTheSameMomentEverywhere(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::Entirely);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $revealAt = $roundPuzzle->round->automaticRevealAt();

        self::assertSame(RoundPuzzleReveal::Automatic, $roundPuzzle->revealMode);
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->revealsAt()?->getTimestamp());
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->puzzle->hideUntil?->getTimestamp());

        $oneSecondBefore = new MockClock($revealAt->modify('-1 second'));
        self::assertFalse($this->foundBySearch($oneSecondBefore));
        self::assertFalse($this->foundByBrandPicker($oneSecondBefore, $puzzleId));
        self::assertFalse($this->onEventPage($oneSecondBefore, $puzzleId));
        self::assertFalse($this->amongCompetitionPuzzles($oneSecondBefore, $puzzleId));
        self::assertFalse($this->usedAtTheCompetition($oneSecondBefore, $puzzleId));

        $atTheMoment = new MockClock($revealAt);
        self::assertTrue($this->foundBySearch($atTheMoment));
        self::assertTrue($this->foundByBrandPicker($atTheMoment, $puzzleId));
        self::assertTrue($this->onEventPage($atTheMoment, $puzzleId));
        self::assertTrue($this->amongCompetitionPuzzles($atTheMoment, $puzzleId));
        self::assertTrue($this->usedAtTheCompetition($atTheMoment, $puzzleId));
    }

    /**
     * @return iterable<string, array{int, PuzzleHideMode}>
     */
    public static function roundDelays(): iterable
    {
        yield 'when the round starts' => [0, PuzzleHideMode::Entirely];
        yield 'the default' => [RoundPuzzleReveal::DEFAULT_DELAY_MINUTES, PuzzleHideMode::Entirely];
        yield 'custom' => [25, PuzzleHideMode::Entirely];
        yield 'the longest' => [RoundPuzzleReveal::MAX_DELAY_MINUTES, PuzzleHideMode::Entirely];
        yield 'custom, picture only' => [25, PuzzleHideMode::ImageOnly];
    }

    /**
     * The round's own delay is THE moment: the entity, the site-wide hide it writes, the SQL every page reads, the
     * organiser's puzzles page and the internal API - one second before it nothing shows the puzzle, at it everything.
     */
    #[DataProvider('roundDelays')]
    public function testAutomaticRevealFollowsTheRoundsDelayEverywhere(int $delay, PuzzleHideMode $hideMode): void
    {
        $this->setRoundDelay($delay);
        $roundPuzzle = $this->addSecretPuzzle($hideMode);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $roundPuzzleId = $roundPuzzle->id->toString();
        $expected = $roundPuzzle->round->startsAt->modify(sprintf('+%d minutes', $delay));

        self::assertSame($delay, $roundPuzzle->round->revealDelayMinutes);
        self::assertSame(RoundPuzzleReveal::Automatic, $roundPuzzle->revealMode);
        self::assertSame($expected->getTimestamp(), $roundPuzzle->round->automaticRevealAt()->getTimestamp());
        self::assertSame($expected->getTimestamp(), $roundPuzzle->revealsAt()?->getTimestamp());
        self::assertSame($expected->getTimestamp(), $roundPuzzle->puzzle->hideImageUntil?->getTimestamp());

        if ($hideMode === PuzzleHideMode::Entirely) {
            self::assertSame($expected->getTimestamp(), $roundPuzzle->puzzle->hideUntil?->getTimestamp());
        } else {
            self::assertNull($roundPuzzle->puzzle->hideUntil);
        }

        // SQL computes the same moment from the round row
        $sqlRevealAt = $this->connection()->fetchOne(
            sprintf(
                'SELECT %s FROM competition_round_puzzle crp INNER JOIN competition_round cr ON cr.id = crp.round_id WHERE crp.id = :id',
                RoundPuzzleReveal::sqlRevealAt('crp', 'cr'),
            ),
            ['id' => $roundPuzzleId],
        );
        self::assertIsString($sqlRevealAt);
        self::assertSame($expected->getTimestamp(), new DateTimeImmutable($sqlRevealAt)->getTimestamp());

        // The internal API answers the same moment
        foreach (self::getContainer()->get(GetAdminCompetitions::class)->round(CompetitionApiFixture::ROUND_FUTURE)->puzzles as $adminPuzzle) {
            if ($adminPuzzle->roundPuzzleId === $roundPuzzleId) {
                self::assertNotNull($adminPuzzle->revealsAt);
                self::assertSame($expected->getTimestamp(), new DateTimeImmutable($adminPuzzle->revealsAt)->getTimestamp());
            }
        }

        $oneSecondBefore = new MockClock($expected->modify('-1 second'));
        $atTheMoment = new MockClock($expected);

        self::assertTrue($this->hiddenOnTheRoundsPuzzlesPage($oneSecondBefore, $roundPuzzleId));
        self::assertFalse($this->hiddenOnTheRoundsPuzzlesPage($atTheMoment, $roundPuzzleId));
        self::assertSame($expected->getTimestamp(), $this->revealShownOnTheRoundsPuzzlesPage($atTheMoment, $roundPuzzleId)?->getTimestamp());

        if ($hideMode === PuzzleHideMode::Entirely) {
            self::assertFalse($this->foundBySearch($oneSecondBefore));
            self::assertFalse($this->foundByBrandPicker($oneSecondBefore, $puzzleId));
            self::assertFalse($this->onEventPage($oneSecondBefore, $puzzleId));
            self::assertFalse($this->amongCompetitionPuzzles($oneSecondBefore, $puzzleId));
            self::assertFalse($this->usedAtTheCompetition($oneSecondBefore, $puzzleId));

            self::assertTrue($this->foundBySearch($atTheMoment));
            self::assertTrue($this->foundByBrandPicker($atTheMoment, $puzzleId));
            self::assertTrue($this->onEventPage($atTheMoment, $puzzleId));
            self::assertTrue($this->amongCompetitionPuzzles($atTheMoment, $puzzleId));
            self::assertTrue($this->usedAtTheCompetition($atTheMoment, $puzzleId));
        } else {
            // The name is public, the picture and the codes are not - until the round's own moment
            self::assertTrue($this->onEventPage($oneSecondBefore, $puzzleId));
            self::assertSame([null, null], $this->shownCodes($oneSecondBefore, $puzzleId));
            self::assertSame([self::SECRET_EAN, self::SECRET_BRAND_CODE], $this->shownCodes($atTheMoment, $puzzleId));
        }
    }

    /**
     * @return iterable<string, array{string, PuzzleHideMode, string}>
     */
    public static function publicPuzzlesSecretOnTheEventPageOnly(): iterable
    {
        yield 'entirely' => [CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY, PuzzleHideMode::Entirely, CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY];
        yield 'image only' => [CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE, PuzzleHideMode::ImageOnly, CompetitionApiFixture::IMAGE_HIDDEN_IMAGE];
    }

    /**
     * A public catalogue puzzle the round keeps secret on its event pages only: nothing hides it on the rest of the site
     * (its own hide_until / hide_image_until stay empty), so the moment GetEditionRounds computes in PHP from the round
     * row is its ONLY guard - on the event and edition pages and in API v1. With a delay of its own (25) it must be that
     * moment, never the default 10.
     */
    #[DataProvider('publicPuzzlesSecretOnTheEventPageOnly')]
    public function testAPublicPuzzleSecretOnTheEventPageOnlyFollowsTheRoundsDelay(string $puzzleId, PuzzleHideMode $hideMode, string $image): void
    {
        $this->setRoundDelay(25);
        $row = $this->connection()->fetchAssociative(
            'SELECT crp.hides_everywhere, crp.hide_until_round_starts, crp.hide_mode, crp.reveal_mode, p.hide_until, p.hide_image_until, cr.starts_at, cr.reveal_delay_minutes
            FROM competition_round_puzzle crp
            INNER JOIN puzzle p ON p.id = crp.puzzle_id
            INNER JOIN competition_round cr ON cr.id = crp.round_id
            WHERE crp.round_id = :roundId AND crp.puzzle_id = :puzzleId',
            ['roundId' => CompetitionApiFixture::ROUND_FUTURE, 'puzzleId' => $puzzleId],
        );
        self::assertIsArray($row);

        // Secret on the event page only, automatic, and nothing else hides it
        self::assertFalse($row['hides_everywhere']);
        self::assertTrue($row['hide_until_round_starts']);
        self::assertSame($hideMode->value, $row['hide_mode']);
        self::assertSame(RoundPuzzleReveal::Automatic->value, $row['reveal_mode']);
        self::assertNull($row['hide_until']);
        self::assertNull($row['hide_image_until']);
        self::assertSame(25, $row['reveal_delay_minutes']);

        self::assertIsString($row['starts_at']);
        $revealAt = new DateTimeImmutable($row['starts_at'] . ' UTC')->modify('+25 minutes');
        $oneSecondBefore = new MockClock($revealAt->modify('-1 second'));
        $atTheMoment = new MockClock($revealAt);

        foreach (['event page' => $this->onEventPageAs(...), 'API v1' => $this->inApiV1As(...)] as $surface => $shown) {
            if ($hideMode === PuzzleHideMode::Entirely) {
                self::assertSame([false, null], $shown($oneSecondBefore, $puzzleId), "{$surface}: one second before - not listed");
            } else {
                self::assertSame([true, null], $shown($oneSecondBefore, $puzzleId), "{$surface}: one second before - the name, no picture");
            }

            self::assertSame([true, $image], $shown($atTheMoment, $puzzleId), "{$surface}: at the moment - out with its picture");
        }
    }

    public function testImageOnlyKeepsThePictureAndTheCodesSecretUntilTheMoment(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::ImageOnly);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $revealAt = $roundPuzzle->round->automaticRevealAt();

        // The name is public, the picture and the codes are not
        self::assertNull($roundPuzzle->puzzle->hideUntil);
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->puzzle->hideImageUntil?->getTimestamp());

        $oneSecondBefore = new MockClock($revealAt->modify('-1 second'));
        self::assertTrue($this->foundBySearch($oneSecondBefore));
        self::assertTrue($this->onEventPage($oneSecondBefore, $puzzleId));
        self::assertSame([null, null], $this->shownCodes($oneSecondBefore, $puzzleId));
        self::assertFalse($this->foundByCode(self::SECRET_EAN));
        self::assertFalse($this->foundByCode(self::SECRET_BRAND_CODE));

        foreach (new SearchPuzzle($this->connection(), $oneSecondBefore)->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER) as $choice) {
            if ($choice->puzzleId === $puzzleId) {
                self::assertNull($choice->puzzleEan);
                self::assertNull($choice->puzzleIdentificationNumber);
            }
        }

        $atTheMoment = new MockClock($revealAt);
        self::assertSame([self::SECRET_EAN, self::SECRET_BRAND_CODE], $this->shownCodes($atTheMoment, $puzzleId));
    }

    public function testPuzzlePageAndBrandPickerFollowRevealNow(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::Entirely);
        $roundPuzzleId = $roundPuzzle->id->toString();
        $puzzleId = $roundPuzzle->puzzle->id->toString();

        // Before the reveal: out of the brand picker, and its own page does not exist for a visitor
        $this->browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());

        $this->browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseStatusCodeSame(404);

        $this->dispatch(new RevealRoundPuzzleNow($roundPuzzleId));

        $this->browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertStringContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());

        $crawler = $this->browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testManualRevealKeepsThePuzzleSecretEverywhereForever(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::Entirely);
        $this->dispatch(new ChangeRoundPuzzleReveal($roundPuzzle->id->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null));
        $puzzleId = $roundPuzzle->puzzle->id->toString();

        $longAfterTheRound = new MockClock(new DateTimeImmutable('+3 years'));
        self::assertFalse($this->foundBySearch($longAfterTheRound));
        self::assertFalse($this->foundByBrandPicker($longAfterTheRound, $puzzleId));
        self::assertFalse($this->onEventPage($longAfterTheRound, $puzzleId));
        self::assertFalse($this->amongCompetitionPuzzles($longAfterTheRound, $puzzleId));
    }

    /**
     * The future round's reveal delay, set the way the organiser does (nothing else of the round changes). A shorter one
     * reveals the fixture's secret puzzles earlier - said yes to here.
     */
    private function setRoundDelay(int $delay): void
    {
        $this->dispatch(new EditCompetitionRound(
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            name: 'ignored',
            minutesLimit: 1,
            startsAt: new DateTimeImmutable(),
            timezone: 'UTC',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            refuseToReveal: false,
            keepFields: EditCompetitionRound::FIELDS,
            revealDelayMinutes: $delay,
        ));
    }

    private function hiddenOnTheRoundsPuzzlesPage(MockClock $clock, string $roundPuzzleId): bool
    {
        return $this->onTheRoundsPuzzlesPage($clock, $roundPuzzleId)->hidden;
    }

    private function revealShownOnTheRoundsPuzzlesPage(MockClock $clock, string $roundPuzzleId): null|DateTimeImmutable
    {
        return $this->onTheRoundsPuzzlesPage($clock, $roundPuzzleId)->revealsAt;
    }

    private function onTheRoundsPuzzlesPage(MockClock $clock, string $roundPuzzleId): RoundPuzzleForManagement
    {
        foreach (new GetRoundPuzzlesForManagement($this->connection(), $clock)->ofRound(CompetitionApiFixture::ROUND_FUTURE) as $roundPuzzle) {
            if ($roundPuzzle->roundPuzzleId === $roundPuzzleId) {
                return $roundPuzzle;
            }
        }

        self::fail('The round puzzle is on its round\'s puzzles page');
    }

    private function addSecretPuzzle(PuzzleHideMode $hideMode): CompetitionRoundPuzzle
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: self::SECRET_NAME,
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(self::SECRET_EAN),
            brandCodes: BrandCodeList::fromStored(self::SECRET_BRAND_CODE),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
        ));

        return $this->roundPuzzle($roundPuzzleId->toString());
    }

    private function foundByCode(string $code): bool
    {
        // Code matching uses the database's own clock: the real now, before the reveal
        $search = self::getContainer()->get(SearchPuzzle::class);

        foreach ([...$search->byUserInput(null, $code, PiecesRange::any(), null), ...$search->allByEan($code)] as $puzzle) {
            if ($puzzle->puzzleName === self::SECRET_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{null|string, null|string} the EAN and brand code the site shows
     */
    private function shownCodes(MockClock $clock, string $puzzleId): array
    {
        $overview = new GetPuzzleOverview($this->connection(), $clock)->byId($puzzleId);

        return [$overview->puzzleEan, $overview->puzzleIdentificationNumber];
    }

    private function foundBySearch(MockClock $clock): bool
    {
        $search = new SearchPuzzle($this->connection(), $clock);

        foreach ($search->byUserInput(null, self::SECRET_NAME, PiecesRange::any(), null) as $puzzle) {
            if ($puzzle->puzzleName === self::SECRET_NAME) {
                return true;
            }
        }

        return false;
    }

    private function foundByBrandPicker(MockClock $clock, string $puzzleId): bool
    {
        $search = new SearchPuzzle($this->connection(), $clock);

        foreach ($search->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER) as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                return true;
            }
        }

        return false;
    }

    private function onEventPage(MockClock $clock, string $puzzleId): bool
    {
        $rounds = new GetEditionRounds($this->connection(), $clock)->forCompetition(CompetitionApiFixture::COMPETITION_API);

        foreach ($rounds as $round) {
            foreach ($round->puzzles as $puzzle) {
                if ($puzzle->puzzleId === $puzzleId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The event and edition pages (EventDetailController, EditionDetailController) read the rounds from GetEditionRounds.
     *
     * @return array{bool, null|string} listed, and the picture shown
     */
    private function onEventPageAs(MockClock $clock, string $puzzleId): array
    {
        foreach (new GetEditionRounds($this->connection(), $clock)->forCompetition(CompetitionApiFixture::COMPETITION_API) as $round) {
            foreach ($round->puzzles as $puzzle) {
                if ($puzzle->puzzleId === $puzzleId) {
                    return [true, $puzzle->puzzleImage];
                }
            }
        }

        return [false, null];
    }

    /**
     * GET /api/v1/competitions/{id} - its provider, with the rounds read at the clock's moment.
     *
     * @return array{bool, null|string} listed, and the picture shown
     */
    private function inApiV1As(MockClock $clock, string $puzzleId): array
    {
        $container = self::getContainer();
        $provider = new CompetitionDetailResponseProvider(
            $container->get(GetCompetitionEvents::class),
            new GetEditionRounds($this->connection(), $clock),
            $container->get(GetCompetitionSeries::class),
            $container->get(IsCompetitionPubliclyVisible::class),
        );
        $detail = $provider->provide(new Get(), ['id' => CompetitionApiFixture::COMPETITION_API]);

        foreach ($detail->rounds as $round) {
            foreach ($round->puzzles as $puzzle) {
                if ($puzzle->id === $puzzleId) {
                    return [true, $puzzle->image];
                }
            }
        }

        return [false, null];
    }

    private function amongCompetitionPuzzles(MockClock $clock, string $puzzleId): bool
    {
        $puzzles = new GetCompetitionPuzzles($this->connection(), $clock)->forCompetitions([CompetitionApiFixture::COMPETITION_API]);

        foreach ($puzzles[CompetitionApiFixture::COMPETITION_API] ?? [] as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                return true;
            }
        }

        return false;
    }

    private function usedAtTheCompetition(MockClock $clock, string $puzzleId): bool
    {
        $summary = new GetPuzzleSummary($this->connection(), $clock)->forPuzzle($puzzleId);

        return $summary->usedAt !== [];
    }

    private function dispatch(object $message): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function roundPuzzle(string $roundPuzzleId): CompetitionRoundPuzzle
    {
        $roundPuzzle = self::getContainer()->get(EntityManagerInterface::class)->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
