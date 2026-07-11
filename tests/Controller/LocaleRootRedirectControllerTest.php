<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocaleRootRedirectControllerTest extends WebTestCase
{
    public function testEnglishLocaleRootRedirectsPermanentlyToDomainRoot(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en');

        $this->assertResponseRedirects('/', 301);
    }
}
