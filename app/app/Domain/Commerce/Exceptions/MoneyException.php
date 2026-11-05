<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use InvalidArgumentException;

/** 金額格式、範圍或運算不合法。 */
final class MoneyException extends InvalidArgumentException {}
