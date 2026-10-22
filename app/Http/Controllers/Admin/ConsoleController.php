<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Console;
use App\Models\ConsoleCommand;
use App\Models\ConsoleCredential;
use App\Services\ConsoleCommandService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ConsoleController extends Controller
{
    public function __construct(private readonly ConsoleCommandService $commands)
    {
    }

    public function index(Request $request)
    {
        $query = Console::with('credentials')->latest();

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('device_id', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $consoles = $query->paginate(15)->withQueryString();

        return view('admin.consoles.index', compact('consoles'));
    }

    public function create()
    {
        return view('admin.consoles.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:50', 'regex:/^ESP-[0-9A-F]{12}$/', 'unique:consoles,device_id'],
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $console = Console::create([
            'device_id' => $validated['device_id'],
            'name' => $validated['name'],
            'location' => $validated['location'],
            'status' => 'offline',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Generate credentials
        $password = ConsoleCredential::generatePassword();
        $apiKey = ConsoleCredential::generateApiKey();

        ConsoleCredential::create([
            'console_id' => $console->id,
            'password_hash' => \Illuminate\Support\Facades\Hash::make($password),
            'api_key' => $apiKey,
        ]);

        // Log audit
        Log::channel('audit')->info('Console created', [
            'console_id' => $console->id,
            'device_id' => $console->device_id,
            'admin_id' => Auth::id(),
        ]);

        return redirect()->route('admin.consoles.edit', ['console' => $console->getRouteKey()])
            ->with('success', 'Console berhasil dibuat.')
            ->with('credentials', [
                'device_id' => $console->device_id,
                'password' => $password,
                'api_key' => $apiKey,
            ]);
    }

    public function show(Console $console)
    {
        $console->load('credentials', 'cashier', 'transactions');
        return view('admin.consoles.show', compact('console'));
    }

    public function edit(Console $console)
    {
        $console->load('credentials');

        // Ticket 10: riwayat perintah terakhir supaya admin tahu apakah
        // `queue.clear` sudah dijalankan perangkat atau masih menggantung.
        $commands = ConsoleCommand::query()
            ->where('console_id', $console->id)
            ->latest()
            ->limit(5)
            ->get();

        return view('admin.consoles.edit', compact('console', 'commands'));
    }

    public function update(Request $request, Console $console)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['online', 'offline', 'maintenance'])],
            'is_active' => ['boolean'],
        ]);

        $console->update([
            'name' => $validated['name'],
            'location' => $validated['location'],
            'status' => $validated['status'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Log audit
        Log::channel('audit')->info('Console updated', [
            'console_id' => $console->id,
            'changes' => $validated,
            'admin_id' => Auth::id(),
        ]);

        return redirect()->route('admin.consoles.edit', ['console' => $console->getRouteKey()])
            ->with('success', 'Console berhasil diperbarui.');
    }

    public function destroy(Console $console)
    {
        $consoleId = $console->id;
        $deviceId = $console->device_id;

        // Check if console has transactions
        if ($console->transactions()->exists()) {
            return back()->withErrors(['console' => 'Console tidak dapat dihapus karena memiliki riwayat transaksi.']);
        }

        $console->delete();

        // Log audit
        Log::channel('audit')->info('Console deleted', [
            'console_id' => $consoleId,
            'device_id' => $deviceId,
            'admin_id' => Auth::id(),
        ]);

        return redirect()->route('admin.consoles.index')
            ->with('success', 'Console berhasil dihapus.');
    }

    public function rotateCredentials(Console $console)
    {
        $credentials = $console->credentials;

        if (!$credentials) {
            // Create new credentials if not exist
            $password = ConsoleCredential::generatePassword();
            $apiKey = ConsoleCredential::generateApiKey();

            $credentials = ConsoleCredential::create([
                'console_id' => $console->id,
                'password_hash' => \Illuminate\Support\Facades\Hash::make($password),
                'api_key' => $apiKey,
            ]);

            $newPassword = $password;
            $newApiKey = $apiKey;
        } else {
            $newPassword = ConsoleCredential::generatePassword();
            $newApiKey = ConsoleCredential::generateApiKey();

            $credentials->update([
                'password_hash' => \Illuminate\Support\Facades\Hash::make($newPassword),
                'api_key' => $newApiKey,
                'rotated_at' => now(),
            ]);
        }

        // Log audit
        Log::channel('audit')->info('Console credentials rotated', [
            'console_id' => $console->id,
            'device_id' => $console->device_id,
            'admin_id' => Auth::id(),
        ]);

        return redirect()->route('admin.consoles.edit', ['console' => $console->getRouteKey()])
            ->with('success', 'Kredensial berhasil dirotasi.')
            ->with('rotated_credentials', [
                'device_id' => $console->device_id,
                'password' => $newPassword,
                'api_key' => $newApiKey,
            ]);
    }

    /**
     * Ticket 10: minta perangkat mengosongkan antrean tap offline-nya.
     *
     * Perangkat tidak berlangganan WebSocket, jadi perintah tidak dikirim
     * seketika: ia disimpan dan diambil perangkat pada heartbeat berikutnya.
     */
    public function clearQueue(Console $console)
    {
        $command = $this->commands->dispatch($console, ConsoleCommand::COMMAND_QUEUE_CLEAR);

        Log::channel('audit')->info('Console queue clear requested', [
            'console_id' => $console->id,
            'device_id' => $console->device_id,
            'command_id' => $command->id,
            'admin_id' => Auth::id(),
        ]);

        return redirect()->route('admin.consoles.edit', ['console' => $console->getRouteKey()])
            ->with('success', 'Perintah kosongkan antrean dikirim. Perangkat akan menjalankannya pada heartbeat berikutnya.');
    }
}