<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_user_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'username' => 'player-one',
            'email' => 'player@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'Coach',
            'team_action' => 'create',
            'team_name' => 'Aces of Dawn',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.username', 'player-one')
            ->assertJsonPath('data.user.email', 'player@example.com')
            ->assertJsonPath('data.user.roles.0', 'Coach')
            ->assertJsonPath('data.team.team_name', 'Aces of Dawn')
            ->assertJsonPath('data.team_membership_status', 'active')
            ->assertJsonStructure(['data' => ['team' => ['team_code']]])
            ->assertJsonPath('code', 201)
            ->assertJsonPath('error', false)
            ->assertJsonStructure(['message', 'data' => ['user', 'team', 'token']]);

        $this->assertDatabaseHas('users', [
            'username' => 'player-one',
            'email' => 'player@example.com',
        ]);
        $this->assertTrue(Hash::check('password123', User::firstOrFail()->password));
        $this->assertStringStartsWith('CH-', User::firstOrFail()->user_code);
        $this->assertStringStartsWith('TM-', $response->json('data.team.team_code'));
        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseHas('team_members', [
            'member_role' => 'main_coach',
            'status' => 'active',
        ]);
    }

    public function test_user_can_register_without_choosing_a_team(): void
    {
        $response = $this->postJson('/api/register', [
            'username' => 'coach-solo',
            'email' => 'coach-solo@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'Coach',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.team', null)
            ->assertJsonPath('data.team_membership_status', null);

        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('team_members', 0);
    }

    public function test_coach_cannot_submit_riot_id_or_team_code_when_creating_a_team(): void
    {
        $this->postJson('/api/register', [
            'username' => 'coach-one',
            'email' => 'coach@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'Coach',
            'riot_id' => 'Coach#APAC',
            'team_action' => 'create',
            'team_name' => 'Aces of Dawn',
            'team_code' => 'USERCODE',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 422)
            ->assertJsonPath('error', true)
            ->assertJsonStructure(['data' => ['errors' => ['riot_id', 'team_code']]]);
    }

    public function test_player_registering_with_a_team_code_creates_a_pending_request(): void
    {
        $team = new Team(['team_name' => 'Aces of Dawn']);
        $team->team_code = 'TM-JOIN1234';
        $team->save();

        $response = $this->postJson('/api/register', [
            'username' => 'player-one',
            'email' => 'player@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'Player',
            'riot_id' => 'PlayerOne#APAC',
            'team_code' => 'tm-join1234',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.riot_id', 'PlayerOne#APAC')
            ->assertJsonPath('data.user.roles.0', 'Player')
            ->assertJsonPath('data.team.id', $team->id)
            ->assertJsonPath('data.team_membership_status', 'pending')
            ->assertJsonPath('data.team.team_code', 'TM-JOIN1234');

        $this->assertStringStartsWith('PL-', User::firstOrFail()->user_code);
        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'member_role' => 'player',
            'status' => 'pending',
        ]);
        // Not yet an active member, so it shouldn't show up under active teams.
        $this->assertEmpty($response->json('data.user.teams'));
    }

    public function test_player_cannot_create_a_team(): void
    {
        $this->postJson('/api/register', [
            'username' => 'player-one',
            'email' => 'player@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'Player',
            'riot_id' => 'PlayerOne#APAC',
            'team_action' => 'create',
            'team_name' => 'Aces of Dawn',
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['team_action']]]);
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        User::create([
            'username' => 'player-one',
            'email' => 'player@example.com',
            'user_code' => 'PL-TESTCODE',
            'password' => 'password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'player@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user.username', 'player-one')
            ->assertJsonPath('code', 200)
            ->assertJsonPath('error', false)
            ->assertJsonStructure(['message', 'data' => ['user', 'token']]);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::create([
            'username' => 'player-one',
            'email' => 'player@example.com',
            'user_code' => 'PL-TESTCODE',
            'password' => 'password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'player@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 422)
            ->assertJsonPath('error', true)
            ->assertJsonPath('data.errors.email.0', 'The provided credentials are incorrect.');
    }

    public function test_authenticated_user_can_log_out_current_token(): void
    {
        $user = User::create([
            'username' => 'player-one',
            'email' => 'player@example.com',
            'user_code' => 'PL-TESTCODE',
            'password' => 'password123',
        ]);
        $token = $user->createToken('auth-token');

        $this->withToken($token->plainTextToken)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.')
            ->assertJsonPath('code', 200)
            ->assertJsonPath('error', false);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }
}
