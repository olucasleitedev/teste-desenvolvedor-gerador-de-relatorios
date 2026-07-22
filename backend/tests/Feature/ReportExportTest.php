<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\ReportExportProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reports.export_driver' => 'sync']);
    }

    public function test_unauthenticated_users_cannot_queue_exports(): void
    {
        $this->postJson('/api/reports/billing/exports', ['format' => 'csv'])
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_queue_export(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/reports/billing/exports', [
            'format' => 'csv',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.format', 'csv')
            ->assertJsonPath('data.download_url', fn ($url) => is_string($url) && $url !== '');

        $this->assertDatabaseHas('report_exports', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'format' => 'csv',
            'status' => 'completed',
        ]);
    }

    public function test_user_can_poll_export_status(): void
    {
        $user = User::factory()->create();
        $export = ReportExport::create([
            'user_id' => $user->id,
            'format' => 'csv',
            'filters' => ['date_from' => '2026-01-01'],
            'status' => 'processing',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/reports/billing/exports/{$export->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_user_cannot_access_another_users_export(): void
    {
        $export = ReportExport::create([
            'user_id' => User::factory()->create()->id,
            'format' => 'csv',
            'filters' => [],
            'status' => 'pending',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/reports/billing/exports/{$export->id}")
            ->assertForbidden();
    }

    public function test_processor_generates_csv_file(): void
    {
        Storage::fake('local');
        Billing::factory()->for(Customer::factory())->create();

        $export = ReportExport::create([
            'user_id' => User::factory()->create()->id,
            'format' => 'csv',
            'filters' => [],
            'status' => 'pending',
        ]);

        app(ReportExportProcessor::class)->process($export);
        $export->refresh();

        $this->assertSame('completed', $export->status);
        $this->assertSame(1, $export->row_count);
        Storage::disk('local')->assertExists($export->file_path);
    }

    public function test_completed_export_can_be_downloaded(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'report-exports/test.csv';
        Storage::disk('local')->put($path, 'id,customer');

        $export = ReportExport::create([
            'user_id' => $user->id,
            'format' => 'csv',
            'filters' => [],
            'status' => 'completed',
            'file_path' => $path,
            'row_count' => 1,
            'completed_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/reports/billing/exports/{$export->id}/download")
            ->assertOk()
            ->assertDownload();
    }
}
