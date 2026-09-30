<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\FirstTry;

use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/first-try-integrity.md - the live check, the edit form, the conflicts page, the profile banner
 * and the API. PLAYER_REGULAR holds several first tries of PUZZLE_500_01 (TIME_01, TIME_09, ...) in the fixtures.
 */
final class FirstTryPagesTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testTheLiveCheckExplainsAnOwnFirstTry(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/first-try-check?puzzle=' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('You already have a first try for this puzzle', $html);
        self::assertStringContainsString('first-try-check#move', $html);

        $browser->request('GET', '/en/first-try-check?resolution=move&puzzle=' . PuzzleFixture::PUZZLE_500_01);
        self::assertStringContainsString('This result becomes your first try', (string) $browser->getResponse()->getContent());
    }

    public function testTheLiveCheckIsSilentWhenThereIsNothingToSay(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/first-try-check?puzzle=' . FirstTryScenario::PUZZLE);
        $this->assertResponseIsSuccessful();
        self::assertSame('', $browser->getResponse()->getContent());

        // Somebody else's result is nobody's business here
        $browser->request('GET', '/en/first-try-check?time=' . PuzzleSolvingTimeFixture::TIME_04);
        self::assertSame('', $browser->getResponse()->getContent());
    }

    public function testTheLiveCheckNeverNamesAPrivateTeammate(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        (new FirstTryScenario($browser->getContainer()))->add(PlayerFixture::PLAYER_PRIVATE_USER_ID, daysAgo: 10, firstTry: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/first-try-check?puzzle=' . FirstTryScenario::PUZZLE . '&group_players[]=%23player2');

        $html = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('Someone in your group already has a first try', $html);
        self::assertStringNotContainsString('Jane', $html);
    }

    public function testTheLiveCheckNeedsASignedInPlayer(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/first-try-check?puzzle=' . PuzzleFixture::PUZZLE_500_01);

        self::assertTrue($browser->getResponse()->isRedirection());
    }

    public function testEditingAnOldDuplicateOnlyPointsToTheConflictsPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
        $notice = $crawler->filter('[data-first-try-check-target="notice"]');
        self::assertStringContainsString('more than one result marked as a first try', $notice->text());
        self::assertCount(1, $notice->filter('a[href="/en/first-try-conflicts"]'));
    }

    public function testAnEditTickingTheTagOnAnOwnDuplicateIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // TIME_07: PLAYER_REGULAR's second solve of PUZZLE_500_02, whose first try is TIME_06
        $crawler = $browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_07);

        $crawler = $browser->request('POST', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_07, [
            'edit_puzzle_solving_time_form' => [
                '_token' => $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                'puzzle' => PuzzleFixture::PUZZLE_500_02,
                'timeHours' => '0',
                'timeMinutes' => '31',
                'timeSeconds' => '40',
                'finishedAt' => '12.07.2026',
                'firstAttempt' => '1',
                'comment' => 'Still here',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You already have a first try', $crawler->filter('[data-first-try-check-target="notice"]')->text());
        self::assertSame('Still here', $crawler->filter('#edit_puzzle_solving_time_form_comment')->text());
    }

    public function testTheConflictsPageResolvesAPuzzle(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/first-try-conflicts');

        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/en/first-try-conflicts/' . PuzzleFixture::PUZZLE_500_01 . '/resolve"]');
        self::assertCount(1, $form);
        self::assertCount(1, $form->filter('input[name="keep"][value="' . PuzzleSolvingTimeFixture::TIME_09 . '"]'));

        $browser->request('POST', '/en/first-try-conflicts/' . PuzzleFixture::PUZZLE_500_01 . '/resolve', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'keep' => PuzzleSolvingTimeFixture::TIME_09,
        ]);

        $this->assertResponseRedirects('/en/first-try-conflicts');
        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_09],
            $browser->getContainer()->get(GetFirstTryTimes::class)->markedTimeIdsOf(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01),
        );

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('that result stays your first try', $crawler->text());
        self::assertCount(0, $crawler->filter('form[action="/en/first-try-conflicts/' . PuzzleFixture::PUZZLE_500_01 . '/resolve"]'));
    }

    public function testAResolveWithoutAValidTokenIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/first-try-conflicts/' . PuzzleFixture::PUZZLE_500_01 . '/resolve', [
            '_token' => 'nope',
            'keep' => 'none',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAResolvedConflictAnsweredAgainSaysItChanged(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        // PLAYER_WITH_FAVORITES has a conflict on PUZZLE_1000_01 (so the page has a token), none on PUZZLE_3000
        $crawler = $browser->request('GET', '/en/first-try-conflicts');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $browser->request('POST', '/en/first-try-conflicts/' . FirstTryScenario::PUZZLE . '/resolve', [
            '_token' => $token,
            'keep' => 'none',
        ]);

        $this->assertResponseRedirects('/en/first-try-conflicts');
        self::assertStringContainsString('This has changed in the meantime', $browser->followRedirect()->text());
    }

    public function testALateFirstTryCanBeKeptOrRemoved(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $scenario = new FirstTryScenario($browser->getContainer());
        $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10);
        $late = $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5, firstTry: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/first-try-conflicts');
        $removeForm = $crawler->filter('form[action="/en/first-try-conflicts/result/' . $late . '/remove"]');
        self::assertCount(1, $removeForm);

        $browser->request('POST', '/en/first-try-conflicts/result/' . $late . '/remove', [
            '_token' => $removeForm->filter('input[name="_token"]')->attr('value'),
        ]);

        $this->assertResponseRedirects('/en/first-try-conflicts');
        self::assertFalse($scenario->isFirstTry($late));
    }

    public function testTheBannerIsForTheOwnerOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-testid="first-try-conflicts-banner"]');
        self::assertCount(1, $banner);
        self::assertCount(1, $banner->filter('a[href="/en/first-try-conflicts"]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->startCountingQueries($browser);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="first-try-conflicts-banner"]'));

        foreach ($this->executedSql($browser) as $sql) {
            self::assertStringNotContainsString('mine AS (', $sql, 'Somebody else\'s profile costs no first-try query');
        }
    }

    public function testTheApiRefusesASecondFirstTryWithAClearMessage(): void
    {
        $browser = self::createClient();
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '40:00',
                'first_attempt' => true,
            ]),
        );

        $this->assertResponseStatusCodeSame(422);
        $body = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('already recorded for you', $body);
        self::assertStringContainsString(PuzzleSolvingTimeFixture::TIME_01, $body);
    }

    public function testTheApiNeverTellsWhichResultOfACoPuzzlerHoldsIt(): void
    {
        $browser = self::createClient();
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_WITH_FAVORITES));

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_1500_02,
                'time' => '59:00',
                'first_attempt' => true,
                'group_players' => ['#player4'],
            ]),
        );

        $this->assertResponseStatusCodeSame(422);
        $body = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('already recorded for a co-puzzler', $body);
        $problem = json_decode($body, true);
        self::assertIsArray($problem);
        self::assertIsString($problem['detail'] ?? null);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/', $problem['detail'], 'No result id of somebody else');
    }

    public function testTheApiAcceptsTheSameResultWithoutTheTag(): void
    {
        $browser = self::createClient();
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '40:00',
                'first_attempt' => false,
            ]),
        );

        $this->assertResponseIsSuccessful();
    }

    public function testTheApiRefusesTickingTheTagOnAnEdit(): void
    {
        $browser = self::createClient();
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_07,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['time' => '31:40', 'first_attempt' => true]),
        );

        $this->assertResponseStatusCodeSame(422);
    }
}
