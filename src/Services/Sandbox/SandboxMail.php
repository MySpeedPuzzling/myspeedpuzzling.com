<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\Headers;

/**
 * How a sandbox e-mail is told apart from production's (docs/features/sandbox/README.md "E-mail"). Production uses it
 * too: its SES event handler ignores every event of a message sent from the sandbox (SesEvent::isFromSandbox()).
 */
final class SandboxMail
{
    /**
     * The sandbox's own SES identity (verified domain, Easy DKIM). Every sender address of the sandbox is moved here.
     */
    public const string SENDER_DOMAIN = 'sandbox.myspeedpuzzling.com';

    public const string SUBJECT_PREFIX = '[Sandbox] ';

    /**
     * SES message tag on every sandbox message; tag values allow letters, digits, hyphens and underscores only
     */
    public const string TAG = 'sandbox';

    private const string TAGS_HEADER = 'X-SES-MESSAGE-TAGS';

    /**
     * notify@notify.myspeedpuzzling.com → notify@sandbox.myspeedpuzzling.com (the name is kept)
     */
    public static function senderAddress(Address $address): Address
    {
        $at = strrpos($address->getAddress(), '@');
        $localPart = $at === false ? $address->getAddress() : substr($address->getAddress(), 0, $at);

        return new Address($localPart . '@' . self::SENDER_DOMAIN, $address->getName());
    }

    public static function isSandboxSender(null|string $address): bool
    {
        return $address !== null && str_ends_with(strtolower($address), '@' . self::SENDER_DOMAIN);
    }

    /**
     * Adds `sandbox=1` to the SES tags (kept next to the audit tags EmailAuditSubscriber set, if any)
     */
    public static function tag(Headers $headers): void
    {
        $tags = $headers->get(self::TAGS_HEADER)?->getBodyAsString();

        if ($tags !== null && str_contains($tags, self::TAG . '=')) {
            return;
        }

        $headers->remove(self::TAGS_HEADER);
        $headers->addTextHeader(self::TAGS_HEADER, ($tags === null || $tags === '' ? '' : $tags . ', ') . self::TAG . '=1');
    }
}
