<?php

namespace Kelunik\Mellon\Plugins;

use Amp\Artax\Client;
use Amp\Artax\Response;
use Amp\Loop;
use Kelunik\Mellon\Chat\Channel;
use Kelunik\Mellon\Mellon;
use Kelunik\Mellon\Storage\KeyValueStorage;
use Kelunik\Mellon\Twitter\TwitterClient;
use Psr\Log\LoggerInterface;
use function Amp\Promise\rethrow;

class GitHubEvents extends Plugin {
    private $mellon;
    private $http;
    private $logger;
    private $storage;
    private $interval;
    private $twitterClient;

    public function __construct(
        Client $http, Mellon $mellon, int $interval, array $channels, string $githubClientId,
        string $githubClientSecret, LoggerInterface $logger, KeyValueStorage $storage,
        string $twitterConsumerKey, string $twitterConsumerSecret, string $twitterAccessToken, string $twitterAccessTokenSecret
    ) {
        $this->http = $http;
        $this->mellon = $mellon;
        $this->logger = $logger;
        $this->storage = $storage;
        $this->interval = $interval;
        $this->twitterClient = new TwitterClient($http, $twitterConsumerKey, $twitterConsumerSecret, $twitterAccessToken, $twitterAccessTokenSecret);

        $orgs = [];

        foreach ($channels as $channel => $channelOrgs) {
            if (!is_array($channelOrgs)) {
                $channelOrgs = [$channelOrgs];
            }

            foreach ($channelOrgs as $channelOrg) {
                $orgs[$channelOrg][] = $channel;
            }
        }

        foreach ($orgs as $githubOrg => $channels) {
            $this->watchGitHub($githubOrg, $channels, $githubClientId, $githubClientSecret);
        }
    }

    private function watchGitHub(string $githubOrg, array $channels, string $githubClientId, string $githubClientSecret) {
        Loop::repeat($this->interval * 60 * 1000, function () use ($githubOrg, $channels, $githubClientId, $githubClientSecret) {
            $this->logger->debug("Requesting recent events from GitHub");

            $query = \http_build_query([
                "client_id" => $githubClientId,
                "client_secret" => $githubClientSecret,
            ]);

            /** @var Response $response */
            $response = yield $this->http->request("https://api.github.com/orgs/" . \rawurlencode($githubOrg) . "/events?{$query}");
            $body = yield $response->getBody();

            if ($response->getStatus() !== 200) {
                $this->logger->warning("Received invalid response from GitHub: " . $response->getStatus());
                return;
            }

            $this->logger->notice("{remaining} of {limit} requests remaining for GitHub.com", [
                "remaining" => $response->getHeader("x-ratelimit-remaining"),
                "limit" => $response->getHeader("x-ratelimit-limit"),
            ]);

            $events = \array_reverse(\json_decode($body, true));
            $lastId = $beforeId = $this->storage->get("last-id.{$githubOrg}") ?? 0;

            foreach ($events as $event) {
                if ($event["id"] <= $lastId) {
                    continue;
                }

                $this->logger->debug("Processing GitHub event " . $event["id"]);

                $lastId = $event["id"];

                if ($beforeId === 0) {
                    // Don't spam if uninitialized
                    continue;
                }

                if ($event["type"] === "ReleaseEvent") {
                    if ($event["payload"]["action"] === "published") {
                        $this->send(
                            $channels,
                            "%s released %s for %s.",
                            $event["actor"]["login"],
                            $event["payload"]["release"]["tag_name"],
                            $event["repo"]["name"]
                        );

                        if (\strtok($event["repo"]["name"], "/") === "amphp") {
                            rethrow($this->twitterClient->tweet(\sprintf(
                                "New Release: %s %s #amphp",
                                $event["repo"]["name"],
                                $event["payload"]["release"]["tag_name"]
                            )));
                        }
                    }
                } else if ($event["type"] === "IssuesEvent") {
                    $this->send(
                        $channels,
                        "%s %s %s (%s).",
                        $event["actor"]["login"],
                        $event["payload"]["action"],
                        $event["payload"]["issue"]["html_url"],
                        $event["payload"]["issue"]["title"]
                    );
                } else if ($event["type"] === "PullRequestEvent") {
                    $this->send(
                        $channels,
                        "%s %s %s (%s).",
                        $event["actor"]["login"],
                        $event["payload"]["action"],
                        $event["payload"]["pull_request"]["html_url"],
                        $event["payload"]["pull_request"]["title"]
                    );
                }
            }

            $this->storage->set("last-id.{$githubOrg}", $lastId);
        });
    }

    private function send(array $channels, string $format, ...$args) {
        $message = \sprintf($format, ...$args);

        foreach ($channels as $channel) {
            $this->mellon->sendMessage(new Channel($channel), $message);
        }
    }

    public function getDescription(): string {
        return "Pushes GitHub events.";
    }

    public function getEndpoints(): array {
        return [];
    }
}