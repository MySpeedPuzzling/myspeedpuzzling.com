<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The baseline of every navigation endpoint budget (implementation plan §3.7, created by MENU-01, reused by CARD-06):
 * endpoint budgets are "≤ N statements more than an empty signed-in endpoint measured in the same test", because a
 * budget test counts every statement of a request. The empty endpoint is the Help sheet's frame - `GET /en/help` with
 * `Turbo-Frame: modal-frame` (no layout, no chrome) - and it is checked to run exactly the user refresh and the
 * viewer's profile row before it is used as the baseline.
 *
 * The using test class uses SpeedPuzzling\Web\Tests\QueryCountAssertions too (this trait calls its methods).
 */
trait EmptySignedInEndpoint
{
    /**
     * @return list<string> the statements of the empty signed-in endpoint (the second request; the first warms caches)
     */
    protected function emptySignedInEndpointStatements(KernelBrowser $browser): array
    {
        $browser->request('GET', '/en/help', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/help', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful();

        $statements = $this->executedSql($browser);

        self::assertCount(2, $statements, "The empty signed-in endpoint is no longer empty:\n" . implode("\n", $statements));
        self::assertStringContainsString('user_account', $statements[0], 'The user refresh comes first');
        self::assertStringContainsString('WHERE player.user_id = ?', $statements[1], 'Then the viewer\'s profile row');

        return $statements;
    }
}
