<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('roles')),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user()->load('roles'));
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();
        $user->update([
            'password' => bcrypt($request->password),
        ]);

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }
}
