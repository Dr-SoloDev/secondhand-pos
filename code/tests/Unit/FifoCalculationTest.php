<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FIFO cost calculation logic.
 *
 * Tests the first-in-first-out costing method used when calculating
 * cost of goods sold (COGS) and remaining inventory value.
 *
 * What to test:
 * - Given a series of purchase batches (qty + unit_cost), consuming N units
 *   should deduct from oldest batch first.
 * - Remaining inventory value should reflect only un-consumed batches.
 * - Edge case: consuming exactly the full quantity of a batch.
 * - Edge case: consuming across multiple batches in one sale.
 */
class FifoCalculationTest extends TestCase
{
    /**
     * TODO: Import or instantiate the FIFO calculation class/function once implemented.
     * Example: use App\Services\FifoCalculator;
     */

    public function testConsumesOldestBatchFirst(): void
    {
        // TODO: Set up two purchase batches:
        //   Batch A: qty=10, unit_cost=100
        //   Batch B: qty=10, unit_cost=120
        // Consume 5 units. Assert COGS = 5 * 100 = 500 (from batch A only).
        $this->markTestIncomplete('TODO: implement FifoCalculator and fill this test');
    }

    public function testConsumesAcrossMultipleBatches(): void
    {
        // TODO: Set up:
        //   Batch A: qty=5, unit_cost=100
        //   Batch B: qty=10, unit_cost=120
        // Consume 8 units. Assert COGS = (5 * 100) + (3 * 120) = 860.
        $this->markTestIncomplete('TODO: implement FifoCalculator and fill this test');
    }

    public function testRemainingInventoryValueAfterConsumption(): void
    {
        // TODO: After consuming units, assert the remaining batch qty and
        // total inventory value are correctly reduced.
        // Batch A: qty=10, cost=100 — consume 10 → batch A exhausted
        // Batch B: qty=5, cost=130 — untouched → remaining value = 650
        $this->markTestIncomplete('TODO: implement FifoCalculator and fill this test');
    }
}
