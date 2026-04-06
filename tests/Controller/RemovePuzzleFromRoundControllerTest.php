<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RemovePuzzleFromRoundControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/en/remove-puzzle-from-round/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects();
    }
}
