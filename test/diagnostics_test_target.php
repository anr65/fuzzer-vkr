<?php

/** @var PhpFuzzer\Config $config */

// Simple test target with predictable branching for diagnostics validation
$config->setTarget(function(string $input) {
    $len = strlen($input);
    
    // Branch 1: Check length
    if ($len > 10) {
        // Branch 2: Check first character
        if ($input[0] === 'A') {
            // Branch 3: Check if contains digit
            if (preg_match('/\d/', $input)) {
                // Branch 4: Check if contains letter
                if (preg_match('/[a-z]/', $input)) {
                    // Deep branch
                    return;
                }
            }
        }
    }
    
    // Branch 5: Check for specific string
    if (strpos($input, 'test') !== false) {
        // Branch 6: Check for another string
        if (strpos($input, 'data') !== false) {
            return;
        }
    }
    
    // Default path
    return;
});

$config->setMaxLen(100);

