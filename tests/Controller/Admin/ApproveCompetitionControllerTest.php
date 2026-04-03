<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApproveCompetitionControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/approve');

        $this->assertResponseRedirects('/login');
    }
}
