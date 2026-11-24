<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Request;

use InvalidArgumentException;

/**
 * A group of items for POST /scan/batch.
 *
 * The backend counts a whole batch as one scan against the daily quota and
 * answers with one result per item, in the same order.
 */
final class BatchCheckRequest
{
    /** The backend rejects a batch with more items than this. */
    public const MAX_ITEMS = 100;

    /**
     * The backend also rejects a batch whose content and raw messages add up
     * to more than 2 MB. The SDK does not check this: it cannot shorten the
     * data for you, and the backend answers with a 422 that comes back as a
     * skipped result.
     */
    public const MAX_TOTAL_BYTES = 2097152;

    /** @var list<CheckSpamRequest> */
    public readonly array $items;

    /**
     * @param list<CheckSpamRequest> $items Between 1 and MAX_ITEMS items.
     *
     * @throws InvalidArgumentException When the list is empty, too long, or has a wrong item.
     */
    public function __construct(array $items)
    {
        $count = count($items);
        if ($count === 0) {
            throw new InvalidArgumentException('A batch needs at least one item.');
        }
        if ($count > self::MAX_ITEMS) {
            throw new InvalidArgumentException(
                'A batch can have at most ' . self::MAX_ITEMS . ' items, got ' . $count . '.',
            );
        }

        foreach ($items as $item) {
            if (!$item instanceof CheckSpamRequest) {
                throw new InvalidArgumentException('Every batch item must be a CheckSpamRequest.');
            }
        }

        $this->items = array_values($items);
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (CheckSpamRequest $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
