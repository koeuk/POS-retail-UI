<?php

/*
 * Published so the page path can be corrected.
 *
 * The package default is resource_path('js/Pages') with a capital P, but this
 * starter kit puts pages in a lowercase `js/pages` directory. Without the
 * override, assertInertia()->component() fails with "Inertia page component
 * file does not exist" for every page in the test suite.
 */

$pagePaths = [
    resource_path('js/pages'),
];

$pageExtensions = [
    'js',
    'jsx',
    'svelte',
    'ts',
    'tsx',
    'vue',
];

return [

    'ssr' => [
        'enabled' => false,
        'url' => 'http://127.0.0.1:13714',
    ],

    'ensure_pages_exist' => false,

    'page_paths' => $pagePaths,

    'page_extensions' => $pageExtensions,

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => $pagePaths,

        'page_extensions' => $pageExtensions,

    ],

];
