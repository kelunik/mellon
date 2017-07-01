<?php

namespace Kelunik\Mellon;

use Amp\Artax\BasicClient;
use Amp\Dns;
use Amp\Loop;
use Amp\MultiReasonException;
use Amp\ReactAdapter\ReactAdapter;
use Amp\Uri\Uri;
use Auryn\Injector;
use Kelunik\Mellon\Chat\Channel;
use Kelunik\Mellon\Chat\Command;
use Kelunik\Mellon\Chat\Message;
use Kelunik\Mellon\Plugins\Plugin;
use Kelunik\Mellon\Storage\FileKeyValueStorage;
use Kelunik\Mellon\Storage\KeyValueStorage;
use Kelunik\Mellon\Storage\PrefixKeyValueStorage;
use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\Bot;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Phergie\Irc\Client\React\Client;
use Phergie\Irc\Client\React\ClientInterface;
use Phergie\Irc\Connection;
use Phergie\Irc\ConnectionInterface;
use Phergie\Irc\Event\UserEventInterface;
use Phergie\Irc\Plugin\React\AutoJoin\Plugin as AutoJoinPlugin;
use Psr\Log\LoggerInterface;
use React\Promise\Promise;
use function Amp\asyncCall;
use function Amp\call;

class Mellon extends AbstractPlugin {
    /** @var Bot */
    private $bot;

    /** @var EventQueueInterface */
    private $queue;

    /** @var callable[] */
    private $plugins = [];

    public function __construct(string $connection, array $channels, array $plugins) {
        $config = [
            "connections" => [$this->createConnectionFromUri(new Uri($connection))],
            "plugins" => [],
        ];

        if ($channels) {
            $config["plugins"][] = new AutoJoinPlugin([
                "channels" => $channels,
                "wait-for-nickserv" => false, // identifying via PASS
            ]);
        }

        $config["plugins"][] = $this;
        $config["client"] = $this->createClient();

        $this->bot = new Bot;
        $this->bot->setConfig($config);

        $injector = new Injector;
        $injector->alias(\Amp\Artax\Client::class, BasicClient::class);
        $injector->alias(LoggerInterface::class, get_class($this->bot->getLogger()));
        $injector->share(new BasicClient);
        $injector->share($this);
        $injector->share($this->bot->getLogger());
        $injector->defineParam("githubOrg", "amphp");

        Loop::setErrorHandler(function (\Throwable $error) {
            $errors = [$error];

            while ($error = array_shift($errors)) {
                $this->bot->getLogger()->critical("An uncaught exception: " . $error->getMessage());
                $this->bot->getLogger()->critical($error->getTraceAsString());

                if ($error instanceof MultiReasonException) {
                    $errors[] = $error->getReasons();
                } else {
                    $errors[] = $error;
                }
            }
        });

        $storage = new FileKeyValueStorage(__DIR__ . "/../data/mellon.json");

        foreach ($plugins as $plugin) {
            /** @var Plugin $plugin */
            $plugin = $injector->make($plugin, [
                "+storage" => function () use ($plugin, $storage) {
                    $lower = \strtolower(\strtr($plugin, "\\", "."));
                    return new PrefixKeyValueStorage($storage, $lower . ".");
                },
            ]);

            $endpoints = $plugin->getEndpoints();

            foreach ($channels as $channel) {
                $plugin->enableForChannel(new Channel($channel));
            }

            foreach ($endpoints as $command => $endpoint) {
                if (isset($this->plugins[$command])) {
                    throw new \Error("Duplicate command: $command");
                }

                $this->plugins[$command] = [$plugin, $endpoint];
            }
        }
    }

    private function createClient(): ClientInterface {
        // Don't bother to set a complete DNS adapter, just override resolveHostname
        $client = new class extends Client {
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
        };

        $client->setLoop(ReactAdapter::get());

        return $client;
    }

    public function start() {
        $this->bot->run(false);
    }

    public function getSubscribedEvents() {
        return [
            "irc.received.privmsg" => function (...$args) {
                $this->onMessage(...$args);
            },
            "connect.after.each" => function (...$args) {
                $this->onConnect(...$args);
            },
        ];
    }

    private function onMessage(UserEventInterface $event, EventQueueInterface $queue) {
        $text = $event->getParams()["text"];

        if (\substr($text, 0, 2) !== "!!") {
            return;
        }

        $channel = new Channel($event->getSource());
        $message = new Message($channel, $event->getNick(), $text);
        $command = Command::fromMessage($message);

        asyncCall(function () use ($command, $queue) {
            if (!isset($this->plugins[$command->getCommandName()])) {
                $this->sendMessage($command->getMessage()->getChannel(), "Sorry, can't find that command.");
                return;
            }

            $handler = $this->plugins[$command->getCommandName()];

            $message = yield call($handler, $command);

            if ($message !== null) {
                $this->sendMessage($command->getMessage()->getChannel(), $message);
            }
        });
    }

    private function onConnect(ConnectionInterface $connection) {
        $this->queue = $this->getEventQueueFactory()->getEventQueue($connection);
    }

    public function sendMessage(Channel $channel, string $text) {
        $this->queue->ircPrivmsg($channel->getName(), $text);
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