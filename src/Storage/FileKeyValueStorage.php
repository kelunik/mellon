<?php

namespace Kelunik\Mellon\Storage;

class FileKeyValueStorage implements KeyValueStorage
{
    private string $storagePath;
    private $data;

    public function __construct(string $storagePath)
    {
        if (\file_exists($storagePath)) {
            $this->data = \json_decode(\file_get_contents($storagePath), true, 512, \JSON_THROW_ON_ERROR);
        } else {
            $this->data = [];
        }

        $this->storagePath = $storagePath;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
        $this->save();
    }

    private function save()
    {
        $json = \json_encode($this->data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        \file_put_contents($this->storagePath, $json);
    }
}