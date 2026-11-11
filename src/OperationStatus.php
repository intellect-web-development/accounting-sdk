<?php

declare(strict_types=1);

namespace IWD\AccountingSdk;

enum OperationStatus: string
{
    case Fulfilled = 'fulfilled';
    case Canceled = 'canceled';
}
