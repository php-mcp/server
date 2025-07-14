<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude([
        'examples',
        'vendor',
        'tests/Mocks',
    ])
    ->in(__DIR__);

return (new PhpCsFixer\Config)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
