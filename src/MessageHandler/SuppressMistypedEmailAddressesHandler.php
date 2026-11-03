<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailSuppression;
use SpeedPuzzling\Web\Message\SuppressMistypedEmailAddresses;
use SpeedPuzzling\Web\Query\GetMistypedEmailAddresses;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Value\EmailSuppressionSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SuppressMistypedEmailAddressesHandler
{
    public function __construct(
        private GetMistypedEmailAddresses $getMistypedEmailAddresses,
        private EmailSuppressionRepository $emailSuppressionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> the addresses suppressed now
     */
    public function __invoke(SuppressMistypedEmailAddresses $message): array
    {
        $now = $this->clock->now();
        $suppressed = [];

        foreach (array_keys($this->getMistypedEmailAddresses->notSuppressed()) as $email) {
            // Several accounts can share an address written differently - one row per address
            if ($this->emailSuppressionRepository->findByEmail($email) !== null || in_array($email, $suppressed, true)) {
                continue;
            }

            $this->emailSuppressionRepository->save(new EmailSuppression(
                Uuid::uuid7(),
                EmailSuppression::canonicalize($email),
                $now,
                null,
                null,
                null,
                EmailSuppressionSource::DomainNotFound,
            ));
            $suppressed[] = $email;
        }

        return $suppressed;
    }
}
