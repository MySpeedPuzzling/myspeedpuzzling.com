<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

/**
 * GEST-13 (docs/features/navigation): a page that confirms asks in the in-page dialog - `data-turbo-confirm` on the form
 * or its submitter, the dialog's <template> rendered, no inline onsubmit/onclick confirm, nothing confirming under
 * data-turbo="false" (Turbo would never ask).
 */
trait AssertsInPageConfirmations
{
    private static function assertAsksInThePage(Crawler $crawler, int $atLeast = 1): void
    {
        self::assertCount(1, $crawler->filter('template#msp-confirm-template'), 'the in-page dialog template of base.html.twig');
        self::assertGreaterThanOrEqual($atLeast, $crawler->filter('[data-turbo-confirm]')->count());
        self::assertCount(0, $crawler->filter('[data-turbo="false"] [data-turbo-confirm], [data-turbo="false"][data-turbo-confirm]'), 'Turbo never asks under data-turbo="false"');

        foreach ($crawler->filter('[onsubmit], [onclick]') as $element) {
            self::assertInstanceOf(DOMElement::class, $element);

            foreach (['onsubmit', 'onclick'] as $attribute) {
                self::assertDoesNotMatchRegularExpression(
                    NativeConfirmAllowlistTest::PATTERN,
                    $element->getAttribute($attribute),
                    'an inline native confirm on <' . $element->nodeName . '>',
                );
            }
        }
    }
}
