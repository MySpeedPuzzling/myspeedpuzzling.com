<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoundStopwatchControllerTest extends WebTestCase
{
    public function testPublicPageAccessibleWithoutLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseIsSuccessful();
    }

    public function testPublicPageShowsRoundName(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Qualification Round');
    }
}
