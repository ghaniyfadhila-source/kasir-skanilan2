<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_login_page(): void
    {
        $response = $this->get(route('admin.login'));
        $response->assertStatus(200);
        $response->assertSee('Masuk Admin');
    }

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $response = $this->post(route('admin.login.post'), [
            'email' => 'admin@kasir.local',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_cannot_login_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $response = $this->post(route('admin.login.post'), [
            'email' => 'admin@kasir.local',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_cashier_cannot_access_admin_dashboard(): void
    {
        $cashier = User::factory()->create([
            'email' => 'cashier@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
        ]);

        $response = $this->actingAs($cashier)->get(route('admin.dashboard'));
        $response->assertStatus(403);
    }

    public function test_admin_can_access_dashboard(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));
        $response->assertStatus(200);
        $response->assertSee('Dashboard');
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $response = $this->get(route('admin.dashboard'));
        // Guest is redirected to /login which then redirects to /admin/login
        $response->assertRedirect();
    }

    public function test_login_rate_limiting(): void
    {
        User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        // Make 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.login.post'), [
                'email' => 'admin@kasir.local',
                'password' => 'wrongpassword',
            ]);
        }

        // 6th attempt should be rate limited
        $response = $this->post(route('admin.login.post'), [
            'email' => 'admin@kasir.local',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $response->assertSessionHasErrors('email', function ($message) {
            return str_contains($message, 'Terlalu banyak percobaan');
        });
    }

    public function test_admin_can_logout(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->post(route('admin.logout'));

        $this->assertGuest();
    }
}