<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The organiser forms say why a change is refused while a round uses online timing (OnlineTimingInUse,
 * docs/features/online-competitions/): a 422 with the reason on the field, nothing saved.
 */
final class OnlineTimingGuardFormsTest extends WebTestCase
{
    public function testTheRoundFormKeepsARoundUsingOnlineTimingSolo(): void
    {
        $browser = $this->admin();

        $browser->request('GET', '/en/edit-event-round/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        $crawler = $browser->submitForm('Save Changes', ['competition_round_form[category]' => 'duo']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Online timing is on for Online Round - it works for solo rounds only', $crawler->filter('.invalid-feedback')->text());
        self::assertSame('solo', $this->connection($browser)->fetchOne(
            'SELECT category FROM competition_round WHERE id = :id',
            ['id' => OnlineRoundsFixture::ROUND_ONLINE_SOLO],
        ));
    }

    public function testTheEventFormKeepsAnEventUsingOnlineTimingOnline(): void
    {
        $browser = $this->admin();

        $browser->request('GET', '/en/edit-event/' . OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        $crawler = $browser->submitForm('Save Changes', [
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Brno',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('it works only at an online event, so the event stays online', $crawler->filter('.invalid-feedback')->text());
        self::assertTrue($this->connection($browser)->fetchOne(
            'SELECT is_online FROM competition WHERE id = :id',
            ['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP],
        ));
    }

    private function admin(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return $browser;
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
