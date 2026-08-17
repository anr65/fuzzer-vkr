<?php declare(strict_types=1);

namespace PhpFuzzer;

final class TargetWorker {
    private \Closure $target;
    private int $timeoutSeconds;
    private ?\Closure $instrumentationMetadataProvider;
    /** @var resource|null */
    private $parentSocket = null;
    private ?int $childPid = null;

    public function __construct(
        \Closure $target,
        int $timeoutSeconds,
        ?\Closure $instrumentationMetadataProvider = null
    ) {
        if (!\extension_loaded('pcntl')) {
            throw new \RuntimeException('TargetWorker requires ext-pcntl');
        }
        $this->target = $target;
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->instrumentationMetadataProvider = $instrumentationMetadataProvider;
    }

    public function __destruct() {
        $this->stopWorker();
    }

    public function run(string $input): TargetExecutionResult {
        $this->ensureWorker();
        if ($this->parentSocket === null || $this->childPid === null) {
            throw new \RuntimeException('Target worker is not available');
        }

        $request = serialize(['input' => $input]);
        $this->writeFrame($this->parentSocket, $request);
        $response = $this->readFrameWithTimeout($this->parentSocket, $this->timeoutSeconds);
        if ($response === null) {
            $this->stopWorker(true);
            return new TargetExecutionResult([], "Target wall-time timeout of {$this->timeoutSeconds} seconds exceeded", \Error::class, true);
        }

        $payload = @unserialize($response, ['allowed_classes' => false]);
        if (!is_array($payload) || !isset($payload['status'])) {
            $this->stopWorker(true);
            return new TargetExecutionResult([], 'Target worker produced an invalid response', \RuntimeException::class);
        }

        if ($payload['status'] === 'parse_error') {
            throw new \ParseError((string) ($payload['message'] ?? 'Target worker parse error'));
        }

        if ($payload['status'] === 'exception') {
            return new TargetExecutionResult(
                (array) ($payload['edges'] ?? []),
                (string) ($payload['message'] ?? 'Target worker exception'),
                (string) ($payload['class'] ?? \RuntimeException::class),
                false,
                (array) ($payload['instrumented_files'] ?? [])
            );
        }

        if ($payload['status'] !== 'ok') {
            $this->stopWorker(true);
            return new TargetExecutionResult([], 'Target worker exited unexpectedly', \RuntimeException::class);
        }

        return new TargetExecutionResult(
            (array) ($payload['edges'] ?? []),
            null,
            null,
            false,
            (array) ($payload['instrumented_files'] ?? [])
        );
    }

    private function ensureWorker(): void {
        if ($this->parentSocket !== null && $this->childPid !== null) {
            $status = null;
            $wait = pcntl_waitpid($this->childPid, $status, WNOHANG);
            if ($wait === 0) {
                return;
            }
            $this->stopWorker();
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            throw new \RuntimeException('Failed to create worker socket pair');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($pair[0]);
            fclose($pair[1]);
            throw new \RuntimeException('Failed to fork target worker');
        }

        if ($pid === 0) {
            fclose($pair[0]);
            $this->runChildLoop($pair[1]);
            exit(0);
        }

        fclose($pair[1]);
        stream_set_blocking($pair[0], true);
        $this->parentSocket = $pair[0];
        $this->childPid = $pid;
    }

    /**
     * @param resource $socket
     */
    private function runChildLoop($socket): void {
        stream_set_blocking($socket, true);
        $reportedInstrumentation = $this->getInstrumentationMetadataSignatures();
        while (true) {
            $request = $this->readFrameBlocking($socket);
            if ($request === null) {
                break;
            }

            $payload = @unserialize($request, ['allowed_classes' => false]);
            if (!is_array($payload) || !array_key_exists('input', $payload) || !is_string($payload['input'])) {
                $this->writeFrame($socket, serialize(['status' => 'exception', 'message' => 'Invalid worker request', 'edges' => []]));
                continue;
            }

            try {
                FuzzingContext::reset();
                $response = ['status' => 'ok', 'edges' => []];
                ($this->target)($payload['input']);
                $response['edges'] = FuzzingContext::$edges;
            } catch (\ParseError $e) {
                $response = ['status' => 'parse_error', 'message' => (string) $e];
            } catch (\Throwable $e) {
                $response = [
                    'status' => 'exception',
                    'class' => get_class($e),
                    'message' => (string) $e,
                    'edges' => FuzzingContext::$edges,
                ];
            }

            $response['instrumented_files'] = $this->collectInstrumentationMetadataDelta(
                $reportedInstrumentation
            );

            $this->writeFrame($socket, serialize($response));
        }

        fclose($socket);
    }

    /**
     * @return array<string, string>
     */
    private function getInstrumentationMetadataSignatures(): array {
        if ($this->instrumentationMetadataProvider === null) {
            return [];
        }

        $signatures = [];
        foreach (($this->instrumentationMetadataProvider)() as $path => $metadata) {
            if (is_string($path) && is_array($metadata)) {
                $signatures[$path] = hash('sha256', serialize($metadata));
            }
        }
        return $signatures;
    }

    /**
     * @param array<string, string> $reportedSignatures
     * @return array<string, array{source_hash: string, instrumented_code: string, block_index_to_pos: array<int, int>}>
     */
    private function collectInstrumentationMetadataDelta(array &$reportedSignatures): array {
        if ($this->instrumentationMetadataProvider === null) {
            return [];
        }

        $delta = [];
        foreach (($this->instrumentationMetadataProvider)() as $path => $metadata) {
            if (!is_string($path) || !is_array($metadata)) {
                continue;
            }
            $signature = hash('sha256', serialize($metadata));
            if (($reportedSignatures[$path] ?? null) === $signature) {
                continue;
            }
            $reportedSignatures[$path] = $signature;
            $delta[$path] = $metadata;
        }
        return $delta;
    }

    private function stopWorker(bool $forceKill = false): void {
        if ($this->parentSocket !== null) {
            fclose($this->parentSocket);
            $this->parentSocket = null;
        }

        if ($this->childPid !== null) {
            if ($forceKill) {
                @posix_kill($this->childPid, SIGKILL);
            }
            pcntl_waitpid($this->childPid, $status);
            $this->childPid = null;
        }
    }

    /**
     * @param resource $socket
     */
    private function writeFrame($socket, string $payload): void {
        $frame = pack('N', strlen($payload)) . $payload;
        $offset = 0;
        $length = strlen($frame);
        while ($offset < $length) {
            $written = fwrite($socket, substr($frame, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Failed to write to target worker');
            }
            $offset += $written;
        }
        fflush($socket);
    }

    /**
     * @param resource $socket
     */
    private function readFrameBlocking($socket): ?string {
        $lengthBytes = $this->readExact($socket, 4);
        if ($lengthBytes === null) {
            return null;
        }
        $length = unpack('Nlen', $lengthBytes);
        if (!is_array($length) || !isset($length['len'])) {
            return null;
        }
        return $this->readExact($socket, (int) $length['len']);
    }

    /**
     * @param resource $socket
     */
    private function readFrameWithTimeout($socket, int $timeoutSeconds): ?string {
        $read = [$socket];
        $write = [];
        $except = [];
        $ready = stream_select($read, $write, $except, $timeoutSeconds, 0);
        if ($ready !== 1) {
            return null;
        }
        return $this->readFrameBlocking($socket);
    }

    /**
     * @param resource $socket
     */
    private function readExact($socket, int $length): ?string {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
