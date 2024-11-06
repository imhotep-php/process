<?php

namespace Imhotep\Process\Pipes;

abstract class AbstractPipes
{
    public array $pipes = [];

    protected mixed $input = null;

    protected bool $isReadMode = false;

    protected bool $unblocked = false;

    protected ?string $lastError = null;

    public function __construct(mixed $input, bool $isReadMode)
    {
        $this->isReadMode = $isReadMode;

        if (is_resource($input) || $input instanceof \Iterator) {
            $this->input = $input;
        }
        else {
            //$this->inputBuffer = (string) $input;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isReadMode(): bool
    {
        return $this->isReadMode;
    }

    public function isOpened(): bool
    {
        return (bool) $this->pipes;
    }

    protected function unblock(): void
    {
        if ($this->unblocked) return;

        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, 0);
        }

        if (is_resource($this->input)) {
            stream_set_blocking($this->input, 0);
        }

        $this->unblocked = true;
    }

    public function write(): void
    {

    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }

        $this->pipes = [];
    }

    protected function handleError(int $type, string $message): void
    {
        $this->lastError = $message;
    }

    abstract public function getDescriptors(): array;
}