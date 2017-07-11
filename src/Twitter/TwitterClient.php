<?php

namespace Kelunik\Mellon\Twitter;

use Amp\Artax\Client;
use Amp\Artax\FormBody;
use Amp\Artax\HttpException;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Promise;
use Amp\Uri\Uri;
use function Amp\call;

class TwitterClient {
    private $http;
    private $consumerKey;
    private $consumerSecret;
    private $accessToken;
    private $accessTokenSecret;

    public function __construct(Client $http, string $consumerKey, string $consumerSecret, string $accessToken, string $accessTokenSecret) {
        $this->http = $http;
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->accessToken = $accessToken;
        $this->accessTokenSecret = $accessTokenSecret;
    }

    public function uploadImage(string $path): Promise {
        $body = new FormBody;
        $body->addFile("media", $path);

        $request = (new Request("https://upload.twitter.com/1.1/media/upload.json", "POST"))->withBody($body);
        $request = $this->signRequest($request);

        return call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->http->request($request);

            if ($response->getStatus() !== 200) {
                throw new HttpException("Invalid response: " . $response->getStatus() . " - " . yield $response->getBody());
            }

            $body = yield $response->getBody();
            $data = \json_decode($body, true);

            return $data["media_id"];
        });
    }

    public function tweet(string $text, array $mediaIds = []): Promise {
        $params = [
            "status" => $text,
            "enable_dm_commands" => "false",
            "media_ids" => implode(",", $mediaIds),
        ];

        $body = new FormBody;
        $body->addFields($params);

        $request = new Request("https://api.twitter.com/1.1/statuses/update.json", "POST");
        $request = $this->signRequest($request->withBody($body), $params);

        return call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->http->request($request);

            if ($response->getStatus() !== 200) {
                throw new HttpException("Invalid response: " . $response->getStatus() . " - " . yield $response->getBody());
            }

            $body = yield $response->getBody();
            $data = \json_decode($body, true);

            return $data;
        });
    }

    public function requestAccessToken() {
        $request = new Request("https://api.twitter.com/oauth/request_token", "POST");
        $request = $this->signRequest($request);

        return call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->http->request($request);

            if ($response->getStatus() !== 200) {
                throw new HttpException("Invalid response: " . $response->getStatus() . " - " . yield $response->getBody());
            }

            $body = yield $response->getBody();

            return $body;
        });
    }

    public function verifyAccessToken(string $verifier) {
        $params = [
            "oauth_verifier" => $verifier,
        ];

        $body = new FormBody;
        $body->addFields($params);

        $request = new Request("https://api.twitter.com/oauth/access_token", "POST");
        $request = $this->signRequest($request->withBody($body), $params);

        return call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->http->request($request);

            if ($response->getStatus() !== 200) {
                throw new HttpException("Invalid response: " . $response->getStatus() . " - " . yield $response->getBody());
            }

            $body = yield $response->getBody();

            return $body;
        });
    }

    private function signRequest(Request $request, array $additionalParams = []): Request {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();

        $params = [
            "oauth_consumer_key" => $this->consumerKey,
            "oauth_nonce" => $nonce,
            "oauth_signature_method" => "HMAC-SHA1",
            "oauth_timestamp" => $timestamp,
            "oauth_token" => $this->accessToken,
            "oauth_version" => "1.0",
        ];

        $authorization = "OAuth ";

        foreach ($params as $key => $param) {
            $authorization .= rawurlencode($key) . '="' . rawurlencode($param) . '", ';
        }

        $uri = new Uri($request->getUri());

        $queryParams = $uri->getAllQueryParameters();
        $encodedParams = [];

        foreach (array_merge($params, $queryParams, $additionalParams) as $key => $value) {
            $encodedParams[\rawurlencode($key)] = \rawurlencode(\is_array($value) ? $value[0] : $value);
        }

        asort($encodedParams);
        ksort($encodedParams);

        $signingData = $request->getMethod() . "&" . \rawurlencode(\strtok($request->getUri(), "?")) . "&" . \rawurlencode(\http_build_query($encodedParams, '', '&', \PHP_QUERY_RFC3986));
        $signature = base64_encode(hash_hmac("sha1", $signingData, \rawurlencode($this->consumerSecret) . "&" . \rawurlencode($this->accessTokenSecret), true));

        return $request->withHeader("authorization", $authorization . 'oauth_signature="' . \rawurlencode($signature) . '"');
    }
}