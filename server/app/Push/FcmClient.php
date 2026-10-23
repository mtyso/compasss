<?php

namespace App\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1 (docs/PLAN.md, D36), with Laravel's HTTP client and openssl: no SDK.
 * The service account key signs a JWT, Google swaps it for an access token for an hour (cached), and each
 * message goes to one phone. Nothing here sleeps: a busy Firebase is retried by the next minute's run.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const ACCESS_KEY = 'fcm:access_token';

    /** Set when Firebase refused our key or project: push stays off for an hour, the bot takes over. */
    private const BROKEN_KEY = 'fcm:broken';

    private const BROKEN_MINUTES = 60;

    /** Firebase asked to slow down (429, 5xx): nothing is sent before this moment. */
    private const PAUSE_KEY = 'fcm:pause_until';

    /** Never wait longer than this between tries, whatever Retry-After says: a reminder lives 15 minutes. */
    private const PAUSE_MAX_SECONDS = 300;

    /** When Firebase stopped answering (busy, down, unreachable); cleared by the next message it takes. */
    private const FAILING_KEY = 'fcm:failing_since';

    /** No answer for this long counts as broken: the bot takes over while a reminder can still make it. */
    private const GIVE_UP_MINUTES = 10;

    /** @var array{project_id: string, client_email: string, private_key: string, private_key_id?: string}|null */
    private ?array $key = null;

    /** The key file is on the server. */
    public function configured(): bool
    {
        return is_file((string) config('firebase.credentials'));
    }

    /** Configured, and Firebase has not refused the key lately. */
    public function ready(): bool
    {
        return $this->configured() && ! Cache::has(self::BROKEN_KEY);
    }

    /** Firebase asked to wait (QUOTA_EXCEEDED, UNAVAILABLE): leave everything for a later minute. */
    public function paused(): bool
    {
        return (int) Cache::get(self::PAUSE_KEY, 0) > now()->getTimestamp();
    }

    public function send(PushMessage $message, string $deviceToken): PushResult
    {
        try {
            $url = 'https://fcm.googleapis.com/v1/projects/'.(config('firebase.project_id') ?: $this->key()['project_id']).'/messages:send';
            $body = ['message' => $this->payload($message, $deviceToken)];
            $response = $this->post($url, $body, $this->accessToken());
            if ($response->status() === 401) {
                // The cached token expired early or was revoked: one fresh try.
                $response = $this->post($url, $body, $this->accessToken(fresh: true));
            }
        } catch (ConnectionException $e) {
            return $this->retry($e->getMessage());
        } catch (RuntimeException $e) {
            return $this->broken($e->getMessage());
        }

        if ($response->successful()) {
            // Firebase works again, e.g. after a new key was uploaded and «Прислать проверочное» went through.
            Cache::forget(self::FAILING_KEY);
            Cache::forget(self::BROKEN_KEY);

            return PushResult::Sent;
        }

        return $this->failure($response);
    }

    /** @return array<string, mixed> */
    private function payload(PushMessage $message, string $deviceToken): array
    {
        // Data values must be strings; a tap gets only these, not the title (app/src/lib/push.ts).
        $data = array_map('strval', ['url' => $message->url, ...$message->data]);

        return [
            'token' => $deviceToken,
            'notification' => ['title' => Str::limit($message->title, 120), 'body' => Str::limit($message->body, 600)],
            'data' => $data,
            'android' => [
                'ttl' => max(0, $message->ttl).'s',
                // No click_action: a tap opens the app's one activity, which hands the data to the app.
                'notification' => array_filter(['channel_id' => (string) config('firebase.android_channel'), 'tag' => $message->tag]),
            ],
        ];
    }

    /**
     * What Firebase's refusal means. Only a token Firebase does not know drops a phone: a wrong project or a
     * bug in our message must not wipe the tokens.
     */
    private function failure(Response $response): PushResult
    {
        $status = $response->status();
        $error = (array) $response->json('error');
        $details = collect($error['details'] ?? [])->filter(fn ($d) => is_array($d));
        $code = $details->firstWhere('@type', 'type.googleapis.com/google.firebase.fcm.v1.FcmError')['errorCode'] ?? null;
        $badToken = $details->flatMap(fn (array $d) => $d['fieldViolations'] ?? [])->contains(fn ($v) => ($v['field'] ?? null) === 'message.token')
            || str_contains((string) ($error['message'] ?? ''), 'registration token');

        if ($status === 429 || $status >= 500) {
            $after = $response->header('Retry-After');
            $wait = min(self::PAUSE_MAX_SECONDS, max(60, is_numeric($after) ? (int) $after : 60));
            Cache::put(self::PAUSE_KEY, now()->getTimestamp() + $wait, 3600);

            return $this->retry("FCM {$status} ".($code ?? $error['status'] ?? ''));
        }

        return match (true) {
            $code === 'UNREGISTERED' => PushResult::Dead,
            $status === 400 && $badToken => PushResult::Dead,
            // The app was built with google-services.json from another Firebase project than the server's key.
            $code === 'SENDER_ID_MISMATCH' => $this->broken('FCM SENDER_ID_MISMATCH: the server key and the app\'s google-services.json come from different Firebase projects'),
            // 401 again, 403 (API off, no permission), 404 for the project itself: the setup, not the phone.
            in_array($status, [401, 403, 404], true) => $this->broken("FCM {$status} ".($code ?? $error['status'] ?? '').': '.($error['message'] ?? '')),
            default => $this->rejected($status, $code ?? ($error['status'] ?? null), (string) ($error['message'] ?? '')),
        };
    }

    /** Busy or unreachable: the next minute tries again, until it has gone on too long (GIVE_UP_MINUTES). */
    private function retry(string $why): PushResult
    {
        $since = Cache::get(self::FAILING_KEY);
        if (! is_int($since)) {
            Cache::put(self::FAILING_KEY, now()->getTimestamp(), 3600);
            Log::warning("FCM did not take a message, trying again: {$why}");

            return PushResult::Retry;
        }
        if (now()->getTimestamp() - $since >= self::GIVE_UP_MINUTES * 60) {
            Cache::forget(self::FAILING_KEY);

            return $this->broken('Firebase has not taken a message for '.self::GIVE_UP_MINUTES." minutes: {$why}");
        }

        return PushResult::Retry;
    }

    private function broken(string $why): PushResult
    {
        Cache::put(self::BROKEN_KEY, true, now()->addMinutes(self::BROKEN_MINUTES));
        Log::error("Push is off for an hour, the bot sends instead: {$why}");

        return PushResult::Broken;
    }

    private function rejected(int $status, ?string $code, string $message): PushResult
    {
        Log::warning("FCM refused a message ({$status} {$code}): {$message}");

        return PushResult::Rejected;
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $url, array $body, string $accessToken): Response
    {
        return Http::withToken($accessToken)->asJson()->connectTimeout(5)->timeout(10)->post($url, $body);
    }

    /** An OAuth access token for an hour, signed with the service account key and cached until near its end. */
    private function accessToken(bool $fresh = false): string
    {
        // Per key: a new key file starts with its own token, not one Google issued for the old account.
        $key = $this->key();
        $cacheKey = self::ACCESS_KEY.':'.($key['private_key_id'] ?? sha1($key['client_email']));
        if (! $fresh && is_string($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $now = now()->getTimestamp();
        $header = self::base64url((string) json_encode(array_filter(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $key['private_key_id'] ?? null])));
        $claims = self::base64url((string) json_encode([
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URI,
            // A minute back for a server clock that runs a little fast; Google allows an hour at most.
            'iat' => $now - 60,
            'exp' => $now + 3000,
        ]));

        $private = openssl_pkey_get_private($key['private_key']);
        if ($private === false || ! openssl_sign("{$header}.{$claims}", $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM: the service account key cannot sign');
        }

        $response = Http::asForm()->connectTimeout(5)->timeout(10)->post(self::TOKEN_URI, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => "{$header}.{$claims}.".self::base64url($signature),
        ]);
        $token = $response->json('access_token');
        if ($response->serverError()) {
            throw new ConnectionException("Google OAuth {$response->status()}");
        }
        if (! $response->ok() || ! is_string($token)) {
            // e.g. 400 invalid_grant «Invalid JWT Signature.»: a deleted key. Never log the assertion.
            throw new RuntimeException("Google OAuth {$response->status()}: ".$response->json('error').' '.$response->json('error_description'));
        }

        Cache::put($cacheKey, $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    /** @return array{project_id: string, client_email: string, private_key: string, private_key_id?: string} */
    private function key(): array
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $path = (string) config('firebase.credentials');
        $key = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($key) || ($key['type'] ?? null) !== 'service_account' || empty($key['private_key']) || empty($key['client_email']) || empty($key['project_id'])) {
            throw new RuntimeException('FCM: '.basename($path).' is not a service account key');
        }
        // A key pasted by hand can come with literal \n or Windows line ends.
        $key['private_key'] = str_replace(['\n', "\r"], ["\n", ''], $key['private_key']);

        return $this->key = $key;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
