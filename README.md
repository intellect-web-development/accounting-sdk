# accounting-sdk

HTTP-клиент универсального сервиса [Accounting](../accounting) (учёт средств: счета, баланс, операции). Пакет `intellect-web-development/accounting-sdk`, namespace `IWD\AccountingSdk\`.

Чеклист подключения продукта: корневой [`../accounting/INTEGRATION.md`](../accounting/INTEGRATION.md).

Сервис не знает пользователей и тарифы продукта. Продукт хранит у себя `uuid` счёта и ключ владельца `bearerKey`, а сервис отвечает за баланс, историю и целостность проводок. Шины между продуктом и сервисом нет: всё синхронно по HTTP.

```php
$client = new AccountingClient($httpClient, baseUrl: 'http://backend-accounting', token: $personalJwt);

// Счёт в системе учёта (код задаёт продукт: rub, bonus). bearerKey отдаётся один раз — сохраните
$account = $client->openAccount('rub');

// Проводки. Сумма — десятичная строка, не float. reference обязателен: ключ идемпотентности в пределах счёта
$posted = $client->credit($account->uuid, '500.00', 'Приветственный бонус', reference: 'welcome-bonus:42');
$posted = $client->debit($account->uuid, $account->bearerKey, '150.50', 'Оплата тарифа', reference: $paymentUuid);
$posted->operation->uuid;   // публичный идентификатор операции — храните в своей сущности
$posted->replayed;          // true: такая операция уже была, повтор баланс не менял

$client->balance($account->uuid, $account->bearerKey);   // '349.50'

// Откат: отмена по uuid, повтор безопасен
$client->cancel($posted->operation->uuid, 'Платёж не сохранён');

// Чтение
$client->operation($uuid);                                   // null — операции нет или она чужая
$client->operations([$uuid1, $uuid2]);                       // пакетно, до 200
$client->search([$account->uuid], type: OperationType::Debit, page: 1, size: 20);   // OperationList: items, totalCount
```

Режимы проводки: `credit` — зачисление, `debit` — списание владельцем (проверка ключа и остатка), `debitAnyway` — списание любой ценой для откатов и корректировок.

Токен — персональный JWT системы (аудитория `accounting`, scopes `accounting:accounts:{read,write}` и `accounting:operations:{read,write}`), его выдаёт integration-registry. Сервис работает только с данными системы из claim `sub`; чужой счёт неотличим от несуществующего.

## Ошибки

- Прикладные ошибки сервиса (4xx с телом `{error, message}`) приходят как `AccountingApiException` с полем `errorCode`: `insufficient_funds`, `invalid_bearer_key`, `account_blocked`, `invalid_amount`, `reference_conflict`, `account_not_found`, `operation_not_found`, `account_system_not_found`, `operation_in_use`. Константы — `AccountingApiException::*`.
- Повтор с тем же `reference`, но другой суммой или типом — `reference_conflict`.
- Ответы 401/403 без `error` (токен, scope, Customer выключен), 5xx и сбои транспорта пробрасываются исключениями Symfony HttpClient как есть.
- Неверная сумма, `reference`, `page`/`size` дают `InvalidArgumentException` до запроса.
- `operation()` возвращает `null` вместо 404.

## Согласованность с БД продукта

Общей транзакции нет. Порядок в продукте: сформировать `reference` → проводка → сохранить свою сущность (платёж, начисление). Если после проводки сохранение не удалось, отмените операцию (`cancel`) или повторите ту же проводку после починки: дубля не будет. Недоступность сервиса — отказ операции (в bond-screener 502), не «списать на веру».

## HTTP-контракт

`/api/sdk/*`, подробности и коды ошибок — в [`../accounting/INTEGRATION.md`](../accounting/INTEGRATION.md).

## Wiring в продукте

`examples/symfony/`: `services.yaml` (DI клиента), `AccountingGateway.php` (адаптер; домен продукта SDK не импортирует). В `composer.json` продукта — vcs-репозиторий и версия пакета, не path-репозиторий.

## Тесты

```shell
composer install
vendor/bin/phpunit
```
