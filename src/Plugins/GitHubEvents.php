<?php

namespace Kelunik\Mellon\Plugins;

use Amp\Artax\Client;
use Amp\Artax\Response;
use Amp\Loop;
use Kelunik\Mellon\Mellon;
use Kelunik\Mellon\Storage\KeyValueStorage;
use Psr\Log\LoggerInterface;

class GitHubEvents extends Plugin {
    private $watcher;
    private $mellon;
    private $http;
    private $githubOrg;
    private $logger;
    private $storage;

    public function __construct(Client $http, Mellon $mellon, string $githubOrg, LoggerInterface $logger, KeyValueStorage $storage) {
        $this->http = $http;
        $this->mellon = $mellon;
        $this->logger = $logger;
        $this->storage = $storage;
        $this->githubOrg = $githubOrg;

        $this->watcher = Loop::repeat(300000, function () {
            /** @var Response $response */
            $response = yield $this->http->request("https://api.github.com/orgs/" . \rawurlencode($this->githubOrg) . "/events");
            $body = yield $response->getBody();

            if ($response->getStatus() !== 200) {
                $this->logger->warning("Received invalid response from GitHub: " . $response->getStatus());
                return;
            }

            $events = \array_reverse(\json_decode($body, true));
            $lastId = $this->storage->get("last-id") ?? 0;

            foreach ($events as $event) {
                if ($event["id"] <= $lastId) {
                    continue;
                }

                $this->logger->debug("Processing GitHub event " . $event["id"]);

                $lastId = $event["id"];

                if ($event["type"] === "ReleaseEvent") {
                    if ($event["payload"]["action"] === "published") {
                        $this->send(
                            "%s released %s for %s.",
                            $event["actor"]["login"],
                            $event["payload"]["release"]["tag_name"],
                            $event["repo"]["name"]
                        );
                    }
                } else if ($event["type"] === "IssuesEvent") {
                    $this->send(
                        "%s %s %s (%s).",
                        $event["payload"]["issue"]["user"]["login"],
                        $event["payload"]["action"],
                        $event["payload"]["issue"]["html_url"],
                        $event["payload"]["issue"]["title"]
                    );
                } else if ($event["type"] === "PullRequestEvent") {
                    $this->send(
                        "%s %s %s (%s).",
                        $event["payload"]["pull_request"]["user"]["login"],
                        $event["payload"]["action"],
                        $event["payload"]["pull_request"]["html_url"],
                        $event["payload"]["pull_request"]["title"]
                    );
                }
            }

            $this->storage->set("last-id", $lastId);
        });
    }

    private function send(string $format, ...$args) {
        $message = \sprintf($format, ...$args);

        foreach ($this->getChannels() as $channel) {
            $this->mellon->sendMessage($channel, $message);
        }
    }

    public function getDescription(): string {
        return "Pushes GitHub events.";
    }

    public function getEndpoints(): array {
        return [];
    }
}