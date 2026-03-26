<?php

/** @var PhpFuzzer\Config $config */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

function target(string $input): void {
    try {
        Yaml::parse($input);
    } catch (\Throwable $e) {
        // Swallow the error — only coverage matters
    }
}

function fuzz_entry(string $input): void {
    target($input);
}

// Set the fuzzing target
$config->setTarget(function(string $input) {
    target($input);
});
