<?php

namespace lucatume\WPBrowser\Process;

use Closure;
use lucatume\WPBrowser\Process\Worker\Exited;
use lucatume\WPBrowser\Process\Worker\Running;
use lucatume\WPBrowser\Process\Worker\Worker;
use lucatume\WPBrowser\Process\Worker\WorkerInterface;
use lucatume\WPBrowser\Process\Worker\Result;
use RuntimeException;
use Throwable;

class Loop
{
    use MemoryUsage;

    private int $peakParallelism = 0;
    private bool $fastFailure;
    private float $timeout;
    private int $parallelism;
    /**
     * @var array<string,Result>
     */
    private array $results = [];
    /**
     * @var array<string,Running>
     */
    private array $started = [];
    /**
     * @var array<string,Running>
     */
    private array $running = [];
    /**
     * @var array<string,Exited>
     */
    private array $exited = [];

    /**
     * @var array<string,Worker>
     */
    private array $workers = [];
    /**
     * @var callable|null
     */
    private $debuggerCallback;
    private bool $debugMode = false;

    /**
     * @var array<callable>
     */
    private array $onWorkerExit = [];
    private bool $fastFailureFlagRaised = false;

    public function __construct(
        array $workers = [],
        int $parallelism = 1,
        bool $fastFailure = false,
        float $timeout = 30
    ) {
        $this->addWorkers($workers);
        $this->parallelism = $parallelism;
        $this->fastFailure = $fastFailure;
        $this->timeout = $timeout;
    }

    public static function executeClosure(Closure $closure): Result
    {
        $results = (new Loop([$closure], 1, true))
            ->run()
            ->getResults();
        return reset($results);
    }

    /**
     * @throws ProcessException
     */
    public static function executeClosureOrThrow(Closure $closure): Result
    {
        $result = self::executeClosure($closure);

        if ($result->getExitCode() !== 0) {
            throw new ProcessException(sprintf(
                    "Closure execution failed with exit code %s and message '%s'",
                    $result->getExitCode(), $result->getStderrBuffer()
                )
            );
        }

        return $result;
    }

