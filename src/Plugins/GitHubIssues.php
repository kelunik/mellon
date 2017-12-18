<?php

namespace Kelunik\Mellon\Plugins;

use Amp\Artax\Client;
use Amp\Artax\Response;
use Amp\Promise;
use Amp\Success;
use Kelunik\Mellon\Chat\Message;
use Kelunik\Mellon\Mellon;
use Psr\Log\LoggerInterface;
use function Amp\call;

class GitHubIssues extends Plugin {
    private $http;
    private $mellon;
    private $logger;
    private $githubClientId;
    private $githubClientSecret;

    public function __construct(
        Client $http, Mellon $mellon, string $githubClientId, string $githubClientSecret, LoggerInterface $logger
    ) {
        $this->http = $http;
        $this->mellon = $mellon;
        $this->logger = $logger;
        $this->githubClientId = $githubClientId;
        $this->githubClientSecret = $githubClientSecret;
    }

    public function onMessage(Message $message): Promise {
        if (!\preg_match_all('(([a-z-]+)#(\d+))i', $message->getText(), $matches, \PREG_SET_ORDER)) {
            return new Success;
        }

        return call(function () use ($message, $matches) {
            /** @var array[] $matches */
            foreach ($matches as $match) {
                $query = \http_build_query([
                    "client_id" => $this->githubClientId,
                    "client_secret" => $this->githubClientSecret,
                ]);

                $url = 'https://api.github.com/repos/amphp/' . \rawurlencode($match[1]) . '/issues/' . \rawurlencode($match[2]);

                $this->logger->debug('Requesting {url}', [
                    'url' => $url
                ]);

                /** @var Response $response */
                $response = yield $this->http->request($url . '?' . $query);
                $body = yield $response->getBody();
                $status = $response->getStatus();

                if ($status !== 200) {
                    if ($status === 404) {
                        continue;
                    }

                    $this->logger->warning("Received invalid response from GitHub: " . $response->getStatus());
                    continue;
                }

                $this->logger->notice("{remaining} of {limit} requests remaining for GitHub.com", [
                    "remaining" => $response->getHeader("x-ratelimit-remaining"),
                    "limit" => $response->getHeader("x-ratelimit-limit"),
                ]);

                $issue = \json_decode($body, true);

                $this->mellon->sendMessage($message->getChannel(), \sprintf(
                    '%s – %s',
                    $issue['html_url'],
                    \trim($issue['title'])
                ));
            }
        });
    }

    public function getDescription(): string {
        return "Posts links to mentioned GitHub issues.";
    }

    public function getEndpoints(): array {
        return [];
    }
}
