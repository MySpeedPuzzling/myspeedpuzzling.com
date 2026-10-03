<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\UnknownCountryCode;
use SpeedPuzzling\Web\Message\SetPlayerCountry;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SetPlayerCountryHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);

        $this->database->executeStatement('UPDATE player SET country = NULL WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
    }

    public function testSetsTheCountryAsItsLowercaseCode(): void
    {
        $this->messageBus->dispatch(new SetPlayerCountry(PlayerFixture::PLAYER_REGULAR, 'AT'));

        self::assertSame('at', $this->countryOf(PlayerFixture::PLAYER_REGULAR));
    }

    public function testChangesACountryThatIsAlreadySet(): void
    {
        $this->messageBus->dispatch(new SetPlayerCountry(PlayerFixture::PLAYER_WITH_FAVORITES, 'cz'));

        self::assertSame('cz', $this->countryOf(PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    public function testRejectsAnUnknownCodeAndChangesNothing(): void
    {
        foreach (['xx', '', 'Czechia'] as $code) {
            try {
                $this->messageBus->dispatch(new SetPlayerCountry(PlayerFixture::PLAYER_REGULAR, $code));
                self::fail(sprintf('"%s" is not a country code', $code));
            } catch (UnknownCountryCode) {
                // expected - the HTTP-flavoured exception reaches the caller unwrapped (UnwrapHttpExceptionMiddleware)
            }

            self::assertNull($this->countryOf(PlayerFixture::PLAYER_REGULAR));
        }
    }

    private function countryOf(string $playerId): null|string
    {
        $country = $this->database->fetchOne('SELECT country FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertTrue($country === null || is_string($country));

        return $country;
    }
}
