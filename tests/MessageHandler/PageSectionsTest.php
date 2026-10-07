<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Message\DeletePageSection;
use SpeedPuzzling\Web\Message\EditPageSection;
use SpeedPuzzling\Web\Message\ReorderPageSections;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Results\PageEntry;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class PageSectionsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionPageSectionRepository $sectionRepository;
    private GetCompetitionPageSections $getPageSections;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->sectionRepository = self::getContainer()->get(CompetitionPageSectionRepository::class);
        $this->getPageSections = self::getContainer()->get(GetCompetitionPageSections::class);
    }

    public function testDefaultLayoutRendersSystemSectionsOnly(): void
    {
        $entries = $this->getPageSections->forCompetition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);

        $keys = array_map(static fn (PageEntry $entry): string => $entry->key, $entries);

        self::assertSame(GetCompetitionPageSections::SYSTEM_SECTIONS, $keys);
    }

    public function testRichTextSectionIsSanitized(): void
    {
        $sectionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPageSection(
            sectionId: $sectionId,
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            seriesId: null,
            type: PageSectionType::RichText,
            title: 'Rules',
            content: ['html' => '<h3>Rules</h3><p onclick="alert(1)">Be <strong>fair</strong></p><script>alert("xss")</script><iframe src="https://evil.example"></iframe>'],
        ));

        $section = $this->sectionRepository->get($sectionId->toString());

        /** @var string $html */
        $html = $section->content['html'];

        self::assertStringContainsString('<h3>Rules</h3>', $html);
        self::assertStringContainsString('<strong>fair</strong>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('onclick', $html);
    }

    public function testLinksSectionRejectsNonHttpUrls(): void
    {
        $sectionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPageSection(
            sectionId: $sectionId,
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            seriesId: null,
            type: PageSectionType::Links,
            title: null,
            content: ['links' => [
                ['label' => 'Facebook', 'url' => 'https://facebook.com/groups/puzzlers'],
                ['label' => 'Evil', 'url' => 'javascript:alert(1)'],
            ]],
        ));

        $section = $this->sectionRepository->get($sectionId->toString());

        /** @var array<array{label: string, url: string}> $links */
        $links = $section->content['links'];

        self::assertCount(1, $links);
        self::assertSame('https://facebook.com/groups/puzzlers', $links[0]['url']);
    }

    public function testEditReorderVisibilityAndDelete(): void
    {
        $sectionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPageSection(
            sectionId: $sectionId,
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            seriesId: null,
            type: PageSectionType::Faq,
            title: 'FAQ',
            content: ['items' => [['question' => 'When?', 'answer' => 'Saturday']]],
        ));

        $this->messageBus->dispatch(new EditPageSection(
            sectionId: $sectionId->toString(),
            title: 'Questions',
            content: ['items' => [
                ['question' => 'When?', 'answer' => 'Saturday morning'],
                ['question' => 'Where?', 'answer' => 'Brno'],
                ['question' => '', 'answer' => 'row without question is dropped'],
            ]],
        ));

        $section = $this->sectionRepository->get($sectionId->toString());
        self::assertSame('Questions', $section->title);

        /** @var array<array{question: string, answer: string}> $items */
        $items = $section->content['items'];
        self::assertCount(2, $items);

        // Custom section moved to the top, participants hidden
        $this->messageBus->dispatch(new ReorderPageSections(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            seriesId: null,
            layout: [
                ['section' => 'custom:' . $sectionId->toString(), 'visible' => true],
                ['section' => 'schedule', 'visible' => true],
                ['section' => 'puzzles', 'visible' => true],
                ['section' => 'results', 'visible' => true],
                ['section' => 'registration', 'visible' => true],
                ['section' => 'participants', 'visible' => false],
            ],
        ));

        $entries = $this->getPageSections->forCompetition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);
        $keys = array_map(static fn (PageEntry $entry): string => $entry->key, $entries);

        self::assertSame('custom:' . $sectionId->toString(), $keys[0]);
        self::assertNotContains('participants', $keys, 'hidden system section is excluded from public entries');

        $allEntries = $this->getPageSections->forCompetition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, includeHidden: true);
        self::assertContains('participants', array_map(static fn (PageEntry $entry): string => $entry->key, $allEntries));

        $this->messageBus->dispatch(new DeletePageSection(sectionId: $sectionId->toString()));

        $entries = $this->getPageSections->forCompetition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);
        $keys = array_map(static fn (PageEntry $entry): string => $entry->key, $entries);

        // Orphaned layout entry is skipped, system sections remain
        self::assertNotContains('custom:' . $sectionId->toString(), $keys);
        self::assertContains('schedule', $keys);
    }

    public function testSeriesSectionsAreInheritedByEditions(): void
    {
        $sectionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPageSection(
            sectionId: $sectionId,
            competitionId: null,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            type: PageSectionType::RichText,
            title: 'Series rules',
            content: ['html' => '<p>Same rules every month.</p>'],
        ));

        // The series page shows the section
        $seriesEntries = $this->getPageSections->forSeries(CompetitionSeriesFixture::SERIES_EJJ);
        $seriesKeys = array_map(static fn (PageEntry $entry): string => $entry->key, $seriesEntries);
        self::assertContains('custom:' . $sectionId->toString(), $seriesKeys);

        // Every edition inherits it, marked as inherited
        $editionEntries = $this->getPageSections->forCompetition(CompetitionSeriesFixture::EDITION_EJJ_68);
        $inherited = array_values(array_filter(
            $editionEntries,
            static fn (PageEntry $entry): bool => $entry->key === 'custom:' . $sectionId->toString(),
        ));

        self::assertCount(1, $inherited);
        self::assertTrue($inherited[0]->inherited);
        self::assertSame('Series rules', $inherited[0]->title);
    }
}
