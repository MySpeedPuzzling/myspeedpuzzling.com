<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\OnlineRounds\OnlineAccuracyController;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineMediaCheck;
use SpeedPuzzling\Web\Message\ConfirmOnlineResult;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineCvTesting;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The completeness check on the organiser's pages (docs/features/online-competitions/README.md §5.8, §5.9 - Phase 3,
 * advisory): the review's list carries the check of the photo it opens on (the page orders "needs a look" first by
 * it), each finish photo of the detail its own check, a `shadow` round never a candidate's; the accuracy report counts
 * the check's verdict on the confirmed photo against the reviewer - a false complete highlighted; while the check is
 * off, none of it: the review's JSON and page carry nothing of it.
 */
final class OnlineCompletenessCheckPagesTest extends WebTestCase
{
    use OnlineCvTesting;
    use OnlineReviewSeeding;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string REVIEW = '/en/online-rounds/' . self::ROUND . '/review';
    private const string ACCURACY = '/en/online-rounds/' . self::ROUND . '/accuracy';
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;
    private const array GAP = ['x' => 812, 'y' => 403, 'w' => 41, 'h' => 38];

    private int $startMs = 0;

    protected function tearDown(): void
    {
        $this->restoreCompletenessCheck();

        parent::tearDown();
    }

