<?php

declare(strict_types=1);

namespace App\Common\Accounting;

use IWD\AccountingSdk\Account;
use IWD\AccountingSdk\AccountingApiException;
use IWD\AccountingSdk\AccountingClient;
use IWD\AccountingSdk\Operation;
use IWD\AccountingSdk\PostedOperation;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Пример адаптера продукта: единственная точка связи с Accounting.
 * Скопировать в продукт при opt-in; домен AccountingClient не импортирует.
 *
 * Недоступность сервиса превращается в AccountingUnavailableHttpException (в продукте — 502): баланс неизвестен,
 * ничего не списывается. Прикладные ошибки (AccountingApiException) пробрасываются как есть: продукт решает, что ответить.
 */
final readonly class AccountingGateway
{
    public function __construct(
        private AccountingClient $accountingClient,
        private LoggerInterface $logger,
    ) {
    }

    public function openAccount(string $accountSystemCode): Account
    {
        return $this->call(
            operation: 'openAccount',
            request: fn (): Account => $this->accountingClient->openAccount($accountSystemCode),
        );
    }

    public function balance(string $accountUuid, string $bearerKey): string
    {
        return $this->call(
            operation: 'balance',
            request: fn (): string => $this->accountingClient->balance($accountUuid, $bearerKey),
        );
    }

    /** @param string $reference ключ идемпотентности: например UUID платежа продукта */
    public function debit(string $accountUuid, string $bearerKey, string $amount, string $reason, string $reference): PostedOperation
    {
        return $this->call(
            operation: 'debit',
            request: fn (): PostedOperation => $this->accountingClient->debit($accountUuid, $bearerKey, $amount, $reason, $reference),
        );
    }

    public function credit(string $accountUuid, string $amount, string $reason, string $reference): PostedOperation
    {
        return $this->call(
            operation: 'credit',
            request: fn (): PostedOperation => $this->accountingClient->credit($accountUuid, $amount, $reason, $reference),
        );
    }

    public function cancel(string $operationUuid, ?string $comment = null): Operation
    {
        return $this->call(
            operation: 'cancel',
            request: fn (): Operation => $this->accountingClient->cancel($operationUuid, $comment),
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $request
     *
     * @return T
     */
    private function call(string $operation, callable $request): mixed
    {
        try {
            return $request();
        } catch (AccountingApiException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            $this->logger->error('Accounting request failed', [
                'operation' => $operation,
                'status' => $exception->getResponse()->getStatusCode(),
            ]);
            throw new AccountingUnavailableHttpException($exception);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Accounting transport failed', ['operation' => $operation, 'error' => $exception->getMessage()]);
            throw new AccountingUnavailableHttpException($exception);
        }
    }
}
