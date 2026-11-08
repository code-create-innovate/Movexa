<?php

return [
    'ssr' => ['enabled' => false, 'url' => 'http://127.0.0.1:13714'],
    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/pages')],
        'page_extensions' => ['tsx', 'ts', 'jsx', 'js'],
    ],
    'history' => ['encrypt' => false],
];
