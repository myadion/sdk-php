<?php

declare(strict_types=1);

namespace MyAdion;

/**
 * An object returned by the API.
 *
 * Fields read as properties or as array keys, nested objects are ApiObject
 * too, and lists stay PHP arrays:
 *
 *     $session->url;
 *     $session['url'];
 *     $payment->metadata->order_id;
 *     $payment->toArray();
 *
 * @implements \ArrayAccess<string,mixed>
 * @implements \IteratorAggregate<string,mixed>
 */
class ApiObject implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @param array<string,mixed> $values */
    final public function __construct(protected array $values = []) {}

    /**
     * Build an object from decoded JSON (stdClass or associative array).
     *
     * @param  array<array-key,mixed>|\stdClass  $values
     */
    public static function constructFrom(array|\stdClass $values): static
    {
        $out = [];
        foreach ((array) $values as $key => $value) {
            $out[(string) $key] = self::convert($value);
        }

        return new static($out);
    }

    /**
     * Turn decoded JSON into library values: objects become ApiObject
     * (Event for an event), lists stay lists.
     */
    public static function convert(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return (($value->object ?? null) === 'event')
                ? Event::constructFrom($value)
                : ApiObject::constructFrom($value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(static fn ($item) => self::convert($item), $value);
            }
            if (($value['object'] ?? null) === 'event') {
                return Event::constructFrom($value);
            }

            return ApiObject::constructFrom($value);
        }

        return $value;
    }

    public function __get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException('API objects are read-only. Send an update request instead.');
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->values);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('API objects are read-only. Send an update request instead.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('API objects are read-only. Send an update request instead.');
    }

    public function count(): int
    {
        return count($this->values);
    }

    /** @return \ArrayIterator<string,mixed> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * The object as plain arrays, recursively.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        /** @var array<string,mixed> */
        return self::unwrap($this->values);
    }

    public function jsonSerialize(): mixed
    {
        return $this->values === [] ? new \stdClass : $this->values;
    }

    public function __toString(): string
    {
        return (string) json_encode($this, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<array-key,mixed>  $values
     * @return array<array-key,mixed>
     */
    private static function unwrap(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if ($value instanceof self) {
                $out[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $out[$key] = self::unwrap($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
