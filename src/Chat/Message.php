<?php

namespace Kelunik\Mellon\Chat;

class Message {
    private $channel;
    private $author;
    private $text;

    public function __construct(Channel $channel, string $author, string $text) {
        $this->channel = $channel;
        $this->author = $author;
        $this->text = $text;
    }

    public function getChannel(): Channel {
        return $this->channel;
    }

    public function getAuthor(): string {
        return $this->author;
    }

    public function getText(): string {
        return $this->text;
    }
}