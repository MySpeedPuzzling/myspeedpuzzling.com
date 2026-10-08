<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\ParticipantsSheet\ApplyParticipantSheetChangesController;
use SpeedPuzzling\Web\Exceptions\ParticipantImportPreviewStale;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /{_locale}/participants-sheet-api/{competitionId}/changes - who may send change sets, what is refused as a whole,
 * and the answer (delivery contract §3.1, §3.2).
 */
final class ApplyParticipantSheetChangesControllerTest extends WebTestCase
{
    private const string CHANGES = '/en/participants-sheet-api/' . Cup::COMPETITION_RESULTS_CUP . '/changes';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNobodySignedInGets401InsteadOfTheLoginPage(): void
    {
        $this->post(self::CHANGES, self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]));

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->json());
        self::assertSame('Ivan Last', $this->ivanName());
    }

    public function testOnlyTheEventsOrganisersMayWrite(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->post(self::CHANGES, self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json());
        self::assertSame('Ivan Last', $this->ivanName());
    }

    public function testAnOrganiserOfAnotherEventCanNotWriteHere(): void
    {
        // PLAYER_WITH_STRIPE organises the Results Cup, not WJPC
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/participants-sheet-api/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/changes', self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testWritesNeedJson(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('POST', self::CHANGES, ['groups' => '[]'], server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'json_required'], $this->json());
    }

    public function testWritesFromAnotherSiteAreRefused(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]), origin: 'https://evil.example');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'invalid_csrf_token'], $this->json());
        self::assertSame('Ivan Last', $this->ivanName());
    }

    public function testAnEventThatDoesNotExistIsAJson404(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/participants-sheet-api/' . Uuid::uuid7()->toString() . '/changes', self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]));

        self::assertResponseStatusCodeSame(404);
        $answer = $this->json();
        self::assertSame('competition_not_found', $answer['error']);
        self::assertSame('This event does not exist any more.', $answer['message']);
    }

    public function testAnUnreadableChangeSetIs400WithTheReasonAndTheOrganisersText(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, ['groups' => [['id' => 'g', 'changes' => [self::rename('Ivan Last', 'Ivan Lastly')]]]]);
        self::assertResponseStatusCodeSame(400);
        $answer = $this->json();
        self::assertSame('invalid_changes', $answer['error']);
        self::assertSame('changeset_id_missing', $answer['reason']);
        self::assertSame('These changes could not be read - reload the page and make them again.', $answer['message']);

        $this->post(self::CHANGES, self::changeSet([['op' => 'mergeEverything']]));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('unknown_op', $this->json()['reason']);

        $this->post(self::CHANGES, self::changeSet(array_fill(0, 501, self::rename('Ivan Last', 'Ivan Lastly'))));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('too_many_changes', $this->json()['reason']);
        self::assertSame('Too many changes at once - make them in smaller steps.', $this->json()['message']);

        self::assertSame('Ivan Last', $this->ivanName());
    }

    public function testAChangeSetIsAnsweredPerGroupAndChangeWithTranslatedTexts(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, self::changeSet(
            [self::rename('Ivan Last', 'Ivan Lastly')],
            [['op' => 'place', 'participant' => Cup::PARTICIPANT_BEN, 'round' => Cup::ROUND_GROUP_A, 'from' => 'in', 'to' => 'out']],
            [self::rename('Somebody Else', 'Ivan Third')],
            [['op' => 'place', 'participant' => Cup::PARTICIPANT_DAN, 'round' => Cup::ROUND_PAIRS, 'from' => 'team:' . Cup::TEAM_CORNERS, 'to' => 'in']],
            [['op' => 'deleteTeam', 'team' => Cup::TEAM_SHARKS], ['op' => 'remove', 'participant' => Cup::PARTICIPANT_FILIP]],
        ));

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);

        $answer = $this->json();
        self::assertFalse($answer['dryRun']);
        self::assertFalse($answer['replayed']);
        self::assertIsString($answer['versionBefore']);
        self::assertIsString($answer['versionAfter']);
        self::assertSame(64, strlen($answer['versionAfter']));
        self::assertNotSame($answer['versionBefore'], $answer['versionAfter']);

        [$renamed, $refused, $conflict, $moved, $skipped] = $this->answeredGroups();
        self::assertSame([
            'id' => 'g0',
            'status' => 'applied',
            'changes' => [['index' => 0, 'status' => 'applied', 'reason' => null, 'message' => null, 'current' => 'Ivan Lastly']],
            'warnings' => [],
            'deletedTeams' => [],
        ], $renamed);

        self::assertSame('refused', $refused['status']);
        self::assertSame([
            'index' => 0,
            'status' => 'refused',
            'reason' => 'has_result_in_round',
            'message' => "Ben Steady's result in Group A is recorded - clear it in this round's Result column or on the Results desk first to take Ben Steady out.",
            'current' => 'in',
        ], $refused['changes'][0]);

        self::assertSame('conflict', $conflict['status']);
        self::assertSame('changed_meanwhile', $conflict['changes'][0]['reason']);
        self::assertSame('Ivan Lastly', $conflict['changes'][0]['current']);
        self::assertIsString($conflict['changes'][0]['message']);

        self::assertSame('applied', $moved['status']);
        self::assertSame([
            [
                'code' => 'team_result_line_up_changed',
                'message' => 'Corner Pieces already has a result in Pairs - it now belongs to the new line-up.',
                'participantId' => Cup::PARTICIPANT_DAN,
                'teamId' => Cup::TEAM_CORNERS,
                'roundId' => Cup::ROUND_PAIRS,
            ],
            [
                'code' => 'team_size_off',
                'message' => 'Corner Pieces has 1 person - Pairs expects 2 per pair/team.',
                'participantId' => null,
                'teamId' => Cup::TEAM_CORNERS,
                'roundId' => Cup::ROUND_PAIRS,
            ],
        ], $moved['warnings']);

        self::assertSame('refused', $skipped['status']);
        self::assertSame('team_has_result', $skipped['changes'][0]['reason']);
        self::assertSame("The result of Puzzle Sharks in Pairs is recorded - clear it in this round's Result column or on the Results desk first to delete the pair/team.", $skipped['changes'][0]['message']);
        self::assertSame('skipped', $skipped['changes'][1]['status']);
        self::assertSame('Not saved - another part of the same change could not be saved.', $skipped['changes'][1]['message']);

        self::assertSame('Ivan Lastly', $this->ivanName());

        // The other open sheets of the event learn about it - once, privately, with the new version
        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame(['/competition-participants/' . Cup::COMPETITION_RESULTS_CUP], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
        $payload = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('participants_sheet.changed', $payload['type']);
        self::assertSame($answer['versionAfter'], $payload['version']);
    }

    /**
     * One code, several causes: the organiser is told what holds the person - something the results desk clears, or a
     * time the player added themselves, which stays (review A-1). The codes stay for the page.
     */
    public function testTheGuardsSayWhatKeepsSomebody(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        // Anna's player added a time in the Final to their own profile
        $this->database()->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = :round WHERE id = (SELECT id FROM puzzle_solving_time WHERE player_id = :player ORDER BY id LIMIT 1)',
            ['round' => Cup::ROUND_FINAL, 'player' => PlayerFixture::PLAYER_ADMIN],
        );
        $this->database()->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => Cup::COMPETITION_RESULTS_CUP]);
        $this->database()->executeStatement("UPDATE competition_participant SET registration_status = 'waitlisted' WHERE id = :id", ['id' => Cup::PARTICIPANT_DAN]);

        $this->post(self::CHANGES, self::changeSet(
            [['op' => 'place', 'participant' => Cup::PARTICIPANT_ANNA, 'round' => Cup::ROUND_FINAL, 'from' => 'in', 'to' => 'out']],
            [['op' => 'remove', 'participant' => Cup::PARTICIPANT_ANNA]],
            [['op' => 'remove', 'participant' => Cup::PARTICIPANT_BEN]],
            [['op' => 'place', 'participant' => Cup::PARTICIPANT_CARA, 'round' => Cup::ROUND_PAIRS, 'from' => 'team:' . Cup::TEAM_CORNERS, 'to' => 'out']],
            [
                ['op' => 'place', 'participant' => Cup::PARTICIPANT_GINA, 'round' => Cup::ROUND_PAIRS, 'from' => 'team:' . Cup::TEAM_EDGES, 'to' => 'in'],
                ['op' => 'place', 'participant' => Cup::PARTICIPANT_HUGO, 'round' => Cup::ROUND_PAIRS, 'from' => 'team:' . Cup::TEAM_EDGES, 'to' => 'in'],
            ],
            [['op' => 'field', 'participant' => Cup::PARTICIPANT_IVAN, 'field' => 'externalId', 'from' => null, 'to' => 'R-1'], ['op' => 'field', 'participant' => Cup::PARTICIPANT_FILIP, 'field' => 'externalId', 'from' => null, 'to' => 'R-1']],
        ));

        self::assertResponseIsSuccessful();
        $messages = array_map(
            static fn (array $group): array => array_values(array_filter(array_map(static fn (array $change): null|string => $change['reason'] !== null ? $change['reason'] . ': ' . $change['message'] : null, $group['changes']))),
            $this->answeredGroups(),
        );

        self::assertSame([
            ['has_result_in_round: Anna Fast added their own time to Final on MySpeedPuzzling - they stay in the round.'],
            ['has_result_in_event: Anna Fast added their own time to Final on MySpeedPuzzling - they stay in the event.'],
            ["has_result_in_event: Ben Steady has a recorded result in Group A - clear it in Group A's Result column or on the Results desk first to remove Ben Steady from the event."],
            // Dan is on the waitlist of the managed event - nobody taking part would be left in Corner Pieces
            ['team_has_result: The result of Corner Pieces in Pairs is recorded - keep at least one person in it who is not on the waitlist.'],
            ["team_has_result: The result of Edge Hunters in Pairs is recorded - keep at least one person in the pair/team, or clear the result in this round's Result column or on the Results desk first."],
            ['external_id_taken: The External ID R-1 belongs to Ivan Last already.'],
        ], $messages);
    }

    /**
     * Review A-r10: something the change set names vanished outside the event's lock (a player deleting their account
     * between the plan and the write) - nothing was written, the page sends the change set again.
     *
     * @return iterable<string, array{bool}>
     */
    public static function vanishedMeanwhile(): iterable
    {
        yield 'an entity the plan names' => [false];
        yield 'a player deleted before the write' => [true];
    }

    #[DataProvider('vanishedMeanwhile')]
    public function testSomethingVanishingMeanwhileIsAConflictToSendAgain(bool $atTheWrite): void
    {
        $vanished = $atTheWrite
            ? new HandlerFailedException(
                new Envelope(new \stdClass()),
                [new ForeignKeyConstraintViolationException(self::createStub(DriverException::class), null)],
            )
            : new ParticipantImportPreviewStale();

        $this->browser->disableReboot();
        $container = $this->browser->getContainer();
        $controller = new ApplyParticipantSheetChangesController(
            $container->get(CompetitionRepository::class),
            new class ($vanished) implements MessageBusInterface {
                public function __construct(
                    private readonly \Throwable $vanished,
                ) {
                }

                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    throw $this->vanished;
                }
            },
            $container->get(OfficialResultsApi::class),
            $container->get(ParticipantsSheetLiveUpdates::class),
            $container->get(TranslatorInterface::class),
        );
        $controller->setContainer($container);
        $container->set(ApplyParticipantSheetChangesController::class, $controller);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]));

        self::assertResponseStatusCodeSame(409);
        self::assertSame([
            'error' => 'changed_meanwhile',
            'message' => 'Something changed on the event meanwhile - nothing was saved. Try again.',
        ], $this->json());
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    /**
     * Review A-r11: a JSON list (or anything but a change set object) is an unreadable change set with the organiser's text.
     */
    public function testAListInsteadOfAChangeSetIsUnreadable(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ([json_encode([self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')])], JSON_THROW_ON_ERROR), '42', '"changes"'] as $content) {
            $this->postRaw(self::CHANGES, $content);

            self::assertResponseStatusCodeSame(400);
            self::assertSame([
                'error' => 'invalid_changes',
                'reason' => 'not_a_list',
                'message' => 'These changes could not be read - reload the page and make them again.',
            ], $this->json(), $content);
        }

        $this->postRaw(self::CHANGES, '{"groups": [');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->json()['error']);
        self::assertIsString($this->json()['message']);

        self::assertSame('Ivan Last', $this->ivanName());
    }

    public function testNoAnswerIsEverIndexed(): void
    {
        $this->post(self::CHANGES, self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]));
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/participants-sheet-api/' . Uuid::uuid7()->toString() . '/changes', self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]));
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

        foreach (['state', 'version'] as $endpoint) {
            $this->browser->request('GET', '/en/participants-sheet-api/' . Cup::COMPETITION_RESULTS_CUP . '/' . $endpoint);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        }

        $this->post(self::CHANGES, self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function testADryRunWritesAndTellsNothing(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, ['dryRun' => true, 'groups' => [['id' => 'g0', 'changes' => [self::rename('Ivan Last', 'Ivan Dry')]]]]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertTrue($answer['dryRun']);
        self::assertSame($answer['versionBefore'], $answer['versionAfter']);
        self::assertSame('Ivan Last', $this->ivanName());
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    public function testAChangeSetSentAgainIsReplayedAndAnIdOfAnotherEventIs409(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $changeSet = self::changeSet([self::rename('Ivan Last', 'Ivan Lastly')]);

        $this->post(self::CHANGES, $changeSet);
        $first = $this->json();

        $this->post(self::CHANGES, $changeSet);
        self::assertResponseIsSuccessful();
        $replayed = $this->json();
        self::assertTrue($replayed['replayed']);
        self::assertSame($first['groups'], $replayed['groups']);
        self::assertSame($first['versionAfter'], $replayed['versionAfter']);
        self::assertSame([], self::hub()->getPublishedUpdates(), 'A replay changes nothing and tells nobody');

        // The same id sent to another event the organiser may edit too
        $this->database()->executeStatement(
            'UPDATE competition SET added_by_player_id = :player WHERE id = :id',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'id' => CompetitionSeriesFixture::EDITION_OFFLINE_1],
        );
        $this->post('/en/participants-sheet-api/' . CompetitionSeriesFixture::EDITION_OFFLINE_1 . '/changes', $changeSet);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('changeset_id_taken', $this->json()['error']);
        self::assertSame('These changes could not be saved - make them again.', $this->json()['message']);
    }

    public function testAParticipantOfAnotherEventIsRefusedAndNeverTouched(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, self::changeSet([[
            'op' => 'field',
            'participant' => MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A,
            'field' => 'name',
            'from' => 'Sarah Williams',
            'to' => 'Hijacked',
        ]]));

        self::assertResponseIsSuccessful();
        $group = $this->answeredGroups()[0];
        self::assertSame('refused', $group['status']);
        self::assertSame('participant_not_found', $group['changes'][0]['reason']);
        self::assertSame('This person is not a participant of the event (any more).', $group['changes'][0]['message']);
        self::assertSame('Sarah Williams', $this->database()->fetchOne(
            'SELECT name FROM competition_participant WHERE id = :id',
            ['id' => MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A],
        ));
    }

    /**
     * @param list<array<string, mixed>> ...$groups
     * @return array<string, mixed>
     */
    private static function changeSet(array ...$groups): array
    {
        $wire = [];
        foreach ($groups as $index => $changes) {
            $wire[] = ['id' => 'g' . $index, 'changes' => $changes];
        }

        return ['changesetId' => Uuid::uuid7()->toString(), 'dryRun' => false, 'groups' => $wire];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rename(string $from, string $to): array
    {
        return ['op' => 'field', 'participant' => Cup::PARTICIPANT_IVAN, 'field' => 'name', 'from' => $from, 'to' => $to];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $url, array $body, string $origin = 'http://localhost'): void
    {
        $this->browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function postRaw(string $url, string $content): void
    {
        $this->browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: $content);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<array{id: string, status: string, changes: list<array{index: int, status: string, reason: null|string, message: null|string, current: mixed}>, warnings: list<array<string, mixed>>, deletedTeams: list<string>}>
     */
    private function answeredGroups(): array
    {
        $groups = $this->json()['groups'];
        self::assertIsArray($groups);

        /** @var list<array{id: string, status: string, changes: list<array{index: int, status: string, reason: null|string, message: null|string, current: mixed}>, warnings: list<array<string, mixed>>, deletedTeams: list<string>}> $groups */
        return $groups;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function ivanName(): mixed
    {
        return $this->database()->fetchOne('SELECT name FROM competition_participant WHERE id = :id', ['id' => Cup::PARTICIPANT_IVAN]);
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
