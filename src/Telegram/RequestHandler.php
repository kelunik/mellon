<?php

namespace Kelunik\Mellon\Telegram;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface as ReactPromise;
use unreal4u\TelegramAPI\Exceptions\ClientException;
use unreal4u\TelegramAPI\InternalFunctionality\TelegramResponse;
use unreal4u\TelegramAPI\RequestHandlerInterface;
use function Amp\call;

final class RequestHandler implements RequestHandlerInterface
{
    private HttpClient $httpClient;

    public function __construct(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function get(string $uri): ReactPromise
    {
        return $this->processRequest(new Request($uri));
    }

    public function post(string $uri, array $formFields): ReactPromise
    {
        $request = new Request($uri, 'POST');

        if (!empty($formFields['headers'])) {
            $request->setHeaders($formFields['headers']);
        }

        if (!empty($formFields['body'])) {
            $request->setBody($formFields['body']);
        }

        return $this->processRequest($request);
    }

    public function processRequest(Request $request): ReactPromise
    {
        $deferred = new Deferred;

        call(function () use ($request) {
            /** @var Response $response */
            $response = yield $this->httpClient->request($request);

            if ($response->getStatus() >= 400) {
                throw new ClientException($response->getReason(), $response->getStatus());
            }

            return new TelegramResponse(yield $response->getBody()->buffer(), $response->getHeaders());
        })->onResolve(static function ($error, $value) use ($deferred) {
            if ($error) {
                $deferred->reject($error);
            } else {
                $deferred->resolve($value);
            }
        });

        return $deferred->promise();
    }
}