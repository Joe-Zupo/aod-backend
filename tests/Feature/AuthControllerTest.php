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

        foreach (['Coach', 'Team Leader', 'Player'] as $role) {
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
            ->assertJsonStructure(['data' => ['team' => ['team_code']]])
            ->assertJsonPath('code', 201)
            ->assertJsonPath('error', false)
            ->assertJsonStructure(['message', 'data' => ['user', 'team', 'token']]);

        $this->assertDatabaseHas('users', [
            'username' => 'player-one',
            'email' => 'player@example.com',
        ]);
        $this->assertTrue(Hash::check('password123', User::firstOrFail()->password));
        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('team_members', 1);
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

    public function test_player_can_register_with_a_riot_id_and_existing_team_code(): void
    {
        $team = Team::create([
            'team_code' => 'JOIN1234',
            'team_name' => 'Aces of Dawn',
        ]);

        $this->postJson('/api/register', [
            'username' => 'player-one',
            'email' => 'player@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'Player',
            'riot_id' => 'PlayerOne#APAC',
            'team_code' => 'join1234',
        ])->assertCreated()
            ->assertJsonPath('data.user.riot_id', 'PlayerOne#APAC')
            ->assertJsonPath('data.user.roles.0', 'Player')
            ->assertJsonPath('data.team.id', $team->id);

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'is_active' => true,
        ]);
    }

    public function test_team_leader_must_supply_riot_id_and_team_choice(): void
    {
        $this->postJson('/api/register', [
            'username' => 'leader-one',
            'email' => 'leader@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'Team Leader',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 422)
            ->assertJsonPath('error', true)
            ->assertJsonStructure(['data' => ['errors' => ['riot_id', 'team_action']]]);
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        User::create([
            'username' => 'player-one',
            'email' => 'player@example.com',
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
