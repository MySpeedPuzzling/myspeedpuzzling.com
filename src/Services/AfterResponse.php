<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Side work of a request that its answer must not wait for - run at kernel.terminate, after the response went to the
 * client (FrankenPHP's worker runner sends it, fastcgi_finish_request(), then terminates the kernel; the dev server and the
 * test client terminate right after the response too). Reliability hardening T4 (competitor-pages.md "Load"): the live
 * entry's save and a competitor's proposal answer as soon as the write is committed, the Mercure pushes to the other
 * devices follow - a hub that does not answer (up to the 3 s of mercure_hub.client per publish) no longer holds back a
 * judge's "saved". The order a page relies on stays: the work was deferred after the dispatch returned, i.e. after the
 * commit, so a push still never arrives before the data it announces; a lost push costs nothing (the pages refetch).
 *
 * Only for work that may fail or never happen without anybody learning of it (each callable runs on its own, a throw is
 * logged as a warning). Nothing deferred survives the request: reset() drops what a request that never terminated left.
 */
final class AfterResponse implements EventSubscriberInterface, ResetInterface
{
    /** @var list<callable(): void> */
    private array $work = [];

    public function __construct(
        readonly private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    /**
     * @param callable(): void $work
     */
    public function defer(callable $work): void
    {
        $this->work[] = $work;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->run();
    }

    /**
     * Runs what is deferred now, in the order it was deferred.
     */
    public function run(): void
    {
        while ($this->work !== []) {
            $work = array_shift($this->work);

            try {
                $work();
            } catch (\Throwable $exception) {
                $this->logger->warning('Work deferred after the response failed', [
                    'exception' => $exception,
                ]);
            }
        }
    }

    public function reset(): void
    {
        $this->work = [];
    }
}
