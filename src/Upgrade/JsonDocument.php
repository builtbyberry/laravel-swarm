<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Upgrade;

use RuntimeException;
use stdClass;

/** JSON object with exact string spans for narrowly scoped, byte-preserving edits. */
final class JsonDocument
{
    /** @var array<string, mixed> */
    public readonly array $data;

    /** @var list<array{string, int}> */
    private array $tokens;

    /** @var array<string, array{int, int}> */
    private array $strings = [];

    private int $cursor = 0;

    public function __construct(public readonly string $contents)
    {
        $object = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);

        if (! $object instanceof stdClass) {
            throw new RuntimeException('Expected a JSON object.');
        }

        $this->data = (array) json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null|[{}\[\]:,]/s', $contents, $matches, PREG_OFFSET_CAPTURE);
        $this->tokens = $matches[0];
        $this->scan([]);
    }

    /** @param list<string> $path */
    private function scan(array $path): void
    {
        [$token, $offset] = $this->tokens[$this->cursor++];

        if ($token === '{') {
            $keys = [];
            while ($this->tokens[$this->cursor][0] !== '}') {
                $key = (string) json_decode($this->tokens[$this->cursor++][0], true, 512, JSON_THROW_ON_ERROR);
                if (in_array($key, $keys, true)) {
                    throw new RuntimeException('Duplicate JSON object keys are not safe to edit.');
                }
                $keys[] = $key;
                $this->cursor++; // colon; json_decode already validates grammar.
                $this->scan([...$path, $key]);
                if ($this->tokens[$this->cursor][0] === ',') {
                    $this->cursor++;
                }
            }
            $this->cursor++;
        } elseif ($token === '[') {
            $index = 0;
            while ($this->tokens[$this->cursor][0] !== ']') {
                $this->scan([...$path, (string) $index++]);
                if ($this->tokens[$this->cursor][0] === ',') {
                    $this->cursor++;
                }
            }
            $this->cursor++;
        } elseif (str_starts_with($token, '"')) {
            $this->strings[json_encode($path, JSON_THROW_ON_ERROR)] = [$offset, strlen($token)];
        }
    }

    /** @param list<array{section: string, package: string, to: string}> $edits */
    public function replace(array $edits): string
    {
        $replacements = [];
        foreach ($edits as $edit) {
            $key = json_encode([$edit['section'], $edit['package']], JSON_THROW_ON_ERROR);
            if (! isset($this->strings[$key])) {
                throw new RuntimeException('The selected dependency is not an editable JSON string.');
            }
            [$offset, $length] = $this->strings[$key];
            $replacements[$offset] = [$length, json_encode($edit['to'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
        }
        krsort($replacements);
        $contents = $this->contents;
        foreach ($replacements as $offset => [$length, $value]) {
            $contents = substr_replace($contents, $value, $offset, $length);
        }

        return $contents;
    }
}
