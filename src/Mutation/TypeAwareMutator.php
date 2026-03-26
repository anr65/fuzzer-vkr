<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

/**
 * Type-driven scalar mutator for YAML scalar content.
 */
final class TypeAwareMutator implements MutatorInterface {
    public function mutate(string $input, \Random\Randomizer $r): string {
        return $this->mutateScalar($input, $r);
    }

    public function mutateScalar(string $value, \Random\Randomizer $r): string {
        if (\preg_match('/^-?\d+$/', $value) === 1) {
            return $this->mutateInteger((int) $value, $r);
        }
        if (\preg_match('/^-?\d+\.\d+$/', $value) === 1) {
            return $this->mutateFloat((float) $value, $r);
        }
        if (\preg_match('/^(true|false|yes|no|on|off)$/i', $value) === 1) {
            return $this->mutateBoolean($value, $r);
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '~' || strtolower($trimmed) === 'null') {
            return $this->mutateNull($r);
        }
        return $this->mutateString($value, $r);
    }

    private function mutateInteger(int $value, \Random\Randomizer $r): string {
        $choices = [
            0,
            -1,
            1,
            PHP_INT_MAX,
            PHP_INT_MIN,
            2147483647,
            -2147483648,
            $value + 1,
            $value - 1,
            $value * 2,
            abs($value),
            ~$value,
        ];
        return (string) $choices[$r->getInt(0, count($choices) - 1)];
    }

    private function mutateFloat(float $value, \Random\Randomizer $r): string {
        $choices = [
            0.0,
            -0.0,
            INF,
            -INF,
            NAN,
            PHP_FLOAT_EPSILON,
            round($value, 0),
            floor($value),
            ceil($value),
        ];
        $selected = $choices[$r->getInt(0, count($choices) - 1)];
        if (is_nan($selected)) {
            return 'NAN';
        }
        if ($selected === INF) {
            return 'INF';
        }
        if ($selected === -INF) {
            return '-INF';
        }
        return (string) $selected;
    }

    private function mutateBoolean(string $value, \Random\Randomizer $r): string {
        $lower = strtolower($value);
        $truthy = in_array($lower, ['true', 'yes', 'on'], true);
        $choices = [$truthy ? 'false' : 'true', '0', '1', 'yes', 'no', 'true', 'false'];
        return $choices[$r->getInt(0, count($choices) - 1)];
    }

    private function mutateNull(\Random\Randomizer $r): string {
        $choices = ['', '0', 'false', '[]', 'null', '~'];
        return $choices[$r->getInt(0, count($choices) - 1)];
    }

    private function mutateString(string $value, \Random\Randomizer $r): string {
        $options = [
            static fn(string $v): string => strtoupper($v),
            static fn(string $v): string => strtolower($v),
            static fn(string $v): string => str_repeat($v, 2),
            static fn(string $v): string => strrev($v),
            static fn(string $v): string => ($v !== '' ? substr($v, 0, 1) : ''),
            static fn(string $v): string => $v . $v,
            static fn(string $v): string => chr(0) . $v,
            static fn(string $v): string => $v . "\n",
        ];
        $fn = $options[$r->getInt(0, count($options) - 1)];
        return $fn($value);
    }
}

