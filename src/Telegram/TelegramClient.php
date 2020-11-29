<?php

namespace Kelunik\Mellon\Telegram;

use Amp\Promise;
use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use unreal4u\TelegramAPI\TgLog;
use function Amp\call;

final class TelegramClient
{
    private string $chatId;
    private TgLog $telegram;
    private bool $disableUrlPreview = false;

    public function __construct(TgLog $telegram, string $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
    }

    public function sendMessage(string $text): Promise
    {
        return call(function () use ($text) {
            $request = new SendMessage;
            $request->chat_id = $this->chatId;
            $request->text = $text;
            $request->disable_web_page_preview = $this->disableUrlPreview;
            $request->disable_notification = true;

            yield $this->telegram->performApiRequest($request);
        });
    }

    public function disableUrlPreview(): void
    {
        $this->disableUrlPreview = true;
    }
}