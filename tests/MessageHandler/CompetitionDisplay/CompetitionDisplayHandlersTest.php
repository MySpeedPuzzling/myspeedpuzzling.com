<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\CompetitionDisplay;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayLimitReached;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Message\ChangeCompetitionDisplayFollow;
use SpeedPuzzling\Web\Message\ChangeCompetitionDisplayLocale;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DeleteCompetitionDisplay;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\DuplicateCompetitionDisplay;
use SpeedPuzzling\Web\Message\RegenerateCompetitionDisplayToken;
use SpeedPuzzling\Web\Message\RenameCompetitionDisplay;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Results\CompetitionDisplayOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\DisplayDesign;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Competition displays core (docs/features/competitions-management/toolkit/D-displays-overlays.md §3, R §4.2 D1) -
 * through the real bus, read back through the organiser's query and the table itself.
 */
final class CompetitionDisplayHandlersTest extends KernelTestCase
{
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string OTHER_EVENT = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;

    private MessageBusInterface $messageBus;
    private GetCompetitionDisplays $getCompetitionDisplays;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->getCompetitionDisplays = self::getContainer()->get(GetCompetitionDisplays::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAnEventHasNoDisplaysUntilOneIsAdded(): void
    {
        self::assertSame([], $this->getCompetitionDisplays->ofCompetition(self::EVENT));
        self::assertSame([], $this->getCompetitionDisplays->ofCompetition(self::OTHER_EVENT));
    }

    public function testAnAddedScreenFollowsTheCurrentRoundWithTheBlankDesign(): void
    {
        $displayId = $this->add(name: "  Hall A\nprojector  ", kind: DisplayKind::Screen, locale: 'cs');

        $display = $this->getCompetitionDisplays->byId($displayId);
        self::assertSame(self::EVENT, $display->competitionId);
        self::assertSame(DisplayKind::Screen, $display->kind);
        self::assertSame('Hall A projector', $display->name);
        self::assertSame('cs', $display->locale);
        self::assertTrue(OnlineToken::looksValid($display->token));
        self::assertSame(DisplayFollowMode::Current, $display->followMode);
        self::assertNull($display->pinnedRoundId);
        self::assertNull($display->followRoundIds);
        self::assertTrue($display->followsCurrentRound());
        self::assertSame(1, $display->designVersion);

        $row = $this->row($displayId);
        // jsonb keeps its own key order
        self::assertEquals(DisplayDesign::blank(DisplayKind::Screen), json_decode($row['design'], true));
        self::assertSame('color', DisplayDesign::blank(DisplayKind::Screen)['canvas']['background']['type']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['created_by_id']);
        self::assertFalse($row['uses_live_results']);
        self::assertFalse($row['uses_results']);

        // Another event is untouched
        self::assertSame([], $this->getCompetitionDisplays->ofCompetition(self::OTHER_EVENT));
    }

    public function testAnOverlayStartsTransparent(): void
    {
        $displayId = $this->add(kind: DisplayKind::Overlay);

        self::assertEquals(DisplayDesign::blank(DisplayKind::Overlay), json_decode($this->row($displayId)['design'], true));
        self::assertSame('transparent', DisplayDesign::blank(DisplayKind::Overlay)['canvas']['background']['type']);
    }

    public function testAnUnknownLanguageIsRefused(): void
    {
        $this->expectException(HandlerFailedException::class);

        $this->add(locale: 'xx');
    }

    public function testAnEventHasAtMostTwentyDisplays(): void
    {
        for ($i = 1; $i <= CompetitionDisplay::MAX_PER_COMPETITION; $i++) {
            $this->add(name: 'Display ' . $i);
        }

        // Another event has its own cap
        $this->add(competitionId: self::OTHER_EVENT);

        try {
            $this->add(name: 'One too many');
            self::fail('The 21st display must be refused');
        } catch (CompetitionDisplayLimitReached) {
        }

        $displays = $this->getCompetitionDisplays->ofCompetition(self::EVENT);
        self::assertCount(CompetitionDisplay::MAX_PER_COMPETITION, $displays);

        // A duplicate counts too
        $this->expectException(CompetitionDisplayLimitReached::class);
        $this->messageBus->dispatch(new DuplicateCompetitionDisplay($displays[0]->id, Uuid::uuid7(), self::EVENT, 'Copy', null));
    }

    public function testRenameAndLanguage(): void
    {
        $displayId = $this->add();

        $this->messageBus->dispatch(new RenameCompetitionDisplay($displayId, str_repeat('x', 150)));
        $this->messageBus->dispatch(new ChangeCompetitionDisplayLocale($displayId, 'ja'));

        $display = $this->getCompetitionDisplays->byId($displayId);
        self::assertSame(str_repeat('x', CompetitionDisplay::NAME_MAX_LENGTH), $display->name);
        self::assertSame('ja', $display->locale);
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $displayId = $this->add();

        try {
            $this->messageBus->dispatch(new RenameCompetitionDisplay($displayId, '   '));
            self::fail('An empty name must be refused');
        } catch (HandlerFailedException) {
        }

        self::assertSame('Hall A', $this->getCompetitionDisplays->byId($displayId)->name);
    }

    public function testPinnedRound(): void
    {
        $displayId = $this->add();

        $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($displayId, DisplayFollowMode::Pinned, OfficialResultsFixture::ROUND_FINAL, [OfficialResultsFixture::ROUND_GROUP_A]));

        $display = $this->getCompetitionDisplays->byId($displayId);
        self::assertSame(DisplayFollowMode::Pinned, $display->followMode);
        self::assertSame(OfficialResultsFixture::ROUND_FINAL, $display->pinnedRoundId);
        // The subset belongs to "current" only
        self::assertNull($display->followRoundIds);
        self::assertFalse($display->followsCurrentRound());

        // Back to current: the pinned round goes
        $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($displayId, DisplayFollowMode::Current, OfficialResultsFixture::ROUND_FINAL));

