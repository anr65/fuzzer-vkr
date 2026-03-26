<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

final class YamlSeedClassifier {
    public function classify(string $seedInput): string {
        $trimmed = trim($seedInput);
        if ($trimmed === '') {
            return 'corrupted';
        }

        $hasUnbalancedFlow = (substr_count($seedInput, '[') !== substr_count($seedInput, ']'))
            || (substr_count($seedInput, '{') !== substr_count($seedInput, '}'));
        $hasTabs = preg_match('/^\t+/m', $seedInput) === 1;
        $hasDanglingColon = preg_match('/^\s*[^:\n]+:\s*$/m', $seedInput) === 1;
        if ($hasUnbalancedFlow || $hasTabs || $hasDanglingColon) {
            return 'corrupted';
        }

        $lineCount = max(1, substr_count($seedInput, "\n") + 1);
        $sequenceLines = preg_match_all('/^\s*-\s+/m', $seedInput);
        $mappingLines = preg_match_all('/^\s*[^#\n][^:\n]*:\s*.+$/m', $seedInput);
        $deepIndentLines = preg_match_all('/^( {2,}|\t+)/m', $seedInput);
        $hasEdgeSyntax = preg_match('/[&*]|^\s*---|^\s*\.\.\.|[|>]\s*$/m', $seedInput) === 1;
        $hasFlow = str_contains($seedInput, '[') || str_contains($seedInput, '{');

        if ($hasEdgeSyntax || $hasFlow) {
            return 'edge_syntax';
        }

        if ($sequenceLines > 0 && $mappingLines > 0) {
            return 'mixed_structure';
        }

        if ($mappingLines > 0 && $deepIndentLines > 0) {
            return 'nested_mapping';
        }

        if ($sequenceLines > 0 && $deepIndentLines <= ($lineCount / 3)) {
            return 'flat_sequence';
        }

        if ($mappingLines === 0 && $sequenceLines === 0) {
            // Scalar-like values still require some shape sanity.
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $seedInput) === 1) {
                return 'corrupted';
            }
            return 'scalar';
        }

        return 'corrupted';
    }
}
