<?php

namespace SpeedPuzzling\Web;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class SymfonyApplicationKernel extends BaseKernel
{
    use MicroKernelTrait;
}
