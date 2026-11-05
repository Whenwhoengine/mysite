<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use RuntimeException;

/** 不合法的訂單狀態轉換（例如已匿名化訂單變更）。 */
final class InvalidOrderTransition extends RuntimeException {}
