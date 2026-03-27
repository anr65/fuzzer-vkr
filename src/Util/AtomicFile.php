<?php declare(strict_types=1);

namespace PhpFuzzer\Util;

/**
 * Atomic replace write: temp file in same directory, then rename.
 */
final class AtomicFile {
    public static function writeString(string $path, string $contents): void {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = tempnam($dir !== '' && $dir !== '.' ? $dir : sys_get_temp_dir(), 'af');
        if ($tmp === false) {
            throw new \RuntimeException('tempnam failed for atomic write');
        }
        try {
            if (file_put_contents($tmp, $contents) === false) {
                throw new \RuntimeException('file_put_contents failed');
            }
            if (!rename($tmp, $path)) {
                throw new \RuntimeException('rename failed for atomic write');
            }
            $tmp = null;
        } finally {
            if ($tmp !== null && file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Append one line with exclusive lock (atomic enough for line-oriented logs).
     */
    public static function appendLine(string $path, string $line): void {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
