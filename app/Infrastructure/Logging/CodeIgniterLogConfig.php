<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Domain\Shared\Ports\LogConfigPort;
use App\Infrastructure\Attributes\AutoBind;
use App\Infrastructure\Attributes\InfrastructureAdapter;
use Config\Logging;

/**
 * CodeIgniter adapter for {@see LogConfigPort}.
 *
 * Wraps the framework's {@see Logging} config so domain handlers can read
 * threshold / sampling / level values without taking a hard dependency on
 * `Config\Logging`. Carrying both attributes:
 *
 *  - {@see InfrastructureAdapter} — deptrac classification (Infrastructure)
 *  - {@see AutoBind}              — DI registration as `codeIgniterLogConfig`
 *
 * keeps the wiring zero-config: the {@see \App\Infrastructure\ServiceProvider\ServiceProviderRegistry}
 * scanner auto-instantiates this class with a fresh `Config\Logging` and
 * makes it available under the well-known `loggingConfig` repository key
 * (see {@see \Config\Services::registerProviders}).
 *
 * @package App\Infrastructure\Logging
 */
#[InfrastructureAdapter]
#[AutoBind]
final readonly class CodeIgniterLogConfig implements LogConfigPort
{
    /**
     * @param Logging $config The framework logging configuration.
     */
    public function __construct(private Logging $config)
    {
    }

    /**
     * @return int Slow-query threshold in milliseconds.
     */
    public function slowQueryThresholdMs(): int
    {
        return $this->config->slowQueryThresholdMs;
    }

    /**
     * @return float Random sampling rate, between 0.0 and 1.0.
     */
    public function samplingRate(): float
    {
        return $this->config->samplingRate;
    }

    /**
     * @return string Logging level token ('all' | 'errors' | 'slow' | 'sampling').
     */
    public function queryLoggingLevel(): string
    {
        return $this->config->queryLoggingLevel;
    }
}
