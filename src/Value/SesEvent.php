<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * One Amazon SES event, read from the Message of an SNS notification. Two shapes arrive: configuration-set event
 * publishing (`eventType`, app mail) and identity feedback notifications (`notificationType`, the newsletter's
 * domain - Listmonk reads the same topic). Both carry `mail` and the same bounce/complaint objects.
 */
final readonly class SesEvent
{
    /** SES's own words for a domain it could not look up, and Postfix's */
    public const string DOMAIN_NOT_FOUND_PATTERN = '/Unable to lookup DNS for|Host or domain name not found/i';

    /**
     * @param list<string> $recipients the addresses the event is about (bounced/complained ones, else all)
     */
    public function __construct(
        public string $type,
        public DateTimeImmutable $occurredAt,
        public array $recipients,
        /** The sender's address alone, lowercased */
        public null|string $source,
        public null|string $auditLogId,
        public null|string $emailType,
        public null|MailStream $stream,
        public null|string $bounceType = null,
        public null|string $bounceSubType = null,
        public null|string $diagnosticCode = null,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $event = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

        if (!is_array($event)) {
            throw new \InvalidArgumentException('SES event is not a JSON object');
        }

        $type = $event['eventType'] ?? $event['notificationType'] ?? null;
        $mail = $event['mail'] ?? [];

        if (!is_string($type) || !is_array($mail)) {
            throw new \InvalidArgumentException('SES event without a type or mail');
        }

        $tags = is_array($mail['tags'] ?? null) ? $mail['tags'] : [];
        $detail = $event[lcfirst($type)] ?? [];
        $detail = is_array($detail) ? $detail : [];

        $recipients = match ($type) {
            'Bounce' => self::addresses($detail['bouncedRecipients'] ?? []),
            'Complaint' => self::addresses($detail['complainedRecipients'] ?? []),
            default => [],
        };

        if ($recipients === []) {
            $recipients = self::addresses($mail['destination'] ?? []);
        }

        $bouncedRecipients = $detail['bouncedRecipients'] ?? [];
        $firstBounced = is_array($bouncedRecipients) && is_array($bouncedRecipients[0] ?? null) ? $bouncedRecipients[0] : [];
        $source = self::addresses([$mail['source'] ?? null])[0] ?? null;

        return new self(
            type: $type,
            occurredAt: self::timestamp($detail['timestamp'] ?? $mail['timestamp'] ?? null),
            recipients: $recipients,
            source: $source,
            auditLogId: self::tag($tags, 'audit_id'),
            emailType: self::tag($tags, 'email_type'),
            stream: MailStream::fromConfigurationSet(self::tag($tags, 'ses:configuration-set'))
                // Identity notifications carry no configuration set: the newsletter is the only one sent from news.
                ?? ($source !== null && str_ends_with($source, '@news.myspeedpuzzling.com') ? MailStream::Newsletter : null),
            bounceType: is_string($detail['bounceType'] ?? null) ? $detail['bounceType'] : null,
            bounceSubType: is_string($detail['bounceSubType'] ?? null) ? $detail['bounceSubType'] : null,
            diagnosticCode: is_string($firstBounced['diagnosticCode'] ?? null) ? $firstBounced['diagnosticCode'] : null,
        );
    }

    public function isPermanentBounce(): bool
    {
        return $this->type === 'Bounce' && $this->bounceType === 'Permanent';
    }

    /**
     * A transient bounce because the recipient's domain could not be looked up in DNS ("550 4.4.7 Message expired …
     * <421 4.4.0 Unable to lookup DNS for googlemail.mail.com>") - a mistyped domain, or a DNS outage on their side
     */
    public function isDomainNotFoundBounce(): bool
    {
        return $this->type === 'Bounce'
            && $this->bounceType !== 'Permanent'
            && $this->diagnosticCode !== null
            && preg_match(self::DOMAIN_NOT_FOUND_PATTERN, $this->diagnosticCode) === 1;
    }

    /**
     * @param array<mixed> $tags SES tags: name => list of values
     */
    private static function tag(array $tags, string $name): null|string
    {
        $values = $tags[$name] ?? null;

        return is_array($values) && is_string($values[0] ?? null) && $values[0] !== '' ? $values[0] : null;
    }

    /**
     * @return list<string>
     */
    private static function addresses(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $addresses = [];

        foreach ($list as $item) {
            $address = is_array($item) ? ($item['emailAddress'] ?? null) : $item;

            if (is_string($address) && $address !== '') {
                // "Name <a@b>" in mail.destination
                if (preg_match('/<([^>]+)>/', $address, $matches) === 1) {
                    $address = $matches[1];
                }

                $addresses[] = mb_strtolower(trim($address));
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function timestamp(mixed $value): DateTimeImmutable
    {
        if (is_string($value)) {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }

        throw new \InvalidArgumentException('SES event without a valid timestamp');
    }
}
