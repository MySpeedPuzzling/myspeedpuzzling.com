<?php

namespace SpeedPuzzling\Web;

use SpeedPuzzling\Web\CompilerPass\SentryDisableShareHandleCompilerPass;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\Compiler\PhpConfigReferenceDumpPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class SymfonyApplicationKernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new SentryDisableShareHandleCompilerPass());

        // FrameworkBundle's PhpConfigReferenceDumpPass regenerates config/reference.php on
        // every debug container compile and registers it as a tracked container resource.
        // Its content is runtime-dependent: mercure-bundle's config tree branches on
        // function_exists('mercure_publish'), which exists in the FrankenPHP workers but
        // not in CLI PHP. CLI compiles (phpunit, bin/console) and worker compiles
        // (web/web-test) therefore keep rewriting the file with two alternating variants,
        // each rewrite invalidating the other runtime's compiled container - an endless
        // recompile ping-pong that also OOMs the Panther suite when recompiles land
        // mid-run. Drop the pass: config/reference.php stays as committed; to regenerate
        // it deliberately (e.g. after a Symfony upgrade), temporarily comment this out
        // and run bin/console cache:warmup.
        $passConfig = $container->getCompilerPassConfig();
        $passConfig->setBeforeOptimizationPasses(array_values(array_filter(
            $passConfig->getBeforeOptimizationPasses(),
            // @phpstan-ignore instanceof.internalClass (the pass has no public off-switch; worst case a rename makes the filter a no-op)
            static fn (CompilerPassInterface $pass): bool => !$pass instanceof PhpConfigReferenceDumpPass,
        )));
    }
}
