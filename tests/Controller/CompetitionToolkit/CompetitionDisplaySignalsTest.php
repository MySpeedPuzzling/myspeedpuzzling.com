<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The display signal hooks (competition toolkit D5, D-displays-overlays.md §7.3): every write a display shows tells the
 * event's display topic after the commit - results, publication, a round deleted, a design saved, the display's
 * settings, a new link, a deletion - an event without displays hears nothing new, and a refused (rolled back) write
 * signals nothing. The stopwatch start with "Reveal the puzzles" is in StopwatchRevealPagesTest.
 */
final class CompetitionDisplaySignalsTest extends WebTestCase
{
    use CreatesCompetitionDisplays;

    private const string CUP = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string TOPIC = '/competition-display/' . self::CUP;
    private const string CHANGES = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/changes';

    public function testAResultsWriteOfAnEventWithoutDisplaysPublishesOnlyWhatItAlwaysDid(): void
    {
        $browser = $this->organiser();

        $this->postJson($browser, self::CHANGES, ['changes' => [self::change(OfficialResultsFixture::ENTRY_A_FILIP, 6)]]);

        self::assertResponseIsSuccessful();
        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertNotContains(self::TOPIC, $updates[0]->getTopics());
    }

    public function testAResultsWriteTellsTheDisplaysShowingResults(): void
    {
        $browser = $this->organiser();
        self::createDisplay(self::CUP, [['type' => 'leaderboard']], OfficialResultsFixture::ROUND_GROUP_A);

        $this->postJson($browser, self::CHANGES, ['changes' => [self::change(OfficialResultsFixture::ENTRY_A_FILIP, 6)]]);

        self::assertResponseIsSuccessful();
        self::assertSame([['type' => 'display.signal', 'what' => 'results', 'roundId' => OfficialResultsFixture::ROUND_GROUP_A]], self::signals());
    }

    public function testAResultsWriteLeavesADisplayWithoutResultsAloneButPublishingTellsIt(): void
    {
        $browser = $this->organiser();
        self::createDisplay(self::CUP, [['type' => 'timer']]);

        $this->postJson($browser, self::CHANGES, ['changes' => [self::change(OfficialResultsFixture::ENTRY_A_FILIP, 6)]]);
        self::assertResponseIsSuccessful();
        self::assertSame([], self::signals());

        $this->postJson($browser, '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_B . '/publish', []);
        self::assertResponseIsSuccessful();
        self::assertSame([['type' => 'display.signal', 'what' => 'round', 'roundId' => OfficialResultsFixture::ROUND_GROUP_B]], self::signals());
    }

    public function testADeletedRoundTellsTheDisplaysToPickTheirRoundAgain(): void
    {
        $browser = $this->organiser();
        self::createDisplay(self::CUP, [['type' => 'timer']]);

        $browser->request('POST', '/en/delete-event-round/' . OfficialResultsFixture::ROUND_PAIRS_FINAL);

        self::assertResponseRedirects('/en/manage-event-rounds/' . self::CUP);
        self::assertSame([['type' => 'display.signal', 'what' => 'round']], self::signals());
    }

    public function testASavedDesignTellsTheOpenDisplayAndARefusedSaveTellsNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();
        $design = ['schema' => 1, 'elements' => [['id' => 'el000001', 'type' => 'text', 'frame' => [], 'props' => ['text' => 'Hello']]]];

        $this->postJson($browser, '/en/display-editor/' . $displayId . '/save', ['design' => $design, 'baseVersion' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame([['type' => 'display.signal', 'what' => 'design', 'displayId' => $displayId]], self::signals());

        // Saved again from a stale editor: the handler refuses, its transaction rolls back - no signal
        $this->postJson($browser, '/en/display-editor/' . $displayId . '/save', ['design' => $design, 'baseVersion' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([], self::hub()->getPublishedUpdates());

        // The same design once more: nothing changed, nothing to tell
        $this->postJson($browser, '/en/display-editor/' . $displayId . '/save', ['design' => $design, 'baseVersion' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    public function testSettingsANewLinkAndADeletionTellTheOpenDisplay(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();
        $expected = [['type' => 'display.signal', 'what' => 'design', 'displayId' => $displayId]];
        $list = '/en/manage-displays/' . self::CUP;

        $page = $browser->request('GET', '/en/display-editor/' . $displayId . '/settings');
        $browser->submit($page->filter('[data-display-settings-save]')->form([
            'competition_display_settings_form[followMode]' => 'pinned',
            'competition_display_settings_form[pinnedRoundId]' => OfficialResultsFixture::ROUND_FINAL,
        ]));
        self::assertResponseRedirects($list, 303);
        self::assertSame($expected, self::signals());

        $page = $browser->request('GET', $list);
        $browser->submit($page->filter('[data-display="' . $displayId . '"] [data-display-regenerate]')->form());
        self::assertResponseRedirects($list, 303);
        self::assertSame($expected, self::signals());

        $page = $browser->request('GET', $list);
        $browser->submit($page->filter('[data-display="' . $displayId . '"] [data-display-delete]')->form());
        self::assertResponseRedirects($list, 303);
        self::assertSame($expected, self::signals());
    }

    private function organiser(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        return $browser;
    }

    private function addDisplay(): string
    {
        $displayId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new AddCompetitionDisplay($displayId, self::CUP, DisplayKind::Screen, 'Hall A', 'en', null),
        );

        return $displayId->toString();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(KernelBrowser $browser, string $url, array $body): void
    {
        $browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function change(string $entryId, int $tableNumber): array
    {
        return ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => 'participant_round:' . $entryId, 'field' => 'table_number', 'from' => null, 'to' => $tableNumber];
    }

    /**
     * The display signals the last request published (decoded) - each private, on the event's display topic only
     *
     * @return list<mixed>
     */
    private static function signals(): array
    {
        $signals = array_values(array_filter(
            self::hub()->getPublishedUpdates(),
            static fn (Update $update): bool => in_array(OfficialResultsSubscription::displayTopic(self::CUP), $update->getTopics(), true),
        ));

        foreach ($signals as $signal) {
            self::assertSame([self::TOPIC], $signal->getTopics());
            self::assertTrue($signal->isPrivate());
        }

        return array_map(static fn (Update $update): mixed => json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR), $signals);
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
