<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One report per camera scanner session (barcode_scanner_controller.js, docs/features/barcode-scanner/README.md §5.1):
 * the phone, camera and reader, and whether a code was read. A scan that never reads leaves no trace anywhere else -
 * this is what the read rate per platform and the phones that never read are counted from (Loki, channel
 * barcode_scanner). Info only: nothing here is a problem for a person to look at.
 */
final class ScanSessionController extends AbstractController
{
    private const int MAX_PAYLOAD_BYTES = 4096;

    private const string UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    public function __construct(
        #[Autowire(service: 'monolog.logger.barcode_scanner')]
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/-/scan-session', name: 'scan_session', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode(
            substr($request->getContent(), 0, self::MAX_PAYLOAD_BYTES),
            associative: true,
        );

        if (is_array($payload) && in_array($payload['outcome'] ?? null, ['read', 'no-read'], true)) {
            $this->logger->info('Barcode scanner session', [
                // Ids in the path (a puzzle, a stopwatch) would make every page its own
                'page' => is_string($payload['page'] ?? null) ? preg_replace(self::UUID_PATTERN, '{id}', mb_substr($payload['page'], 0, 200)) : null,
                'outcome' => $payload['outcome'],
                'engine' => self::text($payload, 'engine', 20),
                'forced' => self::text($payload, 'forced', 20),
                'debug' => ($payload['debug'] ?? null) === true,
                'continuous' => ($payload['continuous'] ?? null) === true,
                'reads' => self::number($payload, 'reads'),
                'first_read_ms' => self::number($payload, 'firstReadMs'),
                'duration_ms' => self::number($payload, 'durationMs'),
                'frames' => self::number($payload, 'frames'),
                'decode_ms' => self::number($payload, 'decodeMs'),
                'seen_codes' => self::number($payload, 'seenCodes'),
                'camera' => self::text($payload, 'camera', 100),
                'camera_pick' => self::text($payload, 'cameraPick', 50),
                'video' => self::text($payload, 'video', 20),
                'zoom' => self::number($payload, 'zoom'),
                'focus_mode' => self::text($payload, 'focusMode', 20),
                'focus_modes' => is_array($payload['focusModes'] ?? null)
                    ? implode(',', array_slice(array_filter($payload['focusModes'], 'is_string'), 0, 5))
                    : null,
                'focus_distance_min' => self::number($payload, 'focusDistanceMin'),
                'torch' => ($payload['torch'] ?? null) === true,
                'model' => self::text($payload, 'model', 100),
                'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 300),
            ]);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<mixed> $payload
     */
    private static function text(array $payload, string $key, int $maxLength): null|string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? mb_substr($value, 0, $maxLength) : null;
    }

    /**
     * @param array<mixed> $payload
     */
    private static function number(array $payload, string $key): null|int|float
    {
        $value = $payload[$key] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }
}
