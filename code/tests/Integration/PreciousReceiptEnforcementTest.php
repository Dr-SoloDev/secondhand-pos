<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ENG-1: precious receipt enforcement.
 *
 * Verifies that purchase orders containing items in categories flagged
 * with requires_precious_receipt = 1 cannot be completed without an
 * attached receipt image.
 *
 * What to test:
 * - Submitting a PO with a precious category item and no photo → HTTP 422
 * - Submitting a PO with a precious category item and a valid photo → HTTP 200/201
 * - Submitting a PO with non-precious items only and no photo → allowed (HTTP 200/201)
 *
 * Setup requirements:
 * - A test database with at least one category where requires_precious_receipt = 1
 * - A seeded test user with PO creation permission
 * - The API router bootstrapped via base-pos/api/autoload.php
 */
class PreciousReceiptEnforcementTest extends TestCase
{
    /**
     * TODO: Bootstrap HTTP client or call controller directly.
     * Example: $this->client = new TestHttpClient(baseUri: 'http://localhost/api');
     * Or instantiate PurchaseOrdersController and call its store() method directly
     * with a mocked Request object.
     */

    protected function setUp(): void
    {
        parent::setUp();
        // TODO: Set up test DB connection using .env.test credentials
        // TODO: Run migrations 043–045 against test DB if not already applied
        // TODO: Seed: one precious category, one normal category, one test user
    }

    public function testPreciousItemWithoutReceiptIsRejected(): void
    {
        // TODO: Build a PO payload with an item in a precious category, no photo attached.
        // POST to /api/purchase-orders (or call controller directly).
        // Assert response status 422 and error message references receipt requirement.
        $this->markTestIncomplete('TODO: wire up HTTP client/controller and fill this test');
    }

    public function testPreciousItemWithReceiptIsAccepted(): void
    {
        // TODO: Build a PO payload with an item in a precious category, photo attached.
        // Assert response status 200 or 201 — order created successfully.
        $this->markTestIncomplete('TODO: wire up HTTP client/controller and fill this test');
    }

    public function testNonPreciousItemWithoutReceiptIsAccepted(): void
    {
        // TODO: Build a PO payload with items only in non-precious categories, no photo.
        // Assert response status 200 or 201 — no receipt required, order created.
        $this->markTestIncomplete('TODO: wire up HTTP client/controller and fill this test');
    }

    protected function tearDown(): void
    {
        // TODO: Roll back test DB changes or truncate seeded rows
        parent::tearDown();
    }
}
