<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventResultsImport\Format;

use SpeedPuzzling\Web\Exceptions\UnknownResultsFormat;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\SarahDoesPuzzlesFormat;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The formats the container knows, by their keys (docs/features/event-results-import/results-import-page.md "Three
 * layers"), and every format's guide - rendered in every locale with no text left untranslated.
 */
final class ResultsFormatsTest extends KernelTestCase
{
    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    public function testEveryFormatIsRegisteredByItsKey(): void
    {
        self::bootKernel();
        $formats = self::getContainer()->get(ResultsFormats::class);

        self::assertContains(SarahDoesPuzzlesFormat::KEY, $formats->keys());
        self::assertInstanceOf(SarahDoesPuzzlesFormat::class, $formats->get(SarahDoesPuzzlesFormat::KEY));
        self::assertTrue($formats->has(SarahDoesPuzzlesFormat::KEY));
        self::assertFalse($formats->has('somebody_else'));
        self::assertCount(count($formats->keys()), array_unique($formats->keys()), 'A key names one format');
    }

    public function testAnUnknownKeyIsAClearError(): void
    {
        self::bootKernel();

        $this->expectException(UnknownResultsFormat::class);
        $this->expectExceptionMessage('No results format "somebody_else"');

        self::getContainer()->get(ResultsFormats::class)->get('somebody_else');
    }

    public function testEveryFormatsGuideRendersInEveryLocale(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (self::getContainer()->get(ResultsFormats::class)->all() as $format) {
            foreach (self::LOCALES as $locale) {
                $translator->setLocale($locale);
                $html = $twig->render($format->guideTemplate());

                self::assertStringNotContainsString('results_import.', $html, sprintf('%s guide in %s: a key without its text', $format->key(), $locale));
                self::assertStringContainsString('Link to MySpeedPuzzling', $html);

                // Every text of the guide in this locale's own catalogue - never the English fallback
                $prefix = 'results_import.format_guide.' . $format->key() . '.';
                $keys = array_filter(array_keys($translator->getCatalogue('en')->all('messages')), static fn (string $key): bool => str_starts_with($key, $prefix));
                self::assertNotSame([], $keys);

                foreach ($keys as $key) {
                    self::assertTrue($translator->getCatalogue($locale)->defines($key), sprintf('%s missing in %s', $key, $locale));
                }
            }
        }
    }
}
