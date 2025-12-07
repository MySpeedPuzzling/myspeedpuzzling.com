<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'dama_doctrine_test' => [
        'enable_static_connection' => true,
        'enable_static_meta_data_cache' => true,
        'enable_static_query_cache' => true,
    ],
]);
