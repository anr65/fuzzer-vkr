<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use PhpFuzzer\Corpus;
use Symfony\Component\Yaml\Yaml;

/**
 * Structure-aware YAML crossover with byte-level fallback.
 */
final class StructuralCrossOver implements MutatorInterface {
    private Corpus $corpus;
    private RNG $rng;

    /** @var callable */
    private $fallback;
    private bool $lastValid = false;

    /**
     * @param callable $fallback fn(string $primary, string $donor): string
     */
    public function __construct(Corpus $corpus, RNG $rng, callable $fallback) {
        $this->corpus = $corpus;
        $this->rng = $rng;
        $this->fallback = $fallback;
    }

    public function mutate(string $input, \Random\Randomizer $r): string {
        $this->lastValid = false;
        $donorEntry = $this->corpus->getRandomEntry($this->rng);
        $donor = $donorEntry?->input;
        if ($donor === null || $donor === '') {
            return $input;
        }

        try {
            $a = Yaml::parse($input);
            $b = Yaml::parse($donor);
        } catch (\Throwable $e) {
            return ($this->fallback)($input, $donor);
        }

        if (is_array($a) && is_array($b) && $this->isAssoc($a) && $this->isAssoc($b)) {
            $shared = array_values(array_intersect(array_keys($a), array_keys($b)));
            if ($shared !== []) {
                $key = $shared[$r->getInt(0, count($shared) - 1)];
                $a[$key] = $b[$key];
                $this->lastValid = true;
                return Yaml::dump($a);
            }
        }

        if (is_array($a) && is_array($b) && !$this->isAssoc($a) && !$this->isAssoc($b)) {
            $short = min(count($a), count($b));
            if ($short > 0) {
                $index = $r->getInt(0, $short - 1);
                $sliceLen = max(1, min(3, count($b) - $index));
                $slice = array_slice($b, $index, $sliceLen);
                $result = array_merge(array_slice($a, 0, $index), $slice, array_slice($a, $index));
                $this->lastValid = true;
                return Yaml::dump($result);
            }
        }

        return ($this->fallback)($input, $donor);
    }

    public function wasLastValid(): bool {
        return $this->lastValid;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isAssoc(array $arr): bool {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

