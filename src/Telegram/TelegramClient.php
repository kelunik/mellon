<?php

namespace Kelunik\Mellon\Telegram;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Psr\Log\LoggerInterface;
use function json_encode;

final class TelegramClient
{
    private HttpClient $httpClient;
    private LoggerInterface $logger;
    private string $auth;
    private string $chatId;
    private bool $disableUrlPreview = false;

    public function __construct(HttpClient $httpClient, LoggerInterface $logger, string $auth, string $chatId)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->auth = $auth;
        $this->chatId = $chatId;
    }

    public function sendMessage(string $text): void
    {
        $this->logger->info('Sending message: ' . $text);

        $request = new Request('https://api.telegram.org/bot' . $this->auth . '/sendMessage', 'POST');
        $request->setHeader('content-type', 'application/json');
        $request->setBody(json_encode([
            'chat_id' => $this->chatId,
            'text' => $text,
            'disable_web_page_preview' => $this->disableUrlPreview,
            'disable_notification' => true,
        ]));

        $response = $this->httpClient->request($request);
        if (!$response->isSuccessful()) {
            $this->logger->error('Invalid response: ' . $response->getStatus());
            $this->logger->error($response->getBody()->buffer());
        }
    }

    public function disableUrlPreview(): void
    {
        $this->disableUrlPreview = true;
    }
}