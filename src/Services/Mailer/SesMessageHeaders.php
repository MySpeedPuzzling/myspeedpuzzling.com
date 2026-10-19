<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Mailer;

use SpeedPuzzling\Web\Value\MailStream;
use Symfony\Component\Mime\Header\Headers;

/**
 * What Amazon SES needs on a message: the configuration set (per stream - it decides which events come back and
 * where) and message tags. The tags come back in every event of the message (`mail.tags`), so a delivery, bounce or
 * complaint finds its email_audit_log row without a lookup. Both are headers of the message, which SES reads over
 * SMTP and over the API's raw content alike (ses+https - its transport also passes the configuration set as the
 * request's ConfigurationSetName). SES removes these headers before delivering the message.
 */
final class SesMessageHeaders
{
    /**
     * Set by MailStreamRouting before the transport is picked (X-Transport is gone by then) and always removed
     * again in EmailAuditSubscriber, so it never leaves the app
     */
    public const string STREAM_HEADER = 'X-MSP-Stream';

    private const string CONFIGURATION_SET_HEADER = 'X-SES-CONFIGURATION-SET';
    private const string TAGS_HEADER = 'X-SES-MESSAGE-TAGS';

    /**
     * SES over SMTP (`smtp://email-smtp.<region>.amazonaws.com:587`) or over its API (`ses+https://<key id>@<region>`)
     */
    public static function isSes(string $transport): bool
    {
        return str_contains($transport, '.amazonaws.com') || str_starts_with($transport, 'ses+');
    }

    /**
     * The transport's name as stored and logged: the API transport names itself with the access key id
     */
    public static function transportLabel(string $transport): string
    {
        return (string) preg_replace('#^(ses\+[a-z]+://)[^@/]*@#', '$1', $transport);
    }

    public static function takeStream(Headers $headers): MailStream
    {
        $stream = $headers->has(self::STREAM_HEADER)
            ? MailStream::tryFrom($headers->get(self::STREAM_HEADER)?->getBodyAsString() ?? '')
            : null;

        $headers->remove(self::STREAM_HEADER);

        return $stream ?? MailStream::Transactional;
    }

    public static function apply(Headers $headers, MailStream $stream, null|string $auditLogId, null|string $emailType): void
    {
        $headers->remove(self::CONFIGURATION_SET_HEADER);
        $headers->remove(self::TAGS_HEADER);
        $headers->addTextHeader(self::CONFIGURATION_SET_HEADER, $stream->configurationSet());

        $tags = [];

        if ($auditLogId !== null) {
            $tags[] = 'audit_id=' . self::tagValue($auditLogId);
        }

        if ($emailType !== null && $emailType !== '') {
            $tags[] = 'email_type=' . self::tagValue($emailType);
        }

        if ($tags !== []) {
            $headers->addTextHeader(self::TAGS_HEADER, implode(', ', $tags));
        }
    }

    /**
     * SES tag values allow only letters, digits, hyphens and underscores, up to 256 characters
     */
    private static function tagValue(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $value), 0, 256);
    }
}
