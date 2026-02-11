<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\EditSellSwapListSettings;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditSellSwapListSettingsHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testSavingSettingsWithShippingCountriesAndCost(): void
    {
        $this->messageBus->dispatch(
            new EditSellSwapListSettings(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                description: 'Test seller',
                currency: 'EUR',
                customCurrency: null,
                shippingInfo: 'Fast shipping',
                contactInfo: 'test@example.com',
                shippingCountries: ['cz', 'sk', 'de'],
                shippingCost: '€5 domestic, €8 EU',
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_WITH_STRIPE);
        $settings = $player->sellSwapListSettings;

        self::assertNotNull($settings);
        self::assertSame('Test seller', $settings->description);
        self::assertSame('EUR', $settings->currency);
        self::assertSame(['cz', 'sk', 'de'], $settings->shippingCountries);
        self::assertSame('€5 domestic, €8 EU', $settings->shippingCost);
    }

    public function testSavingSettingsWithEmptyShippingCountries(): void
    {
        $this->messageBus->dispatch(
            new EditSellSwapListSettings(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                description: 'Test',
                currency: null,
                customCurrency: null,
                shippingInfo: null,
                contactInfo: null,
                shippingCountries: [],
                shippingCost: null,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_WITH_STRIPE);
        $settings = $player->sellSwapListSettings;

        self::assertNotNull($settings);
        self::assertSame([], $settings->shippingCountries);
        self::assertNull($settings->shippingCost);
    }

    public function testExistingSettingsWithoutNewFieldsLoadCorrectly(): void
    {
        // First save settings without new fields (simulating old data)
        $this->messageBus->dispatch(
            new EditSellSwapListSettings(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                description: 'Old settings',
                currency: 'CZK',
                customCurrency: null,
                shippingInfo: 'Ships via post',
                contactInfo: 'old@example.com',
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_WITH_STRIPE);
        $settings = $player->sellSwapListSettings;

        self::assertNotNull($settings);
        self::assertSame('Old settings', $settings->description);
        self::assertSame([], $settings->shippingCountries);
        self::assertNull($settings->shippingCost);
    }
}
