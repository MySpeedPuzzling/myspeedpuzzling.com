<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VoucherImageControllerTest extends WebTestCase
{
    public function testAvailableVoucherReturnsImage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/voucher/' . VoucherFixture::VOUCHER_AVAILABLE . '/image-1.png');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testAllVariantsWork(): void
    {
        $browser = self::createClient();

        foreach ([1, 2, 3, 4] as $variant) {
            $browser->request('GET', '/voucher/' . VoucherFixture::VOUCHER_AVAILABLE . '/image-' . $variant . '.png');

            $this->assertResponseIsSuccessful();
            $this->assertResponseHeaderSame('Content-Type', 'image/png');
        }
    }

    public function testPercentageAvailableVoucherReturnsImage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/voucher/' . VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE . '/image-2.png');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testUsedVoucherReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/voucher/' . VoucherFixture::VOUCHER_USED . '/image-1.png');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testExpiredVoucherReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/voucher/' . VoucherFixture::VOUCHER_EXPIRED . '/image-1.png');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testExpiredPercentageVoucherReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/voucher/' . VoucherFixture::VOUCHER_PERCENTAGE_EXPIRED . '/image-1.png');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPercentageVoucherMaxUsesReachedReturns404(): void
    {
        $browser = self::createClient();

        // This voucher has maxUses=1 and a claim exists in VoucherClaimFixture
        $browser->request('GET', '/voucher/' . VoucherFixture::VOUCHER_PERCENTAGE_MAX_USES_REACHED . '/image-1.png');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonExistentVoucherReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/voucher/00000000-0000-0000-0000-000000000000/image-1.png');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidUuidReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/voucher/not-a-uuid/image-1.png');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidVariantReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/voucher/' . VoucherFixture::VOUCHER_AVAILABLE . '/image-5.png');

        $this->assertResponseStatusCodeSame(404);
    }
}
