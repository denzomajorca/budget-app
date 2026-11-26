<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['budget.setup_token' => 'secret-token']);
    }

    public function test_setup_is_hidden_without_a_configured_token()
    {
        config(['budget.setup_token' => '']);

        $this->get('/setup?token=')->assertNotFound();
    }

    public function test_setup_is_hidden_with_a_wrong_token()
    {
        $this->get('/setup?token=wrong')->assertNotFound();
        $this->post('/setup/user', ['token' => 'wrong'])->assertNotFound();
    }

    public function test_setup_page_shows_with_the_right_token()
    {
        $this->get('/setup?token=secret-token')->assertOk();
    }

    public function test_first_user_can_be_created_and_is_logged_in()
    {
        $this->post('/setup/user', [
            'token' => 'secret-token',
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'a-long-password-123',
            'password_confirmation' => 'a-long-password-123',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
        $this->assertNotNull(User::first()->email_verified_at);
    }

    public function test_a_second_user_cannot_be_created()
    {
        User::factory()->create();

        $this->post('/setup/user', [
            'token' => 'secret-token',
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'a-long-password-123',
            'password_confirmation' => 'a-long-password-123',
        ])->assertForbidden();

        $this->assertSame(1, User::count());
    }

    public function test_migrations_can_be_run_from_setup()
    {
        $this->post('/setup/migrate', ['token' => 'secret-token'])
            ->assertRedirect('/setup?token=secret-token');
    }

    public function test_registration_is_disabled()
    {
        $this->get('/register')->assertNotFound();
    }
}
