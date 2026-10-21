<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Telegram\BotUsers;
use App\Telegram\WebAppInitData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sign-in inside the Telegram Mini App (docs/BOT.md): Telegram has already vouched for the
 * user with signed launch data, so the app gets a token without the bot round trip.
 */
class TelegramWebAppController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
            'platform' => ['nullable', 'string', 'max:30'],
        ]);

        $token = (string) config('telegram.bot_token');
        if ($token === '') {
            return response()->json(['message' => __('login.bot_not_configured')], 503);
        }

        $fields = WebAppInitData::validate($data['init_data'], $token, config('telegram.webapp_max_age'));
        $from = $fields ? json_decode($fields['user'], true) : null;
        if (! is_array($from) || ! isset($from['id'])) {
            return response()->json(['message' => __('login.webapp_invalid')], 401);
        }

        if (! BotUsers::isAllowed($from['id'])) {
            return response()->json(['message' => __('login.not_allowed')], 403);
        }

        $user = BotUsers::upsert($from);

        // One token per Telegram app (phone, desktop): opening the Mini App again replaces it.
        $name = 'Telegram'.(! empty($data['platform']) ? ' · '.$data['platform'] : '');
        $user->tokens()->where('name', $name)->delete();

        return response()->json([
            'token' => $user->createToken($name)->plainTextToken,
            'user' => $user->toApi(),
        ]);
    }
}
