<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GenerateTableLayoutControllerTest extends WebTestCase
{
    public function testFormRendersForMaintainer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/generate-table-layout/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseIsSuccessful();
    }

    public function testFormSubmissionGeneratesLayout(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/generate-table-layout/' . CompetitionRoundFixture::ROUND_CZECH_FINAL);
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'generate_table_layout_form[numberOfRows]' => '2',
            'generate_table_layout_form[tablesPerRow]' => '3',
            'generate_table_layout_form[spotsPerTable]' => '2',
        ]);
        $browser->submit($form);

        $this->assertResponseRedirects('/en/manage-round-tables/' . CompetitionRoundFixture::ROUND_CZECH_FINAL);
    }
}
