<?php

declare(strict_types=1);

namespace Debi\Util;

/**
 * Case-insensitive associative array for HTTP headers, which RFC 7230 defines
 * as case-insensitive. Preserves the original casing of the first time a key
 * was set for display purposes.
 *
 * @implements \ArrayAccess<string, string>
 * @implements \IteratorAggregate<string, string>
 */
final class CaseInsensitiveArray implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<string, string> normalized lowercase key => value */
    private array $values = [];

    /** @var array<string, string> normalized lowercase key => original-cased key */
    private array $originalKeys = [];

    /**
     * @param array<string, string|array<int,string>> $initial
     */
    public function __construct(array $initial = [])
    {
        foreach ($initial as $k => $v) {
            $this[$k] = is_array($v) ? implode(', ', $v) : $v;
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && isset($this->values[strtolower($offset)]);
    }

    public function offsetGet(mixed $offset): ?string
    {
        if (!is_string($offset)) {
            return null;
        }
        return $this->values[strtolower($offset)] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_string($offset)) {
            throw new \InvalidArgumentException('Header name must be a string.');
        }
        $lower = strtolower($offset);
        $this->values[$lower] = (string) $value;
        $this->originalKeys[$lower] ??= $offset;
    }

    public function offsetUnset(mixed $offset): void
    {
        if (!is_string($offset)) {
            return;
        }
        $lower = strtolower($offset);
        unset($this->values[$lower], $this->originalKeys[$lower]);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function getIterator(): \Generator
    {
        foreach ($this->values as $lower => $v) {
            yield $this->originalKeys[$lower] => $v;
        }
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->values as $lower => $v) {
            $out[$this->originalKeys[$lower]] = $v;
        }
        return $out;
    }
}
