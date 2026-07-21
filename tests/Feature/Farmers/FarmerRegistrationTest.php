<?php

namespace Tests\Feature\Farmers;

use App\Models\Farmer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'user_id'        => 'tester',
            'password'       => bcrypt('secret'),
            'real_name'      => 'Tester',
            'phone'          => '0700000000',
            'pin'            => '0000',
            'print_profile'  => 'default',
            'startup_tab'    => 'dashboard',
            'default_store'  => 'MAIN',
        ]);

        $this->actingAs($user, 'api');
    }

    public function test_new_farmer_defaults_to_pending_and_is_excluded_from_default_active_search(): void
    {
        $res = $this->postJson('/api/farmers/farmers', [
            'full_name' => 'Jane Wanjiku',
            'phone'     => '0722000001',
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.status', 'pending');
        $res->assertJsonPath('data.farmer_no', 'FRM-0001');

        // FarmerController::search() defaults to status=active when no status
        // filter is given — a freshly registered farmer shouldn't be pickable
        // anywhere (e.g. the milk-collection farmer picker) until approved.
        $search = $this->getJson('/api/farmers/farmers/search?q=Jane');
        $search->assertStatus(200);
        $this->assertCount(0, $search->json('data'), 'A pending farmer must not appear in the default (active-only) search.');

        // But it's still visible when a caller explicitly asks for pending ones.
        $pending = $this->getJson('/api/farmers/farmers/search?q=Jane&status=pending');
        $this->assertCount(1, $pending->json('data'));
    }

    public function test_farmer_numbers_are_generated_sequentially(): void
    {
        $first = $this->postJson('/api/farmers/farmers', ['full_name' => 'Farmer One'])
            ->assertStatus(201)->json('data');
        $second = $this->postJson('/api/farmers/farmers', ['full_name' => 'Farmer Two'])
            ->assertStatus(201)->json('data');

        $this->assertSame('FRM-0001', $first['farmer_no']);
        $this->assertSame('FRM-0002', $second['farmer_no']);
    }

    public function test_approving_a_farmer_makes_it_active_and_searchable(): void
    {
        $farmer = Farmer::create(['farmer_no' => 'FRM-0001', 'full_name' => 'Jane Wanjiku', 'status' => 'pending']);

        $approve = $this->putJson("/api/farmers/farmers/{$farmer->id}/approve");
        $approve->assertStatus(200);
        $approve->assertJsonPath('data.status', 'active');

        $search = $this->getJson('/api/farmers/farmers/search?q=Jane');
        $this->assertCount(1, $search->json('data'), 'An approved (active) farmer must appear in the default search.');
    }
}
