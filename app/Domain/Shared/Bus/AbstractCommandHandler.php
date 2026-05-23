<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * Template Method base for command handlers.
 *
 * ... (full content from patch) ...