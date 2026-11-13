<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\CopyAccountToSandbox;
use SpeedPuzzling\Web\MessageHandler\CopyAccountToSandboxHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxAccountExport;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A real account copied from production into the sandbox (docs/features/sandbox/README.md "Copying a real account"):
 * the person signs in there with their own password.
 */
final class CopyAccountToSandboxHandlerTest extends KernelTestCase
{
    public function testTheCopySignsInWithTheSamePassword(): void
    {
        self::bootKernel();
        $hash = self::hash('their-own-password');

        $created = self::handler()(self::message('Someone.Real@Example.com', $hash, code: 'realcode'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertTrue($created);
        $account = self::getContainer()->get(UserAccountRepository::class)->findByEmail('someone.real@example.com');
        self::assertNotNull($account);
        self::assertNotNull($account->emailVerifiedAt);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($account, 'their-own-password'));
        self::assertFalse(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($account, 'sandbox'));

        $player = self::getContainer()->get(PlayerRepository::class)->findByUserId($account->userId);
        self::assertNotNull($player);
        self::assertSame('realcode', $player->code);
        self::assertSame('Real Person', $player->name);
        self::assertSame('de', $player->country);
        self::assertSame('Europe/Berlin', $player->timezone);
    }

    public function testATakenCodeGetsAnotherOne(): void
    {
        self::bootKernel();

        self::handler()(self::message('other.real@example.com', self::hash('x'), code: 'player1'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $account = self::getContainer()->get(UserAccountRepository::class)->findByEmail('other.real@example.com');
        self::assertNotNull($account);
        $player = self::getContainer()->get(PlayerRepository::class)->findByUserId($account->userId);
        self::assertNotNull($player);
        self::assertNotSame('player1', $player->code);
    }

    public function testAnAccountRegisteredOnTheSandboxOnlyGetsTheProductionPassword(): void
    {
        self::bootKernel();

        $created = self::handler()(self::message(PlayerFixture::PLAYER_REGULAR_EMAIL, self::hash('from-production'), code: 'zzz', name: 'Other Name'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertFalse($created);
        $account = self::getContainer()->get(UserAccountRepository::class)->findByEmail(PlayerFixture::PLAYER_REGULAR_EMAIL);
        self::assertNotNull($account);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($account, 'from-production'));
        $player = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $player->name);
        self::assertSame('player1', $player->code);
    }

    public function testMadeUpPlayersAreNeverOverwritten(): void
    {
        self::bootKernel();

        $this->expectException(\InvalidArgumentException::class);

        self::handler()(self::message('test-user+5@myspeedpuzzling.com', self::hash('x'), code: 'abc'));
    }

    public function testTheExportCarriesTheSignInAndTheBasicProfile(): void
    {
        self::bootKernel();

        $export = self::getContainer()->get(SandboxAccountExport::class)->byEmail(' Player1@SpeedPuzzling.cz ');

        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $export['email']);
        self::assertSame('player1', $export['code']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $export['name']);
        self::assertSame('cz', $export['country']);
        self::assertTrue($export['emailVerified']);
        // The fixture accounts mirror an Auth0 import: no password of their own
        self::assertNull($export['passwordHash']);
    }

    private static function handler(): CopyAccountToSandboxHandler
    {
        $container = self::getContainer();

        return new CopyAccountToSandboxHandler(
            new SandboxMode(true),
            $container->get(UserAccountRepository::class),
            $container->get(PlayerRepository::class),
            $container->get(GenerateUniquePlayerCode::class),
            $container->get(ClockInterface::class),
        );
    }

    private static function hash(string $password): string
    {
        $account = new UserAccount(Uuid::uuid7(), 'msp|hash', 'hash@example.com', TestClock::now());

        return self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($account, $password);
    }

    private static function message(string $email, null|string $hash, string $code, string $name = 'Real Person'): CopyAccountToSandbox
    {
        return new CopyAccountToSandbox($email, $hash, true, $name, $code, 'de', 'de', 'Europe/Berlin');
    }
}
