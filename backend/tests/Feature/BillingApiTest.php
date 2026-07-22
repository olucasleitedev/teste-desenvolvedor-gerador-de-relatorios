<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_users_cannot_access_or_export_reports(): void
    {
        $this->getJson('/api/reports/billing')->assertUnauthorized();
        $this->getJson('/api/reports/billing/export/csv')->assertUnauthorized();
        $this->getJson('/api/reports/billing/export/pdf')->assertUnauthorized();
        $this->postJson('/api/reports/billing/exports', ['format' => 'csv'])->assertUnauthorized();
    }

    public function test_overdue_billing_uses_compound_interest(): void
    {
        Carbon::setTestNow('2026-07-22');
        $billing = Billing::factory()->for(Customer::factory())->create([
            'original_amount' => 1000,
            'monthly_interest_rate' => 0.02,
            'due_date' => '2026-06-22',
        ]);

        $this->assertSame('overdue', $billing->derivedStatus());
        $this->assertSame(1020.00, $billing->currentUpdatedAmount());
        $this->assertSame(20.00, $billing->currentInterestAmount());
    }

    public function test_paid_billing_does_not_accumulate_interest(): void
    {
        Carbon::setTestNow('2026-07-22');
        $billing = Billing::factory()->for(Customer::factory())->create([
            'original_amount' => 1000,
            'monthly_interest_rate' => 0.02,
            'due_date' => '2026-05-22',
            'payment_date' => '2026-06-22',
            'paid_amount' => 1020,
            'interest_amount_at_payment' => 20,
            'status' => 'paid',
        ]);

        $this->assertSame('paid', $billing->derivedStatus());
        $this->assertSame(1020.00, $billing->currentUpdatedAmount());
        $this->assertSame(20.00, $billing->currentInterestAmount());
    }

    public function test_report_filters_and_totals(): void
    {
        Carbon::setTestNow('2026-07-22');
        $customer = Customer::factory()->create();
        Billing::factory()->for($customer)->create([
            'original_amount' => 100, 'monthly_interest_rate' => 0,
            'issue_date' => '2026-07-01', 'due_date' => '2026-08-01',
        ]);
        Billing::factory()->create(['original_amount' => 999, 'issue_date' => '2025-01-01']);

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/reports/billing?customer_id={$customer->id}&date_from=2026-07-01&date_to=2026-07-31");

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('totals.count', 1)
            ->assertJsonPath('totals.original_total', '100.00')
            ->assertJsonPath('totals.updated_total', '100.00')
            ->assertJsonPath('totals.pending_total', '100.00');
    }

    public function test_payment_registration_persists_calculated_amount(): void
    {
        Carbon::setTestNow('2026-07-22');
        $billing = Billing::factory()->for(Customer::factory())->create([
            'original_amount' => 1000, 'monthly_interest_rate' => 0.02, 'due_date' => '2026-06-22',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/billings/{$billing->id}/pay", ['payment_date' => '2026-07-22'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid_amount', '1020.00');

        $this->assertDatabaseHas('billings', ['id' => $billing->id, 'status' => 'paid', 'paid_amount' => 1020.00]);
    }

    public function test_csv_and_pdf_exports(): void
    {
        Billing::factory()->for(Customer::factory())->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->get('/api/reports/billing/export/csv')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($user, 'sanctum')->get('/api/reports/billing/export/pdf')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
