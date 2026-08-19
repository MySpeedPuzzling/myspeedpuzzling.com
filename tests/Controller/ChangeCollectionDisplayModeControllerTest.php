<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Message\EditFeaturesOptions;
use SpeedPuzzling\Web\Query\GetCollectionDisplayMode;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CollectionDisplayMode;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChangeCollectionDisplayModeControllerTest extends WebTestCase
{
    private const string ENDPOINT = '/en/collection-display-mode';

    private const string COLLECTION_PAGE = '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC;

    public function testRequiresAuthentication(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::ENDPOINT, ['mode' => 'times']);

        $this->assertResponseRedirects();
        self::assertSame(CollectionDisplayMode::Off, $this->modeOf($browser, PlayerFixture::PLAYER_REGULAR));
    }

    public function testMemberCanChooseEveryModeAndIsSentBackToTheCollection(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->csrfTokenFrom($browser);

        foreach (['times' => CollectionDisplayMode::Times, 'times_predictions' => CollectionDisplayMode::TimesPredictions, 'off' => CollectionDisplayMode::Off] as $posted => $expected) {
            $browser->request('POST', self::ENDPOINT . '?return=' . rawurlencode(self::COLLECTION_PAGE), ['mode' => $posted, '_token' => $token]);

            $this->assertResponseRedirects(self::COLLECTION_PAGE);
            self::assertSame($expected, $this->modeOf($browser, PlayerFixture::PLAYER_WITH_STRIPE), "Mode {$posted} is persisted");
        }
    }

    public function testNonMemberAskingForPredictionsIsDowngradedToTimes(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->csrfTokenFrom($browser);

        $browser->request('POST', self::ENDPOINT . '?return=' . rawurlencode(self::COLLECTION_PAGE), ['mode' => 'times_predictions', '_token' => $token]);

        $this->assertResponseRedirects(self::COLLECTION_PAGE);
        self::assertSame(CollectionDisplayMode::Times, $this->modeOf($browser, PlayerFixture::PLAYER_REGULAR));

        // "My times" itself is free
        $browser->request('POST', self::ENDPOINT . '?return=' . rawurlencode(self::COLLECTION_PAGE), ['mode' => 'times', '_token' => $token]);
        self::assertSame(CollectionDisplayMode::Times, $this->modeOf($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->request('POST', self::ENDPOINT . '?return=' . rawurlencode(self::COLLECTION_PAGE), ['mode' => 'off', '_token' => $token]);
        self::assertSame(CollectionDisplayMode::Off, $this->modeOf($browser, PlayerFixture::PLAYER_REGULAR));
    }

    public function testMemberWhoOptedOutOfPredictionsIsDowngradedToTimes(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->csrfTokenFrom($browser);

        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new EditFeaturesOptions(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            streakOptedOut: false,
            rankingOptedOut: false,
            timePredictionsOptedOut: true,
        ));

        $browser->request('POST', self::ENDPOINT . '?return=' . rawurlencode(self::COLLECTION_PAGE), ['mode' => 'times_predictions', '_token' => $token]);

        $this->assertResponseRedirects(self::COLLECTION_PAGE);
        self::assertSame(CollectionDisplayMode::Times, $this->modeOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testInvalidCsrfTokenChangesNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', self::ENDPOINT . '?return=' . rawurlencode(self::COLLECTION_PAGE), ['mode' => 'times', '_token' => 'not-the-token']);
        $this->assertResponseRedirects(self::COLLECTION_PAGE);
        self::assertSame(CollectionDisplayMode::Off, $this->modeOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));

        $browser->request('POST', self::ENDPOINT, ['mode' => 'times']);
        $this->assertResponseRedirects('/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(CollectionDisplayMode::Off, $this->modeOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testUnknownModeIsABadRequest(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->csrfTokenFrom($browser);

        $browser->request('POST', self::ENDPOINT, ['mode' => 'everything', '_token' => $token]);
        $this->assertResponseStatusCodeSame(400);

        $browser->request('POST', self::ENDPOINT, ['_token' => $token]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testOnlySameSiteReturnUrlsAreFollowed(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->csrfTokenFrom($browser);

        foreach (['//evil.example', 'https://evil.example/', '/\\evil.example', '%2F%2Fevil.example', ''] as $hostile) {
            $browser->request('POST', self::ENDPOINT . '?return=' . rawurlencode($hostile), ['mode' => 'times', '_token' => $token]);

            $this->assertResponseRedirects('/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE, message: "return={$hostile} falls back to the library");
        }

        // No return at all: the library as well
        $browser->request('POST', self::ENDPOINT, ['mode' => 'times', '_token' => $token]);
        $this->assertResponseRedirects('/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(CollectionDisplayMode::Times, $this->modeOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    /**
     * The token the collection page's Display form carries - reading it from the
     * page also pins that the control renders for a signed-in viewer.
     */
    private function csrfTokenFrom(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', self::COLLECTION_PAGE);
        $this->assertResponseIsSuccessful();

        $token = $crawler->filter('.collection-display form input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        self::assertNotSame('', $token);

        return $token;
    }

    private function modeOf(KernelBrowser $browser, string $playerId): CollectionDisplayMode
    {
        return $browser->getContainer()->get(GetCollectionDisplayMode::class)->forPlayer($playerId);
    }
}
