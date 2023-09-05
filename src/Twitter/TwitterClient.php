<?php

namespace Kelunik\Mellon\Twitter;

use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use League\Uri\QueryString;

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

    public function uploadImage(string $path): string
    {
        $body = new Form;
        $body->addFile("media", $path);

        $request = new Request("https://upload.twitter.com/1.1/media/upload.json", "POST");
        $request->setBody($body);
        $request->setHeader("authorization", $this->signRequest($request));

        $response = $this->httpClient->request($request);
        if (!$response->isSuccessful()) {
            throw new HttpException("Invalid response: " . $response->getStatus() . " - " . $response->getRequest()->getUri() . " - " . $response->getBody()->buffer());
        }

        $data = \json_decode($response->getBody()->buffer(), true, 512, \JSON_THROW_ON_ERROR);

        return $data["media_id_string"];
    }

    public function tweet(string $text, array $mediaIds = []): array
    {
        $params = [
            "text" => $text,
            "media" => [
                "media_ids" => $mediaIds,
            ],
        ];

        $request = new Request("https://api.twitter.com/2/tweets", "POST");
        $request->setBody(\json_encode($params));
        $request->setHeader("authorization", $this->signRequest($request));
        $request->setHeader('content-type', 'application/json');

        $response = $this->httpClient->request($request);
        if (!$response->isSuccessful()) {
            throw new HttpException("Invalid response: " . $response->getStatus() . " - " . $response->getRequest()->getUri() . " - " . $response->getBody()->buffer());
        }

        return \json_decode($response->getBody()->buffer(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function signRequest(Request $request): string
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

        foreach (\array_merge($params, $queryParams) as $key => $value) {
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