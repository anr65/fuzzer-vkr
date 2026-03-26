<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use Symfony\Component\Yaml\Yaml;

/**
 * Selects mutators from YAML structure and returns optional target ranges.
 */
final class StructuralMutatorSelector {
    private RNG $rng;

    public function __construct(RNG $rng) {
        $this->rng = $rng;
    }

    public function select(string $input): StructuralSelectionResult {
        try {
            $parsed = Yaml::parse($input);
        } catch (\Throwable $e) {
            return new StructuralSelectionResult($this->fallbackMutator(), null, null, false);
        }

        $nodes = [];
        $this->collectNodes($parsed, $nodes);
        if ($nodes === []) {
            return new StructuralSelectionResult($this->fallbackMutator(), null, null, true);
        }

        $node = $this->rng->randomElement($nodes);
        $mutator = $this->weightedPick($node['type']);
        [$offset, $length] = $this->findApproximateRange($input, $node);
        return new StructuralSelectionResult($mutator, $offset, $length, true);
    }

    public function selectMutator(string $input): string {
        return $this->select($input)->mutatorName;
    }

    /**
     * @param mixed $node
     * @param list<array{type:string,key:string,value:mixed}> $out
     */
    private function collectNodes($node, array &$out): void {
        if (is_array($node)) {
            $isList = array_keys($node) === range(0, count($node) - 1);
            if ($isList) {
                foreach ($node as $value) {
                    $out[] = ['type' => 'SEQUENCE_ITEM', 'key' => '', 'value' => $value];
                    $this->collectNodes($value, $out);
                }
            } else {
                $out[] = ['type' => 'MAPPING_VALUE', 'key' => '', 'value' => $node];
                foreach ($node as $key => $value) {
                    if (is_string($key)) {
                        $out[] = ['type' => 'KEY', 'key' => $key, 'value' => $value];
                    }
                    $out[] = ['type' => $this->scalarType($value), 'key' => (string) $key, 'value' => $value];
                    $this->collectNodes($value, $out);
                }
            }
            return;
        }
        $out[] = ['type' => $this->scalarType($node), 'key' => '', 'value' => $node];
    }

    /**
     * @param mixed $value
     */
    private function scalarType($value): string {
        if (is_string($value)) {
            return 'SCALAR_STRING';
        }
        if (is_int($value)) {
            return 'SCALAR_INT';
        }
        if (is_float($value)) {
            return 'SCALAR_FLOAT';
        }
        if (is_bool($value)) {
            return 'SCALAR_BOOL';
        }
        if ($value === null) {
            return 'SCALAR_NULL';
        }
        return 'MAPPING_VALUE';
    }

    /**
     * @param array{type:string,key:string,value:mixed} $node
     * @return array{?int, ?int}
     */
    private function findApproximateRange(string $input, array $node): array {
        if ($node['type'] === 'KEY' && $node['key'] !== '') {
            $needle = $node['key'] . ':';
            $offset = strpos($input, $needle);
            return [$offset === false ? null : $offset, $offset === false ? null : strlen($node['key'])];
        }

        $value = $node['value'];
        if (is_scalar($value) || $value === null) {
            $needle = is_bool($value) ? ($value ? 'true' : 'false') : ($value === null ? 'null' : (string) $value);
            if ($needle !== '') {
                $offset = strpos($input, $needle);
                if ($offset !== false) {
                    return [$offset, strlen($needle)];
                }
            }
        }
        return [null, null];
    }

    private function weightedPick(string $type): string {
        return match ($type) {
            'KEY' => $this->pick(['AddWordFromManualDictionary' => 0.5, 'ChangeByte' => 0.3, 'ShuffleBytes' => 0.2]),
            'SCALAR_INT' => $this->pick(['ChangeASCIIInt' => 0.4, 'ChangeBinInt' => 0.4, 'InsertByte' => 0.2]),
            'SCALAR_STRING' => $this->pick(['AddWordFromManualDictionary' => 0.4, 'InsertRepeatedBytes' => 0.3, 'EraseBytes' => 0.3]),
            'SCALAR_BOOL', 'SCALAR_NULL' => $this->pick(['ChangeByte' => 0.6, 'InsertByte' => 0.4]),
            'SEQUENCE_ITEM' => $this->pick(['CopyPart' => 0.5, 'CrossOver' => 0.3, 'EraseBytes' => 0.2]),
            'MAPPING_VALUE' => $this->pick(['CrossOver' => 0.5, 'CopyPart' => 0.3, 'ShuffleBytes' => 0.2]),
            default => $this->fallbackMutator(),
        };
    }

    /**
     * @param array<string,float> $weights
     */
    private function pick(array $weights): string {
        $value = mt_rand() / mt_getrandmax();
        $acc = 0.0;
        foreach ($weights as $name => $weight) {
            $acc += $weight;
            if ($value <= $acc) {
                return $name;
            }
        }
        return array_key_last($weights);
    }

    private function fallbackMutator(): string {
        return $this->rng->randomElement([
            'EraseBytes',
            'InsertByte',
            'InsertRepeatedBytes',
            'ChangeByte',
            'ChangeBit',
            'ShuffleBytes',
            'ChangeASCIIInt',
            'ChangeBinInt',
            'CopyPart',
            'CrossOver',
            'AddWordFromManualDictionary',
        ]);
    }
}

