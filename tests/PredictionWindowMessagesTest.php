<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The prediction window texts (docs/features/insights/README.md §3 "Prediction windows") exist in every language of
 * translations/ on their own (no English fallback), keep their placeholder and are translated.
 */
final class PredictionWindowMessagesTest extends KernelTestCase
{
    private const array KEYS = [
        'puzzle_intelligence.prediction.window.likely' => '%range%',
        'puzzle_intelligence.prediction.window.likely_half' => '%range%',
        'puzzle_intelligence.prediction.window.hit' => null,
        'puzzle_intelligence.prediction.window.faster' => '%margin%',
        'puzzle_intelligence.prediction.window.slower' => '%margin%',
        'puzzle_intelligence.prediction.window.first_try_faster' => '%margin%',
    ];

    private const array LOCALES = ['cs', 'de', 'en', 'es', 'fr', 'ja'];

    public function testEveryLanguageDefinesEveryWindowText(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $english = $translator->getCatalogue('en');

        foreach (self::LOCALES as $locale) {
            self::assertFileExists(sprintf('%s/../translations/messages.%s.yml', __DIR__, $locale));
            $catalogue = $translator->getCatalogue($locale);

            foreach (self::KEYS as $key => $placeholder) {
                self::assertTrue($catalogue->defines($key, 'messages'), sprintf('%s: %s', $locale, $key));
                $message = $catalogue->get($key, 'messages');

                if ($placeholder !== null) {
                    self::assertStringContainsString($placeholder, $message, sprintf('%s: %s', $locale, $key));
                }

                if ($locale !== 'en') {
                    self::assertNotSame($english->get($key, 'messages'), $message, sprintf('%s: %s is not translated', $locale, $key));
                }
            }
        }
    }
}
