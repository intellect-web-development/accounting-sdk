<?php

declare(strict_types=1);

namespace IWD\AccountingSdk;

final readonly class OperationList
{
    /** @param list<Operation> $items */
    public function __construct(
        public array $items,
        public int $totalCount,
    ) {
    }
}
