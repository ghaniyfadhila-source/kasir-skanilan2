<?php

namespace Tests\Feature;

use App\Models\Console;
use App\Models\ConsoleCommand;
use App\Models\ConsoleCredential;
use App\Models\User;
use App\Services\ConsoleCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 10: perintah admin -> perangkat (`queue.clear`).
 *
 * Perangkat tidak memakai WebSocket, jadi perintah harus ikut pada heartbeat
 * atau sync dan diulang sampai di-ack.
 */
class ConsoleCommandTest extends TestCase
{
    use RefreshDatabase;

    private Console $console;

    private string $apiKey;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = Console::factory()->create(['is_active' => true]);
        $this->apiKey = ConsoleCredential::generateApiKey();

        ConsoleCredential::create([
            'console_id' => $this->console->id,
            'password_hash' => bcrypt('kasir12345'),
            'api_key' => $this->apiKey,
        ]);

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function headers(?Console $console = null): array
    {
        $console ??= $this->console;

        return [
            'X-Device-ID' => $console->device_id,
            'X-API-Key' => $console === $this->console
                ? $this->apiKey
                : ConsoleCredential::generateApiKey(),
        ];
    }

    private function queueClearCommand(Console $console): ConsoleCommand
    {
        return ConsoleCommand::create([
            'console_id' => $console->id,
            'command' => ConsoleCommand::COMMAND_QUEUE_CLEAR,
        ]);
    }

    // ------------------------------------------------------------------
    // Sumber perintah
    // ------------------------------------------------------------------

    public function test_admin_button_creates_queue_clear_command(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.consoles.queue.clear', $this->console));

        $response->assertRedirect(route('admin.consoles.edit', $this->console));

        $this->assertDatabaseHas('console_commands', [
            'console_id' => $this->console->id,
            'command' => ConsoleCommand::COMMAND_QUEUE_CLEAR,
            'acknowledged_at' => null,
        ]);
    }

    public function test_cashier_cannot_send_queue_clear(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)
            ->post(route('admin.consoles.queue.clear', $this->console))
            ->assertForbidden();
    }

    public function test_artisan_command_creates_queue_clear(): void
    {
        $this->artisan('console:queue-clear', ['device_id' => $this->console->device_id])
            ->assertSuccessful();

        $this->assertDatabaseHas('console_commands', [
            'console_id' => $this->console->id,
            'command' => ConsoleCommand::COMMAND_QUEUE_CLEAR,
        ]);
    }

    public function test_artisan_command_fails_for_unknown_device(): void
    {
        $this->artisan('console:queue-clear', ['device_id' => 'ESP-000000000000'])
            ->assertFailed();
    }

    public function test_edit_page_shows_queue_panel_and_command_state(): void
    {
        $this->queueClearCommand($this->console);

        $this->actingAs($this->admin)
            ->get(route('admin.consoles.edit', $this->console))
            ->assertOk()
            ->assertSee('Antrean Offline')
            ->assertSee('Kosongkan Antrean')
            ->assertSee('Menunggu perangkat');
    }

    public function test_unknown_command_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ConsoleCommandService::class)->dispatch($this->console, 'rm.rf');
    }

    // ------------------------------------------------------------------
    // Pengiriman & ack
    // ------------------------------------------------------------------

    public function test_heartbeat_delivers_outstanding_command_and_ack_stops_it(): void
    {
        $command = $this->queueClearCommand($this->console);

        $first = $this->withHeaders($this->headers())
            ->postJson('/api/console/heartbeat', [])
            ->assertOk()
            ->assertJsonCount(1, 'data.queue_commands')
            ->assertJsonPath('data.queue_commands.0.id', $command->id)
            ->assertJsonPath('data.queue_commands.0.command', 'queue.clear');

        $this->assertNotNull($command->refresh()->delivered_at);

        $this->withHeaders($this->headers())
            ->postJson('/api/console/commands/ack', [
                'commands' => [['id' => $command->id, 'status' => 'ok']],
            ])
            ->assertOk()
            ->assertJsonPath('data.acknowledged', 1);

        $command->refresh();
        $this->assertNotNull($command->acknowledged_at);
        $this->assertSame('ok', $command->result);

        $this->withHeaders($this->headers())
            ->postJson('/api/console/heartbeat', [])
            ->assertOk()
            ->assertJsonCount(0, 'data.queue_commands');
    }

    public function test_command_is_redelivered_when_ack_never_arrives(): void
    {
        $this->queueClearCommand($this->console);

        $this->withHeaders($this->headers())
            ->postJson('/api/console/heartbeat', [])
            ->assertJsonCount(1, 'data.queue_commands');

        // Respons pertama bisa saja hilang di jaringan; perintah harus tetap
        // dikirim pada heartbeat berikutnya.
        $this->withHeaders($this->headers())
            ->postJson('/api/console/heartbeat', [])
            ->assertJsonCount(1, 'data.queue_commands');
    }

    public function test_sync_also_delivers_commands(): void
    {
        $command = $this->queueClearCommand($this->console);

        $this->withHeaders($this->headers())
            ->postJson('/api/console/sync', ['taps' => []])
            ->assertOk()
            ->assertJsonPath('data.queue_commands.0.id', $command->id);
    }

    public function test_ack_from_another_console_is_ignored(): void
    {
        $command = $this->queueClearCommand($this->console);

        $other = Console::factory()->create(['is_active' => true]);
        $otherKey = ConsoleCredential::generateApiKey();

        ConsoleCredential::create([
            'console_id' => $other->id,
            'password_hash' => bcrypt('kasir12345'),
            'api_key' => $otherKey,
        ]);

        $this->withHeaders(['X-Device-ID' => $other->device_id, 'X-API-Key' => $otherKey])
            ->postJson('/api/console/commands/ack', [
                'commands' => [['id' => $command->id, 'status' => 'ok']],
            ])
            ->assertOk()
            ->assertJsonPath('data.acknowledged', 0);

        $this->assertNull($command->refresh()->acknowledged_at);
    }

    public function test_ack_rejects_invalid_status(): void
    {
        $command = $this->queueClearCommand($this->console);

        $this->withHeaders($this->headers())
            ->postJson('/api/console/commands/ack', [
                'commands' => [['id' => $command->id, 'status' => 'selesai']],
            ])
            ->assertOk()
            ->assertJsonPath('data.acknowledged', 0);

        $this->assertNull($command->refresh()->acknowledged_at);
    }

    public function test_expired_command_is_not_delivered(): void
    {
        $command = $this->queueClearCommand($this->console);

        $command->forceFill(['created_at' => now()->subMinutes(ConsoleCommand::TTL_MINUTES + 1)])->save();

        $this->withHeaders($this->headers())
            ->postJson('/api/console/heartbeat', [])
            ->assertOk()
            ->assertJsonCount(0, 'data.queue_commands');
    }

    public function test_command_is_only_delivered_to_its_own_console(): void
    {
        $this->queueClearCommand($this->console);

        $other = Console::factory()->create(['is_active' => true]);
        $otherKey = ConsoleCredential::generateApiKey();

        ConsoleCredential::create([
            'console_id' => $other->id,
            'password_hash' => bcrypt('kasir12345'),
            'api_key' => $otherKey,
        ]);

        $this->withHeaders(['X-Device-ID' => $other->device_id, 'X-API-Key' => $otherKey])
            ->postJson('/api/console/heartbeat', [])
            ->assertOk()
            ->assertJsonCount(0, 'data.queue_commands');
    }
}
