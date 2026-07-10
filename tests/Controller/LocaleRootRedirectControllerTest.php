<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocaleRootRedirectControllerTest extends WebTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideLocaleRoots(): array
    {
        return [
            'en' => ['/en', '/en/home'],
            'es' => ['/es', '/es/inicio'],
            'fr' => ['/fr', '/fr/accueil'],
            'de' => ['/de', '/de/start'],
        ];
    }

    #[DataProvider('provideLocaleRoots')]
    public function testBareLocaleRootRedirectsPermanentlyToHomepage(string $path, string $expectedTarget): void
    {
        $browser = self::createClient();

        $browser->request('GET', $path);

        $this->assertResponseRedirects($expectedTarget, 301);
    }
}
