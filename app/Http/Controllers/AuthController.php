<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('username', $credentials['username'])->first();

        if ($user === null || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Username atau password salah.'], 401);
        }

        $expiresAt = $user->role === 'owner' ? Carbon::now()->addMinutes(45) : null;
        $token = $user->createToken('api', ['*'], $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->bearerToken() !== null) {
            PersonalAccessToken::findToken($request->bearerToken())?->delete();
        }

        return response()->json(['message' => 'Berhasil logout.']);
    }

    public function me(Request $request): array
    {
        return ['user' => new UserResource($request->user())];
    }
}
