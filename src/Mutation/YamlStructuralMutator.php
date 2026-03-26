<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

final class YamlStructuralMutator {
    private RNG $rng;

    /** @var array<string, float|int> */
    private array $strategyWeights;

    /**
     * @param array<string, float|int>|null $strategyWeights
     */
    public function __construct(RNG $rng, ?array $strategyWeights = null) {
        $this->rng = $rng;
        $this->strategyWeights = $strategyWeights ?? [
            'mutateKey' => 1.0,
            'mutateIndentation' => 1.0,
            'mutateNode' => 1.0,
            'mutateValueType' => 1.0,
            'mutateSpecialChars' => 1.0,
        ];
    }

    /**
     * Заменяет имя случайного ключа на другой из словаря.
     */
    public function mutateKey(string $input): string {
        $lines = preg_split('/\R/u', $input) ?: [];
        $candidates = [];
        foreach ($lines as $idx => $line) {
            if (preg_match('/^(\s*)([^:\s][^:]*)(\s*:\s*.*)$/', $line, $matches) === 1) {
                $candidates[] = [$idx, $matches[1], $matches[3]];
            }
        }
        if (empty($candidates)) {
            return $input;
        }

        $replacementKeys = ['name', 'id', 'type', 'enabled', 'value', 'items', 'metadata', 'config'];
        [$lineIdx, $indent, $suffix] = $this->rng->randomElement($candidates);
        $lines[$lineIdx] = $indent . $this->rng->randomElement($replacementKeys) . $suffix;
        return implode("\n", $lines);
    }

    /**
     * Изменяет отступ случайной строки на ±1-2 пробела, кроме первой строки.
     */
    public function mutateIndentation(string $input): string {
        $lines = preg_split('/\R/u', $input) ?: [];
        if (count($lines) < 2) {
            return $input;
        }

        $candidates = [];
        foreach ($lines as $idx => $line) {
            if ($idx === 0 || trim($line) === '') {
                continue;
            }
            $candidates[] = $idx;
        }
        if (empty($candidates)) {
            return $input;
        }

        $lineIdx = $this->rng->randomElement($candidates);
        preg_match('/^(\s*)/', $lines[$lineIdx], $matches);
        $currentIndent = strlen($matches[1] ?? '');
        $delta = $this->rng->randomIntRange(1, 2);
        if ($this->rng->randomBool()) {
            $newIndent = $currentIndent + $delta;
        } else {
            $newIndent = max(0, $currentIndent - $delta);
        }

        $lines[$lineIdx] = str_repeat(' ', $newIndent) . ltrim($lines[$lineIdx]);
        return implode("\n", $lines);
    }

    /**
     * Вставляет или удаляет валидно выглядящий YAML-узел.
     */
    public function mutateNode(string $input): string {
        $lines = preg_split('/\R/u', $input) ?: [];
        if (empty($lines)) {
            return "- item: value";
        }

        if ($this->rng->randomBool() && count($lines) > 1) {
            $lineIdx = $this->rng->randomInt(count($lines));
            unset($lines[$lineIdx]);
            return implode("\n", array_values($lines));
        }

        $nodeOptions = [
            "- item: value",
            "- flag: true",
            "child:\n  key: value",
            "count: 0",
            "values:\n  - one\n  - two",
        ];
        $node = $this->rng->randomElement($nodeOptions);
        $insertPos = $this->rng->randomInt(count($lines) + 1);
        array_splice($lines, $insertPos, 0, explode("\n", $node));
        return implode("\n", $lines);
    }

    /**
     * Заменяет тип значения у случайного key: value.
     */
    public function mutateValueType(string $input): string {
        $lines = preg_split('/\R/u', $input) ?: [];
        $candidates = [];
        foreach ($lines as $idx => $line) {
            if (preg_match('/^(\s*[^:\n]+:\s*)(.+)$/', $line, $matches) === 1) {
                $candidates[] = [$idx, $matches[1]];
            }
        }
        if (empty($candidates)) {
            return $input;
        }

        $values = ['"text"', '42', 'true', 'false', 'null', '[1, 2, 3]'];
        [$lineIdx, $prefix] = $this->rng->randomElement($candidates);
        $lines[$lineIdx] = $prefix . $this->rng->randomElement($values);
        return implode("\n", $lines);
    }

    /**
     * Мутирует YAML-спецсимволы : - | > & * в случайной строке.
     */
    public function mutateSpecialChars(string $input): string {
        if ($input === '') {
            return $input;
        }

        $search = [':', '-', '|', '>', '&', '*'];
        $replace = ['-', ':', '>', '|', '*', '&'];
        $mutated = str_replace($search, $replace, $input, $count);
        if ($count > 0) {
            return $mutated;
        }

        $lines = preg_split('/\R/u', $input) ?: [];
        if (empty($lines)) {
            return $input;
        }
        $lineIdx = $this->rng->randomInt(count($lines));
        $special = $this->rng->randomElement($search);
        $lines[$lineIdx] = $special . ' ' . $lines[$lineIdx];
        return implode("\n", $lines);
    }

    /**
     * Выбирает стратегию по весам и применяет ее.
     */
    public function mutate(string $input): string {
        $strategyName = $this->rng->weightedRandomKey($this->strategyWeights);
        if ($strategyName === null || !method_exists($this, $strategyName)) {
            return $input;
        }

        /** @var string $result */
        $result = $this->{$strategyName}($input);
        return $result;
    }
}
