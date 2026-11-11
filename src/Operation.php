<?php

declare(strict_types=1);

namespace IWD\AccountingSdk;

use DateTimeImmutable;

final readonly class Operation
{
    public function __construct(
        public string $uuid,
        public ?string $reference,
        public string $accountUuid,
        public string $accountSystemCode,
        public OperationType $type,
        public OperationStatus $status,
        /** Десятичная строка, например «150.50» */
        public string $amount,
        public ?string $reason,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $canceledAt,
        public ?string $canceledComment,
    ) {
    }

    public function isCanceled(): bool
    {
        return OperationStatus::Canceled === $this->status;
    }
}
