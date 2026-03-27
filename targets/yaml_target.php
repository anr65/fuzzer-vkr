<?php

/** @var PhpFuzzer\Config $config */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$config->setTarget(function (string $input) use ($config) {
    try {
        $yc = $config->getYamlCache();
        if ($yc !== null) {
            $yc->getOrParse($input);
        } else {
            Yaml::parse($input);
        }
    } catch (\Throwable $e) {
        // Swallow the error — only coverage matters
    }
});
