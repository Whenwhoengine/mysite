<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use RuntimeException;

/** 購物車數量或行數超過上限（訊息可直接顯示給訪客）。 */
final class CartLimitException extends RuntimeException {}
