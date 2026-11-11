<?php

declare(strict_types=1);

namespace IWD\AccountingSdk;

use RuntimeException;

/**
 * Прикладная ошибка сервиса: 4xx с телом `{error, message}`. Различайте причины по `errorCode`.
 * Ошибки транспорта, 401/403 без `error` и 5xx пробрасываются исключениями Symfony HttpClient.
 */
final class AccountingApiException extends RuntimeException
{
    public const string ACCOUNT_NOT_FOUND = 'account_not_found';
    public const string OPERATION_NOT_FOUND = 'operation_not_found';
    public const string ACCOUNT_SYSTEM_NOT_FOUND = 'account_system_not_found';
    public const string INVALID_BEARER_KEY = 'invalid_bearer_key';
    public const string INSUFFICIENT_FUNDS = 'insufficient_funds';
    public const string ACCOUNT_BLOCKED = 'account_blocked';
    public const string INVALID_AMOUNT = 'invalid_amount';
    public const string REFERENCE_CONFLICT = 'reference_conflict';
    public const string OPERATION_IN_USE = 'operation_in_use';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function isInsufficientFunds(): bool
    {
        return self::INSUFFICIENT_FUNDS === $this->errorCode;
    }

    public function isNotFound(): bool
    {
        return in_array($this->errorCode, [self::ACCOUNT_NOT_FOUND, self::OPERATION_NOT_FOUND, self::ACCOUNT_SYSTEM_NOT_FOUND], true);
    }
}
