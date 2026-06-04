<?php

declare(strict_types=1);

namespace Debi;

/**
 * Base for every object returned by the Debi API.
 *
 * Intentionally array-backed rather than declaring a typed property for every
 * field, so that the SDK does not need a release every time the API adds a
 * new field. Use PHPDoc `@property` on subclasses to document the shape.
 *
 * @implements \ArrayAccess<string, mixed>
 *
 * @phpstan-consistent-constructor
 */
class DebiObject implements \ArrayAccess, \JsonSerializable, \Countable
{
    /** @var array<string, mixed> */
    protected array $values = [];

    /** @var array<string, true> */
    protected array $unsavedValues = [];

    /**
     * Subclasses must keep this signature unchanged. {@see constructFrom()} is
     * the only supported way to create a populated instance.
     *
     * @final
     */
    public function __construct() {}

    /**
     * @param array<string,mixed> $values
     */
    public static function constructFrom(array $values): static
    {
        $instance = new static();
        $instance->refreshFrom($values);
        return $instance;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function refreshFrom(array $values): void
    {
        $this->values = [];
        foreach ($values as $k => $v) {
            $this->values[(string) $k] = Util\Util::convertToObject($v);
        }
        $this->unsavedValues = [];
    }

    public function __get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
        $this->unsavedValues[$key] = true;
    }

    public function __isset(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->values[$key], $this->unsavedValues[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[(string) $offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->__unset((string) $offset);
    }

    public function count(): int
    {
        return count($this->values);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->values as $k => $v) {
            $result[$k] = $this->valueToArray($v);
        }
        return $result;
    }

    private function valueToArray(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return array_map($this->valueToArray(...), $value);
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return $this->values;
    }
}
