<?php

declare(strict_types=1);

namespace Debi;

/**
 * A paginated list response.
 *
 * The Debi API returns lists in a JSON envelope with three top-level keys:
 *
 *     {
 *       "data":  [ ...items... ],
 *       "links": { "first": "...", "last": "...", "prev": null, "next": "...|null" },
 *       "meta":  { "path": "...", "per_page": N, "next_cursor": "...|null", ... }
 *     }
 *
 * Iterate directly over the current page, or with {@see autoPagingIterator()}
 * across all pages using `meta.next_cursor`.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Collection extends DebiObject implements \IteratorAggregate
{
    private ?ApiRequestor $requestor = null;
    private string $requestPath = '';
    /** @var array<int|string,mixed> */
    private array $requestParams = [];
    private ?RequestOptions $requestOpts = null;

    /**
     * Hydrate a Collection from the raw decoded response body. Items inside
     * `data` are converted to their concrete resource classes via the `object`
     * discriminator.
     *
     * @param array<string,mixed> $body
     */
    public static function fromList(array $body): self
    {
        $rawItems = $body['data'] ?? [];
        $items = [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                $items[] = Util\Util::convertToObject($item);
            }
        }

        $instance = new self();
        $instance->values = [
            'data' => $items,
            'links' => $body['links'] ?? null,
            'meta' => $body['meta'] ?? null,
        ];
        return $instance;
    }

    /**
     * Bind the parameters that produced this list so {@see autoPagingIterator()}
     * can transparently fetch subsequent pages. Called by services after each
     * list-style request — user code should not invoke this directly.
     *
     * @internal
     *
     * @param array<int|string,mixed> $params
     */
    public function setRequestParams(
        ApiRequestor $requestor,
        string $path,
        array $params,
        ?RequestOptions $opts,
    ): void {
        $this->requestor = $requestor;
        $this->requestPath = $path;
        $this->requestParams = $params;
        $this->requestOpts = $opts;
    }

    /**
     * @return array<int, mixed>
     */
    public function data(): array
    {
        $data = $this->values['data'] ?? [];
        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Whether there is at least one more page after the current one.
     */
    public function hasMore(): bool
    {
        $links = $this->values['links'] ?? null;
        if (!is_array($links)) {
            return false;
        }
        $next = $links['next'] ?? null;
        return is_string($next) && $next !== '';
    }

    /**
     * Cursor token to fetch the next page, when one exists.
     */
    public function nextCursor(): ?string
    {
        if (!$this->hasMore()) {
            return null;
        }
        $meta = $this->values['meta'] ?? null;
        if (is_array($meta) && isset($meta['next_cursor']) && is_string($meta['next_cursor'])) {
            return $meta['next_cursor'];
        }
        return null;
    }

    public function getIterator(): \Generator
    {
        foreach ($this->data() as $item) {
            yield $item;
        }
    }

    /**
     * Iterate over every item across all pages, transparently fetching the
     * next page when the current one is exhausted. Safe to interrupt; the
     * iterator keeps no resources beyond the next-page cursor.
     *
     * @return \Generator<int, mixed>
     */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;
        while (true) {
            foreach ($page->data() as $item) {
                yield $item;
            }
            $cursor = $page->nextCursor();
            if ($cursor === null || $page->requestor === null) {
                return;
            }
            $next = $page->fetchNextPage($cursor);
            if ($next === null) {
                return;
            }
            $page = $next;
        }
    }

    private function fetchNextPage(string $cursor): ?self
    {
        if ($this->requestor === null) {
            return null;
        }
        $params = $this->requestParams;
        $params['starting_after'] = $cursor;
        unset($params['ending_before']);

        [$body] = $this->requestor->request('GET', $this->requestPath, $params, $this->requestOpts);
        if (!isset($body['data']) || !is_array($body['data'])) {
            return null;
        }

        $next = self::fromList($body);
        $next->setRequestParams($this->requestor, $this->requestPath, $params, $this->requestOpts);
        return $next;
    }
}
