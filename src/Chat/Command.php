<?php

namespace Kelunik\Mellon\Chat;

class Command {
    private $message;
    private $commandName;
    private $parameters;

    public static function fromMessage(Message $message): self {
        $command = new self;
        $command->message = $message;

        $text = $message->getText();

        if (\substr($text, 0, 2) !== "!!") {
            throw new \Error("Invalid message: Not a command");
        }

        $parts = \explode(" ", \substr($text, 2));
        $command->commandName = array_shift($parts);
        $command->parameters = $parts ?? [];

        return $command;
    }

    public function getMessage(): Message {
        return $this->message;
    }

    public function getCommandName(): string {
        return $this->commandName;
    }

    public function getParameters(): array {
        return $this->parameters;
    }

    public function hasParameter(int $index): bool {
        return isset($this->parameters[$index]);
    }

    public function getParameter(int $index): string {
        if (isset($this->parameters[$index])) {
            return $this->parameters[$index];
        }

        throw new \Error("Invalid parameter index ({$index})");
    }
}