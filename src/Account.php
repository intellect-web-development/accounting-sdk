<?php

declare(strict_types=1);

namespace IWD\AccountingSdk;

/**
 * Счёт в системе учёта. Продукт хранит uuid и bearerKey у себя: bearerKey нужен для списания владельцем
 * и баланса, сервис отдаёт его только при открытии счёта.
 */
final readonly class Account
{
    public function __construct(
        public string $uuid,
        public string $bearerKey,
        public string $accountSystemCode,
        /** Десятичная строка, например «0.00» */
        public string $balance,
    ) {
    }
}
