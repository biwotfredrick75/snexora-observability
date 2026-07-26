<?php

namespace Tests\Feature\Sacco;

use App\Models\Farmer;
use App\Models\User;
use App\Services\Sacco\SaccoServiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Exercises SaccoServiceClient and the checkoff-insert logic in
 * SaccoController against a faked HTTP layer -- no live nexora-sacco Go
 * service required.
 */
class SaccoServiceClientTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'user_id'       => 'tester',
            'password'      => bcrypt('secret'),
            'real_name'     => 'Tester',
            'phone'         => '0700000000',
            'pin'           => '0000',
            'print_profile' => 'default',
            'startup_tab'   => 'dashboard',
            'default_store' => 'MAIN',
        ]);

        $permissions = collect(['view-sacco', 'manage-sacco', 'approve-sacco-loans'])
            ->map(fn ($perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']));
        $this->user->givePermissionTo($permissions);

        $this->actingAs($this->user, 'api');
    }

    public function test_create_member_calls_gateway_with_api_key_and_returns_data(): void
    {
        Http::fake([
            '*/api/v1/members' => Http::response([
                'success' => true,
                'data' => ['id' => 'uuid-123', 'member_no' => 'SAC-00001', 'full_name' => 'Jane Wanjiku'],
            ], 201),
        ]);

        $client = app(SaccoServiceClient::class);
        $result = $client->createMember(['full_name' => 'Jane Wanjiku', 'external_ref_type' => 'farmer', 'external_ref_id' => '1']);

        $this->assertSame('uuid-123', $result['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/members')
                && $request->method() === 'POST'
                && $request->hasHeader('X-API-Key');
        });
    }

    public function test_gateway_failure_is_caught_and_returns_a_failure_shape_not_an_exception(): void
    {
        Http::fake([
            '*/api/v1/loans/*/approve' => Http::response(['success' => false, 'message' => 'loan is not pending'], 422),
        ]);

        $client = app(SaccoServiceClient::class);
        $result = $client->approveLoan('some-loan-id');

        // approveLoan() unwraps ['data'] -- on a failure response there is
        // no 'data' key, so it must degrade to an empty array rather than
        // throwing.
        $this->assertSame([], $result);
    }

    public function test_controller_stores_member_by_hydrating_farmer_from_local_db(): void
    {
        $farmer = Farmer::create([
            'farmer_no' => 'F-0001',
            'full_name' => 'Jane Wanjiku',
            'phone'     => '0722000001',
            'status'    => 'active',
        ]);

        Http::fake([
            '*/api/v1/members' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'uuid-456', 'member_no' => 'SAC-00001', 'full_name' => 'Jane Wanjiku',
                    'external_ref_type' => 'farmer', 'external_ref_id' => (string) $farmer->id,
                ],
            ], 201),
        ]);

        $res = $this->postJson('/api/sacco/members', [
            'farmer_id' => $farmer->id,
            'join_date' => now()->toDateString(),
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.id', 'uuid-456');
        $res->assertJsonPath('data.farmer.full_name', 'Jane Wanjiku');
    }

    public function test_post_checkoff_for_period_inserts_farmer_checkoff_entries_from_gateway_response(): void
    {
        $farmer = Farmer::create([
            'farmer_no' => 'F-0002',
            'full_name' => 'John Kiptoo',
            'phone'     => '0733000002',
            'status'    => 'active',
        ]);

        Http::fake([
            '*/api/v1/integrations/checkoff/post-period' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'external_ref_id' => (string) $farmer->id,
                        'amount' => 1500.0,
                        'loan_no' => 'LN-000001',
                        'installment_no' => 1,
                        'source_ref' => 'schedule-row-uuid-1',
                    ],
                ],
            ], 200),
        ]);

        $res = $this->postJson('/api/sacco/checkoff/post', ['month' => 7, 'year' => 2026]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.count', 1);

        $this->assertDatabaseHas('farmer_checkoff_entries', [
            'farmer_id'   => $farmer->id,
            'source_type' => 'sacco_loan',
            'source_ref'  => 'schedule-row-uuid-1',
            'amount'      => 1500.0,
        ]);
    }

    public function test_post_checkoff_for_period_is_idempotent_on_source_ref(): void
    {
        $farmer = Farmer::create(['farmer_no' => 'F-0003', 'full_name' => 'Ann Chebet', 'phone' => '0744000003', 'status' => 'active']);

        DB::table('checkoff_services')->insert([
            'service_name' => 'SACCO Loan Repayment', 'service_type' => 'Deduction', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('farmer_checkoff_entries')->insert([
            'farmer_id' => $farmer->id, 'month' => 7, 'year' => 2026,
            'service_id' => DB::table('checkoff_services')->value('id'), 'service_name' => 'SACCO Loan Repayment',
            'amount' => 900.0, 'source_type' => 'sacco_loan', 'source_ref' => 'already-posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake([
            '*/api/v1/integrations/checkoff/post-period' => Http::response([
                'success' => true,
                'data' => [[
                    'external_ref_id' => (string) $farmer->id, 'amount' => 900.0,
                    'loan_no' => 'LN-000002', 'installment_no' => 1, 'source_ref' => 'already-posted',
                ]],
            ], 200),
        ]);

        $res = $this->postJson('/api/sacco/checkoff/post', ['month' => 7, 'year' => 2026]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.count', 0); // already posted -- must not double-insert

        $this->assertDatabaseCount('farmer_checkoff_entries', 1);
    }
}