    public function testTheListCarriesTheCheckOfThePhotoTheReviewOpensOn(): void
    {
        $browser = $this->signedIn(checkOn: true);
        $this->detectionMode(OnlineDetectionMode::On);
        $this->seedReviewRound();

        $browser->request('GET', self::REVIEW, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $list = $this->json($browser);

        self::assertIsArray($list['round']);
        self::assertTrue($list['round']['completenessCheck'] ?? null);
        $checks = $this->checksByName($list);

        // Olivia's suggestion is her raised hands - their photo shows a gap (her tap's photo looks complete)
        self::assertSame('gaps', $checks['Olivia Online']['state'] ?? null);
        self::assertSame([self::GAP], $checks['Olivia Online']['gaps'] ?? null);
        self::assertSame([1920, 1080], [$checks['Olivia Online']['imageWidth'] ?? null, $checks['Olivia Online']['imageHeight'] ?? null]);
        self::assertSame('unsure', $checks['Paula Pace']['state'] ?? null);
        self::assertSame('pending', $checks['Una Unchecked']['state'] ?? null, 'not checked yet');
        self::assertSame('complete', $checks['Vic Victory']['state'] ?? null);
        self::assertSame('pending', $checks['Wes Worker']['state'] ?? null, 'a check that could not run reads as not checked');
        self::assertSame('failed', $this->entryCheck($list, 'Wes Worker')['state'] ?? null);
        self::assertArrayHasKey('Arthur Anonymous', $checks);
        self::assertNull($checks['Arthur Anonymous'], 'no photo to check');
        // The list stays by name - the page orders it ("needs a look" first: assets/online/review_check.js)
        self::assertSame(['Arthur Anonymous', 'Olivia Online', 'Paula Pace', 'Una Unchecked', 'Vic Victory', 'Wes Worker'], array_keys($checks));
    }

    public function testEachFinishPhotoOfTheDetailCarriesItsCheck(): void
    {
        $browser = $this->signedIn(checkOn: true);
        $this->detectionMode(OnlineDetectionMode::On);
        $this->seedReviewRound();

        $browser->request('GET', self::REVIEW . '/' . self::OLIVIA, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $detail = $this->json($browser);
        self::assertIsArray($detail['media']);
        $byKind = [];

        foreach ($detail['media'] as $media) {
            self::assertIsArray($media);
            self::assertIsString($media['kind']);
            $byKind[$media['kind']] = $media;
        }

        self::assertIsArray($byKind['candidate']['check'] ?? null);
        self::assertSame('gaps', $byKind['candidate']['check']['state']);
        self::assertIsArray($byKind['button']['check'] ?? null);
        self::assertSame('complete', $byKind['button']['check']['state']);
        self::assertSame('Looks clean', $byKind['button']['check']['reason']);
        self::assertIsArray($byKind['end']['check'] ?? null);
        self::assertSame('pending', $byKind['end']['check']['state']);
        // Not a finish photo: nothing to check
        self::assertArrayNotHasKey('check', $byKind['empty_mat']);
        self::assertArrayNotHasKey('check', $byKind['burst']);
        self::assertIsArray($detail['round']);
        self::assertTrue($detail['round']['completenessCheck'] ?? null);
    }

    public function testAShadowRoundNeverShowsACandidatesVerdict(): void
    {
        $browser = $this->signedIn(checkOn: true);
        $this->detectionMode(OnlineDetectionMode::Shadow);
        $this->seedReviewRound();

        $browser->request('GET', self::REVIEW, server: ['HTTP_ACCEPT' => 'application/json']);
        $checks = $this->checksByName($this->json($browser));
        // The suggestion is her tap there - its photo, never the raised hands' (gaps)
        self::assertSame('complete', $checks['Olivia Online']['state'] ?? null);

        $browser->request('GET', self::REVIEW . '/' . self::OLIVIA, server: ['HTTP_ACCEPT' => 'application/json']);
        $detail = $this->json($browser);
        self::assertIsArray($detail['media']);
        $kinds = array_map(static fn (mixed $media): mixed => is_array($media) ? $media['kind'] : null, $detail['media']);
        self::assertNotContains('candidate', $kinds);
        self::assertStringNotContainsString('"state":"gaps"', (string) json_encode($detail));
    }

    public function testThePageShowsTheCheckOnlyWhileItIsOn(): void
    {
        $browser = $this->signedIn(checkOn: true);
        $this->seedReviewRound();

        $crawler = $browser->request('GET', self::REVIEW);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-online-review-target="tapPhotoBoxes"]'));
        self::assertCount(1, $crawler->filter('[data-online-review-target="showGaps"]'));
        self::assertCount(1, $crawler->filter('[data-online-review-target="endPhotoBoxes"]'));
        $texts = json_decode((string) $crawler->filter('[data-controller="online-review"]')->attr('data-online-review-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertSame('Gap?', $texts['check_gaps'] ?? null);
        self::assertSame('Looks complete', $texts['check_complete'] ?? null);
    }

    public function testWhileTheCheckIsOffTheReviewIsWhatItWasBefore(): void
    {
        $browser = $this->signedIn(checkOn: false);
        $this->detectionMode(OnlineDetectionMode::On);
        $this->seedReviewRound();

        $crawler = $browser->request('GET', self::REVIEW);
        self::assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('online-check', $html);
        self::assertStringNotContainsString('completenessCheck', $html);
        self::assertStringNotContainsString('check_', $html);
        self::assertStringNotContainsString('show-gaps', $html);
        self::assertCount(0, $crawler->filter('[data-online-review-target="tapPhotoCheckLine"]'));

        $browser->request('GET', self::REVIEW, server: ['HTTP_ACCEPT' => 'application/json']);
        $list = $this->json($browser);
        self::assertIsArray($list['round']);
        self::assertArrayNotHasKey('completenessCheck', $list['round']);
        self::assertIsArray($list['entries']);

        foreach ($list['entries'] as $entry) {
            self::assertIsArray($entry);
            self::assertArrayNotHasKey('check', $entry);
        }

        $browser->request('GET', self::REVIEW . '/' . self::OLIVIA, server: ['HTTP_ACCEPT' => 'application/json']);
        $detail = $this->json($browser);
        self::assertIsArray($detail['media']);

        foreach ($detail['media'] as $media) {
            self::assertIsArray($media);
            self::assertArrayNotHasKey('check', $media);
        }
    }

    public function testTheAccuracyReportComparesTheCheckWithTheReviewer(): void
    {
        $browser = $this->signedIn(checkOn: true);
        $this->seedConfirmedRound();

        $crawler = $browser->request('GET', self::ACCURACY);
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('[data-accuracy-completeness]');
        self::assertSame('5', $section->attr('data-accuracy-completeness'));
        $counts = [];

        foreach ($section->filter('[data-completeness-outcome]') as $node) {
            self::assertInstanceOf(\DOMElement::class, $node);
            $counts[$node->getAttribute('data-completeness-outcome')] = trim((string) $node->getElementsByTagName('dd')->item(0)?->textContent);
        }

        self::assertSame(['agree' => '1', 'false_complete' => '1', 'false_gap' => '1', 'unsure' => '1', 'not_checked' => '1'], $counts);
        // The one error that matters is highlighted - on the summary and on its row
        self::assertStringContainsString('text-danger', (string) $section->filter('[data-completeness-outcome="false_complete"]')->attr('class'));
        $una = $crawler->filter('[data-accuracy-completeness-outcome="false_complete"]');
        self::assertCount(1, $una);
        self::assertSame('False complete', trim($una->text()));
        self::assertStringContainsString('table-danger', (string) $una->closest('tr')?->attr('class'));

        $browser->request('GET', self::ACCURACY . '?format=csv');
        $rows = $this->csvRows($browser);
        $columns = array_flip(OnlineAccuracyController::CSV_COLUMNS);
        self::assertSame(['complete', 'agree'], [$rows['Olivia Online'][$columns['completeness_verdict']], $rows['Olivia Online'][$columns['completeness_outcome']]]);
        self::assertSame(['gaps', 'false_gap'], [$rows['Paula Pace'][$columns['completeness_verdict']], $rows['Paula Pace'][$columns['completeness_outcome']]]);
        self::assertSame(['complete', 'false_complete'], [$rows['Uma Unfinished'][$columns['completeness_verdict']], $rows['Uma Unfinished'][$columns['completeness_outcome']]]);
        self::assertSame(['unsure', 'unsure'], [$rows['Vic Victory'][$columns['completeness_verdict']], $rows['Vic Victory'][$columns['completeness_outcome']]]);
        self::assertSame(['pending', 'not_checked'], [$rows['Wes Worker'][$columns['completeness_verdict']], $rows['Wes Worker'][$columns['completeness_outcome']]]);
        self::assertSame(['', ''], [$rows['Arthur Anonymous'][$columns['completeness_verdict']], $rows['Arthur Anonymous'][$columns['completeness_outcome']]], 'not confirmed');
    }

    public function testWhileTheCheckIsOffTheAccuracyReportHasNoneOfIt(): void
    {
        $browser = $this->signedIn(checkOn: false);
        $this->seedConfirmedRound();

        $crawler = $browser->request('GET', self::ACCURACY);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-accuracy-completeness]'));
        self::assertCount(0, $crawler->filter('[data-accuracy-completeness-outcome]'));

        // The CSV's columns stay - empty
        $browser->request('GET', self::ACCURACY . '?format=csv');
        $rows = $this->csvRows($browser);
        $columns = array_flip(OnlineAccuracyController::CSV_COLUMNS);
        self::assertSame(['', ''], [$rows['Uma Unfinished'][$columns['completeness_verdict']], $rows['Uma Unfinished'][$columns['completeness_outcome']]]);
    }

    /**
     * A round that ran, detection watching: Olivia raised her hands (their photo: a gap) and tapped 10 s later (the
     * tap's photo looks complete; her end photo not checked yet), Paula tapped (unsure), Una tapped (not checked yet),
     * Vic tapped (complete), Wes tapped (the check could not run); Arthur never connected
     */
    private function seedReviewRound(): void
    {
        $this->startMs = $this->startOnlineRound();
        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $raiseMs = $this->startMs + 600_000;
        $raiseId = $this->finishCandidate($olivia, $raiseMs);
        $this->checked($this->evidence($olivia, OnlineMediaKind::Candidate, $raiseMs + 520, $raiseId), OnlineFinishCheckVerdict::Gaps, [self::GAP]);
        $this->evidence($olivia, OnlineMediaKind::Burst, $raiseMs, $raiseId, self::burstManifest($raiseMs));
        $tapMs = $raiseMs + 10_000;
        $tapId = $this->finishTap($olivia, $tapMs);
        $this->checked($this->evidence($olivia, OnlineMediaKind::Button, $tapMs, $tapId), OnlineFinishCheckVerdict::Complete);
        $this->evidence($olivia, OnlineMediaKind::End, $this->startMs + 1_700_000);

        foreach ([['Paula Pace', OnlineFinishCheckVerdict::Unsure], ['Una Unchecked', null], ['Vic Victory', OnlineFinishCheckVerdict::Complete], ['Wes Worker', OnlineFinishCheckVerdict::Failed]] as $index => [$name, $verdict]) {
            $this->tapWithPhoto($name, $this->startMs + 700_000 + $index * 10_000, $verdict);
        }
    }

    /**
     * Confirmed by a reviewer: Olivia and Paula finished (their tap photos: complete, a gap), Uma unfinished though her
     * tap's photo looked complete, Vic finished (unsure), Wes finished (not checked); Arthur not
     */
    private function seedConfirmedRound(): void
    {
        $this->startMs = $this->startOnlineRound();
        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapMs = $this->startMs + 900_000;
        $tapId = $this->finishTap($olivia, $tapMs);
        $this->checked($this->evidence($olivia, OnlineMediaKind::Button, $tapMs, $tapId), OnlineFinishCheckVerdict::Complete);
        $this->confirm(self::OLIVIA, $tapMs, null);

        foreach (
            [
            ['Paula Pace', OnlineFinishCheckVerdict::Gaps, false],
            ['Uma Unfinished', OnlineFinishCheckVerdict::Complete, true],
            ['Vic Victory', OnlineFinishCheckVerdict::Unsure, false],
            ['Wes Worker', null, false],
            ] as $index => [$name, $verdict, $unfinished]
        ) {
            $momentMs = $this->startMs + 1_000_000 + $index * 10_000;
            $participantId = $this->tapWithPhoto($name, $momentMs, $verdict);
            $this->confirm($participantId, $unfinished ? null : $momentMs, $unfinished ? 2_500 : null);
        }
    }

    /**
     * Another participant who tapped Finished once - the tap's photo checked with this verdict (null: not yet)
     */
    private function tapWithPhoto(string $name, int $momentMs, null|OnlineFinishCheckVerdict $verdict): string
    {
        $entry = $this->enterParticipant($name);
        $onlineEntry = $this->onlineEntryOf($entry->id->toString());
        $tapId = $this->finishTap($onlineEntry, $momentMs);
        $photo = $this->evidence($onlineEntry, OnlineMediaKind::Button, $momentMs, $tapId);

        if ($verdict !== null) {
            $this->checked($photo, $verdict, $verdict === OnlineFinishCheckVerdict::Gaps ? [self::GAP] : []);
        }

        return $entry->participant->id->toString();
    }

    /**
     * @param list<array{x: int, y: int, w: int, h: int}> $gaps
     */
    private function checked(OnlineEntryMedia $photo, OnlineFinishCheckVerdict $verdict, array $gaps = []): void
    {
        $failed = $verdict === OnlineFinishCheckVerdict::Failed;
        $this->entityManager()->persist(new OnlineMediaCheck(
            id: Uuid::uuid7(),
            media: $photo,
            workerVersion: $failed ? OnlineMediaCheck::NO_VERSION : 'w1-c1',
            verdict: $verdict,
            checkedAt: new DateTimeImmutable(),
            confidence: $failed ? null : 0.8,
            gaps: $gaps,
            reason: $failed ? null : 'Looks clean',
            method: $failed ? null : 'holes',
            ms: 200,
            imageWidth: $failed ? null : 1920,
            imageHeight: $failed ? null : 1080,
            error: $failed ? 'finish_unreadable' : null,
        ));
        $this->entityManager()->flush();
    }

    private function confirm(string $participantId, null|int $momentMs, null|int $pieces): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ConfirmOnlineResult(
            OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP,
            self::ROUND,
            $participantId,
            $momentMs,
            $pieces === null ? OnlineConfirmedSource::Button : OnlineConfirmedSource::Reviewer,
            $pieces,
            PlayerFixture::PLAYER_ADMIN,
            RoundEntryResult::none(),
        ));
    }

