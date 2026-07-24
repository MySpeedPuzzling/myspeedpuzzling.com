<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;

return App::config([
    'symfonycasts_reset_password' => [
        'request_password_repository' => ResetPasswordRequestRepository::class,
    ],
]);
