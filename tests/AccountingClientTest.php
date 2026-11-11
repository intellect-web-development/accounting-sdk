<?php

declare(strict_types=1);

namespace IWD\AccountingSdk\Tests;

use InvalidArgumentException;
use IWD\AccountingSdk\AccountingApiException;
use IWD\AccountingSdk\AccountingClient;
use IWD\AccountingSdk\OperationStatus;
use IWD\AccountingSdk\OperationType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

final class AccountingClientTest extends TestCase
{
    private const string OPERATION = '{"uuid":"019c4000-0000-7000-8000-000000000001","reference":"pay-1","accountUuid":"019c4000-0000-7000-8000-0000000000aa","accountSystemCode":"rub","type":"debit","status":"fulfilled","amount":"150.50","reason":"Оплата","createdAt":"2026-11-11T10:00:00+00:00","canceledAt":null,"canceledComment":null}';

    /** @var array{method: string, url: string, headers: list<string>, body: string} */
    private array $captured = [];

    public function testOpenAccountMapsAccount(): void
    {
        $account = $this->client('{"uuid":"u-1","bearerKey":"key-1","accountSystemCode":"rub","balance":"0.00"}', 201)->openAccount('rub');

        self::assertSame('POST', $this->captured['method']);
        self::assertSame('http://accounting.test/api/sdk/accounts', $this->captured['url']);
        self::assertSame(['accountSystemCode' => 'rub'], $this->body());
        self::assertContains('Authorization: Bearer jwt-token', $this->captured['headers']);
        self::assertSame('u-1', $account->uuid);
        self::assertSame('key-1', $account->bearerKey);
        self::assertSame('0.00', $account->balance);
    }

    public function testBalanceSendsKeyInBodyNotInUrl(): void
    {
        $balance = $this->client('{"balance":"69.50"}')->balance('u-1', 'key-1');

        self::assertSame('69.50', $balance);
        self::assertSame('http://accounting.test/api/sdk/accounts/balance', $this->captured['url']);
        self::assertSame(['accountUuid' => 'u-1', 'bearerKey' => 'key-1'], $this->body());
    }

    public function testDebitSendsModeKeyAndReferenceAndMapsOperation(): void
    {
        $posted = $this->client(self::OPERATION, 201)->debit('u-1', 'key-1', '150.50', 'Оплата', 'pay-1');

        self::assertSame(
            ['accountUuid' => 'u-1', 'mode' => 'debit', 'amount' => '150.50', 'reason' => 'Оплата', 'reference' => 'pay-1', 'bearerKey' => 'key-1'],
            $this->body(),
        );
        self::assertFalse($posted->replayed);
        self::assertSame(OperationType::Debit, $posted->operation->type);
        self::assertSame(OperationStatus::Fulfilled, $posted->operation->status);
        self::assertSame('150.50', $posted->operation->amount);
        self::assertSame('pay-1', $posted->operation->reference);
        self::assertFalse($posted->operation->isCanceled());
    }

    public function testCreditAndDebitAnywayOmitBearerKey(): void
    {
        $this->client(self::OPERATION, 201)->credit('u-1', '10.00', 'Пополнение', 'top-1');
        self::assertSame('credit', $this->body()['mode']);
        self::assertArrayNotHasKey('bearerKey', $this->body());

        $this->client(self::OPERATION, 201)->debitAnyway('u-1', '10.00', 'Откат', 'fix-1');
        self::assertSame('debit_anyway', $this->body()['mode']);
        self::assertArrayNotHasKey('bearerKey', $this->body());
    }

    public function testReplayIsFlaggedByStatus200(): void
    {
        $posted = $this->client(self::OPERATION, 200)->credit('u-1', '10.00', 'Пополнение', 'top-1');

        self::assertTrue($posted->replayed);
    }

    public function testInsufficientFundsBecomesApiException(): void
    {
        try {
            $this->client('{"error":"insufficient_funds","message":"Insufficient funds"}', 422)->debit('u-1', 'key-1', '999.00', 'Оплата', 'pay-2');
            self::fail('Ожидалась AccountingApiException');
        } catch (AccountingApiException $exception) {
            self::assertTrue($exception->isInsufficientFunds());
            self::assertSame(AccountingApiException::INSUFFICIENT_FUNDS, $exception->errorCode);
            self::assertSame(422, $exception->httpStatus);
        }
    }

