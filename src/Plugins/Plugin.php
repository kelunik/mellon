<?php

namespace Kelunik\Mellon\Plugins;

use Kelunik\Mellon\Chat\Channel;

abstract class Plugin {
    /** @var Channel[] */
    private $activeChannels = [];

    public function getName(): string {
        return basename(strtr(get_class($this), '\\', '/'));
    }

    abstract public function getDescription(): string;

    abstract public function getEndpoints(): array;

    public function enableForChannel(Channel $channel) {
        $this->activeChannels[$channel->getName()] = $channel;
    }

    public function disableForChannel(Channel $channel) {
        unset($this->activeChannels[$channel->getName()]);
    }

    public function getChannels(): array {
        return $this->activeChannels;
    }
}