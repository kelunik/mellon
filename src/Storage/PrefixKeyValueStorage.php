<?php

namespace Kelunik\Mellon\Storage;

class PrefixKeyValueStorage implements KeyValueStorage
{
    private string $prefix;
    private KeyValueStorage $storage;

    public function __construct(KeyValueStorage $storage, string $prefix)
    {
        $this->storage = $storage;
        $this->prefix = $prefix;
    }

    public function has(string $key): bool
    {
        return $this->storage->has($this->prefix . $key);
    }

    public function get(string $key)
    {
        return $this->storage->get($this->prefix . $key);
    }

    public function set(string $key, $value): void
    {
        $this->storage->set($this->prefix . $key, $value);
    }
}