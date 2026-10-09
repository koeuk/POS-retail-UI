<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token auth for the JSON API.
 *
 * Same accounts, same permissions: a token acts exactly as its user, and the
 * `permission:*` middleware answers for it the way it answers for a session.
 * Revoking a token, or deactivating the user, shuts the integration out on
 * its next request.
 */
class AuthController extends Controller
{
    /**
     * Issue a token
     *
     * Exchange credentials for a bearer token. Deactivated accounts are refused.
     *
     * @group Auth
     *
     * @unauthenticated
     *
     * @response 201 {"token": "1|kANNnRWFrw...", "user": {"id": 1, "name": "bobo", "email": "owner@shop.test", "role": "admin", "store_id": null}, "can": {"pos": true, "orders": true, "reports": true}}
     */
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Names the token in any future token list — "warehouse-laptop"
            // reads better than a bare id when one has to be revoked.
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => $user->only('id', 'name', 'email', 'role', 'store_id'),
            'can' => $user->permissionFlags(),
        ], 201);
    }

    /**
     * Revoke this token
     *
     * Revokes the very token that authenticated the request.
     *
     * @group Auth
     *
     * @response {"ok": true}
     */
    public function revoke(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Who am I
     *
     * The token's user and the resolved permission map — recomputed on every
     * request, so a Staff-screen grant or revoke applies without reissuing.
     *
     * @group Auth
     *
     * @response {"user": {"id": 1, "name": "bobo", "email": "owner@shop.test", "role": "admin", "store_id": null}, "can": {"pos": true, "orders": true}}
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user->only('id', 'name', 'email', 'role', 'store_id'),
            'can' => $user->permissionFlags(),
        ]);
    }
}
