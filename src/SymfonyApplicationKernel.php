<?php

namespace SpeedPuzzling\Web;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class SymfonyApplicationKernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import(__DIR__ . '/../config/packages/*.php');
        $container->import(__DIR__ . '/../config/packages/'.$this->environment.'/*.php');

        $container->import(__DIR__ . '/../config/services.php');
        $container->import(__DIR__ . '/../config/{services}_'.$this->environment.'.php');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/../config/{routes}/'.$this->environment.'/*.php');
        $routes->import(__DIR__ . '/../config/{routes}/*.php');

        $routes->import(__DIR__ . '/../config/routes.php');
    }


    /**
     * @return iterable<Bundle>
     */
    public function registerBundles(): iterable
    {
        /** @var array<class-string<Bundle>, array<string>> $contents */
        $contents = require __DIR__ . '/../config/bundles.php';

        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }
}
