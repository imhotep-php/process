<?php

namespace Imhotep\Process;

use Closure;
use Imhotep\Process\Pipes\UnixPipes;
use Imhotep\Process\Pipes\WindowsPipes;
use LogicException;

class Process
{
    public const STATUS_READY = 'ready';
    public const STATUS_STARTED = 'started';
    public const STATUS_TERMINATED = 'terminated';

    protected string $status = self::STATUS_READY;
    protected ?int $exitCode = null;

    protected array $fallbackStatus = [];

    protected mixed $process;

    protected array $processInfo = [];

    protected ?int $cachedExitCode = null;

    protected string|array $command;

    protected string $compiledCommand = '';

    protected bool $isWindows;

    protected ?Closure $callback = null;

    protected mixed $stdout = null;

    protected mixed $stderr = null;



    protected static ?bool $sigchldEnabled = null;

    protected bool $pty = false;

    protected static ?bool $ptySupported = null;

    protected bool $tty = false;

    protected static ?bool $ttySupported = null;

    protected ?string $cwd = null;

    protected array $env = [];

    protected UnixPipes|WindowsPipes $pipes;


    public function __construct(string|array $command, string $cwd = null, array $env = null, mixed $input = null, float $timeout = 60)
    {
        if (! function_exists('proc_open')) {
            throw new LogicException('The "proc_open" is not available on your PHP.');
        }

        $this->isWindows = DIRECTORY_SEPARATOR === '\\';

        $this->compiledCommand = $this->compileCommand($this->command = $command);

        $this->setCwd($cwd);
        $this->setEnv($env ?: []);
    }

    public static function fromCommand(string|array $command): static
    {
        return new static($command); //$cwd, $env, $input, $timeout;
    }


    public function isSigchldEnabled(): bool
    {
        if (static::$sigchldEnabled !== null) {
            return static::$sigchldEnabled;
        }

        if (! function_exists('phpinfo')) {
            return static::$sigchldEnabled = false;
        }

        ob_start();
        phpinfo(INFO_GENERAL);

        return self::$sigchldEnabled = str_contains(ob_get_clean(), '--enable-sigchild');
    }

    public function isPtySupported(): bool
    {
        if (is_bool(static::$ptySupported)) {
            return static::$ptySupported;
        }

        if ($this->isWindows) {
            return static::$ptySupported = false;
        }

        return static::$ptySupported = (bool) @proc_open('echo 1 >/dev/null', [['pty'], ['pty'], ['pty']], $pipes);
    }

    public function isTtySupported(): bool
    {
        if (is_bool(static::$ttySupported)) {
            return static::$ttySupported;
        }

        if ($this->isWindows) {
            return static::$ttySupported = false;
        }

        // file_exists('/dev/tty') && is_writable('/dev/tty')
        return static::$ttySupported = stream_isatty(STDOUT) && @is_writable('/dev/tty');
    }

    public function pty(bool $state = null): bool|static
    {
        if (is_null($state)) {
            return $this->pty;
        }

        $this->pty = $state;

        return $this;
    }

    public function tty(bool $state = null): bool|static
    {
        if (is_null($state)) {
            return $this->tty;
        }

        $this->tty = $state;

        return $this;
    }

    public function getCwd(): ?string
    {
        return $this->cwd;
    }

    public function setCwd(?string $cwd): static
    {
        if (is_null($cwd) && (defined('ZEND_THREAD_SAFE') || $this->isWindows)) {
            $cwd = getcwd() ?? null;
        }

        if (is_string($cwd) && ! is_dir($cwd)) {
            throw new LogicException(sprintf('The provided cwd "%s" does not exist.', $cwd));
        }

        $this->cwd = $cwd;

        return $this;
    }

    public function getEnv(): array
    {
        return $this->env;
    }

    public function setEnv(array $env): static
    {
        $this->env = $env;

        return $this;
    }

    public function getCommand(): string
    {
        return implode(' ', (array)$this->command);
    }

    public function getCompiledCommand(): string
    {
        return $this->command;
    }

    protected function compileCommand(string|array $command): string
    {
        if (is_array($command)) {
            $command = implode(' ', array_map($this->escapeArgument(...), $command));
        }

        if ($this->isWindows) {
            // ...
        }

        return $command;
    }

    protected function escapeArgument(?string $argument): string
    {
        return $argument ?: '';
    }


    public function run(Closure $callback = null, array $env = []): int
    {
        $this->start($callback, $env);

        return $this->wait();
    }

