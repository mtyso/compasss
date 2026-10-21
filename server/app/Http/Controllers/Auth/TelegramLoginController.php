<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Sign-in through the Telegram bot (docs/PLAN.md, section 6). */
class TelegramLoginController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['device_name' => ['nullable', 'string', 'max:120']]);

        $bot = config('telegram.bot_username');
        if (! $bot) {
            return response()->json(['message' => __('login.bot_not_configured')], 503);
        }

        [$login, $code, $secret] = LoginRequest::open($data['device_name'] ?? 'Web', $request->ip());

        return response()->json([
            'id' => $login->id,
            'secret' => $secret,
            'link' => "https://t.me/{$bot}?start=login_{$code}",
            'expires_at' => $login->expires_at->toIso8601String(),
        ], 201);
    }

    /** Polled by the app every couple of seconds until the request is confirmed in the bot. */
    public function status(Request $request, string $id): JsonResponse
    {
        $login = LoginRequest::find($id);
        abort_unless($login && $login->secretMatches($request->header('X-Login-Secret')), 404);

        $status = $login->publicStatus();
        if ($status !== 'approved') {
            return response()->json(['status' => $status]);
        }

        // Hand out the token exactly once, even when two polls race.
        $claimed = LoginRequest::whereKey($login->id)->where('status', 'approved')->update(['status' => 'used']);
        if (! $claimed) {
            return response()->json(['status' => 'used']);
        }

        $user = $login->user;
        if (! $user->isAllowed()) {
            return response()->json(['message' => __('login.not_allowed')], 403);
        }

        return response()->json([
            'status' => 'approved',
            'token' => $user->createToken($login->device_name)->plainTextToken,
            'user' => $user->toApi(),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
