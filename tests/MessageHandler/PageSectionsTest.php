<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PageSectionNotFound;
use SpeedPuzzling\Web\Exceptions\PageSectionTypeNotAvailable;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Message\ChangePageSectionVisibility;
use SpeedPuzzling\Web\Message\DeletePageSection;
use SpeedPuzzling\Web\Message\DeletePageSectionImages;
use SpeedPuzzling\Web\Message\EditPageSection;
use SpeedPuzzling\Web\Message\ReorderPageSections;
use SpeedPuzzling\Web\MessageHandler\DeletePageSectionImagesHandler;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Results\PageSection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Organiser-written page sections (docs/features/competitions-management/public-page.md): writes, ownership, order,
 * inheritance and the "has sections" flag the public pages read.
 */
final class PageSectionsTest extends KernelTestCase
{
    private const string EVENT = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;
    private const string OTHER_EVENT = CompetitionFixture::COMPETITION_WJPC_2024;
    private const string ONLINE_EVENT = CompetitionFixture::COMPETITION_RECURRING_ONLINE;
    private const string SERIES = CompetitionSeriesFixture::SERIES_OFFLINE;
    private const string EDITION = CompetitionSeriesFixture::EDITION_OFFLINE_1;

    private MessageBusInterface $messageBus;
    private CompetitionPageSectionRepository $sectionRepository;
    private GetCompetitionPageSections $getPageSections;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->sectionRepository = self::getContainer()->get(CompetitionPageSectionRepository::class);
        $this->getPageSections = self::getContainer()->get(GetCompetitionPageSections::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnUntouchedPageHasNoSections(): void
    {
        $getEvents = self::getContainer()->get(GetCompetitionEvents::class);
        $getSeries = self::getContainer()->get(GetCompetitionSeries::class);

        self::assertFalse($getEvents->byId(self::EVENT)->hasPageSections);
        self::assertFalse($getEvents->byId(self::EDITION)->hasPageSections);
        self::assertFalse($getSeries->byId(self::SERIES)->hasPageSections);
        self::assertSame([], $this->getPageSections->forCompetitionPage(self::EVENT));
    }

    public function testANewSectionIsSanitisedAndGoesLast(): void
    {
        $first = $this->add(self::EVENT, PageSectionType::RichText, '  Rules  ', ['html' => '<p onclick="x()">Be <strong>fair</strong></p><script>alert(1)</script>']);
        $second = $this->add(self::EVENT, PageSectionType::Faq, '', ['items' => [['question' => 'Parking?', 'answer' => 'Yes']]]);

        $firstSection = $this->sectionRepository->get($first);
        self::assertSame('Rules', $firstSection->title);
        self::assertSame(['html' => '<p>Be <strong>fair</strong></p>'], $firstSection->content);
        self::assertSame(1, $firstSection->position);

        $secondSection = $this->sectionRepository->get($second);
        self::assertNull($secondSection->title);
        self::assertSame(2, $secondSection->position);

        self::assertSame([$first, $second], $this->ids($this->getPageSections->forCompetitionPage(self::EVENT)));
        self::assertTrue(self::getContainer()->get(GetCompetitionEvents::class)->byId(self::EVENT)->hasPageSections);
    }

    public function testASectionBelongsToExactlyOnePage(): void
    {
        try {
            $this->messageBus->dispatch(new AddPageSection(Uuid::uuid7(), self::EVENT, self::SERIES, PageSectionType::RichText, null, ['html' => '<p>x</p>']));
            self::fail('A section of an event and a series at once was accepted');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }

        try {
            $this->messageBus->dispatch(new AddPageSection(Uuid::uuid7(), null, null, PageSectionType::RichText, null, ['html' => '<p>x</p>']));
            self::fail('A section of no page was accepted');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    public function testAnOnlineEventGetsNoVenue(): void
    {
        $this->expectException(PageSectionTypeNotAvailable::class);

        $this->add(self::ONLINE_EVENT, PageSectionType::Venue, null, ['address' => 'Main square 1']);
    }

    public function testEditingKeepsThePositionAndDeletesRemovedPicturesAfterwards(): void
    {
        $owner = PageSectionOwner::competition(self::EVENT);
        $kept = $owner->uploadDirectory() . 'kept.jpg';
        $removed = $owner->uploadDirectory() . 'removed.jpg';
        $this->add(self::EVENT, PageSectionType::RichText, null, ['html' => '<p>First</p>']);
        $sectionId = $this->add(self::EVENT, PageSectionType::Gallery, 'Photos', ['images' => [
            ['path' => $kept, 'caption' => ''],
            ['path' => $removed, 'caption' => ''],
        ]]);
        $this->clearAsyncTransport();

        $this->messageBus->dispatch(new EditPageSection($sectionId, 'Last year', ['images' => [['path' => $kept, 'caption' => 'Finals']]]));

        $section = $this->sectionRepository->get($sectionId);
        self::assertSame('Last year', $section->title);
        self::assertSame(['images' => [['path' => $kept, 'caption' => 'Finals']]], $section->content);
        self::assertSame(2, $section->position);
        self::assertNotNull($section->updatedAt);

        self::assertEquals([new DeletePageSectionImages([$removed])], $this->sentImageDeletions());
    }

    public function testDeletingASectionDeletesItsPicturesButNoPictureStillShown(): void
    {
        $owner = PageSectionOwner::competition(self::EVENT);
        $logo = $owner->uploadDirectory() . 'logo.png';
        $shared = $owner->uploadDirectory() . 'shared.jpg';
        $sectionId = $this->add(self::EVENT, PageSectionType::Sponsors, null, ['sponsors' => [
            ['name' => 'Shop', 'url' => '', 'logoPath' => $logo],
            ['name' => 'Cafe', 'url' => '', 'logoPath' => $shared],
        ]]);
        // Another section of the page shows the same upload
        $this->add(self::EVENT, PageSectionType::Gallery, null, ['images' => [['path' => $shared, 'caption' => '']]]);
        $this->clearAsyncTransport();

        $filesystem = self::getContainer()->get(Filesystem::class);
        $filesystem->write($logo, 'logo');
        $filesystem->write($shared, 'shared');

        $this->messageBus->dispatch(new DeletePageSection($sectionId));
        $this->entityManager->flush();

        $deletions = $this->sentImageDeletions();
        self::assertEquals([new DeletePageSectionImages([$logo, $shared])], $deletions);

        (self::getContainer()->get(DeletePageSectionImagesHandler::class))($deletions[0]);

        self::assertFalse($filesystem->fileExists($logo));
        self::assertTrue($filesystem->fileExists($shared), 'A picture another section still shows was deleted');

        $this->expectException(PageSectionNotFound::class);
        $this->sectionRepository->get($sectionId);
    }

    public function testAHiddenSectionStaysInTheEditorButLeavesThePage(): void
    {
        $sectionId = $this->add(self::EVENT, PageSectionType::RichText, null, ['html' => '<p>Draft</p>']);

        $this->messageBus->dispatch(new ChangePageSectionVisibility($sectionId, false));

        self::assertSame([], $this->getPageSections->forCompetitionPage(self::EVENT));
        self::assertFalse(self::getContainer()->get(GetCompetitionEvents::class)->byId(self::EVENT)->hasPageSections);
        $inEditor = $this->getPageSections->forCompetitionEditor(self::EVENT);
        self::assertSame([$sectionId], $this->ids($inEditor));
        self::assertFalse($inEditor[0]->visible);

        $this->messageBus->dispatch(new ChangePageSectionVisibility($sectionId, true));

        self::assertSame([$sectionId], $this->ids($this->getPageSections->forCompetitionPage(self::EVENT)));
    }

    public function testReorderingOrdersThePagesOwnSections(): void
    {
        $a = $this->add(self::EVENT, PageSectionType::RichText, 'A', ['html' => '<p>A</p>']);
        $b = $this->add(self::EVENT, PageSectionType::RichText, 'B', ['html' => '<p>B</p>']);
        $c = $this->add(self::EVENT, PageSectionType::RichText, 'C', ['html' => '<p>C</p>']);

        $this->messageBus->dispatch(new ReorderPageSections(self::EVENT, null, [$c, $a, $b]));
        self::assertSame([$c, $a, $b], $this->ids($this->getPageSections->forCompetitionPage(self::EVENT)));

        // A section the request does not name (added meanwhile in another tab) keeps its place after the named ones
        $this->messageBus->dispatch(new ReorderPageSections(self::EVENT, null, [strtoupper($b), $c]));
        self::assertSame([$b, $c, $a], $this->ids($this->getPageSections->forCompetitionPage(self::EVENT)));
    }

    public function testASectionOfAnotherPageRefusesTheWholeReorder(): void
    {
        $own = $this->add(self::EVENT, PageSectionType::RichText, 'Own', ['html' => '<p>Own</p>']);
        $ownSecond = $this->add(self::EVENT, PageSectionType::RichText, 'Own 2', ['html' => '<p>Own 2</p>']);
        $foreign = $this->add(self::OTHER_EVENT, PageSectionType::RichText, 'Foreign', ['html' => '<p>Foreign</p>']);

        try {
            $this->messageBus->dispatch(new ReorderPageSections(self::EVENT, null, [$ownSecond, $foreign, $own]));
            self::fail('Another event\'s section was accepted');
        } catch (PageSectionNotFound) {
            // Refused - nobody learns whether the id exists
        }

        $this->entityManager->clear();
        self::assertSame([$own, $ownSecond], $this->ids($this->getPageSections->forCompetitionPage(self::EVENT)));
        self::assertSame(1, $this->sectionRepository->get($foreign)->position);
    }

    public function testAnEditionCannotReorderItsSeriesSections(): void
    {
        $seriesSection = $this->add(null, PageSectionType::RichText, 'Rules', ['html' => '<p>Rules</p>'], self::SERIES);

        $this->expectException(PageSectionNotFound::class);
        $this->messageBus->dispatch(new ReorderPageSections(self::EDITION, null, [$seriesSection]));
    }

    public function testAnEditionShowsItsOwnSectionsFirstThenItsSeries(): void
    {
        $seriesRules = $this->add(null, PageSectionType::RichText, 'Series rules', ['html' => '<p>Rules</p>'], self::SERIES);
        $seriesVenue = $this->add(null, PageSectionType::Venue, 'Venue', ['address' => 'Prague'], self::SERIES);
        $seriesHidden = $this->add(null, PageSectionType::RichText, 'Hidden', ['html' => '<p>Draft</p>'], self::SERIES);
        $this->messageBus->dispatch(new ChangePageSectionVisibility($seriesHidden, false));
        $editionNews = $this->add(self::EDITION, PageSectionType::RichText, 'This edition', ['html' => '<p>News</p>']);

        $page = $this->getPageSections->forCompetitionPage(self::EDITION);
        self::assertSame([$editionNews, $seriesRules, $seriesVenue], $this->ids($page));
        self::assertSame([false, true, true], array_map(static fn (PageSection $section): bool => $section->inherited, $page));

        self::assertSame([$seriesRules, $seriesVenue], $this->ids($this->getPageSections->forSeriesPage(self::SERIES)));
        // The edition's editor lists its own sections (hidden ones too) and what the series adds to its page
        self::assertSame([$editionNews, $seriesRules, $seriesVenue], $this->ids($this->getPageSections->forCompetitionEditor(self::EDITION)));
        self::assertSame([$seriesRules, $seriesVenue, $seriesHidden], $this->ids($this->getPageSections->forSeriesEditor(self::SERIES)));
    }

    public function testAnEditionInheritsTheSeriesSectionsForTheHasSectionsFlag(): void
    {
        $this->add(null, PageSectionType::RichText, 'Series rules', ['html' => '<p>Rules</p>'], self::SERIES);

        self::assertTrue(self::getContainer()->get(GetCompetitionEvents::class)->byId(self::EDITION)->hasPageSections);
        self::assertTrue(self::getContainer()->get(GetCompetitionSeries::class)->byId(self::SERIES)->hasPageSections);
        self::assertFalse(self::getContainer()->get(GetCompetitionEvents::class)->byId(self::EVENT)->hasPageSections);
    }

    public function testAVenueNeverShowsOnAnOnlinePage(): void
    {
        $venue = $this->add(null, PageSectionType::Venue, 'Venue', ['address' => 'Prague'], self::SERIES);
        self::assertSame([$venue], $this->ids($this->getPageSections->forCompetitionPage(self::EDITION)));

        // The edition moved online after the series got its venue: the venue stays stored, but not on that page
        $this->entityManager->getConnection()->executeStatement('UPDATE competition SET is_online = true WHERE id = :id', ['id' => self::EDITION]);

        self::assertSame([], $this->getPageSections->forCompetitionPage(self::EDITION));
        self::assertFalse(self::getContainer()->get(GetCompetitionEvents::class)->byId(self::EDITION)->hasPageSections);
        self::assertSame([$venue], $this->ids($this->getPageSections->forSeriesPage(self::SERIES)));
    }

    public function testLinksCarryTheSourceLikeTheOtherEventLinks(): void
    {
        $this->add(self::EVENT, PageSectionType::Links, null, ['links' => [
            ['label' => 'Group', 'url' => 'https://facebook.com/groups/x'],
            ['label' => 'Rules', 'url' => 'https://example.com/rules?lang=en#prizes'],
        ]]);

        $links = $this->getPageSections->forCompetitionPage(self::EVENT)[0]->content['links'];
        self::assertIsArray($links);
        self::assertSame(
            ['https://facebook.com/groups/x?utm_source=myspeedpuzzling', 'https://example.com/rules?lang=en&utm_source=myspeedpuzzling#prizes'],
            array_column($links, 'href'),
        );
    }

    /**
     * @param array<string, mixed> $content
     */
    private function add(null|string $competitionId, PageSectionType $type, null|string $title, array $content, null|string $seriesId = null): string
    {
        $sectionId = Uuid::uuid7();
        $this->messageBus->dispatch(new AddPageSection($sectionId, $competitionId, $seriesId, $type, $title, $content));

        return $sectionId->toString();
    }

    /**
     * @param list<PageSection> $sections
     * @return list<string>
     */
    private function ids(array $sections): array
    {
        return array_map(static fn (PageSection $section): string => $section->id, $sections);
    }

    private function clearAsyncTransport(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
    }

    /**
     * @return list<DeletePageSectionImages>
     */
    private function sentImageDeletions(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof DeletePageSectionImages,
        ));
    }
}