    public function start(Closure $callback = null, array $env = []): void
    {
        if ($this->isRunning()) {
            throw new LogicException('Process is already running.');
        }

        $this->reset();

        $this->callback = $callback;

        $lastError = null;
        set_error_handler(function ($type, $msg) use (&$lastError) {
            $lastError = $msg;

            return true;
        });

        $descriptors = $this->getDescriptors(! is_null($callback));

        $env = array_merge(getenv(), $this->env, $env);

        try {
            $this->process = @proc_open($this->compiledCommand, $descriptors, $this->pipes->pipes, $this->cwd, $env); // $this->options

            // Ensure array vs string commands behave the same
            //if (!$process && \is_array($commandline)) {
            //$process = @proc_open('exec '.$this->buildShellCommandline($commandline), $this->getDescriptors(), $this->pipes, $this->cwd); // $envPairs, $this->options
            //}
        } finally {
            //if ($this->ignoredSignals && \function_exists('pcntl_sigprocmask')) {
            // we restore the signal mask here to avoid any side effects
            //pcntl_sigprocmask(\SIG_SETMASK, $oldMask);
            //}

            restore_error_handler();
        }

        if (! $this->process) {
            $this->handleException($lastError);
        }

        $this->status = self::STATUS_STARTED;

        if (isset($descriptors[3])) {
            $this->fallbackStatus['pid'] = (int)fgets($this->pipes->pipes[3]);
        }

        $this->updateStatus(true);
    }

    public function wait(Closure $callback = null): int
    {
        if (! $this->isStarted()) {
            throw new LogicException(sprintf('Process must be started before calling "%s()".', __FUNCTION__));
        }

        while ($this->isRunning()) {
            usleep(10);
        }

        return $this->exitCode;
    }

    public function isRunning(): bool
    {
        if (static::STATUS_STARTED !== $this->status) {
            return false;
        }

        $this->updateStatus();

        return $this->processInfo['running'];
    }

    public function isStarted(): bool
    {
        return static::STATUS_READY !== $this->status;
    }

    public function isTerminated(): bool
    {
        $this->updateStatus();

        return static::STATUS_TERMINATED === $this->status;
    }

    public function getStatus(): string
    {
        $this->updateStatus();

        return $this->status;
    }

    /*
    protected function buildCallback(Closure $callback = null): Closure
    {
        return function () {

        };
    }
    */

    protected function reset(): void
    {
        $this->process = null;
        $this->callback = null;
        $this->exitCode = null;
        $this->fallbackStatus = [];
        $this->processInfo = [];
        $this->stdout = fopen('php://temp/maxmemory:'.(1024*1024), 'w+');
        $this->stderr = fopen('php://temp/maxmemory:'.(1024*1024), 'w+');
        $this->status = static::STATUS_READY;
    }

    protected function updateStatus(bool $blocking = false): void
    {
        //echo "UpdateStatus\n";

        if ($this->status !== self::STATUS_STARTED)  {
            return;
        }

        $this->processInfo = proc_get_status($this->process);

        $running = $this->processInfo['running'];

        // In PHP < 8.3, "proc_get_status" only returns the correct exit status on the first call.
        if (PHP_VERSION_ID < 80300) {
            if (! isset($this->cachedExitCode) && ! $running && $this->processInfo['exitcode'] !== -1) {
                $this->cachedExitCode = $this->processInfo['exitcode'];
            }

            if (isset($this->cachedExitCode) && ! $running && $this->processInfo['exitcode'] === -1) {
                $this->processInfo['exitcode'] = $this->cachedExitCode;
            }
        }

        $this->readPipes($running && $blocking, $this->isWindows || ! $running);

        //if ($this->fallbackStatus && $this->isSigchildEnabled()) {
        //    $this->processInformation = $this->fallbackStatus + $this->processInformation;
        //}

        if (! $running) $this->close();
    }

    protected function readPipes(bool $blocking, bool $close): void
    {
        if ( is_null($result = $this->pipes->readAndWrite($blocking, $close))) {
            return;
        }

        $callback = $this->callback;

        foreach ($result as $type => $line) {
            if ($type !== 3) {
                $callback($type === 1 ? 'out' : 'err', $line);
            }
            elseif ($this->fallbackStatus['signaled']) {
                $this->fallbackStatus['exitcode'] = (int)$line;
            }
        }
    }

    protected function close(): int
    {
        if ($this->process) {
            proc_close($this->process);
            $this->process = null;
        }

        $this->exitCode = $this->processInfo['exitcode'];
        $this->status = self::STATUS_TERMINATED;

        if ($this->exitCode !== -1) {
            if ($this->processInfo['signaled'] && $this->processInfo['termsig'] > 0) {
                $this->exitCode = 128 + $this->processInfo['termsig'];
            }
            elseif ($this->isSigchldEnabled()) {
                $this->processInfo['signaled'] = true;
                $this->processInfo['termsig'] = -1;
            }
        }

        $this->callback = null;

        return $this->exitCode;
    }

    protected function getDescriptors(bool $hasCallback): array
    {
        $isReadMode = $hasCallback;

        if ($this->isWindows) {
            $this->pipes = new WindowsPipes(null, $isReadMode);
        } else {
            $this->pipes = new UnixPipes($this->tty, $this->pty, null, $isReadMode);
        }

        $descriptors = $this->pipes->getDescriptors();

        if ($this->isSigchldEnabled()) {
            $descriptors[3] = ['pipe', 'w'];
        }

        return $descriptors;
    }

    protected function handleException(string $message = null): void
    {
        $error = sprintf('The command "%s" failed.'."\n\nWorking directory: %s\n\nError: %s",
            $this->command,
            $this->cwd,
            $message ?? 'unknown'
        );

        throw new \Exception($message);
    }
}