<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The live control of an event's displays (competition toolkit v2 L1, displays-standings.md §5.3): the page, its JSON
 * state and the message endpoint are the organisers' (COMPETITION_TOOLKIT_MANAGE on the event - never a referee); a
 * display shows the message only with a Message element, every other design answers exactly as before.
 */
final class DisplayControlControllerTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use QueryCountAssertions;

    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string PAGE = '/en/display-control/' . self::EVENT;
    private const string STATE = self::PAGE . '/state';
    private const string MESSAGE = self::PAGE . '/message';

    public function testTheOrganiserSetsAMessageAndTheDisplaysShowIt(): void
    {
        $browser = $this->browser(self::ORGANISER);
        $withMessage = self::createDisplay(self::EVENT, [['type' => 'message', 'props' => ['whenEmpty' => 'text', 'emptyText' => 'Welcome']], ['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);
        $withoutMessage = self::createDisplay(self::EVENT, [['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);
        [$rawBefore] = self::displayState($browser, $withoutMessage);

        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="display-control"]');
        self::assertSame('no-store, private', $browser->getResponse()->headers->get('Cache-Control'));

        [, $state] = self::displayState($browser, $withMessage);
        self::assertSame(['message' => null, 'spotlight' => null], $state['control']);

        $answer = $this->post($browser, self::MESSAGE, ['text' => "Break -\nnext round 14:30"]);
        self::assertResponseIsSuccessful();
        self::assertSame(['message' => 'Break - next round 14:30', 'recentMessages' => ['Break - next round 14:30']], $answer);

        [, $state] = self::displayState($browser, $withMessage);
        self::assertSame('Break - next round 14:30', self::at($state, 'control.message'));

        // A design without a Message element: no `control` key, the answer as it was (the clock aside)
        [$rawAfter, $other] = self::displayState($browser, $withoutMessage);
        self::assertArrayNotHasKey('control', $other);
        self::assertStringNotContainsString('Break', $rawAfter);
        self::assertSame(self::withoutClock($rawBefore), self::withoutClock($rawAfter));

        $browser->request('GET', self::STATE);
        self::assertResponseIsSuccessful();
        $control = self::json($browser);
        self::assertSame('Break - next round 14:30', $control['message']);
        self::assertSame(['Break - next round 14:30'], $control['recentMessages']);
        self::assertSame(2, $control['displays']);
        self::assertSame(['message' => 1, 'spotlight' => 0], $control['elements']);
        self::assertIsString($control['roundId']);

        // Clear: the displays show their own text again, the recent one stays
        self::assertSame(['message' => null, 'recentMessages' => ['Break - next round 14:30']], $this->post($browser, self::MESSAGE, ['text' => null]));
        [, $state] = self::displayState($browser, $withMessage);
        self::assertNull(self::at($state, 'control.message'));
    }

    public function testTheMessageCostsNoStatementOnTheDisplay(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(self::EVENT, [['type' => 'message'], ['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);

        $this->startCountingQueries($browser);
        self::displayState($browser, $token);
        self::assertSame(3, $this->queryCount($browser));
    }

    public function testRefusals(): void
    {
        $browser = $this->browser(self::ORGANISER);

        // No display yet
        self::assertSame(['error' => 'no_displays'], $this->post($browser, self::MESSAGE, ['text' => 'Hello']));
        self::assertResponseStatusCodeSame(422);

        self::createDisplay(self::EVENT, [['type' => 'message']]);
        self::assertSame(['error' => 'too_long'], $this->post($browser, self::MESSAGE, ['text' => str_repeat('x', 201)]));
        self::assertResponseStatusCodeSame(422);

        $this->post($browser, self::MESSAGE, ['text' => ['not', 'a', 'text']]);
        self::assertResponseStatusCodeSame(400);

        // No CSRF header, not JSON
        $browser->request('POST', self::MESSAGE, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: '{"text":"x"}');
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', self::MESSAGE, ['text' => 'x'], server: self::headers());
        self::assertResponseStatusCodeSame(415);

        self::assertFalse(self::getContainer()->get(Connection::class)->fetchOne('SELECT message FROM competition_display_control WHERE competition_id = :id', ['id' => self::EVENT]));
    }

    public function testOnlyTheEventsOrganisers(): void
    {
        $browser = self::createClient();
        self::createDisplay(self::EVENT, [['type' => 'message']]);

        // A referee of the event enters results - the live control is the organisers'
        $referee = PlayerFixture::PLAYER_REGULAR;
        self::getContainer()->get(Connection::class)->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => self::EVENT,
            'player_id' => $referee,
            'added_by_id' => self::ORGANISER,
            'added_at' => '2026-10-01 10:00:00',
        ]);

        TestingLogin::asPlayer($browser, $referee);
        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
        $browser->request('GET', self::STATE);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->post($browser, self::MESSAGE, ['text' => 'Hijacked']));
        self::assertResponseStatusCodeSame(403);

        // An admin may
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
    }

    public function testSignedOutIsAskedToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::STATE);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->post($browser, self::MESSAGE, ['text' => 'x']));
        $browser->request('GET', self::PAGE);
        self::assertResponseRedirects();
    }

    public function testTheDisplaysListLinksTheLiveControlOnceThereIsADisplay(): void
    {
        $browser = $this->browser(self::ORGANISER);
        $browser->request('GET', '/en/manage-displays/' . self::EVENT);
        self::assertSelectorNotExists('[data-display-control-link]');

        self::createDisplay(self::EVENT, [['type' => 'timer']]);
        $browser->request('GET', '/en/manage-displays/' . self::EVENT);
        self::assertSelectorExists('[data-display-control-link][href="' . self::PAGE . '"]');
    }

    private static function withoutClock(string $raw): string
    {
        return (string) preg_replace('/"(serverNowMs|asOfMs|token|expiresIn)":("[^"]*"|\d+)/', '"$1":0', $raw);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    private function post(KernelBrowser $browser, string $url, array $payload): array
    {
        $browser->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', ...self::headers()], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return self::json($browser);
    }

    /**
     * @return array<mixed>
     */
    private static function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ];
    }

    private function browser(string $player): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
