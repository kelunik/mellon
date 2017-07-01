<?php

namespace Kelunik\Mellon\Plugins;

use Kelunik\Mellon\Chat\Command;
use Kelunik\Mellon\Storage\KeyValueStorage;

class Canon extends Plugin {
    const USAGE = "Usage: !!canon topic | list | add topic content | remove topic";

    private $canons;
    private $storage;

    public function __construct(KeyValueStorage $storage) {
        $this->canons = $storage->get("canons") ?? [];
        $this->storage = $storage;

        var_dump($storage);
    }

    public function getDescription(): string {
        return "Posts links to canonical resources on various subjects";
    }

    public function getEndpoints(): array {
        return [
            "canon" => "handleCommand",
        ];
    }

    public function handleCommand(Command $command) {
        if (!$command->hasParameter(0)) {
            return self::USAGE;
        }

        $param = \strtolower($command->getParameter(0));

        switch ($param) {
            case "add":
                return $this->addTopic($command);

            case "remove":
                return $this->removeTopic($command);

            case "list":
                return $this->listTopics($command);

            default:
                return $this->getTopic($command);
        }
    }

    private function addTopic(Command $command): ?string {
        if ($command->getMessage()->getAuthor() !== "kelunik") {
            return "Sorry, but you can't do that.";
        }

        if (!$command->hasParameter(2)) {
            return "Usage: !!canon add topic content";
        }

        $args = $command->getParameters();

        \array_shift($args); // "add"
        $topic = \array_shift($args);
        $content = \implode(" ", $args);

        $this->canons[\strtolower($topic)] = $content;
        $this->storage->set("canons", $this->canons);

        return "'{$topic}' has been added.";
    }

    private function removeTopic(Command $command): ?string {
        if ($command->getMessage()->getAuthor() !== "kelunik") {
            return "Sorry, but you can't do that.";
        }

        if (!$command->hasParameter(1)) {
            return "Usage: !!canon remove topic";
        }

        $topic = \strtolower($command->getParameter(1));

        if (!isset($this->canons[$topic])) {
            return "Sorry, but that topic doesn't exist.";
        }

        unset($this->canons[$topic]);
        $this->storage->set("canons", $this->canons);

        return "'{$topic}' has been removed.";
    }

    private function listTopics(Command $command): ?string {
        return $this->canons ? \implode(", ", \array_keys($this->canons)) : "No topics exist yet.";
    }

    private function getTopic(Command $command): ?string {
        $topic = \strtolower($command->getParameter(0));

        if (isset($this->canons[$topic])) {
            return "[{$topic}] " . $this->canons[$topic];
        }

        $bestMatch = "";
        $bestMatchPercentage = 70; /* min */

        foreach ($this->canons as $name => $content) {
            \similar_text($topic, $name, $byRefPercentage);

            if ($byRefPercentage > $bestMatchPercentage) {
                $bestMatchPercentage = $byRefPercentage;
                $bestMatch = $name;
            }
        }

        return $bestMatch === ""
            ? "Sorry, can't find that topic."
            : "[{$bestMatch}] " . $this->canons[$bestMatch];
    }
}