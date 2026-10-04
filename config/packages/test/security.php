<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Entity\UserAccount;

// Same algorithms as production, at the lowest cost each allows: at production cost
// every hash or verify takes tens of milliseconds and the suite does hundreds of them
// (registration, password changes, sign-ins). The entry replaces the production one
// as a whole (no deep merge), so it repeats migrate_from.
return App::config([
    'security' => [
        'password_hashers' => [
            UserAccount::class => [
                'algorithm' => 'argon2id',
                'migrate_from' => ['bcrypt'],
                'cost' => 4,
                'time_cost' => 3,
                'memory_cost' => 10,
            ],
        ],
    ],
]);