    public function testReferenceConflictBecomesApiException(): void
    {
        $this->expectException(AccountingApiException::class);

        $this->client('{"error":"reference_conflict","message":"x"}', 409)->credit('u-1', '10.00', 'Пополнение', 'top-1');
    }

    public function testClientErrorWithoutApiBodyIsRethrown(): void
    {
        $this->expectException(ClientExceptionInterface::class);

        $this->client('{"status":403,"messages":["Platform token was revoked."]}', 403)->balance('u-1', 'key-1');
    }

    public function testServerErrorIsRethrown(): void
    {
        $this->expectException(ServerExceptionInterface::class);

        $this->client('{}', 500)->balance('u-1', 'key-1');
    }

    public function testOperationReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->client('{"error":"operation_not_found","message":"x"}', 404)->operation('u-1'));
    }

    public function testOperationMapsFound(): void
    {
        $operation = $this->client(self::OPERATION)->operation('019c4000-0000-7000-8000-000000000001');

        self::assertNotNull($operation);
        self::assertSame('http://accounting.test/api/sdk/operations/019c4000-0000-7000-8000-000000000001', $this->captured['url']);
        self::assertSame('Оплата', $operation->reason);
    }

    public function testCancelPostsCommentAndMapsCanceledOperation(): void
    {
        $canceled = str_replace(['"status":"fulfilled"', '"canceledAt":null', '"canceledComment":null'], ['"status":"canceled"', '"canceledAt":"2026-11-11T11:00:00+00:00"', '"canceledComment":"rollback"'], self::OPERATION);

        $operation = $this->client($canceled)->cancel('u-1', 'rollback');

        self::assertSame('POST', $this->captured['method']);
        self::assertSame(['comment' => 'rollback'], $this->body());
        self::assertTrue($operation->isCanceled());
        self::assertSame('rollback', $operation->canceledComment);
        self::assertSame('2026-11-11T11:00:00+00:00', $operation->canceledAt?->format(DATE_ATOM));
    }

    public function testOperationsBatch(): void
    {
        $operations = $this->client('{"items":[' . self::OPERATION . ']}')->operations(['a', 'b']);

        self::assertCount(1, $operations);
        self::assertSame(['uuids' => ['a', 'b']], $this->body());
        self::assertSame([], $this->client('{}')->operations([]));
    }

    public function testSearchBuildsQueryAndMapsList(): void
    {
        $list = $this->client('{"items":[' . self::OPERATION . '],"totalCount":7,"page":2,"size":5}')->search(
            accountUuids: ['a', 'b'],
            type: OperationType::Debit,
            accountSystemCode: 'rub',
            page: 2,
            size: 5,
        );

        self::assertSame(7, $list->totalCount);
        self::assertCount(1, $list->items);
        self::assertStringContainsString('accountUuids=a%2Cb', $this->captured['url']);
        self::assertStringContainsString('type=debit', $this->captured['url']);
        self::assertStringContainsString('accountSystemCode=rub', $this->captured['url']);
        self::assertStringContainsString('page=2', $this->captured['url']);
    }

    public function testSearchWithoutAccountsMakesNoRequest(): void
    {
        $list = $this->client('{}')->search(accountUuids: []);

        self::assertSame(0, $list->totalCount);
        self::assertSame([], $this->captured);
    }

    public function testRejectsBadAmountReferenceAndPaging(): void
    {
        $client = $this->client('{}');
        foreach ([
            fn () => $client->credit('u-1', '1.234', 'r', 'ref'),
            fn () => $client->credit('u-1', '-1', 'r', 'ref'),
            fn () => $client->credit('u-1', '1.00', 'r', ''),
            fn () => $client->credit('u-1', '1.00', 'r', str_repeat('x', 129)),
            fn () => $client->search(accountUuids: ['a'], page: 0),
            fn () => $client->search(accountUuids: ['a'], size: 201),
        ] as $call) {
            try {
                $call();
                self::fail('Ожидалось InvalidArgumentException');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->captured);
            }
        }
    }

    private function client(string $body, int $status = 200): AccountingClient
    {
        $this->captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($body, $status): MockResponse {
            $this->captured = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => (string) ($options['body'] ?? ''),
            ];

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
        });

        return new AccountingClient(httpClient: $httpClient, baseUrl: 'http://accounting.test', token: 'jwt-token');
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->captured['body'], true, 512, JSON_THROW_ON_ERROR);
    }
}
