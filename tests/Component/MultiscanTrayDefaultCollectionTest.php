<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\MultiscanTray;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Message\ChangeDefaultCollection;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The tray's target collection starts with the member's default (docs/features/collections/default-collection.md);
 * a ?collection= preset and a restored tray still win.
 */
final class MultiscanTrayDefaultCollectionTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testWithoutADefaultTheSystemCollection(): void
    {
        $client = self::createClient();

        self::assertSame(Collection::SYSTEM_ID, $this->collectionOf($this->tray($client)));
    }

    public function testTheDefaultUnlessAPresetOrARestoredTraySaysOtherwise(): void
    {
        $client = self::createClient();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_STRIPE_TREFL));

        self::assertSame(CollectionFixture::COLLECTION_STRIPE_TREFL, $this->collectionOf($this->tray($client)));
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $this->collectionOf($this->tray($client, ['presetCollectionId' => CollectionFixture::COLLECTION_PUBLIC])));
        self::assertSame(Collection::SYSTEM_ID, $this->collectionOf($this->tray($client, ['presetCollectionId' => Collection::SYSTEM_ID])));

        $tray = $this->tray($client);
        $tray->call('restore', ['state' => (string) json_encode(['rows' => [], 'collectionId' => CollectionFixture::COLLECTION_PUBLIC])]);
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $this->collectionOf($tray));
    }

    public function testAMoveDoesNotStartWithTheDefaultAsItsTarget(): void
    {
        $client = self::createClient();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_STRIPE_TREFL));

        // "Scan to move or remove" of the default collection's page
        $tray = $this->tray($client, ['presetAction' => 'move_to_collection', 'presetSourceCollectionId' => CollectionFixture::COLLECTION_STRIPE_TREFL]);
        self::assertSame(Collection::SYSTEM_ID, $this->collectionOf($tray));
    }

    public function testANonMembersDefaultIsIgnored(): void
    {
        $client = self::createClient();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeDefaultCollection(PlayerFixture::PLAYER_REGULAR, CollectionFixture::COLLECTION_PRIVATE));

        self::assertSame(Collection::SYSTEM_ID, $this->collectionOf($this->tray($client, playerId: PlayerFixture::PLAYER_REGULAR)));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function tray(KernelBrowser $client, array $data = [], string $playerId = PlayerFixture::PLAYER_WITH_STRIPE): TestLiveComponent
    {
        TestingLogin::asPlayer($client, $playerId);
        $component = $this->createLiveComponent('MultiscanTray', $data, $client);
        $component->setRouteLocale('en');

        return $component;
    }

    private function collectionOf(TestLiveComponent $tray): string
    {
        $component = $tray->component();
        self::assertInstanceOf(MultiscanTray::class, $component);

        return $component->collectionId;
    }
}
