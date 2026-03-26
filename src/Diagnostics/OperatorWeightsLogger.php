<?php declare(strict_types=1);

namespace PhpFuzzer\Diagnostics;

final class OperatorWeightsLogger {
    private string $path;
    private int $snapshotCounter = 0;

    public function __construct(string $path) {
        $this->path = $path;
        $dir = dirname($path);
        if ($dir && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * @param array<string, float> $weights
     * @param array<string, array{total_uses:int,interesting_count:int}> $stats
     */
    public function logSnapshot(int $run, string $seedClass, array $weights, array $stats): string {
        $snapshotId = 'weights_' . $run . '_' . (++$this->snapshotCounter);
        $payload = [
            'snapshot_id' => $snapshotId,
            'run' => $run,
            'seed_class' => $seedClass,
            'weights' => $weights,
            'stats' => $stats,
            'timestamp' => microtime(true),
        ];

        file_put_contents($this->path, json_encode($payload) . "\n", FILE_APPEND);

        return $snapshotId;
    }
}
