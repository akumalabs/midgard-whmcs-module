<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\BillingSummaryBuilder;
use PHPUnit\Framework\TestCase;

final class BillingSummaryBuilderTest extends TestCase
{
    public function test_from_module_params_uses_canonical_fields_when_present(): void
    {
        $summary = BillingSummaryBuilder::fromModuleParams([
            'groupname' => 'VPS Servers',
            'product' => 'JP-AVA-1',
            'recurringamount' => '$10.00 USD',
            'billingcycle' => 'Monthly',
            'regdate' => '2026-03-01',
            'nextduedate' => '2026-04-01',
            'paymentmethod' => 'paypal',
        ]);

        $this->assertSame('VPS Servers - JP-AVA-1', $summary['billingProduct']);
        $this->assertSame('$10.00 USD', $summary['billingRecurringAmount']);
        $this->assertSame('Monthly', $summary['billingCycle']);
        $this->assertSame('2026-03-01', $summary['billingRegistrationDate']);
        $this->assertSame('2026-04-01', $summary['billingNextDueDate']);
        $this->assertSame('paypal', $summary['billingPaymentMethod']);
    }

    public function test_from_module_params_uses_alternate_fields_when_canonical_missing(): void
    {
        $summary = BillingSummaryBuilder::fromModuleParams([
            'productname' => 'Compute Slice C2',
            'amount' => 'EUR 19.99',
            'billing_cycle' => 'Quarterly',
            'registrationdate' => '2026-01-12',
            'next_due_date' => '2026-04-12',
            'paymentmethodname' => 'Credit Card',
        ]);

        $this->assertSame('Compute Slice C2', $summary['billingProduct']);
        $this->assertSame('EUR 19.99', $summary['billingRecurringAmount']);
        $this->assertSame('Quarterly', $summary['billingCycle']);
        $this->assertSame('2026-01-12', $summary['billingRegistrationDate']);
        $this->assertSame('2026-04-12', $summary['billingNextDueDate']);
        $this->assertSame('Credit Card', $summary['billingPaymentMethod']);
    }

    public function test_from_module_params_returns_dash_for_missing_values(): void
    {
        $summary = BillingSummaryBuilder::fromModuleParams([]);

        $this->assertSame('-', $summary['billingProduct']);
        $this->assertSame('-', $summary['billingRecurringAmount']);
        $this->assertSame('-', $summary['billingCycle']);
        $this->assertSame('-', $summary['billingRegistrationDate']);
        $this->assertSame('-', $summary['billingNextDueDate']);
        $this->assertSame('-', $summary['billingPaymentMethod']);
    }
}
