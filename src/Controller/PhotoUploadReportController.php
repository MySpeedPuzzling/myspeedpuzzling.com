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
 *
 * `photo_dropped`: a photo the phone handed over empty, or that the browser could not read, was taken out of the form
 * (docs/features/photo-uploads/README.md "A photo the phone cannot read") - routine, info. `error` is the browser's own
 * words for a failure ("TypeError: Failed to fetch"), cleaned again here: one line, no address, no path, 200 characters.
 */
final class PhotoUploadReportController extends AbstractController
{
    private const int MAX_PAYLOAD_BYTES = 4096;

    private const array EVENTS = ['upload', 'save', 'photo_dropped'];

    private const int MAX_ERROR_LENGTH = 200;

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
            'error' => self::errorText($payload),
            // A lost save: which message the player got (photo / either / network)
            'failure' => self::text($payload, 'failure', 20),
            // Photos taken out as unreadable: where it was noticed (pick / start / save / save_without), how many so far
            'detected_on' => self::text($payload, 'detectedOn', 20),
            'photos_dropped' => self::number($payload, 'photosDropped'),
            'dropped_on' => self::text($payload, 'droppedOn', 20),
            'effective_type' => self::text($payload, 'effectiveType', 10),
            'downlink' => is_int($payload['downlink'] ?? null) || is_float($payload['downlink'] ?? null) ? (float) $payload['downlink'] : null,
            'online' => is_bool($payload['online'] ?? null) ? $payload['online'] : null,
            'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 300),
        ];

        if ($context['event'] === 'upload' && $context['reason'] === 'store_refused') {
            $this->logger->warning('Object storage refused a signed photo upload - the photo went with the form instead', $context);
        } elseif ($context['event'] === 'upload') {
            $this->logger->info('Background photo upload', $context);
        } elseif ($context['event'] === 'photo_dropped') {
            $this->logger->info('Photo the device could not read was taken out of the form', $context);
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
     * The browser's name and message of an error, as assets/photo_readability.js sends it. Whatever arrives is cut and
     * cleaned once more: one line, no address, no path, no id.
     *
     * @param array<mixed> $payload
     */
    private static function errorText(array $payload): null|string
    {
        $value = $payload['error'] ?? null;

        if (!is_string($value)) {
            return null;
        }

        // Cut first, so the patterns never run over more than a short text
        $text = mb_substr($value, 0, self::MAX_ERROR_LENGTH * 2);
        $text = preg_replace('~\b(?:blob|file|content|https?):[^\s\'"<>)]+~iu', '[url]', $text) ?? '';
        $text = preg_replace('~(?:[A-Za-z]:)?[\\\\/](?:[^\s\\\\/\'"<>:]+[\\\\/])+[^\s\\\\/\'"<>:]*~u', '[path]', $text) ?? '';
        $text = preg_replace(self::UUID_PATTERN, '{id}', $text) ?? '';
        $text = trim(preg_replace('~[\s\p{C}]+~u', ' ', $text) ?? '');

        return $text === '' ? null : mb_substr($text, 0, self::MAX_ERROR_LENGTH);
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
