<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

final class MutationWeightManager {
    /** @var list<string> */
    private array $operatorIds;
    /** @var array<string, array<string, float>> */
    private array $weightsByClass = [];
    /** @var array<string, array<string, array{total_uses:int, interesting_count:int}>> */
    private array $statsByClass = [];
    /** @var callable */
    private $classifier;
    private string $policy;
    private float $alpha;
    private int $rebalancePeriod;
    private int $warmupIterations;
    private string $decayMode;
    private float $decayFactor;
    private float $minWeightFloor = 1e-6;
    private bool $weightsEverDiverged = false;
    /** @var array<string, int> */
    private array $iterationsByClass = [];

    /** @var array<string, float> */
    private array $baseWeights;

    /**
     * @param list<string> $operatorIds
     * @param array<string, float>|array<string, array<string, float>> $staticWeights
     */
    public function __construct(
        array $operatorIds,
        string $policy = 'uniform',
        array $staticWeights = [],
        float $alpha = 0.2,
        int $rebalancePeriod = 1000,
        ?int $warmupIterations = null,
        string $decayMode = 'reset',
        float $decayFactor = 0.5
    ) {
        if (empty($operatorIds)) {
            throw new \InvalidArgumentException('Operator ID list must not be empty');
        }
        $this->operatorIds = array_values($operatorIds);
        $this->policy = $policy;
        $this->alpha = $alpha;
        $this->rebalancePeriod = max(1, $rebalancePeriod);
        $this->warmupIterations = $warmupIterations ?? (2 * $this->rebalancePeriod);
        $this->decayMode = $decayMode;
        $this->decayFactor = $decayFactor;
        $this->classifier = static fn(string $seedInput): string => 'default';
        $this->baseWeights = $this->buildBaseWeights($staticWeights);
        $this->ensureClassInitialized('default');
    }

    public function classify(string $seedInput): string {
        $class = ($this->classifier)($seedInput);
        return $class === '' ? 'default' : $class;
    }

    /**
     * @param callable $classifier fn(string): string
     */
    public function setClassifier(callable $classifier): void {
        $this->classifier = $classifier;
    }

    public function select(string $seedClass, RNG $rng): string {
        $seedClass = $this->resolveClass($seedClass);
        $this->ensureClassInitialized($seedClass);
        $weights = $this->weightsByClass[$seedClass];
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0.0) {
            return $rng->randomElement($this->operatorIds);
        }

        $threshold = $rng->randomInt(1000000) / 1000000.0 * $totalWeight;
        $cumulative = 0.0;
        foreach ($weights as $operatorId => $weight) {
            $cumulative += $weight;
            if ($threshold <= $cumulative) {
                return $operatorId;
            }
        }

