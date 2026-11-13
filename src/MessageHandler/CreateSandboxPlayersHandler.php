<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\CreateSandboxPlayers;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMembership;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxPeople;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
readonly final class CreateSandboxPlayersHandler
{
    public function __construct(
        private SandboxMode $sandboxMode,
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private MembershipRepository $membershipRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private GenerateUniquePlayerCode $generateUniquePlayerCode,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int how many accounts were created
     *
     * @throws SandboxModeIsOff
     * @throws CouldNotGenerateUniqueCode
     */
    public function __invoke(CreateSandboxPlayers $message): int
    {
        $this->sandboxMode->assertOn();

        $now = $this->clock->now();
        $passwordHash = null;
        $usedCodes = [];
        $created = 0;

        $accounts = [];

        for ($number = 1; $number <= $message->count; $number++) {
            $accounts[] = [...SandboxPeople::person($number), 'registeredAt' => SandboxPeople::registeredAt($number, $now), 'member' => true];
        }

        $accounts[] = [
            'name' => 'Free Tester',
            'country' => CountryCode::cz,
            'locale' => 'en',
            'email' => SandboxPeople::FREE_PLAYER_EMAIL,
            'registeredAt' => $now->modify('-30 days'),
            'member' => false,
        ];

        foreach ($accounts as $account) {
            if ($this->userAccountRepository->findByEmail($account['email']) !== null) {
                continue;
            }

            $userId = 'msp|' . Uuid::uuid7($account['registeredAt'])->toString();
            $userAccount = new UserAccount(Uuid::uuid7($account['registeredAt']), $userId, $account['email'], $account['registeredAt']);
            // One hash for everybody: hashing it per account would make the seed wait minutes for the same string
            $passwordHash ??= $this->passwordHasher->hashPassword($userAccount, $message->password);
            $userAccount->changePassword($passwordHash);
            $userAccount->markEmailVerified($account['registeredAt']);
            $this->userAccountRepository->save($userAccount);

            $player = new Player(
                Uuid::uuid7($account['registeredAt']),
                $this->uniqueCode($usedCodes),
                $userId,
                $account['name'],
                $account['registeredAt'],
            );
            $player->changeCountry($account['country']);
            $player->changeLocale($account['locale']);
            $this->playerRepository->save($player);

            $this->membershipRepository->save(new Membership(
                Uuid::uuid7($account['registeredAt']),
                $player,
                $account['registeredAt'],
                // The free account had a short grant that ended - a membership row, so signing in never grants one
                grantedUntil: $account['member'] ? SandboxMembership::grantedUntil() : $account['registeredAt']->modify('+10 days'),
            ));

            $created++;
        }

        return $created;
    }

    /**
     * @param array<string, true> $usedCodes codes handed out in this run, not in the database until the flush
     *
     * @throws CouldNotGenerateUniqueCode
     */
    private function uniqueCode(array &$usedCodes): string
    {
        do {
            $code = $this->generateUniquePlayerCode->generate();
        } while (isset($usedCodes[strtolower($code)]));

        $usedCodes[strtolower($code)] = true;

        return $code;
    }
}