        $display = $this->getCompetitionDisplays->byId($displayId);
        self::assertSame(DisplayFollowMode::Current, $display->followMode);
        self::assertNull($display->pinnedRoundId);
        self::assertNull($display->followRoundIds);
    }

    public function testCurrentRoundAmongASubsetKeepsTheEventsRoundOrder(): void
    {
        $displayId = $this->add();

        $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($displayId, DisplayFollowMode::Current, null, [
            OfficialResultsFixture::ROUND_FINAL,
            strtoupper(OfficialResultsFixture::ROUND_GROUP_A),
            OfficialResultsFixture::ROUND_FINAL,
        ]));

        $display = $this->getCompetitionDisplays->byId($displayId);
        self::assertTrue($display->followsCurrentRound());
        self::assertNotNull($display->followRoundIds);
        self::assertEqualsCanonicalizing([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_FINAL], $display->followRoundIds);
        self::assertCount(2, $display->followRoundIds);

        // Every round ticked = every round (also the ones added later)
        $allRounds = $this->database->fetchFirstColumn('SELECT id FROM competition_round WHERE competition_id = :id', ['id' => self::EVENT]);
        $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($displayId, DisplayFollowMode::Current, null, array_map(static fn (mixed $roundId): string => is_string($roundId) ? $roundId : '', $allRounds)));

        self::assertNull($this->getCompetitionDisplays->byId($displayId)->followRoundIds);
    }

    public function testARoundOfAnotherEventIsNotFound(): void
    {
        $displayId = $this->add();

        foreach (
            [
            new ChangeCompetitionDisplayFollow($displayId, DisplayFollowMode::Pinned, CompetitionRoundFixture::ROUND_CZECH_FINAL),
            new ChangeCompetitionDisplayFollow($displayId, DisplayFollowMode::Current, null, [OfficialResultsFixture::ROUND_GROUP_A, CompetitionRoundFixture::ROUND_CZECH_FINAL]),
            ] as $message
        ) {
            try {
                $this->messageBus->dispatch($message);
                self::fail('A foreign round must not be followed');
            } catch (\Throwable $exception) {
                self::assertInstanceOf(CompetitionDisplayNotFound::class, $exception);
            }
        }

        $display = $this->getCompetitionDisplays->byId($displayId);
        self::assertSame(DisplayFollowMode::Current, $display->followMode);
        self::assertNull($display->pinnedRoundId);
        self::assertNull($display->followRoundIds);
    }

    public function testADeletedPinnedRoundFallsBackToTheCurrentOne(): void
    {
        $displayId = $this->add(competitionId: self::OTHER_EVENT);
        $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($displayId, DisplayFollowMode::Pinned, CompetitionRoundFixture::ROUND_CZECH_FINAL));

        $this->messageBus->dispatch(new DeleteCompetitionRound(CompetitionRoundFixture::ROUND_CZECH_FINAL, self::OTHER_EVENT));

        $display = $this->getCompetitionDisplays->byId($displayId);
        self::assertSame(DisplayFollowMode::Pinned, $display->followMode);
        self::assertNull($display->pinnedRoundId);
        self::assertTrue($display->followsCurrentRound());
    }

    public function testADuplicateCopiesEverythingButTheLink(): void
    {
        $sourceId = $this->add(kind: DisplayKind::Overlay, locale: 'de');
        $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow($sourceId, DisplayFollowMode::Pinned, OfficialResultsFixture::ROUND_GROUP_B));
        // A design D2 would have saved - copied as it is
        $this->database->executeStatement(
            "UPDATE competition_display SET design = :design, design_version = 7, uses_results = true WHERE id = :id",
            ['id' => $sourceId, 'design' => json_encode(['schema' => 1, 'elements' => [['id' => 'abcdefgh', 'type' => 'timer']]])],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $copyId = Uuid::uuid7();
        $this->messageBus->dispatch(new DuplicateCompetitionDisplay($sourceId, $copyId, self::EVENT, 'Hall A (copy)', PlayerFixture::PLAYER_WITH_STRIPE));

        $source = $this->getCompetitionDisplays->byId($sourceId);
        $copy = $this->getCompetitionDisplays->byId($copyId->toString());
        self::assertSame('Hall A (copy)', $copy->name);
        self::assertSame(DisplayKind::Overlay, $copy->kind);
        self::assertSame('de', $copy->locale);
        self::assertSame(DisplayFollowMode::Pinned, $copy->followMode);
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_B, $copy->pinnedRoundId);
        self::assertSame(1, $copy->designVersion);
        self::assertNotSame($source->token, $copy->token);
        self::assertTrue(OnlineToken::looksValid($copy->token));

        $sourceRow = $this->row($sourceId);
        $copyRow = $this->row($copyId->toString());
        self::assertEquals(json_decode($sourceRow['design'], true), json_decode($copyRow['design'], true));
        self::assertTrue($copyRow['uses_results']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $copyRow['created_by_id']);
    }

    public function testADisplayOfAnotherEventIsNotDuplicatedUnderThisOnesLock(): void
    {
        $sourceId = $this->add(competitionId: self::OTHER_EVENT);

        $this->expectException(CompetitionDisplayNotFound::class);
        $this->messageBus->dispatch(new DuplicateCompetitionDisplay($sourceId, Uuid::uuid7(), self::EVENT, 'Copy', null));
    }

    public function testANewLinkKillsTheOldOne(): void
    {
        $displayId = $this->add();
        $oldToken = $this->getCompetitionDisplays->byId($displayId)->token;

        $this->messageBus->dispatch(new RegenerateCompetitionDisplayToken($displayId));

        $newToken = $this->getCompetitionDisplays->byId($displayId)->token;
        self::assertNotSame($oldToken, $newToken);
        self::assertTrue(OnlineToken::looksValid($newToken));
        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_display WHERE token = :token', ['token' => $oldToken]));
        self::assertSame($displayId, $this->database->fetchOne('SELECT id FROM competition_display WHERE token = :token', ['token' => $newToken]));
    }

    public function testDelete(): void
    {
        $displayId = $this->add();
        $keptId = $this->add(name: 'Kept');
        $token = $this->getCompetitionDisplays->byId($displayId)->token;

        $this->messageBus->dispatch(new DeleteCompetitionDisplay($displayId));

        self::assertSame([$keptId], array_map(static fn (CompetitionDisplayOverview $display): string => $display->id, $this->getCompetitionDisplays->ofCompetition(self::EVENT)));
        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_display WHERE token = :token', ['token' => $token]));

        $this->expectException(CompetitionDisplayNotFound::class);
        $this->messageBus->dispatch(new DeleteCompetitionDisplay($displayId));
    }

    public function testDeletingTheEventDeletesItsDisplays(): void
    {
        $this->add(competitionId: self::OTHER_EVENT);
        $keptId = $this->add();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->messageBus->dispatch(new DeleteCompetition(self::OTHER_EVENT));

        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_display WHERE competition_id = :id', ['id' => self::OTHER_EVENT]));
        self::assertCount(1, $this->getCompetitionDisplays->ofCompetition(self::EVENT));
        self::assertSame($keptId, $this->getCompetitionDisplays->ofCompetition(self::EVENT)[0]->id);
    }

    private function add(
        string $name = 'Hall A',
        DisplayKind $kind = DisplayKind::Screen,
        string $locale = 'en',
        string $competitionId = self::EVENT,
    ): string {
        $displayId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetitionDisplay($displayId, $competitionId, $kind, $name, $locale, PlayerFixture::PLAYER_ADMIN));

        return $displayId->toString();
    }

    /**
     * @return array{design: string, created_by_id: null|string, uses_live_results: bool, uses_results: bool}
     */
    private function row(string $displayId): array
    {
        /** @var array{design: string, created_by_id: null|string, uses_live_results: bool, uses_results: bool} $row */
        $row = $this->database->fetchAssociative(
            'SELECT design, created_by_id, uses_live_results, uses_results FROM competition_display WHERE id = :id',
            ['id' => $displayId],
        );

        return $row;
    }
}
