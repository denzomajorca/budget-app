<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Lets the owner finish installation from a browser, for shared hosting
 * without SSH: run pending migrations and create the single user account.
 * Every action requires SETUP_TOKEN from .env; without it the page is a 404.
 */
class SetupController extends Controller
{
    public function show(Request $request): Response
    {
        $token = $this->authorizeToken($request);

        return Inertia::render('Setup', [
            'token' => $token,
            'pendingMigrations' => $this->pendingMigrations(),
            'hasUser' => $this->hasUser(),
        ]);
    }

    public function migrate(Request $request): RedirectResponse
    {
        $token = $this->authorizeToken($request);

        Artisan::call('migrate', ['--force' => true]);

        return redirect()->route('setup.show', ['token' => $token])
            ->with('status', 'Database is up to date.');
    }

    public function createUser(Request $request): RedirectResponse
    {
        $token = $this->authorizeToken($request);

        abort_if($this->hasUser(), 403, 'An account already exists.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function authorizeToken(Request $request): string
    {
        $expected = (string) config('budget.setup_token');
        $given = (string) $request->input('token', '');

        abort_if($expected === '' || ! hash_equals($expected, $given), 404);

        return $given;
    }

    private function pendingMigrations(): int
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]);

        try {
            $ran = $migrator->getRepository()->repositoryExists()
                ? $migrator->getRepository()->getRan()
                : [];
        } catch (Throwable) {
            $ran = [];
        }

        return count(array_diff(array_keys($files), $ran));
    }

    private function hasUser(): bool
    {
        try {
            return Schema::hasTable('users') && User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
