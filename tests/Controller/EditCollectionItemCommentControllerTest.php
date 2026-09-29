<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CollectionItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditCollectionItemCommentControllerTest extends WebTestCase
{
    public function testOwnerGetsTheFormKeptOutOfTheIndex(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/edit-collection-item-comment/' . CollectionItemFixture::ITEM_01);

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(1, $crawler->filter('textarea[name="edit_collection_item_comment_form[comment]"]'));
    }

    /**
     * What crawlers got for every collection item they found: a redirect to sign in
     * (robots.txt disallows these URLs now).
     */
    public function testGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/edit-collection-item-comment/' . CollectionItemFixture::ITEM_01);

        $this->assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }
}
