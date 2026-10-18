<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Psr\Log\AbstractLogger;
use SpeedPuzzling\Web\Controller\ScanSessionController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ScanSessionControllerTest extends WebTestCase
{
    public function testValidReportIsAcceptedWithoutSession(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/scan-session', content: (string) json_encode(self::report()));

        $this->assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
    }

    public function testGarbageBodyIsAcceptedSilently(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/scan-session', content: 'not-json{{{');

        $this->assertResponseStatusCodeSame(204);
    }

    public function testGetIsNotAllowed(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/scan-session');

        $this->assertResponseStatusCodeSame(405);
    }

    /**
     * The scanner posts to this path - the two must not drift apart.
     */
    public function testTheScannerReportsToThisEndpoint(): void
    {
        self::assertStringContainsString(
            "navigator.sendBeacon('/-/scan-session'",
            (string) file_get_contents(__DIR__ . '/../../assets/controllers/barcode_scanner_controller.js'),
        );
    }

    public function testASessionIsOneInfoLineWithoutIdsInThePage(): void
    {
        $logged = self::logged(self::report());

        self::assertCount(1, $logged);
        self::assertSame('info', $logged[0]['level']);
        self::assertSame('/en/puzzle-add/{id}', $logged[0]['context']['page']);
        self::assertSame('read', $logged[0]['context']['outcome']);
        self::assertSame('SM-S921B Android 15.0.0', $logged[0]['context']['model']);
        self::assertSame('continuous,manual', $logged[0]['context']['focus_modes']);
        self::assertSame('both', $logged[0]['context']['accepted_by']);
        self::assertSame(['frames' => 40, 'decode_ms' => 45, 'first_seen_ms' => 610, 'solo_ms' => null, 'baseline_ms' => 41, 'max_backoff' => null], $logged[0]['context']['native']);
        self::assertSame(['frames' => 21, 'decode_ms' => 31, 'first_seen_ms' => 700, 'solo_ms' => 1210, 'baseline_ms' => null, 'max_backoff' => 4], $logged[0]['context']['zbar']);
        self::assertFalse($logged[0]['context']['zbar_missing']);
        self::assertSame('4005555017585 native x3 zbar x4 q113', $logged[0]['context']['seen']);
    }

    public function testASingleReaderSessionHasNoNumbersForTheOther(): void
    {
        $report = self::report();
        $report['engine'] = 'zbar';
        unset($report['native']);
        $report['acceptedBy'] = 'zbar';

        $logged = self::logged($report);

        self::assertNull($logged[0]['context']['native']);
        self::assertSame('zbar', $logged[0]['context']['accepted_by']);
    }

    public function testAReportWithoutAnOutcomeIsNotLogged(): void
    {
        self::assertSame([], self::logged(['page' => '/en/multiscan']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function report(): array
    {
        return [
            'page' => '/en/puzzle-add/018e0555-6311-715e-95da-9d30aad917d6',
            'engine' => 'dual',
            'forced' => null,
            'debug' => false,
            'continuous' => false,
            'outcome' => 'read',
            'reads' => 1,
            'firstReadMs' => 1840,
            'durationMs' => 1900,
            'frames' => 52,
            'decodeMs' => 18,
            'seenCodes' => 1,
            'acceptedBy' => 'both',
            'native' => ['frames' => 40, 'decodeMs' => 45, 'firstSeenMs' => 610, 'soloMs' => null, 'baselineMs' => 41, 'maxBackoff' => null],
            'zbar' => ['frames' => 21, 'decodeMs' => 31, 'firstSeenMs' => 700, 'soloMs' => 1210, 'baselineMs' => null, 'maxBackoff' => 4],
            'zbarMissing' => false,
            'seen' => '4005555017585 native x3 zbar x4 q113',
            'camera' => 'camera 0, facing back',
            'cameraPick' => 'main lens',
            'video' => '1080x1920',
            'zoom' => 1.5,
            'focusMode' => 'continuous',
            'focusModes' => ['continuous', 'manual'],
            'focusDistanceMin' => 0.1,
            'torch' => true,
            'model' => 'SM-S921B Android 15.0.0',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{level: string, context: array<mixed>}>
     */
    private static function logged(array $payload): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: string, context: array<mixed>}> */
            public array $records = [];

            /**
             * @param array<mixed> $context
             */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                assert(is_string($level));
                $this->records[] = ['level' => $level, 'context' => $context];
            }
        };

        new ScanSessionController($logger)(Request::create('/-/scan-session', 'POST', content: (string) json_encode($payload)));

        return $logger->records;
    }
}
