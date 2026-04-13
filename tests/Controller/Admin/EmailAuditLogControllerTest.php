<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmailAuditLogControllerTest extends WebTestCase
{
    public function testListIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/email-audit');

        $this->assertResponseRedirects('/login');
    }

    public function testDetailIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/email-audit/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects('/login');
    }
}
