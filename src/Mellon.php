<?php

namespace Kelunik\Mellon;

use Amp\Failure;
use Amp\Promise;
use Kelunik\Mellon\Chat\Channel;
use Kelunik\Mellon\Chat\Command;
use Kelunik\Mellon\Chat\Message;
use Kelunik\Mellon\Plugins\Plugin;
use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\Bot;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Phergie\Irc\ConnectionInterface;
use Phergie\Irc\Event\UserEventInterface;
use Phergie\Irc\Plugin\React\AutoJoin\Plugin as AutoJoinPlugin;
use function Amp\asyncCall;
use function Amp\call;

class Mellon extends AbstractPlugin {
    /** @var Bot */
    private $bot;

    /** @var EventQueueInterface */
    private $queue;

    /** @var callable[] */
    private $commands = [];

    /** @var Plugin[] */
    private $plugins = [];

    public function __construct(string $connection, array $channels, IrcClient $ircClient) {
        $phergiePlugins = [$this];

        if ($channels) {
            $phergiePlugins[] = new AutoJoinPlugin([
                "channels" => $channels,
                "wait-for-nickserv" => false, // identifying via PASS
            ]);
        }

        $this->bot = new IrcBot($connection, $phergiePlugins, $ircClient);
    }

    public function start(array $plugins) {
        foreach ($plugins as $plugin) {
            $this->plugins[] = $plugin;
            $endpoints = $plugin->getEndpoints();

            foreach ($endpoints as $command => $endpoint) {
                if (isset($this->commands[$command])) {
                    throw new \Error("Duplicate command: $command");
                }

                $this->commands[$command] = [$plugin, $endpoint];
            }
        }

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
        $channel = new Channel($event->getSource());
        $message = new Message($channel, $event->getNick(), $text);

        if (\substr($text, 0, 2) !== "!!") {
            foreach ($this->plugins as $plugin) {
                try {
                    Promise\rethrow($plugin->onMessage($message));
                } catch (\Throwable $e) {
                    Promise\rethrow(new Failure($e));
                }
            }
        }

        $command = Command::fromMessage($message);

        asyncCall(function () use ($command, $queue) {
            if (!isset($this->commands[$command->getCommandName()])) {
                $this->sendMessage($command->getMessage()->getChannel(), "Sorry, can't find that command.");
                return;
            }

            $handler = $this->commands[$command->getCommandName()];

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
}