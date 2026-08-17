<?php declare(strict_types=1);

namespace PhpFuzzer\TestFixture;

function exerciseCoverageMetadata(string $input): int {
    if ($input === 'covered') {
        return 1;
    }
    return 0;
}
