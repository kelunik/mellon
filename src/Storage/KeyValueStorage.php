<?php

namespace Kelunik\Mellon\Storage;

interface KeyValueStorage
{
    public function has(string $key): bool;

    public function get(string $key);

    public function set(string $key, $value): void;
}