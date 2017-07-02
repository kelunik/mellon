<?php

namespace Kelunik\Mellon\Plugins;

use Kelunik\Mellon\Chat\Channel;

abstract class Plugin {
    public function getName(): string {
        return basename(strtr(get_class($this), '\\', '/'));
    }

    abstract public function getDescription(): string;

    abstract public function getEndpoints(): array;
}