<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Message\DismissHint;
use SpeedPuzzling\Web\Message\RegisterUser;
use SpeedPuzzling\Web\Query\GetGettingStartedProgress;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class GetGettingStartedProgressTest extends KernelTestCase
{
    private GetGettingStartedProgress $getGettingStartedProgress;
    private GetPlayerProfile $getPlayerProfile;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getGettingStartedProgress = self::getContainer()->get(GetGettingStartedProgress::class);
        $this->getPlayerProfile = self::getContainer()->get(GetPlayerProfile::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testFreshRegistrationStartsWithOnlyTheAccountStepDone(): void
    {
        $progress = $this->getGettingStartedProgress->forPlayer(
            $this->getPlayerProfile->byUserId($this->register(null)),
        );

        self::assertTrue($progress->isNewcomer);
        self::assertFalse($progress->dismissed);
        self::assertFalse($progress->hasLoggedPuzzle);
        self::assertFalse($progress->hasFinishedProfile);
        self::assertFalse($progress->hasFavoritePlayer);
        self::assertFalse($progress->hasPuzzleInLibrary);
        self::assertSame(1, $progress->doneCount());
        self::assertSame('log_puzzle', $progress->nextStep());
        self::assertTrue($progress->shouldBeShown());
    }

    public function testNameAloneDoesNotFinishTheProfile(): void
    {
        $progress = $this->getGettingStartedProgress->forPlayer(
            $this->getPlayerProfile->byUserId($this->register('Jane Puzzler')),
        );

        self::assertFalse($progress->hasFinishedProfile);
    }

    public function testPlayerWithTimesNameCountryAndFavoritesIsComplete(): void
    {
        $progress = $this->getGettingStartedProgress->forPlayer(
            $this->getPlayerProfile->byId(PlayerFixture::PLAYER_WITH_FAVORITES),
        );

        self::assertTrue($progress->hasLoggedPuzzle);
        self::assertTrue($progress->hasFavoritePlayer);
    }

    public function testDismissedCardIsNotShownAgain(): void
    {
        $userId = $this->register(null);
        $profile = $this->getPlayerProfile->byUserId($userId);

        $this->messageBus->dispatch(new DismissHint($profile->playerId, HintType::GettingStartedChecklist));

        $progress = $this->getGettingStartedProgress->forPlayer($profile);

        self::assertTrue($progress->dismissed);
        self::assertFalse($progress->shouldBeShown());
    }

    private function register(null|string $name): string
    {
        $envelope = $this->messageBus->dispatch(new RegisterUser(
            sprintf('getting.started+%s@example.com', bin2hex(random_bytes(4))),
            'a-properly-long-passphrase',
            'en',
            $name,
        ));

        $userId = $envelope->last(HandledStamp::class)?->getResult();
        self::assertIsString($userId);

        return $userId;
    }
}
