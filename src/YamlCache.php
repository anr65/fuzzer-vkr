<?php declare(strict_types=1);

namespace PhpFuzzer;

use PhpFuzzer\Util\AtomicFile;
use Symfony\Component\Yaml\Yaml;

/**
 * Parse-once cache for YAML inputs keyed by sha256(raw bytes).
 */
final class YamlCache {
    /** @var array<string, array{parsed: mixed, hits: int, misses: int}> */
    private array $cache = [];
    private int $totalHits = 0;
    private int $totalMisses = 0;

    /**
     * @return mixed|null
     */
    public function get(string $rawInput) {
        $k = hash('sha256', $rawInput);
        if (!isset($this->cache[$k])) {
            return null;
        }
        $this->cache[$k]['hits']++;
        $this->totalHits++;
        return $this->cache[$k]['parsed'];
    }

    /**
     * @param mixed $parsed
     */
    public function set(string $rawInput, $parsed): void {
        $k = hash('sha256', $rawInput);
        if (!isset($this->cache[$k])) {
            $this->cache[$k] = ['parsed' => $parsed, 'hits' => 0, 'misses' => 0];
        }
        $this->cache[$k]['parsed'] = $parsed;
    }

    public function invalidate(string $rawInput): void {
        unset($this->cache[hash('sha256', $rawInput)]);
    }

    /**
     * Parse using Symfony Yaml; fills cache on miss.
     *
     * @return mixed
     */
    public function getOrParse(string $rawInput) {
        $k = hash('sha256', $rawInput);
        if (isset($this->cache[$k])) {
            $this->cache[$k]['hits']++;
            $this->totalHits++;
            return $this->cache[$k]['parsed'];
        }
        $this->totalMisses++;
        $parsed = Yaml::parse($rawInput);
        $this->cache[$k] = ['parsed' => $parsed, 'hits' => 0, 'misses' => 1];
        return $parsed;
    }

    /**
     * @return array{total_hits: int, total_misses: int, hit_rate: float, cache_size: int}
     */
    public function getStats(): array {
        $t = $this->totalHits + $this->totalMisses;
        return [
            'total_hits' => $this->totalHits,
            'total_misses' => $this->totalMisses,
            'hit_rate' => $t > 0 ? $this->totalHits / $t : 0.0,
            'cache_size' => \count($this->cache),
        ];
    }

    public function exportStatsJson(string $path): void {
        AtomicFile::writeString($path, json_encode($this->getStats(), JSON_PRETTY_PRINT) . "\n");
    }
}
