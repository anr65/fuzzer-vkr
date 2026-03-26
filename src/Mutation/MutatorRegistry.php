<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

/**
 * Holds mutator weights and derives weighted pools.
 */
final class MutatorRegistry {
    /** @var array<string, float> */
    private array $weights = [];

    /**
     * @param list<string> $mutatorNames
     */
    public function __construct(array $mutatorNames) {
        foreach ($mutatorNames as $name) {
            $this->weights[$name] = 1.0;
        }
    }

    public function setWeight(string $name, float $weight): void {
        if (!isset($this->weights[$name])) {
            return;
        }
        $this->weights[$name] = max(0.0, $weight);
    }

    /**
     * @return array<string, float>
     */
    public function getWeights(): array {
        return $this->weights;
    }

    /**
     * @return list<string>
     */
    public function getPool(): array {
        return array_keys(array_filter($this->weights, static fn(float $w): bool => $w > 0.0));
    }

    /**
     * @param list<string> $preferred
     * @return list<string>
     */
    public function filterPoolByStructuralType(array $preferred): array {
        $preferredSet = array_fill_keys($preferred, true);
        $filtered = [];
        foreach ($this->getPool() as $name) {
            if (isset($preferredSet[$name])) {
                $filtered[] = $name;
            }
        }
        return $filtered ?: $this->getPool();
    }
}

