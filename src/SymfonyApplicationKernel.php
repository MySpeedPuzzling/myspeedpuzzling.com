<?php

namespace SpeedPuzzling\Web;

use SpeedPuzzling\Web\CompilerPass\SentryDisableShareHandleCompilerPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class SymfonyApplicationKernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new SentryDisableShareHandleCompilerPass());
    }
}
