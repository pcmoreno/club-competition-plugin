<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/includes',
        __DIR__ . '/tests',
    ])
    ->append([__DIR__ . '/club-competition-plugin.php'])
    ->exclude('vendor');

$config = new PhpCsFixer\Config();
return $config
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'blank_line_after_namespace' => true,
        'blank_line_before_statement' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'cast_spaces' => ['space' => 'none'],
    ])
    ->setFinder($finder);
