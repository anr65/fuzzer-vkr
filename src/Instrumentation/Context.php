<?php declare(strict_types=1);

namespace PhpFuzzer\Instrumentation;

final class Context {
    public string $runtimeContextName;
    // We reserve 0 as the global entry point, start counting from 1.
    private int $blockIndex = 1;

    public FileInfo $fileInfo;
    public MutableString $code;

    public function __construct(string $runtimeContextName) {
        $this->runtimeContextName = $runtimeContextName;
    }

    public function getNewBlockIndex(int $pos): int {
        $blockIndex = $this->blockIndex++;
        $this->fileInfo->blockIndexToPos[$blockIndex] = $pos;
        return $blockIndex;
    }

    public function reserveBlockIndexesThrough(int $blockIndex): void {
        if ($blockIndex >= (1 << 28)) {
            throw new \OverflowException('Instrumentation block index exceeds the 28-bit feature encoding');
        }
        $this->blockIndex = max($this->blockIndex, $blockIndex + 1);
    }
}
