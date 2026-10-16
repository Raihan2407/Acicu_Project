<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /** POST /api/admin/login : login dengan email atau username, balasan berisi token Bearer. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $user = User::query()
            ->where('email', $data['login'])
            ->orWhere('username', $data['login'])
            ->first();

        // Pesan sengaja sama untuk akun tidak ada / sandi salah / akun nonaktif.
        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Username/email atau kata sandi salah.'], 401);
        }

        $hours = $request->boolean('remember')
            ? config('acicu.token_remember_hours')
            : config('acicu.token_ttl_hours');

        $token = $user->createToken('admin-panel', ['*'], now()->addHours($hours))->plainTextToken;

        ActivityLog::record($user, 'auth.login', 'Login ke panel admin', [], $request);

        return response()->json([
            'data' => [
                'token' => $token,
                'expires_at' => now()->addHours($hours)->toIso8601String(),
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    /** POST /api/admin/logout : cabut token yang sedang dipakai. */
    public function logout(Request $request): JsonResponse
    {
        ActivityLog::record($request->user(), 'auth.logout', 'Keluar dari panel admin', [], $request);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    /** POST /api/admin/me/password : ganti sandi sendiri (semua perangkat lain otomatis keluar) */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::min(10)->letters()->numbers(), 'confirmed', 'different:current_password'],
        ], [
            'password.confirmed' => 'Konfirmasi sandi baru tidak sama.',
            'password.different' => 'Sandi baru harus berbeda dari sandi lama.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Sandi saat ini salah.', 'errors' => ['current_password' => ['Sandi saat ini salah.']]], 422);
        }

        $user->update(['password' => $data['password']]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        ActivityLog::record($user, 'auth.password', 'Mengganti sandi sendiri', [], $request);

        return response()->json(['message' => 'Sandi berhasil diganti.']);
    }

    /** GET /api/admin/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'can_view_reports' => $user->isOwner(),
            // badge di menu "Data Pesanan": pembayaran yang menunggu dikonfirmasi
            'pending_count' => Order::query()->awaitingConfirmation()->count(),
        ];
    }
}
