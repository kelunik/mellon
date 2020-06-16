<?php

namespace Kelunik\Mellon\Twitter;

use Amp\Http\Client\Body\FormBody;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use League\Uri\Parser\QueryString;
use function Amp\call;

final class TwitterClient
{
    private HttpClient $httpClient;
    private string $consumerKey;
    private string $consumerSecret;
    private string $accessToken;
    private string $accessTokenSecret;

    public function __construct(
        HttpClient $httpClient,
        string $consumerKey,
        string $consumerSecret,
        string $accessToken,
        string $accessTokenSecret
    ) {
        $this->httpClient = $httpClient;
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->accessToken = $accessToken;
        $this->accessTokenSecret = $accessTokenSecret;
    }

    public function uploadImage(string $path): Promise
    {
        $body = new FormBody;
        $body->addFile("media", $path);

        $request = new Request("https://upload.twitter.com/1.1/media/upload.json", "POST");
        $request->setBody($body);
        $request->setHeader("authorization", $this->signRequest($request));

        return call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->httpClient->request($request);

            if ($response->getStatus() !== 200) {
                throw new HttpException("Invalid response: " . $response->getStatus() . " - " . yield $response->getBody()->buffer());
            }

            $body = yield $response->getBody()->buffer();
            $data = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

            return $data["media_id"];
        });
    }

    public function tweet(string $text, array $mediaIds = []): Promise
    {
        $params = [
            "status" => $text,
            "enable_dm_commands" => "false",
            "media_ids" => implode(",", $mediaIds),
        ];

        $body = new FormBody;
        $body->addFields($params);

        $request = new Request("https://api.twitter.com/1.1/statuses/update.json", "POST");
        $request->setBody($body);
        $request->setHeader("authorization", $this->signRequest($request, $params));

        return call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->httpClient->request($request);

            if ($response->getStatus() !== 200) {
                throw new HttpException("Invalid response: " . $response->getStatus() . " - " . yield $response->getBody()->buffer());
            }

            $body = yield $response->getBody()->buffer();

            return \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        });
    }

    public function requestAccessToken(): Promise
    {
        $request = new Request("https://api.twitter.com/oauth/request_token", "POST");
        $request->setHeader("authorization", $this->signRequest($request));

        return call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->httpClient->request($request);

            if ($response->getStatus() !== 200) {
                throw new HttpException("Invalid response: " . $response->getStatus() . " - " . yield $response->getBody()->buffer());
            }

            return yield $response->getBody()->buffer();
        });
    }

    public function verifyAccessToken(string $verifier): Promise
    {
        $params = [
            "oauth_verifier" => $verifier,
        ];

        $body = new FormBody;
        $body->addFields($params);

        $request = new Request("https://api.twitter.com/oauth/access_token", "POST");
        $request->setBody($body);
        $request->setHeader("authorization", $this->signRequest($request, $params));

        return call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->httpClient->request($request);

            if ($response->getStatus() !== 200) {
                throw new HttpException("Invalid response: " . $response->getStatus() . " - " . yield $response->getBody()->buffer());
            }

            return yield $response->getBody()->buffer();
        });
    }

    private function signRequest(Request $request, array $additionalParams = []): string
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $nonce = \bin2hex(\random_bytes(16));
        $timestamp = \time();

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
            $authorization .= \rawurlencode($key) . '="' . \rawurlencode($param) . '", ';
        }

        $uri = $request->getUri();

        $queryParams = QueryString::extract($uri->getQuery());
        $encodedParams = [];

        foreach (\array_merge($params, $queryParams, $additionalParams) as $key => $value) {
            $encodedParams[\rawurlencode($key)] = \rawurlencode(\is_array($value) ? $value[0] : $value);
        }

        \asort($encodedParams);
        \ksort($encodedParams);

        $query = [];
        foreach ($encodedParams as $key => $value) {
            $query[] = "$key=$value";
        }

        $signingData = $request->getMethod() . "&" //
            . \rawurlencode($request->getUri()->withQuery('')) . "&" //
            . \rawurlencode(\implode("&", $query));

        $key = \rawurlencode($this->consumerSecret) . "&" . \rawurlencode($this->accessTokenSecret);
        $signature = \base64_encode(\hash_hmac("sha1", $signingData, $key, true));

        return $authorization . 'oauth_signature="' . \rawurlencode($signature) . '"';
    }
}