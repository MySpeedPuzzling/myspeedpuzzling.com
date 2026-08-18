<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Message\RegisterUser;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Native registration (Stage A of issue #147). Creates the account and the
 * player together - one transaction, one identity string - so a player row can
 * never exist without the credentials that reach it, or the other way round.
 */
#[AsMessageHandler]
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private GenerateUniquePlayerCode $generateUniquePlayerCode,
        private ClockInterface $clock,
        private AuthAuditRecorder $authAuditRecorder,
    ) {
    }

    /**
     * @return string the new account's user_id - the caller logs the user in with it
     *
     * @throws EmailAlreadyRegistered
     * @throws CouldNotGenerateUniqueCode
     */
    public function __invoke(RegisterUser $message): string
    {
        $email = UserAccount::canonicalizeEmail($message->email);

        if ($this->userAccountRepository->findByEmail($email) !== null) {
            throw new EmailAlreadyRegistered();
        }

        // Player, not just user_account: through window A the user_account table holds
        // native registrants only, so a returning user who forgot they had an account
        // could otherwise register natively with their existing address. At Stage B the
        // import would then skip their Auth0 identity (email taken by another user_id)
        // and strand their real profile and every solving time on it.
        if ($this->playerRepository->findByEmail($email) !== null) {
            throw new EmailAlreadyRegistered();
        }

        $now = $this->clock->now();
        // Provider-agnostic by design (README §Auth-method extensibility): the identity
        // string never encodes how the account signs in, so linking a social identity
        // later never touches the Player.userId seam.
        $userId = 'msp|' . Uuid::uuid7()->toString();

        $userAccount = new UserAccount(
            Uuid::uuid7(),
            $userId,
            $email,
            $now,
        );
        $userAccount->changePassword(
            $this->passwordHasher->hashPassword($userAccount, $message->plainPassword),
        );

        $this->userAccountRepository->save($userAccount);

        $player = new Player(
            Uuid::uuid7(),
            $this->generateUniquePlayerCode->generate(),
            $userId,
            $email,
            null,
            $now,
        );

        if ($message->locale !== null) {
            $player->changeLocale($message->locale);
        }

        $this->playerRepository->save($player);

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::Registration,
            userAccountId: $userAccount->id->toString(),
            userId: $userId,
            email: $email,
        ));

        return $userId;
    }
}
