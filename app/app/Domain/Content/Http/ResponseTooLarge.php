<?php

declare(strict_types=1);

namespace App\Domain\Content\Http;

use RuntimeException;

/** 回應超過 mysite.content.max_response_bytes 時由串流回呼拋出，用來中止下載。 */
final class ResponseTooLarge extends RuntimeException {}