    private function signedIn(bool $checkOn): KernelBrowser
    {
        if ($checkOn) {
            $this->turnCompletenessCheckOn();
        }

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->disableReboot();

        return $browser;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The badge each row shows: the check of the photo the review opens on (failed reads as pending, review_check.js)
     *
     * @param array<string, mixed> $list
     * @return array<string, null|array<string, mixed>>
     */
    private function checksByName(array $list): array
    {
        self::assertIsArray($list['entries']);
        $checks = [];

        foreach ($list['entries'] as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('check', $entry);
            $check = $entry['check'];

            if (is_array($check) && $check['state'] === 'failed') {
                $check['state'] = 'pending';
            }

            self::assertIsString($entry['name']);
            /** @var null|array<string, mixed> $check */
            $checks[$entry['name']] = $check;
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $list
     * @return null|array<string, mixed>
     */
    private function entryCheck(array $list, string $name): null|array
    {
        self::assertIsArray($list['entries']);

        foreach ($list['entries'] as $entry) {
            if (is_array($entry) && $entry['name'] === $name) {
                /** @var null|array<string, mixed> $check */
                $check = $entry['check'];

                return $check;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function csvRows(KernelBrowser $browser): array
    {
        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", substr((string) $browser->getResponse()->getContent(), 3)), static fn (string $line): bool => $line !== '')),
        );

        /** @var array<string, list<string>> $rows */
        $rows = array_column(array_slice($lines, 1), null, 0);

        return $rows;
    }
}
