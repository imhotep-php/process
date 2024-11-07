<?php

namespace Imhotep\Process\Pipes;

class UnixPipes extends AbstractPipes
{
    protected bool $tty = false;

    protected bool $pty = false;

    public function __construct(bool $tty, bool $pty, mixed $input, bool $isReadMode)
    {
        parent::__construct($input, $isReadMode);

        $this->tty = $tty;
        $this->pty = $pty;
    }

    public function getDescriptors(): array
    {
        if (! $this->isReadMode) {
            $nullstream = fopen('/dev/null', 'c');

            return [ ['pipe', 'r'], $nullstream, $nullstream ];
        }

        if ($this->tty) {
            return [ ['file', '/dev/tty', 'r'], ['file', '/dev/tty', 'w'], ['file', '/dev/tty', 'w'] ];
        }

        if ($this->pty) {
            return [ ['pty'], ['pty'], ['pty'] ];
        }

        return [ ['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w'] ];
    }

    public function readAndWrite(bool $blocking, bool $close = false): ?array
    {
        $this->unblock();
        $this->write();

        if (! $this->isReadMode || count($this->pipes) < 2) return null;

        $w = $e = $read = [];
        $r = $this->pipes;
        unset($r[0]);

        set_error_handler($this->handleError(...));

        if (stream_select($r, $w, $e, 0, $blocking ? 200 : 0) === false) {
            restore_error_handler();

            // if a system call has been interrupted, forget about it, let's try again
            // otherwise, an error occurred, let's reset pipes
            if (stripos($this->lastError ?: '', 'interrupted system call')) {
                $this->pipes = [];
            }

            return null;
        }

        restore_error_handler();

        foreach ($r as $type => $pipe) {
            $read[$type] = '';

            while ($data = @fread($pipe, 8192)) {
                $read[$type].= $data;

                if (empty($data) || ! isset($data[8191])) {
                    break;
                }
            }

            if (empty($read[$type])) {
                unset($read[$type]);
            }

            if (feof($pipe) && $close) {
                fclose($pipe);
                unset($this->pipes[$type]);
            }
        }

        return empty($read) ? null : $read;
    }
}