    /**
     * @return array<Worker>
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    public function addWorkers(array $workers): Loop
    {
        $builtWorkers = array_map([$this, 'ensureWorker'], array_keys($workers), $workers);
        array_push($this->workers, ...$builtWorkers);
        $this->sortWorkersByResource();

        return $this;
    }

    private function getRunnableWorker(): ?Worker
    {
        $exitedWorkersIds = array_keys($this->exited);
        $runningWorkersIds = array_keys($this->started);

        if (count($exitedWorkersIds) === 0 && count($runningWorkersIds) === 0) {
            return reset($this->workers);
        }

        $notStartedWorkers = array_filter(
            $this->workers,
            static function (Worker $w) use ($exitedWorkersIds, $runningWorkersIds): bool {
                $workerId = $w->getId();
                return !in_array($workerId, $exitedWorkersIds, false)
                    && !in_array($workerId, $runningWorkersIds, false);
            }
        );

        if (!count($notStartedWorkers)) {
            return null;
        }

        $busyResourcesIds = array_merge(
            ...array_map(static function (Running $rw) {
                return $rw->getRequiredResourcesIds();
            }, array_values($this->running))
        );

        foreach ($notStartedWorkers as $w) {
            if (count(array_intersect($w->getRequiredResourcesIds(), $busyResourcesIds)) === 0) {
                return $w;
            }
        }

        return null;
    }

    private function startWorker(): void
    {
        $runnableWorker = $this->getRunnableWorker();

        if (!$runnableWorker instanceof Worker) {
            return;
        }

        try {
            $w = Running::fromWorker($runnableWorker);
            $this->started[$w->getId()] = $w;
            $this->running[$w->getId()] = $w;
            $this->debugLine("Worker {$w->getId()} started.");
            $this->peakParallelism = max((int)$this->peakParallelism, count($this->running));
        } catch (Throwable $t) {
            $this->terminateAllRunningWorkers();
            throw $t;
        }
    }

    private function sortWorkersByResource(): void
    {
        usort($this->workers, static function (Worker $a, Worker $b): int {
            return count($a->getRequiredResourcesIds()) - count($b->getRequiredResourcesIds());
        });
        $this->workers = array_combine(
            array_map(static function (Worker $worker): string {
                return $worker->getId();
            }, $this->workers),
            $this->workers
        );
    }

    private function bootstrap(): void
    {
        $bootstrapLimit = min($this->parallelism, count($this->workers));
        for ($c = 0; $c < $bootstrapLimit; $c++) {
            $this->startWorker();
        }
    }

    private function collectOutput(): void
    {
        $readIndexToWorkerMap = [];

        $read = array_reduce(
            $this->running,
            function (array $streams, Running $w) use (&$readIndexToWorkerMap) {
                $this->debugLine("Collecting output of worker {$w->getId()}.");
                $streams[] = $w->getStdoutStream();
                $streams[] = $w->getStdErrStream();
                $readIndexToWorkerMap[count($streams) - 2] = $w;
                $readIndexToWorkerMap[count($streams) - 1] = $w;
                return $streams;
            },
            []
        );

        if (empty($read)) {
            return;
        }

        $write = [];
        $except = [];
        $streamSelectStartTime = microtime(true);
        $updates = stream_select($read, $write, $except, 2);

        $streamSelectRuntime = microtime(true) - $streamSelectStartTime;
        if ($streamSelectRuntime < .01) {
            // Avoid CPU hogging on Mac.
            usleep(10 ** 4 - $streamSelectRuntime);
        }

        if ($updates === false) {
            $this->terminateAllRunningWorkers();
            throw new ProcessException('Failed to read streams.');
        }

        if ($updates > 0) {
            /** @var int $readIndex */
            /** @var resource $stream */
            foreach ($read as $readIndex => $stream) {
                /** @var Running $worker */
                $worker = $readIndexToWorkerMap[$readIndex];
                $worker->readStream($stream);
            }
        }
    }

    private function collectResults(): void
    {
        foreach ($this->started as $runningWorker) {
            $id = $runningWorker->getId();
            $this->results[$id] = new Result(
                $id,
                $runningWorker->getExitCode(),
                $runningWorker->getStderrBuffer(),
                $runningWorker->getStdoutBuffer(),
                $runningWorker->getReturnValue(),
                $runningWorker->getMemoryUsage()
            );
        }
    }

    /**
     * @throws Throwable
     * @throws WorkerException
     * @throws ProcessException
     */
    public function run(): Loop
    {
        $this->bootstrap();

        /** @noinspection SlowArrayOperationsInLoopInspection */
        while (count($this->exited) < count($this->workers)) {
            foreach ($this->running as $w) {
                $status = $w->isRunning() ? null : $w->getExitCode();
                $isOverTime = (microtime(true) - $w->getStartTime()) > $this->timeout;
                $fastFailureFlagRaised = $this->fastFailure && ($isOverTime || ($status !== null && $status !== 0));

                $this->collectOutput();

                if ($fastFailureFlagRaised) {
                    $this->fastFailureFlagRaised = true;
                    $this->dispatchOnWorkerExit(Exited::fromRunningWorker($w));
                    $this->debugLine('Fast failure flag raised, terminating all workers.');
                    $this->terminateAllRunningWorkers();
                    break 2;
                }

                if ($status !== null) {
                    $exitedWorker = Exited::fromRunningWorker($w);
                    $this->dispatchOnWorkerExit($exitedWorker);
                    $this->exited[$w->getId()] = $exitedWorker;
                    unset($this->running[$w->getId()]);
                    $this->debugLine("Worker {$w->getId()} exited with status {$w->getExitCode()}.");
                    $this->startWorker();
                    continue;
                }

                if ($isOverTime) {
                    $exitedWorker = $w->terminate();
                    $this->dispatchOnWorkerExit($exitedWorker);
                    $this->exited[$w->getId()] = $w;
                    unset($this->running[$w->getId()]);
                    $this->debugLine("Worker {$w->getId()} took too long, terminated.");
                    $this->startWorker();
                }
            }
        }

        $this->memoryUsage = memory_get_peak_usage(true);

        $this->collectOutput();
        $this->collectResults();

        if ($this->debugMode) {
            $this->assertPostRunConditions();
        }

        return $this;
    }

    /**
     * @return array<Result>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    private function terminateAllRunningWorkers(): Loop
    {
        foreach ($this->running as $key => $runningWorker) {
            $this->exited[$runningWorker->getId()] = $runningWorker->terminate();
            $this->debugLine("Worker {$runningWorker->getId()} terminated.");
        }

        $this->running = array_values(array_diff_key($this->running, $this->exited));

        return $this;
    }

    protected function assertPostRunConditions(): void
    {
        assert(count($this->started) >= 1);
        assert(count($this->started) === count($this->exited));
        $uncollectedWorkerOutput = array_map(static function (Running $w) {
            return ['stdout' => $w->readStdoutStream(), 'stderr' => $w->readStderrStream()];
        }, array_values($this->started));
        assert(array_sum(array_merge(...$uncollectedWorkerOutput)) === 0, print_r($uncollectedWorkerOutput, true));
        if (!$this->fastFailure) {
            assert(count($this->started) === count($this->workers));
        }
    }

    public function getPeakParallelism(): int
    {
        return $this->peakParallelism;
    }

    private function getExitedWorkerById(string $workerId): ?Exited
    {
        foreach ($this->exited as $id => $worker) {
            if ($workerId === $id) {
                return $worker;
            }
        }

        return null;
    }

    private function getRunningWorkerById(string $workerId): ?Running
    {
        foreach ($this->running as $id => $worker) {
            if ($workerId === $id) {
                return $worker;
            }
        }

        return null;
    }

    private function getWorkerById(string $workerId): ?WorkerInterface
    {
        if ($worker = $this->getExitedWorkerById($workerId)) {
            return $worker;
        }

        if ($worker = $this->getRunningWorkerById($workerId)) {
            return $worker;
        }

        foreach ($this->workers as $id => $worker) {
            if ($workerId === $id) {
                return $worker;
            }
        }

        return null;
    }

    public function removeWorker(string $workerId): ?WorkerInterface
    {
        if ($worker = $this->getWorkerById($workerId)) {
            unset($this->started[$workerId], $this->running[$workerId], $this->exited[$workerId], $this->results[$workerId], $this->workers[$workerId]);

            if ($worker instanceof Running) {
                return $worker->terminate();
            }

            $this->sortWorkersByResource();

            return $worker;
        }

        return null;
    }

    public function getDebuggerCallback(): ?callable
    {
        return $this->debuggerCallback;
    }

    public function setDebuggerCallback(?callable $debuggerCallback): void
    {
        $this->debuggerCallback = $debuggerCallback;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    public function setDebugMode(bool $debugMode): void
    {
        $this->debugMode = $debugMode;
    }

    public function failed(): bool
    {
        return $this->fastFailure && $this->fastFailureFlagRaised;
    }

    private function ensureWorker(string $id, $worker): Worker
    {
        if ($worker instanceof Worker) {
            return $worker;
        }

        return $this->buildWorker($id, $worker);
    }

    private function buildWorker(string $id, callable $worker): Worker
    {
        return new Worker($id, $worker, [], []);
    }

    private function debugLine(string $line): void
    {
        if ($this->debuggerCallback === null) {
            return;
        }
        ($this->debuggerCallback)($line);
    }

    public function subscribeToWorkerExit(callable $debug): Closure
    {
        $this->onWorkerExit[] = $debug;
        $index = count($this->onWorkerExit) - 1;

        return function () {
            unset($this->onWorkerExit[$index]);
            $this->onWorkerExit = array_values($this->onWorkerExit);
        };
    }

    private function dispatchOnWorkerExit(Exited $exitedWorker): void
    {
        if (empty($this->onWorkerExit)) {
            return;
        }
        foreach ($this->onWorkerExit as $subscriber) {
            $subscriber($exitedWorker);
        }
    }
}
