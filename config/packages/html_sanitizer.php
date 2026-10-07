<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

// Organiser-written text of the event, edition and series page sections
// (docs/features/competitions-management/public-page.md). The security boundary: applied by the handlers on every
// write, the editor's own rules are only convenience. A strict allow-list - no id, class or style on anything (an
// organiser's id could clobber an element of the page, e.g. Turbo's modal frame), links open like the page's other
// external links, pictures only from our own image hosts.
return App::config([
    'framework' => [
        'html_sanitizer' => [
            'sanitizers' => [
                'competition_page' => [
                    'allow_elements' => [
                        'h2' => [],
                        'h3' => [],
                        'h4' => [],
                        'p' => [],
                        'br' => [],
                        'strong' => [],
                        'b' => [],
                        'em' => [],
                        'i' => [],
                        'u' => [],
                        's' => [],
                        'ul' => [],
                        'ol' => [],
                        'li' => [],
                        'blockquote' => [],
                        'a' => ['href', 'title'],
                        'img' => ['src', 'alt'],
                    ],
                    'force_attributes' => [
                        'a' => [
                            'rel' => 'noopener noreferrer nofollow ugc',
                            'target' => '_blank',
                        ],
                    ],
                    'allowed_link_schemes' => ['http', 'https', 'mailto'],
                    'allowed_media_schemes' => ['http', 'https'],
                    'allowed_media_hosts' => [
                        '%env(string:key:host:url:NGINX_PROXY_BASE_URL)%',
                        '%env(string:key:host:url:UPLOADS_BASE_URL)%',
                    ],
                    // = PageSectionContentSanitizer::MAX_HTML_LENGTH - the form refuses longer text before it gets here
                    'max_input_length' => 100000,
                ],
            ],
        ],
    ],
]);
