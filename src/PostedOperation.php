<?php

declare(strict_types=1);

namespace IWD\AccountingSdk;

/** Результат проводки. `replayed=true`: операция с этим reference уже была создана раньше, повтор баланс не менял */
final readonly class PostedOperation
{
    public function __construct(
        public Operation $operation,
        public bool $replayed,
    ) {
    }
}
