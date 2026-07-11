<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageLegacyRedirectControllerTest extends WebTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideLegacyHomepagePaths(): array
    {
        return [
            'cs' => ['/uvod', '/cs'],
            'en' => ['/en/home', '/'],
            'es' => ['/es/inicio', '/es'],
            'ja' => ['/ja/ホーム', '/ja'],
            'fr' => ['/fr/accueil', '/fr'],
            'de' => ['/de/start', '/de'],
        ];
    }

    #[DataProvider('provideLegacyHomepagePaths')]
    public function testLegacyHomepagePathRedirectsPermanentlyToLocaleRoot(string $path, string $expectedTarget): void
    {
        $browser = self::createClient();

        $browser->request('GET', $path);

        $this->assertResponseRedirects($expectedTarget, 301);
    }
}
