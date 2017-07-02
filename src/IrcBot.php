<?php

namespace Kelunik\Mellon;

use Amp\Uri\Uri;
use Phergie\Irc\Bot\React\Bot;
use Phergie\Irc\Connection;

class IrcBot extends Bot {
    public function __construct(string $connection, array $phergiePlugins, IrcClient $ircClient) {
        $connection = $this->createConnectionFromUri(new Uri($connection));

        $this->setConfig([
            "client" => $ircClient,
            "plugins" => $phergiePlugins,
            "connections" => [$connection],
        ]);
    }

    private function createConnectionFromUri(Uri $uri): Connection {
        return new Connection([
            "serverHostname" => $uri->getHost(),
            "serverPort" => $uri->getPort(),
            "username" => $uri->getUser(),
            "nickname" => $uri->getUser(),
            "realname" => $uri->getUser(),
            "password" => $uri->getPass(),
            "options" => [
                "transport" => "ssl",
            ],
        ]);
    }
}