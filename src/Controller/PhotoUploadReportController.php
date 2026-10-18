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
 * What the browser saw while it uploaded photos and saved the add/edit-time form (assets/photo_upload.js,
 * submit_prevention_controller.js, docs/features/photo-uploads/README.md "Telemetry"). A save that never reached us -
 * a dropped connection, the edge cutting a slow upload - leaves no trace on the server; this is where those are
 * counted (Loki, channel photo_upload).
 *
 * Info, one line per report: a player's bad connection is nobody's problem to look at. The one warning (→ Sentry) is
 * object storage itself refusing a signed upload with an HTTP error while the player's connection worked - that is ours.
 */
final class PhotoUploadReportController extends AbstractController
{
    private const int MAX_PAYLOAD_BYTES = 4096;

    private const array EVENTS = ['upload', 'save'];

    private const string UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    public function __construct(
        #[Autowire(service: 'monolog.logger.photo_upload')]
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/-/photo-upload-report', name: 'photo_upload_report', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode(
            substr($request->getContent(), 0, self::MAX_PAYLOAD_BYTES),
            associative: true,
        );

        if (!is_array($payload) || !in_array($payload['event'] ?? null, self::EVENTS, true)) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $context = [
            'event' => $payload['event'],
            'outcome' => self::text($payload, 'outcome', 30),
            'reason' => self::text($payload, 'reason', 30),
            // Ids in the path (a puzzle, a time) would make every page its own
            'page' => is_string($payload['page'] ?? null) ? preg_replace(self::UUID_PATTERN, '{id}', mb_substr($payload['page'], 0, 200)) : null,
            'field' => self::text($payload, 'field', 40),
            'status' => self::number($payload, 'status'),
            'bytes' => self::number($payload, 'bytes'),
            'sent_bytes' => self::number($payload, 'sentBytes'),
            'duration_ms' => self::number($payload, 'durationMs'),
            'attempts' => self::number($payload, 'attempts'),
            'photos_direct' => self::number($payload, 'photosDirect'),
            'photos_in_form' => self::number($payload, 'photosInForm'),
            'waited_ms' => self::number($payload, 'waitedMs'),
            'retried' => ($payload['retried'] ?? null) === true,
            'effective_type' => self::text($payload, 'effectiveType', 10),
            'downlink' => is_int($payload['downlink'] ?? null) || is_float($payload['downlink'] ?? null) ? (float) $payload['downlink'] : null,
            'online' => is_bool($payload['online'] ?? null) ? $payload['online'] : null,
            'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 300),
        ];

        if ($context['event'] === 'upload' && $context['reason'] === 'store_refused') {
            $this->logger->warning('Object storage refused a signed photo upload - the photo went with the form instead', $context);
        } elseif ($context['event'] === 'upload') {
            $this->logger->info('Background photo upload', $context);
        } else {
            $this->logger->info('Add/edit time form save', $context);
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
    private static function number(array $payload, string $key): null|int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) || is_float($value) ? (int) round($value) : null;
    }
}
