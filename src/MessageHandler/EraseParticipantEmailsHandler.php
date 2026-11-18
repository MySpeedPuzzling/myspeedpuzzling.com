<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\EraseParticipantEmails;
use SpeedPuzzling\Web\Query\GetEventsWithParticipantEmails;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The organisers' addresses of participants are kept for the claim invitations only (docs/features/
 * competitions-management/participant-linking.md D15): erased once 60 days have passed since the end of the event's last
 * day in the event's zone (the zone of its registration, else of its or its series' country - Competition::endsAt()).
 */
#[AsMessageHandler]
readonly final class EraseParticipantEmailsHandler
{
    public const int KEEP_DAYS = 60;

    public function __construct(
        private GetEventsWithParticipantEmails $getEventsWithParticipantEmails,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EraseParticipantEmails $message): int
    {
        $now = $this->clock->now();
        $ended = [];

        foreach ($this->getEventsWithParticipantEmails->endedBefore(self::KEEP_DAYS) as $event) {
            $zone = RoundTimezone::resolve($event['registrationTimezone'], $event['countryCode'], $event['seriesCountryCode']);
            $endsAt = RegistrationAvailability::eventEndsAt($event['dateFrom'], $event['dateTo'], $zone);

            if ($endsAt !== null && $endsAt->modify(sprintf('+%d days', self::KEEP_DAYS)) <= $now) {
                $ended[] = $event['id'];
            }
        }

        if ($ended === []) {
            return 0;
        }

        // A genuine bulk operation (CLAUDE.md exception): every address of every participant of the ended events, rows
        // not loaded one by one. Only the column nothing but the organisers' tools reads, so no participants lock - like
        // account deletion, it spans many events in one statement.
        return (int) $this->database->executeStatement(
            'UPDATE competition_participant SET email = NULL WHERE competition_id IN (:ids) AND email IS NOT NULL',
            ['ids' => $ended],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
