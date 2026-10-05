<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use SpeedPuzzling\Web\Twig\PuzzleNameLanguageChoicesTwigExtension;
use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class PuzzleNameLanguageChoicesTwigExtensionTest extends KernelTestCase
{
    public function testTheChoicesAreBuiltOncePerLocaleUntilTheRequestEnds(): void
    {
        self::bootKernel();
        $extension = self::getContainer()->get(PuzzleNameLanguageChoicesTwigExtension::class);
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);

        $localeSwitcher->setLocale('cs');
        self::assertSame(PuzzleNameLanguageChoices::choices('cs'), $extension->languageChoices());
        self::assertSame('cs', $extension->languageChoices()['Čeština']);

        // Another locale, other names - the first ones stay for their locale
        $localeSwitcher->setLocale('de');
        self::assertSame(PuzzleNameLanguageChoices::choices('de'), $extension->languageChoices());
        self::assertSame('cs', $extension->languageChoices()['Tschechisch']);

        $extension->reset();
        self::assertSame(PuzzleNameLanguageChoices::choices('de'), $extension->languageChoices());
    }
}
