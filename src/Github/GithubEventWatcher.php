<?php

namespace Kelunik\Mellon\Github;

use Amp\File;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use Amp\Process\Process;
use Amp\Promise;
use Kelunik\Mellon\Storage\KeyValueStorage;
use Kelunik\Mellon\Telegram\TelegramClient;
use Kelunik\Mellon\Twitter\TwitterClient;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\ByteStream\buffer;
use function Amp\call;
use function Amp\Promise\rethrow;

class GithubEventWatcher
{
    private PsrLogger $logger;
    private HttpClient $httpClient;
    private TelegramClient $telegramClient;
    private ?TwitterClient $twitterClient;
    private KeyValueStorage $storage;
    private int $interval;

    public function __construct(
        PsrLogger $logger,
        HttpClient $httpClient,
        TelegramClient $telegramClient,
        ?TwitterClient $twitterClient,
        KeyValueStorage $storage,
        int $interval,
        string $githubClientId,
        string $githubClientSecret,
        string $githubOrganization
    ) {
        $this->logger = $logger;
        $this->httpClient = $httpClient;
        $this->telegramClient = $telegramClient;
        $this->twitterClient = $twitterClient;
        $this->storage = $storage;
        $this->interval = $interval;

        $this->watchGitHub($githubOrganization, $githubClientId, $githubClientSecret);
    }

    private function watchGitHub(
        string $githubOrganization,
        string $githubClientId,
        string $githubClientSecret
    ): void {
        Loop::repeat($this->interval * 60 * 1000,
            function () use ($githubOrganization, $githubClientId, $githubClientSecret) {
                $this->logger->debug("Requesting recent events for $githubOrganization from api.github.com");

                $auth = 'Basic ' . \base64_encode($githubClientId . ':' . $githubClientSecret);
                $request = new Request("https://api.github.com/orgs/" . \rawurlencode($githubOrganization) . "/events");
                $request->setHeader('authorization', $auth);

                /** @var Response $response */
                $response = yield $this->httpClient->request($request);
                $body = yield $response->getBody()->buffer();

                if ($response->getStatus() !== 200) {
                    $this->logger->warning("Received invalid response from api.github.com: " . $response->getStatus());
                    return;
                }

                $this->logger->notice("{remaining} of {limit} requests remaining for api.github.com", [
                    "remaining" => $response->getHeader("x-ratelimit-remaining"),
                    "limit" => $response->getHeader("x-ratelimit-limit"),
                ]);

                $events = \array_reverse(\json_decode($body, true, 512, \JSON_THROW_ON_ERROR));
                $lastId = $beforeId = $this->storage->get("last-id.{$githubOrganization}") ?? 0;

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
                            yield $this->send(
                                "%s released %s %s. %s",
                                $event["actor"]["login"],
                                $event["repo"]["name"],
                                $event["payload"]["release"]["tag_name"],
                                $event["payload"]["release"]["html_url"]
                            );

                            if ($this->twitterClient !== null && \strtok($event["repo"]["name"], "/") === "amphp") {
                                rethrow(call(function () use ($event) {
                                    if ($event["repo"]["name"] === "amphp/windows-process-wrapper") {
                                        return; // ignore releases, as it's only bundled and not a separate package really.
                                    }

                                    $imgPath = \tempnam(\sys_get_temp_dir(), "mellon-twitter-release-");

                                    $process = new Process([
                                        __DIR__ . "/../../bin/generate-release",
                                        $event["repo"]["name"],
                                        $event["payload"]["release"]["tag_name"],
                                    ]);

                                    yield $process->start();

                                    [$png, $errors] = yield [
                                        buffer($process->getStdout()),
                                        buffer($process->getStderr()),
                                    ];

                                    $status = yield $process->join();

                                    if ($status !== 0) {
                                        throw new \Exception("Release sub-process failed ({$status}): {$errors}");
                                    }

                                    yield File\put($imgPath, $png);

                                    $mediaId = yield $this->twitterClient->uploadImage($imgPath);

                                    yield $this->twitterClient->tweet(\sprintf(
                                        "%s %s released. %s",
                                        $event["repo"]["name"],
                                        $event["payload"]["release"]["tag_name"],
                                        $event["payload"]["release"]["html_url"]
                                    ), [
                                        $mediaId,
                                    ]);

                                    yield File\unlink($imgPath);
                                }));
                            }
                        }
                    } elseif ($event["type"] === "IssuesEvent") {
                        yield $this->send(
                            "%s %s %s (%s).",
                            $event["actor"]["login"],
                            $event["payload"]["action"],
                            $event["payload"]["issue"]["html_url"],
                            $event["payload"]["issue"]["title"]
                        );
                    } elseif ($event["type"] === "PullRequestEvent") {
                        $action = $event['payload']['action'];
                        if ($event["payload"]["pull_request"]["merged"]) {
                            $action = 'merged';
                        }

                        yield $this->send(
                            "%s %s %s (%s).",
                            $event["actor"]["login"],
                            $action,
                            $event["payload"]["pull_request"]["html_url"],
                            $event["payload"]["pull_request"]["title"]
                        );
                    }
                }

                $this->storage->set("last-id.{$githubOrganization}", $lastId);
            });
    }

    private function send(string $format, ...$args): Promise
    {
        return $this->telegramClient->sendMessage(\sprintf($format, ...$args));
    }
}
