<?php

namespace Kelunik\Mellon;

use Amp\Dns;
use Amp\ReactAdapter\ReactAdapter;
use Phergie\Irc\Client\React\Client;
use Psr\Log\LoggerInterface as PsrLogger;
use React\Promise\Promise;

/**
 * Extension of the original client to provide DNS resolution based on Amp's resolver and automatically uses Amp's event
 * loop implementation.
 *
 * We don't bother to set a complete DNS adapter, just override resolveHostname.
 */
class IrcClient extends Client {
    public function __construct(PsrLogger $logger) {
        $this->setLogger($logger);
        $this->setLoop(ReactAdapter::get());
    }

    protected function resolveHostname($hostname) {
        return new Promise(function ($resolve, $reject) use ($hostname) {
            Dns\resolve($hostname)->onResolve(function ($error, $result) use ($resolve, $reject) {
                if ($error) {
                    $reject($error);
                } else {
                    $resolve($result);
                }
            });
        });
    }
}