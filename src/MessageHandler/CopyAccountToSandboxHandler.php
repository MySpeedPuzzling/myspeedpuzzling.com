<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\CopyAccountToSandbox;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxPeople;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Creates the account with production's password hash (same e-mail, same password) and the basic profile - or, when
 * the e-mail is registered on the sandbox already, gives that account production's password and keeps everything else
 * it has there. The membership comes with the first sign-in, like for everybody on the sandbox.
 */
#[AsMessageHandler]
readonly final class CopyAccountToSandboxHandler
{
    public function __construct(
        private SandboxMode $sandboxMode,
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private GenerateUniquePlayerCode $generateUniquePlayerCode,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool true = created, false = an existing sandbox account got production's password
     *
     * @throws SandboxModeIsOff
     * @throws CouldNotGenerateUniqueCode
     */
    public function __invoke(CopyAccountToSandbox $message): bool
    {
        $this->sandboxMode->assertOn();

        $email = UserAccount::canonicalizeEmail($message->email);

        if (SandboxPeople::isMadeUpEmail($email)) {
            throw new \InvalidArgumentException('A made-up player of the sandbox is not copied from production.');
        }

        $now = $this->clock->now();
        $userAccount = $this->userAccountRepository->findByEmail($email);

        if ($userAccount !== null) {
            if ($message->passwordHash !== null) {
                $userAccount->changePassword($message->passwordHash);
            }

            if ($message->emailVerified) {
                $userAccount->markEmailVerified($now);
            }

            return false;
        }

        $userId = 'msp|' . Uuid::uuid7()->toString();
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, $now);

        if ($message->passwordHash !== null) {
            $userAccount->changePassword($message->passwordHash);
        }

        if ($message->emailVerified) {
            $userAccount->markEmailVerified($now);
        }

        $this->userAccountRepository->save($userAccount);

        $code = $this->generateUniquePlayerCode->isCodeAvailable($message->code, null)
            ? strtolower($message->code)
            : $this->generateUniquePlayerCode->generate();

        $name = trim($message->name ?? '');
        $player = new Player(Uuid::uuid7(), $code, $userId, $name !== '' ? $name : null, $now);

        $country = CountryCode::fromCode($message->country);

        if ($country !== null) {
            $player->changeCountry($country);
        }

        if ($message->locale !== null) {
            $player->changeLocale($message->locale);
        }

        if ($message->timezone !== null && TimezoneIdentifier::isListed($message->timezone)) {
            $player->changeTimezone($message->timezone);
        }

        $this->playerRepository->save($player);

        return true;
    }
}
