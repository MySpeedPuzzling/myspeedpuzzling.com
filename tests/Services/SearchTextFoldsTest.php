<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Services\EventsPage\EventsIndexFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Services\SearchTextFolds;
use SpeedPuzzling\Web\Tests\Services\EventDetail\PathUrlGenerator;
use SpeedPuzzling\Web\Tests\Services\EventsPage\EventsIndexExamples;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SearchText::fold() remembered while the events page is built (EventsPageFoldCharacterizationTest pins that the
 * page is the same). Worker mode: emptied between requests and capped.
 */
final class SearchTextFoldsTest extends KernelTestCase
{
    public function testAFoldIsTheFoldAndIsRemembered(): void
    {
        $folds = new SearchTextFolds();

        foreach (['Kouzelná zahrada', "Where's Wally? Ｎｏ.５", '  Łódź, Polska ', '2026', '', '魔法の庭', "Straße\u{200B}"] as $text) {
            self::assertSame(SearchText::fold($text), $folds->fold($text));
            self::assertSame(SearchText::fold($text), $folds->fold($text));
        }

        self::assertSame(7, $folds->remembered());

        $folds->reset();
        self::assertSame(0, $folds->remembered());
    }

    public function testItNeverHoldsMoreThanItsCap(): void
    {
        $folds = new SearchTextFolds();

        for ($i = 0; $i <= SearchTextFolds::MAX_ENTRIES + 10; $i++) {
            self::assertSame('name ' . $i, $folds->fold('Name ' . $i));
        }

        self::assertLessThanOrEqual(SearchTextFolds::MAX_ENTRIES, $folds->remembered());
    }

    public function testTheEventsPageFoldsThroughIt(): void
    {
        $folds = new SearchTextFolds();
        $translator = new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], null|string $domain = null, null|string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
        $open = new EventOccurrence(
            competitionId: '018d0099-0000-0000-0000-000000000035',
            name: 'Riverside Puzzle Open',
            slug: 'riverside-puzzle-open',
            location: 'Riverside, United States',
            countryCode: CountryCode::us,
            startDate: new DateTimeImmutable('2026-10-20'),
        );

        new EventsPageBuilder(new EventUrls(new PathUrlGenerator()), new EventsIndexFactory($translator, $folds), folds: $folds)
            ->build([$open], [], [], null, EventsScope::everywhere(), new DateTimeImmutable(EventsIndexExamples::NOW), 'en', null);

        // The name, the place's city and country names, the year, the English country name
        self::assertGreaterThan(3, $folds->remembered());
    }

    public function testTheEventsPageServicesShareOneThatIsResetAfterEachRequest(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $folds = $container->get(SearchTextFolds::class);

        foreach ([EventsIndexFactory::class, EventRowFactory::class, EventsPageBuilder::class] as $service) {
            $property = new \ReflectionProperty($service, 'folds');
            self::assertSame($folds, $property->getValue($container->get($service)), $service);
        }

        $folds->fold('Kouzelná zahrada');
        $container->get('services_resetter')->reset();

        self::assertSame(0, $folds->remembered());
    }
}
