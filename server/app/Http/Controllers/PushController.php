<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Push\FcmClient;
use App\Push\PushNotifier;
use App\Push\PushResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The phones that take push notifications (docs/PLAN.md, D36). The app sends its Firebase token on every
 * start, so a refreshed token replaces the old one and a phone in use is never pruned as stale.
 */
class PushController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['sometimes', Rule::in(DeviceToken::PLATFORMS)],
        ]);
        $user = $request->user();
        $access = $user->currentAccessToken();

        // One sign-in is one app on one phone: a new token (rotated, or an APK built for another Firebase
        // project) replaces the one it had.
        if ($access instanceof PersonalAccessToken) {
            DeviceToken::where('personal_access_token_id', $access->id)->where('token', '!=', $data['token'])->delete();
        }
        // Tied to this sign-in: signing out deletes it, and the database takes the phone's row with it.
        DeviceToken::updateOrCreate(['token' => $data['token']], [
            'user_id' => $user->id,
            'personal_access_token_id' => $access instanceof PersonalAccessToken ? $access->id : null,
            'platform' => $data['platform'] ?? 'android',
            'device_name' => $access instanceof PersonalAccessToken ? Str::limit($access->name, 100, '') : null,
            'last_seen_at' => now(),
        ]);

        return response()->json(['push' => $user->toApi()['push']]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $user = $request->user();
        $user->deviceTokens()->where('token', $data['token'])->delete();

        return response()->json(['push' => $user->toApi()['push']]);
    }

    /** «Проверить»: one notification to every phone of the user, to see the whole way works. */
    public function test(Request $request, PushNotifier $push, FcmClient $fcm): JsonResponse
    {
        $user = $request->user();
        if (! $fcm->configured()) {
            return response()->json(['message' => __('push.not_configured')], 409);
        }

        return match ($push->test($user)) {
            PushResult::Sent => response()->json(['sent' => true]),
            PushResult::Dead => response()->json(['message' => __('push.no_device'), 'push' => $user->toApi()['push']], 410),
            default => response()->json(['message' => __('push.failed')], 502),
        };
    }
}