        return $this->operatorIds[\count($this->operatorIds) - 1];
    }

    /**
     * @return array{seed_class:string,weights:array<string,float>,stats:array<string,array{total_uses:int,interesting_count:int}>}|null
     */
    public function update(string $seedClass, string $operatorId, bool $wasInteresting): ?array {
        $seedClass = $this->resolveClass($seedClass);
        $this->ensureClassInitialized($seedClass);
        if (!isset($this->statsByClass[$seedClass][$operatorId])) {
            return null;
        }
        $this->statsByClass[$seedClass][$operatorId]['total_uses']++;
        if ($wasInteresting) {
            $this->statsByClass[$seedClass][$operatorId]['interesting_count']++;
        }

        $this->iterationsByClass[$seedClass]++;
        if (!$this->shouldRebalance($seedClass)) {
            return null;
        }

        $this->rebalance($seedClass);
        $this->applyForgetting($seedClass);

        return [
            'seed_class' => $seedClass,
            'weights' => $this->weightsByClass[$seedClass],
            'stats' => $this->statsByClass[$seedClass],
        ];
    }

    /**
     * @return array<string, float>
     */
    public function getWeightVector(string $seedClass): array {
        $seedClass = $this->resolveClass($seedClass);
        $this->ensureClassInitialized($seedClass);
        return $this->weightsByClass[$seedClass];
    }

    /**
     * @return array<string, array{total_uses:int, interesting_count:int}>
     */
    public function getStats(string $seedClass): array {
        $seedClass = $this->resolveClass($seedClass);
        $this->ensureClassInitialized($seedClass);
        return $this->statsByClass[$seedClass];
    }

    public function getPolicy(): string {
        return $this->policy;
    }

    public function getRebalancePeriod(): int {
        return $this->rebalancePeriod;
    }

    public function hasWeightsDiverged(): bool {
        return $this->weightsEverDiverged;
    }

    /**
     * @param array<string, float>|array<string, array<string, float>> $staticWeights
     * @return array<string, float>
     */
    private function buildBaseWeights(array $staticWeights): array {
        $uniform = [];
        foreach ($this->operatorIds as $operatorId) {
            $uniform[$operatorId] = 1.0;
        }
        $uniform = $this->normalizeWeights($uniform);

        if ($this->policy !== 'static') {
            return $uniform;
        }

        $candidate = $staticWeights;
        if (isset($staticWeights['default']) && \is_array($staticWeights['default'])) {
            /** @var array<string, float> $candidate */
            $candidate = $staticWeights['default'];
        }

        $selected = [];
        foreach ($this->operatorIds as $operatorId) {
            $weight = isset($candidate[$operatorId]) ? (float) $candidate[$operatorId] : 0.0;
            $selected[$operatorId] = max(0.0, $weight);
        }

        return $this->normalizeWeights($selected);
    }

    private function ensureClassInitialized(string $seedClass): void {
        if (isset($this->weightsByClass[$seedClass])) {
            return;
        }

        $this->weightsByClass[$seedClass] = $this->baseWeights;
        $this->iterationsByClass[$seedClass] = 0;
        $this->statsByClass[$seedClass] = [];
        foreach ($this->operatorIds as $operatorId) {
            $this->statsByClass[$seedClass][$operatorId] = [
                'total_uses' => 0,
                'interesting_count' => 0,
            ];
        }
    }

    /**
     * @param array<string, float> $weights
     * @return array<string, float>
     */
    private function normalizeWeights(array $weights): array {
        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            $weights = [];
            foreach ($this->operatorIds as $operatorId) {
                $weights[$operatorId] = 1.0;
            }
            $sum = array_sum($weights);
        }

        foreach ($weights as $operatorId => $weight) {
            $weights[$operatorId] = max($this->minWeightFloor, $weight / $sum);
        }

        $renormalized = array_sum($weights);
        foreach ($weights as $operatorId => $weight) {
            $weights[$operatorId] = $weight / $renormalized;
        }

        return $weights;
    }

    private function resolveClass(string $seedClass): string {
        if ($this->policy === 'class-adaptive') {
            return $seedClass;
        }

        return 'default';
    }

    private function shouldRebalance(string $seedClass): bool {
        if (!in_array($this->policy, ['adaptive', 'class-adaptive'], true)) {
            return false;
        }

        $iterations = $this->iterationsByClass[$seedClass] ?? 0;
        if ($iterations < $this->warmupIterations) {
            return false;
        }

        return ($iterations % $this->rebalancePeriod) === 0;
    }

    private function rebalance(string $seedClass): void {
        $weights = $this->weightsByClass[$seedClass];
        $stats = $this->statsByClass[$seedClass];
        foreach ($weights as $operatorId => $weight) {
            $total = $stats[$operatorId]['total_uses'];
            $interesting = $stats[$operatorId]['interesting_count'];
            $rate = $total > 0 ? ($interesting / $total) : 0.0;
            $weights[$operatorId] = ($weight * (1.0 - $this->alpha)) + ($this->alpha * $rate);
        }

        $this->weightsByClass[$seedClass] = $this->normalizeWeights($weights);
        if ($this->isNonUniform($this->weightsByClass[$seedClass])) {
            $this->weightsEverDiverged = true;
        }
    }

    /**
     * @param array<string, float> $weights
     */
    private function isNonUniform(array $weights): bool {
        $count = \count($weights);
        if ($count === 0) {
            return false;
        }

        $uniformWeight = 1.0 / $count;
        $maxDeviation = 0.0;
        foreach ($weights as $weight) {
            $deviation = abs($weight - $uniformWeight);
            if ($deviation > $maxDeviation) {
                $maxDeviation = $deviation;
            }
        }

        return $maxDeviation > 0.01;
    }

    private function applyForgetting(string $seedClass): void {
        if ($this->decayMode === 'cumulative') {
            return;
        }

        if ($this->decayMode === 'multiplicative' || $this->decayMode === 'ema_decay') {
            foreach ($this->statsByClass[$seedClass] as $operatorId => $operatorStats) {
                $this->statsByClass[$seedClass][$operatorId]['total_uses'] = (int) round($operatorStats['total_uses'] * $this->decayFactor);
                $this->statsByClass[$seedClass][$operatorId]['interesting_count'] = (int) round($operatorStats['interesting_count'] * $this->decayFactor);
            }
            return;
        }

        foreach ($this->statsByClass[$seedClass] as $operatorId => $_) {
            $this->statsByClass[$seedClass][$operatorId] = [
                'total_uses' => 0,
                'interesting_count' => 0,
            ];
        }
    }
}
