<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence\Concerns;

trait InteractsWithJsonColumns
{
    protected function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        return $encoded;
    }

    protected function decodeJson(?string $value, mixed $default): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
