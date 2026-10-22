<?php

namespace App\Http\Controllers;

use App\Telegram\UpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, UpdateHandler $handler): JsonResponse
    {
        // Telegram sends the secret given to setWebhook in this header on every call.
        $secret = (string) config('telegram.webhook_secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        try {
            $handler->handle($request->json()->all());
        } catch (Throwable $e) {
            // Any non-2xx makes Telegram resend the same update again and again.
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
