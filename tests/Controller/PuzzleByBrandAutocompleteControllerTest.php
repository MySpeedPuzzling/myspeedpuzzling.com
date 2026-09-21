<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleByBrandAutocompleteControllerTest extends WebTestCase
{
    public function testSearchableTextHoldsNoMarkupAndNoPiecesLabel(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);

        $this->assertResponseIsSuccessful();

        /** @var array{results: list<array{value: string, text: string, search: string, piecesCount: int}>} $data */
        $data = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNotEmpty($data['results']);

        foreach ($data['results'] as $option) {
            // The form types point Tom Select's `searchField` at this - `text` is markup and must never be searched
            self::assertNotSame('', $option['search']);
            self::assertStringNotContainsStringIgnoringCase('pieces', $option['search']);
            self::assertStringNotContainsString('<', $option['search']);
            self::assertStringContainsString((string) $option['piecesCount'], $option['search']);
        }
    }

    public function testUnknownBrandIsNotFound(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=nonsense');

        $this->assertResponseStatusCodeSame(404);
    }
}
