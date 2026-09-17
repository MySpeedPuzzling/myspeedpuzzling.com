<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ClaimVoucherControllerTest extends WebTestCase
{
    public function testClaimVoucherPageIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/claim-voucher');

        $this->assertResponseRedirects('/login?return=/en/claim-voucher');
    }

    public function testClaimVoucherPageCzechLocale(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/uplatnit-voucher');

        $this->assertResponseRedirects('/login?return=/uplatnit-voucher');
    }

    public function testClaimVoucherPageGermanLocale(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/de/gutschein-einloesen');

        $this->assertResponseRedirects('/login?return=/de/gutschein-einloesen');
    }

    /**
     * Turbo Drive discards a 200 answer to a form submission, so a claim that
     * renders its success page instead of redirecting looks like nothing happened.
     */
    public function testSuccessfulClaimRedirectsToMembership(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/claim-voucher');
        $browser->submitForm('Claim voucher', [
            'claim_voucher_form[code]' => VoucherFixture::VOUCHER_AVAILABLE_CODE,
        ]);

        $this->assertResponseRedirects('/en/membership');

        $browser->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Voucher claimed! 1 free month has been added to your membership.');

        // The membership page spells out what the voucher covers and that nothing gets charged
        $this->assertSelectorTextContains('.card-body', 'Free months from your voucher');
        $this->assertSelectorTextContains('.card-body', VoucherFixture::VOUCHER_AVAILABLE_CODE);
        $this->assertSelectorTextContains('.card-body', 'nothing will be charged');

        // No Stripe customer, nothing to manage - the portal link would only bounce back to this page
        $this->assertSelectorNotExists('a[href$="/billing-portal"]');
    }

    public function testReclaimingOwnVoucherReassuresInsteadOfFailing(): void
    {
        $browser = self::createClient();
        // VOUCHER_USED was redeemed by PLAYER_REGULAR
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/claim-voucher');
        $browser->submitForm('Claim voucher', [
            'claim_voucher_form[code]' => strtolower(VoucherFixture::VOUCHER_USED_CODE),
        ]);

        $this->assertResponseRedirects('/en/membership');

        $browser->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'voucher ' . VoucherFixture::VOUCHER_USED_CODE . ' is already applied to your account');
    }

    public function testRejectedClaimRendersErrorWithUnprocessableStatus(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/claim-voucher');
        $browser->submitForm('Claim voucher', [
            'claim_voucher_form[code]' => VoucherFixture::VOUCHER_USED_CODE,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form .alert-danger', 'This voucher has already been used on another account.');
    }

    public function testClaimPageIsTranslated(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/uplatnit-voucher');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Uplatnit voucher');
        $this->assertSelectorTextContains('label', 'Kód voucheru');
    }
}
