<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

// Enable stateless CSRF protection for forms and logins/logouts
return App::config([
    'framework' => [
        'form' => [
            'csrf_protection' => [
                'token_id' => 'submit',
            ],
        ],
        'csrf_protection' => [
            'stateless_token_ids' => [
                'submit',
                'authenticate',
                'logout',
                // Sign-in link request form: rendered on anonymous pages that must stay
                // session-free (#164), so its token id must not fall back to the
                // session-backed manager
                'sign_in_link',
            ],
        ],
    ],
]);
