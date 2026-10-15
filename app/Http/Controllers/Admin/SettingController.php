<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.settings.index', [
            'schema' => Setting::definitions(),
            'values' => $this->currentValues(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $definitions = Setting::definitions();

        $rules = [];
        foreach ($definitions as $key => $def) {
            $rules[$key] = $def['rules'] ?? 'nullable';
        }

        $request->validate($rules);

        $changed = [];

        foreach ($definitions as $key => $def) {
            $new = $request->has($key) ? $request->input($key) : null;
            $old = Setting::get($key);

            $normalized = $def['type'] === 'bool'
                ? (bool) $request->boolean($key)
                : $new;

            if ((string) $normalized === (string) $old) {
                continue;
            }

            Setting::put($key, $normalized, $def['type']);
            $changed[$key] = ['from' => $old, 'to' => $normalized];
        }

        if ($changed !== []) {
            $audit->log(AuditLogger::ACT_ADMIN_SETTINGS_UPDATED, ['changed' => $changed]);
        }

        // Redirect eksplisit, bukan back(): back() bergantung pada header
        // Referer yang tidak selalu ada (curl, beberapa proxy, API).
        return redirect()
            ->route('admin.settings.index')
            ->with('success', $changed === []
                ? 'Tidak ada pengaturan yang berubah.'
                : 'Pengaturan berhasil disimpan.');
    }

    /** Ubah password admin sendiri; wajib password lama yang benar. */
    public function updatePassword(Request $request, AuditLogger $audit)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        if (! Hash::check($validated['current_password'], $admin->password)) {
            return redirect()
                ->route('admin.settings.index')
                ->withErrors(['current_password' => 'Password saat ini salah.'])
                ->withInput();
        }

        $admin->password = $validated['password']; // cast `hashed` di model
        $admin->save();

        $audit->log(AuditLogger::ACT_ADMIN_PASSWORD_CHANGED, [], null, $admin->id);

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Password berhasil diubah.');
    }

    /** @return array<string, mixed> */
    private function currentValues(): array
    {
        $values = [];

        foreach (array_keys(Setting::definitions()) as $key) {
            $values[$key] = Setting::get($key);
        }

        return $values;
    }
}