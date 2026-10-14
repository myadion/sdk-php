<?php

declare(strict_types=1);

namespace MyAdion;

/**
 * One page of a list, with what is needed to walk through the next ones.
 *
 *     $page = $client->pay->payments->all(['per_page' => 50]);
 *     foreach ($page as $payment) { … }                       // this page only
 *     foreach ($page->autoPagingIterator() as $payment) { … } // every page
 *
 * The API paginates with `page` and `per_page`. The library understands the
 * shapes it returns: `{data, meta: {current_page, last_page}}`,
 * `{data, current_page, last_page}` and a bare list (a single page).
 *
 * @implements \IteratorAggregate<int,mixed>
 */
final class Collection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * @param  list<mixed>  $data
     * @param  (\Closure(int):Collection)|null  $fetchPage
     */
    public function __construct(
        public readonly array $data,
        public readonly ?int $currentPage = null,
        public readonly ?int $lastPage = null,
        public readonly ?int $perPage = null,
        public readonly ?int $total = null,
        private readonly bool $hasMore = false,
        private readonly ?\Closure $fetchPage = null,
        private readonly ?ApiObject $raw = null,
    ) {}

    /**
     * @param  array<string,mixed>  $params  the parameters of the request, `page` included
     * @param  (\Closure(int):Collection)|null  $fetchPage
     */
    public static function fromResponse(mixed $decoded, array $params = [], ?\Closure $fetchPage = null): self
    {
        if (is_array($decoded)) {
            return new self(array_values($decoded));
        }
        if (! $decoded instanceof ApiObject) {
            return new self([]);
        }

        $data = $decoded['data'];
        $data = is_array($data) ? array_values($data) : [];
        $meta = $decoded['meta'] instanceof ApiObject ? $decoded['meta'] : $decoded;

        $current = self::int($meta['current_page']);
        $last = self::int($meta['last_page']);
        $perPage = self::int($meta['per_page']);
        $total = self::int($meta['total']);

        if ($current !== null && $last !== null) {
            $hasMore = $current < $last;
        } else {
            $hasMore = $decoded['has_more'] === true;
            $current ??= self::int($params['page'] ?? null) ?? 1;
        }

        return new self($data, $current, $last, $perPage, $total, $hasMore, $fetchPage, $decoded);
    }

    public function hasMore(): bool
    {
        return $this->hasMore && $this->fetchPage !== null && $this->data !== [];
    }

    /** The next page, or null on the last one. */
    public function nextPage(): ?self
    {
        if (! $this->hasMore() || $this->fetchPage === null) {
            return null;
        }

        return ($this->fetchPage)(($this->currentPage ?? 1) + 1);
    }

    /**
     * Every item of this page and of the following ones, fetched lazily.
     *
     * @return \Generator<int,mixed>
     */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            foreach ($page->data as $item) {
                yield $item;
            }
            $page = $page->nextPage();
        }
    }

    /**
     * The full response, for the fields that are not the list itself
     * (`event_types` next to the webhook endpoints, for instance).
     */
    public function raw(): ?ApiObject
    {
        return $this->raw;
    }

    /** @return \ArrayIterator<int,mixed> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->data,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'has_more' => $this->hasMore,
        ];
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
