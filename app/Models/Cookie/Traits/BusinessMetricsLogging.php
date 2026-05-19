<?php

declare(strict_types=1);

namespace App\Models\Cookie\Traits;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookiePrice;

/**
 * Trait for business metrics logging.
 *
 * Tracks business-level events like low stock, price changes,
 * and popular cookies.
 *
 * @package App\Models\Cookie\Traits
 */
trait BusinessMetricsLogging
{
    /**
     * Query count tracking for popular cookies.
     *
     * @var array<int, int>
     */
    private array $queryCount = [];

    /**
     * Log business metrics after successful save.
     */
    private function logBusinessMetrics(Cookie $cookie, int $cookieId, ?CookiePrice $oldPrice): void
    {
        if (!$this->loggingConfig->businessMetricsEnabled) {
            return;
        }

        $this->logLowStockAlert($cookie, $cookieId);
        $this->logPriceChange($cookie, $cookieId, $oldPrice);
    }

    /**
     * Log low stock alert if stock is below threshold.
     */
    private function logLowStockAlert(Cookie $cookie, int $cookieId): void
    {
        if ($cookie->getStock() >= 10) {
            return;
        }

        $this->logger->warning('Low stock alert', [
            'domain' => 'Cookie',
            'repository' => 'CookieRepository',
            'cookieId' => $cookieId,
            'stock' => $cookie->getStock(),
            'threshold' => 10,
        ]);
    }

    /**
     * Log significant price change.
     */
    private function logPriceChange(Cookie $cookie, int $cookieId, ?CookiePrice $oldPrice): void
    {
        if ($oldPrice === null) {
            return;
        }

        $newPrice = $cookie->getPrice();
        $oldMinorUnits = $oldPrice->getMinorUnits();

        if ($oldMinorUnits === 0) {
            return;
        }

        $changePercent = abs(($newPrice->getMinorUnits() - $oldMinorUnits) / $oldMinorUnits * 100);

        if ($changePercent <= 10) {
            return;
        }

        $this->logger->info('Significant price change', [
            'domain' => 'Cookie',
            'repository' => 'CookieRepository',
            'cookieId' => $cookieId,
            'oldPrice' => $oldPrice->toDecimalString(),
            'newPrice' => $newPrice->toDecimalString(),
            'changePercent' => round($changePercent, 2),
        ]);
    }

    /**
     * Track popular cookie queries.
     */
    private function trackPopularCookie(int $id): void
    {
        if (!$this->loggingConfig->businessMetricsEnabled) {
            return;
        }

        if (!isset($this->queryCount[$id])) {
            $this->queryCount[$id] = 0;
        }

        $this->queryCount[$id]++;

        if ($this->queryCount[$id] <= 100) {
            return;
        }

        $this->logger->info('Popular cookie', [
            'domain' => 'Cookie',
            'repository' => 'CookieRepository',
            'cookieId' => $id,
            'queryCount' => $this->queryCount[$id],
        ]);
    }
}
