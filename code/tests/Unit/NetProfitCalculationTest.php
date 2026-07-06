<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for net profit calculation logic.
 *
 * Tests the calculation of net profit on purchase orders and sales,
 * factoring in FIFO-based COGS, expenses, and selling price.
 *
 * What to test:
 * - Net profit = revenue - COGS - direct expenses
 * - Zero-profit edge case (cost == selling price)
 * - Negative profit (loss) when expenses exceed margin
 */
class NetProfitCalculationTest extends TestCase
{
    /**
     * TODO: Import or instantiate the profit calculation class/function.
     * Example: use App\Services\ProfitCalculator;
     */

    public function testBasicNetProfit(): void
    {
        // TODO: selling_price=500, fifo_cost=300, expenses=50
        // Expected net_profit = 500 - 300 - 50 = 150
        $this->markTestIncomplete('TODO: implement ProfitCalculator and fill this test');
    }

    public function testZeroProfitWhenCostEqualsRevenue(): void
    {
        // TODO: selling_price=300, fifo_cost=300, expenses=0
        // Expected net_profit = 0
        $this->markTestIncomplete('TODO: implement ProfitCalculator and fill this test');
    }

    public function testNetLossWhenExpensesExceedMargin(): void
    {
        // TODO: selling_price=400, fifo_cost=350, expenses=100
        // Expected net_profit = -50 (loss)
        $this->markTestIncomplete('TODO: implement ProfitCalculator and fill this test');
    }
}
