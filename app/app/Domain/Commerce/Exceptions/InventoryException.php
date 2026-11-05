<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use RuntimeException;

/** 庫存調整不合法（例如調整後為負數）。訊息可直接顯示給後臺使用者。 */
final class InventoryException extends RuntimeException {}
