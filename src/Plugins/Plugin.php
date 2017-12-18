<?php

namespace Kelunik\Mellon\Plugins;

use Amp\Promise;
use Amp\Success;
use Kelunik\Mellon\Chat\Message;

abstract class Plugin {
    public function getName(): string {
        return basename(strtr(get_class($this), '\\', '/'));
    }

    public function onMessage(Message $message): Promise {
        return new Success; // default implementation
    }

    abstract public function getDescription(): string;

    abstract public function getEndpoints(): array;
}