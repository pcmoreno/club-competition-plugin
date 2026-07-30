<?php

// Finder::in() throws on a missing directory, so only pass through what exists.
// tests/ is not tracked (git can't carry empty directories), so it's present on
// some working copies and absent on a fresh clone — listing it unconditionally
// made `composer lint` fail for everyone who hadn't created it by hand.
$paths = array_values(array_filter(
    [
        __DIR__ . '/src',
        __DIR__ . '/includes',
        __DIR__ . '/tests',
    ],
    'is_dir'
));

$finder = PhpCsFixer\Finder::create()
    ->in($paths)
    ->append([__DIR__ . '/club-competition-plugin.php'])
    ->exclude('vendor');

$config = new PhpCsFixer\Config();
return $config
    // declare_strict_types is classed as risky (it changes runtime behaviour, by
    // design here — CLAUDE.md requires it on every file). Without this the whole
    // run refuses to start rather than skipping the one rule.
    ->setRiskyAllowed(true)
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
