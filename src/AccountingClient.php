<?php

declare(strict_types=1);

namespace IWD\AccountingSdk;

use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент универсального сервиса Accounting (учёт средств).
 *
 * Токен — персональный JWT системы (выдаёт integration-registry): сервис берёт код системы из claim `sub`
 * и работает только со счетами и операциями этой системы. Чужой счёт неотличим от несуществующего.
 *
 * Суммы — десятичные строки с точкой и не более чем двумя знаками («150.50»), float не используем.
 * Каждая проводка требует `reference` — ключ идемпотентности в пределах счёта. Повтор с теми же параметрами
 * возвращает исходную операцию (`replayed`), с другими — AccountingApiException::REFERENCE_CONFLICT.
 *
 * Прикладные ошибки (нет средств, неверный ключ, конфликт reference и т.д.) приходят как AccountingApiException.
 * Транспортные ошибки и 401/403/5xx пробрасываются исключениями Symfony HttpClient — обработка на стороне адаптера продукта.
 */
final readonly class AccountingClient
{
    public const int REFERENCE_MAX_LENGTH = 128;
    public const int BATCH_MAX = 200;
    public const int PAGE_SIZE_MAX = 200;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $token,
    ) {
    }

    /** Открывает счёт в системе учёта с этим кодом (например `rub`, `bonus`) */
    public function openAccount(string $accountSystemCode): Account
    {
        $data = $this->request('POST', '/accounts', ['json' => ['accountSystemCode' => $accountSystemCode]])['data'];

        return new Account(
            uuid: (string) $data['uuid'],
            bearerKey: (string) $data['bearerKey'],
            accountSystemCode: (string) $data['accountSystemCode'],
            balance: (string) $data['balance'],
        );
    }

    /** Баланс счёта. Нужен ключ владельца */
    public function balance(string $accountUuid, string $bearerKey): string
    {
        $data = $this->request('POST', '/accounts/balance', ['json' => ['accountUuid' => $accountUuid, 'bearerKey' => $bearerKey]])['data'];

        return (string) $data['balance'];
    }

    /** Зачисление. Ключ владельца не нужен */
    public function credit(string $accountUuid, string $amount, string $reason, string $reference): PostedOperation
    {
        return $this->post(mode: 'credit', accountUuid: $accountUuid, amount: $amount, reason: $reason, reference: $reference, bearerKey: null);
    }

    /** Списание владельцем: проверяются ключ и остаток (INSUFFICIENT_FUNDS, если системе не разрешён минус) */
    public function debit(string $accountUuid, string $bearerKey, string $amount, string $reason, string $reference): PostedOperation
    {
        return $this->post(mode: 'debit', accountUuid: $accountUuid, amount: $amount, reason: $reason, reference: $reference, bearerKey: $bearerKey);
    }

    /** Списание любой ценой, даже в минус. Для откатов и корректировок продукта; ключ не нужен */
    public function debitAnyway(string $accountUuid, string $amount, string $reason, string $reference): PostedOperation
    {
        return $this->post(mode: 'debit_anyway', accountUuid: $accountUuid, amount: $amount, reason: $reason, reference: $reference, bearerKey: null);
    }

    /** Отмена операции с пересчётом баланса. Повтор по уже отменённой возвращает её же */
    public function cancel(string $operationUuid, ?string $comment = null): Operation
    {
        $data = $this->request('POST', '/operations/' . rawurlencode($operationUuid) . '/cancel', [
            'json' => null === $comment ? new \stdClass() : ['comment' => $comment],
        ])['data'];

        return $this->mapOperation($data);
    }

    /** Операция по uuid. null — операции нет или она принадлежит другой системе */
    public function operation(string $operationUuid): ?Operation
    {
        try {
            return $this->mapOperation($this->request('GET', '/operations/' . rawurlencode($operationUuid))['data']);
        } catch (AccountingApiException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Пакетное чтение по uuid. Неизвестные и чужие uuid в результат не попадают.
     *
     * @param list<string> $operationUuids до 200 штук
     *
     * @return list<Operation>
     */
    public function operations(array $operationUuids): array
    {
        if ([] === $operationUuids) {
            return [];
        }
        if (count($operationUuids) > self::BATCH_MAX) {
            throw new InvalidArgumentException(sprintf('Не более %d операций за вызов.', self::BATCH_MAX));
        }

        $data = $this->request('POST', '/operations/batch', ['json' => ['uuids' => array_values($operationUuids)]])['data'];

        return array_map($this->mapOperation(...), array_values($data['items']));
    }

    /**
     * История операций по счетам, от новых к старым.
     *
     * @param list<string> $accountUuids пустой список даёт пустой результат без запроса
     */
    public function search(
        array $accountUuids,
        ?OperationType $type = null,
        ?string $accountSystemCode = null,
        int $page = 1,
        int $size = 20,
    ): OperationList {
        if ([] === $accountUuids) {
            return new OperationList(items: [], totalCount: 0);
        }
        if (count($accountUuids) > self::BATCH_MAX) {
            throw new InvalidArgumentException(sprintf('Не более %d счетов за вызов.', self::BATCH_MAX));
        }
        if ($page < 1 || $size < 1 || $size > self::PAGE_SIZE_MAX) {
            throw new InvalidArgumentException(sprintf('page ≥ 1, size от 1 до %d.', self::PAGE_SIZE_MAX));
        }

        $query = array_filter([
            'accountUuids' => implode(',', $accountUuids),
            'type' => $type?->value,
            'accountSystemCode' => $accountSystemCode,
            'page' => $page,
            'size' => $size,
        ], static fn (mixed $value): bool => null !== $value);

        $data = $this->request('GET', '/operations', ['query' => $query])['data'];

        return new OperationList(
            items: array_map($this->mapOperation(...), array_values($data['items'])),
            totalCount: (int) $data['totalCount'],
        );
    }

    private function post(string $mode, string $accountUuid, string $amount, string $reason, string $reference, ?string $bearerKey): PostedOperation
    {
        if ('' === $reference || mb_strlen($reference) > self::REFERENCE_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('reference: непустая строка до %d символов.', self::REFERENCE_MAX_LENGTH));
        }
        if (1 !== preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('amount: десятичная строка с точкой и не более чем двумя знаками, например «150.50».');
        }

        $response = $this->request('POST', '/operations', ['json' => array_filter([
            'accountUuid' => $accountUuid,
            'mode' => $mode,
            'amount' => $amount,
            'reason' => $reason,
            'reference' => $reference,
            'bearerKey' => $bearerKey,
        ], static fn (mixed $value): bool => null !== $value)]);

        return new PostedOperation(operation: $this->mapOperation($response['data']), replayed: 200 === $response['status']);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, data: array<string, mixed>}
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = ['Authorization' => 'Bearer ' . $this->token];

        $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/') . '/api/sdk' . $path, $options);
        try {
            $data = $response->toArray();
        } catch (ClientExceptionInterface $exception) {
            $body = json_decode($exception->getResponse()->getContent(false), true);
            if (is_array($body) && isset($body['error']) && is_string($body['error'])) {
                throw new AccountingApiException(
                    errorCode: $body['error'],
                    message: (string) ($body['message'] ?? $body['error']),
                    httpStatus: $exception->getResponse()->getStatusCode(),
                );
            }

            throw $exception;
        }

        return ['status' => $response->getStatusCode(), 'data' => $data];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapOperation(array $data): Operation
    {
        return new Operation(
            uuid: (string) $data['uuid'],
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            accountUuid: (string) $data['accountUuid'],
            accountSystemCode: (string) $data['accountSystemCode'],
            type: OperationType::from((string) $data['type']),
            status: OperationStatus::from((string) $data['status']),
            amount: (string) $data['amount'],
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            createdAt: new DateTimeImmutable((string) $data['createdAt']),
            canceledAt: isset($data['canceledAt']) ? new DateTimeImmutable((string) $data['canceledAt']) : null,
            canceledComment: isset($data['canceledComment']) ? (string) $data['canceledComment'] : null,
        );
    }
}
