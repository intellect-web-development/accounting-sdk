<?php

declare(strict_types=1);

namespace IWD\AccountingSdk;

enum OperationType: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
