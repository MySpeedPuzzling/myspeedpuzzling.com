<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The display editor's Save endpoint (D-displays-overlays.md §8.1 `save_competition_display`): 200 with the normalised
 * design, 409 on a stale version, 422 with readable errors, 401/403 for everybody but the event's toolkit managers -
 * and uses_results / uses_live_results derived on save, live results only after the organiser confirmed them.
 */
final class SaveCompetitionDisplayDesignControllerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testAnAdminSavesADesignAndGetsItNormalised(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();

        $response = $this->save($browser, $displayId, [
            'schema' => 1,
            'theme' => ['font' => 'bebas', 'accent' => '#AABBCC', 'css' => 'body{}'],
            'elements' => [
                ['id' => 'timer001', 'type' => 'timer', 'frame' => ['x' => 10, 'y' => 20, 'w' => 80, 'h' => 40], 'style' => ['fontSize' => 2000, 'color' => 'red;x:y']],
                ['id' => 'board001', 'type' => 'leaderboard', 'frame' => ['x' => 0, 'y' => 0, 'w' => 30, 'h' => 90], 'props' => ['rows' => 5]],
            ],
        ], baseVersion: 1);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame(2, $response['designVersion']);
        self::assertTrue($response['usesResults']);
        self::assertFalse($response['usesLiveResults']);
        self::assertIsArray($response['design']);
        self::assertSame(['font' => 'bebas', 'color' => '#ffffff', 'accent' => '#aabbcc'], self::at($response, 'design', 'theme'));
        self::assertSame(['fontSize' => 900], self::at($response, 'design', 'elements', 0, 'style'));
        self::assertSame('published', self::at($response, 'design', 'elements', 1, 'props', 'source'));

        $row = $this->row($displayId);
        self::assertSame(2, $row['design_version']);
        self::assertTrue($row['uses_results']);
        self::assertFalse($row['uses_live_results']);
        self::assertEquals($response['design'], json_decode($row['design'], true));
    }

    public function testAStaleVersionIsAConflict(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();
        $this->save($browser, $displayId, self::design('first001'), baseVersion: 1);
        self::assertResponseIsSuccessful();

        // A second tab still on version 1
        $response = $this->save($browser, $displayId, self::design('second01'), baseVersion: 1);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'conflict', 'designVersion' => 2], $response);
        self::assertStringContainsString('first001', $this->row($displayId)['design']);

        // "Overwrite" = the same design on top of the current version
        $response = $this->save($browser, $displayId, self::design('second01'), baseVersion: 2);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $response['designVersion']);
    }

    public function testSavingTheSameDesignAgainKeepsTheVersion(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();
        $this->save($browser, $displayId, self::design('same0001'), baseVersion: 1);

        $response = $this->save($browser, $displayId, self::design('same0001'), baseVersion: 2);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $response['designVersion']);
        self::assertSame(2, $this->row($displayId)['design_version']);
    }

    public function testAnInvalidDesignIsRefusedWithReadableErrorsAndNothingChanges(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();
        $before = $this->row($displayId);

        $response = $this->save($browser, $displayId, [
            'schema' => 1,
            'elements' => [
                ['id' => 'html0001', 'type' => 'html', 'frame' => []],
                ['id' => 'image001', 'type' => 'image', 'frame' => [], 'props' => ['path' => 'competition-displays/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '/0193a7b2-1c3d-7e4f-8a9b-0c1d2e3f4a5b.png']],
            ],
        ], baseVersion: 1);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_design', $response['error']);
        self::assertSame([
            ['path' => '/elements/0/type', 'code' => 'unknown_type', 'message' => 'This kind of element is not available.'],
            ['path' => '/elements/1/props/path', 'code' => 'foreign_image', 'message' => 'This picture was not uploaded for this event. Upload it again.'],
        ], $response['errors']);
        self::assertSame($before, $this->row($displayId));
    }

    public function testLiveResultsNeedTheOrganisersConfirmationOnce(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();
        $live = [
            'schema' => 1,
            'elements' => [
                ['id' => 'count001', 'type' => 'finished_count', 'frame' => []],
                ['id' => 'board001', 'type' => 'leaderboard', 'frame' => [], 'props' => ['source' => 'live']],
            ],
        ];

        $response = $this->save($browser, $displayId, $live, baseVersion: 1);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('live_not_confirmed', self::at($response, 'errors', 0, 'code'));
        self::assertSame('/elements/1/props/source', self::at($response, 'errors', 0, 'path'));
        self::assertFalse($this->row($displayId)['uses_live_results']);

        $response = $this->save($browser, $displayId, $live, baseVersion: 1, liveConfirmed: true);

        self::assertResponseIsSuccessful();
        self::assertTrue($response['usesLiveResults']);
        self::assertTrue($this->row($displayId)['uses_live_results']);
        self::assertTrue($this->row($displayId)['uses_results']);

        // Already live: later saves need no confirmation; hiding the element stops asking for live data
        $live['elements'][1]['hidden'] = true;
        $response = $this->save($browser, $displayId, $live, baseVersion: 2);

        self::assertResponseIsSuccessful();
        self::assertFalse($response['usesLiveResults']);
        self::assertTrue($response['usesResults']);
        self::assertFalse($this->row($displayId)['uses_live_results']);

        // Another element switched to live asks again
        $live['elements'][0]['props'] = ['source' => 'live'];
        $response = $this->save($browser, $displayId, $live, baseVersion: 3);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['path' => '/elements/0/props/source', 'code' => 'live_not_confirmed', 'message' => 'This element shows results before they are published. Confirm it to save.']], $response['errors']);
    }

    public function testALivePresetNeedsNoConfirmationToBeSavedAgain(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay(DisplayPreset::TimerWithLiveBoard);
        self::assertTrue($this->row($displayId)['uses_live_results']);

        $design = json_decode($this->row($displayId)['design'], true);
        self::assertIsArray($design);
        $response = $this->save($browser, $displayId, $design, baseVersion: 1);

        self::assertResponseIsSuccessful();
        self::assertTrue($response['usesLiveResults']);
    }

    public function testTheFlagLetsTheOrganiserSave(): void
    {
        $browser = $this->browser(flag: true, player: self::ORGANISER);
        $displayId = $this->addDisplay();

        $this->save($browser, $displayId, self::design('organise'), baseVersion: 1);

        self::assertResponseIsSuccessful();
    }

    public function testTheOrganiserCannotSaveWhileTheFlagIsOff(): void
    {
        $this->assertForbidden(flag: false, player: self::ORGANISER);
    }

    public function testAnotherPlayerCannotSaveEvenWithTheFlag(): void
    {
        $this->assertForbidden(flag: true, player: PlayerFixture::PLAYER_REGULAR);
    }

    public function testSignedOutIsAskedToSignInNotRedirected(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        $displayId = $this->addDisplay();

        $response = $this->save($browser, $displayId, self::design('anonymou'), baseVersion: 1);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $response);
    }

    public function testRequestsThatAreNotAProperSave(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();
        $url = '/en/display-editor/' . $displayId . '/save';

        // No CSRF header
        $browser->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: '{"design":{"schema":1},"baseVersion":1}');
        self::assertResponseStatusCodeSame(403);

        // Not JSON
        $browser->request('POST', $url, ['design' => 'x'], server: self::headers());
        self::assertResponseStatusCodeSame(415);

        $browser->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', ...self::headers()], content: '{"design":');
        self::assertResponseStatusCodeSame(400);

        $browser->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', ...self::headers()], content: '{"design":{"schema":1},"baseVersion":"1"}');
        self::assertResponseStatusCodeSame(400);

        $browser->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', ...self::headers()], content: '{"design":"' . str_repeat('x', 300000) . '","baseVersion":1}');
        self::assertResponseStatusCodeSame(413);

        $this->save($browser, Uuid::uuid7()->toString(), self::design('nowhere1'), baseVersion: 1);
        self::assertResponseStatusCodeSame(404);

        // PUT is the same Save
        $browser->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/json', ...self::headers()], content: '{"design":{"schema":1},"baseVersion":1}');
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->row($displayId)['design_version'], 'The empty design of a blank screen is what it had');
    }

    private function assertForbidden(bool $flag, string $player): void
    {
        $browser = $this->browser(flag: $flag, player: $player);
        $displayId = $this->addDisplay();

        $response = $this->save($browser, $displayId, self::design('intruder'), baseVersion: 1);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $response);
        self::assertSame(1, $this->row($displayId)['design_version']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function design(string $elementId): array
    {
        return ['schema' => 1, 'elements' => [['id' => $elementId, 'type' => 'text', 'frame' => [], 'props' => ['text' => 'Hello']]]];
    }

    /**
     * @param array<mixed> $design
     * @return array<string, mixed>
     */
    private function save(KernelBrowser $browser, string $displayId, mixed $design, int $baseVersion, bool $liveConfirmed = false): array
    {
        $browser->request(
            'POST',
            '/en/display-editor/' . $displayId . '/save',
            server: ['CONTENT_TYPE' => 'application/json', ...self::headers()],
            content: json_encode(['design' => $design, 'baseVersion' => $baseVersion, 'liveConfirmed' => $liveConfirmed], JSON_THROW_ON_ERROR),
        );

        /** @var array<string, mixed> $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $response;
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

    private function addDisplay(DisplayPreset $preset = DisplayPreset::Blank): string
    {
        $displayId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new AddCompetitionDisplay($displayId, self::EVENT, DisplayKind::Screen, 'Hall A', 'en', null, $preset),
        );

        return $displayId->toString();
    }

    /**
     * @return array{design: string, design_version: int, uses_results: bool, uses_live_results: bool}
     */
    private function row(string $displayId): array
    {
        /** @var array{design: string, design_version: int, uses_results: bool, uses_live_results: bool} $row */
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT design, design_version, uses_results, uses_live_results FROM competition_display WHERE id = :id',
            ['id' => $displayId],
        );

        return $row;
    }

    private function browser(bool $flag, string $player): KernelBrowser
    {
        // Before the kernel boots - the flag is a container parameter
        $this->overrideFeatureFlagEnv(self::FLAG, $flag);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }

    /**
     * The value at a path of a decoded JSON answer (null when it is not there).
     */
    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
