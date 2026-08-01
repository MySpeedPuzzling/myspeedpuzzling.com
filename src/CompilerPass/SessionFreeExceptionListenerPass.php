<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Stops the main firewall storing the requested URL in the session before it
 * hands over to the entry point. LoginEntryPoint carries that destination in
 * `?return=` instead, which makes the session write pure cost - and a large one.
 *
 * A protected URL is reachable by anyone, crawlers included, and this codebase
 * has 332 IsGranted/denyAccessUnlessGranted sites. Every anonymous hit on one
 * wrote a row into the Postgres-backed session store and set a cookie:
 * production's sessions table reached 3,434,070 rows / 1.8 GB, of which a sample
 * found 68,450 of 68,524 anonymous, holding nothing but _security.main.target_path.
 *
 * The lever is ExceptionListener's own $stateless constructor flag, which guards
 * exactly one thing - the setTargetPath() call in startAuthentication():
 *
 *     if (!$this->stateless) {
 *         $this->setTargetPath($request);
 *     }
 *
 * It is the whole use of that property, so flipping it here suppresses the
 * target-path write and nothing else. The firewall itself stays stateful:
 * `stateless` is not set in security.php, and sessions still work normally for
 * everyone who is actually signed in.
 *
 * Preferred over subclassing ExceptionListener and overriding setTargetPath():
 * that class is @final, and copying it would freeze a copy of framework code
 * that quietly drifts from upstream.
 */
final class SessionFreeExceptionListenerPass implements CompilerPassInterface
{
    private const string LISTENER_ID = 'security.exception_listener.main';

    /** Position of $stateless in ExceptionListener's constructor. */
    private const int STATELESS_ARGUMENT = 8;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::LISTENER_ID)) {
            return;
        }

        $container->getDefinition(self::LISTENER_ID)
            ->replaceArgument(self::STATELESS_ARGUMENT, true);
    }
}
