<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/public')
    ->in(__DIR__ . '/database')
    ->in(__DIR__ . '/scripts')
    ->in(__DIR__ . '/tests')
    ->notPath('public/adminer.php')
    ->notPath('_dev')
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'declare_strict_types' => false,
        'blank_line_after_opening_tag' => true,
        'single_class_element_per_statement' => true,
        'visibility_required' => ['elements' => ['method', 'property']],
        'no_extra_blank_lines' => true,
        'return_type_declaration' => ['space_before' => 'none'],
    ])
    ->setFinder($finder)
;
