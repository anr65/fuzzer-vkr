<?php declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

$config->setTarget(static function (string $input): void {
    if ($input === 'timeout') {
        while (true) {
        }
    }

    require_once __DIR__ . '/coverage_metadata_subject.php';
    PhpFuzzer\TestFixture\exerciseCoverageMetadata($input);
});
