<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Kelola akun pengelola (hanya owner). Tidak ada pendaftaran publik: akun hanya dibuat di sini. */
class UserController extends Controller
{
    /** GET /api/admin/users */
    public function index(): JsonResponse
    {
        return response()->json(['data' => User::query()->orderBy('role')->orderBy('id')->get()->map(fn (User $u) => $this->row($u))->values()]);
    }

    /** POST /api/admin/users */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'username' => ['required', 'alpha_dash:ascii', 'max:30', 'unique:users,username'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_OWNER])],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
        ]);

        $user = User::query()->create($data + ['is_active' => true]);

        ActivityLog::record($request->user(), 'user.create', "Menambah akun {$user->username} ({$user->role})", ['user_id' => $user->id], $request);

        return response()->json(['data' => $this->row($user)], 201);
    }

    /** PUT|PATCH /api/admin/users/{user} : nama, email, peran, aktif/nonaktif */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'username' => ['sometimes', 'alpha_dash:ascii', 'max:30', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['sometimes', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in([User::ROLE_ADMIN, User::ROLE_OWNER])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $isSelf = $request->user()->is($user);
        $losesOwner = ($data['role'] ?? $user->role) !== User::ROLE_OWNER || ($data['is_active'] ?? $user->is_active) === false;

        if ($isSelf && ($data['is_active'] ?? true) === false) {
            return response()->json(['message' => 'Anda tidak bisa menonaktifkan akun sendiri.'], 422);
        }
        if ($user->isOwner() && $losesOwner && ! $this->otherActiveOwnerExists($user)) {
            return response()->json(['message' => 'Harus ada minimal satu owner yang aktif.'], 422);
        }

        $user->update($data);

        // akun yang dinonaktifkan / diubah perannya langsung keluar dari semua perangkat
        if (array_key_exists('is_active', $data) && ! $data['is_active'] || array_key_exists('role', $data)) {
            $user->tokens()->delete();
        }

        ActivityLog::record($request->user(), 'user.update', "Mengubah akun {$user->username}", ['user_id' => $user->id, 'keys' => array_keys($data)], $request);

        return response()->json(['data' => $this->row($user->fresh())]);
    }

    /** POST /api/admin/users/{user}/password : owner mengatur ulang sandi (lupa sandi) */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
        ]);

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        ActivityLog::record($request->user(), 'user.password', "Mengatur ulang sandi {$user->username}", ['user_id' => $user->id], $request);

        return response()->json(['message' => 'Sandi diatur ulang. Pengguna harus login kembali.']);
    }

    private function otherActiveOwnerExists(User $except): bool
    {
        return User::query()->where('role', User::ROLE_OWNER)->where('is_active', true)->whereKeyNot($except->id)->exists();
    }

    private function row(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'email' => $u->email,
            'role' => $u->role,
            'role_label' => $u->roleLabel(),
            'is_active' => $u->is_active,
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
