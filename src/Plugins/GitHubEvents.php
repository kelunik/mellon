<?php

namespace Kelunik\Mellon\Plugins;

use Amp\Artax\Client;
use Amp\Artax\Response;
use Amp\Loop;
use Kelunik\Mellon\Mellon;
use Psr\Log\LoggerInterface;

class GitHubEvents extends Plugin {
    private $watcher;
    private $mellon;
    private $storagePath;
    private $lastId;
    private $http;
    private $githubOrg;
    private $logger;

    public function __construct(Client $http, Mellon $mellon, string $githubOrg, LoggerInterface $logger, string $storagePath = null) {
        $this->http = $http;
        $this->mellon = $mellon;
        $this->logger = $logger;
        $this->storagePath = $storagePath ?? __DIR__ . "/../../data/plugin.github.events.last.txt";
        $this->githubOrg = $githubOrg;
        $this->load();

        $this->watcher = Loop::repeat(300000, function () {
            /** @var Response $response */
            $response = yield $this->http->request("https://api.github.com/orgs/" . \rawurlencode($this->githubOrg) . "/events");
            $body = yield $response->getBody();

            if ($response->getStatus() !== 200) {
                $this->logger->warning("Received invalid response from GitHub: " . $response->getStatus());
                return;
            }

            $events = \array_reverse(\json_decode($body, true));

            foreach ($events as $event) {
                if ($event["id"] <= $this->lastId) {
                    continue;
                }

                $this->logger->debug("Processing GitHub event " . $event["id"]);

                $this->lastId = $event["id"];

                if ($event["type"] === "ReleaseEvent") {
                    if ($event["payload"]["action"] === "published") {
                        $message = \sprintf(
                            "%s released %s for %s.",
                            $event["actor"]["login"],
                            $event["payload"]["release"]["tag_name"],
                            $event["repo"]["name"]
                        );

                        foreach ($this->getChannels() as $channel) {
                            $this->mellon->sendMessage($channel, $message);
                        }
                    }
                } else if ($event["type"] === "IssueCommentEvent") {
                    if ($event["payload"]["action"] === "created") {
                        $message = \sprintf(
                            "%s just commented on %s.",
                            $event["payload"]["comment"]["user"]["login"],
                            $event["payload"]["comment"]["html_url"]
                        );

                        foreach ($this->getChannels() as $channel) {
                            $this->mellon->sendMessage($channel, $message);
                        }
                    }
                } else if ($event["type"] === "IssuesEvent") {
                    if ($event["payload"]["action"] === "opened") {
                        $message = \sprintf(
                            "%s opened an issue @ %s (%s).",
                            $event["payload"]["issue"]["user"]["login"],
                            $event["payload"]["issue"]["html_url"],
                            $event["payload"]["issue"]["title"]
                        );

                        foreach ($this->getChannels() as $channel) {
                            $this->mellon->sendMessage($channel, $message);
                        }
                    }
                }
            }

            $this->save();
        });
    }

    private function load() {
        if (\file_exists($this->storagePath)) {
            $this->lastId = (int) \file_get_contents($this->storagePath);
        } else {
            $this->lastId = 0;
        }
    }

    private function save() {
        \file_put_contents($this->storagePath, (string) $this->lastId);
    }

    public function getDescription(): string {
        return "Pushes GitHub events.";
    }

    public function getEndpoints(): array {
        return [];
    }
}