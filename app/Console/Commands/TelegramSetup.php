<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('telegram:setup {--avatar : Upload the profile photo even if it has not changed}')]
#[Description('Point the bot at APP_URL/telegram/webhook, set its name, photo, commands, descriptions and the Mini App menu button')]
class TelegramSetup extends Command
{
    /** The photo the bot shows everywhere in Telegram (source: avatar.svg next to it). */
    private const AVATAR = 'telegram/avatar.jpg';

    /** Hash of the last uploaded photo, on the local disk so it outlives deploys and cache clears. */
    private const AVATAR_SENT = 'telegram/avatar.sha1';

    /** The step 1.2 name still works. */
    protected $aliases = ['telegram:set-webhook'];

    public function handle(TelegramClient $telegram): int
    {
        $url = rtrim((string) config('app.url'), '/').'/telegram/webhook';
        $appUrl = rtrim((string) config('telegram.webapp_url'), '/').'/';

        if (! str_starts_with($url, 'https://') || ! str_starts_with($appUrl, 'https://')) {
            $this->error("Telegram needs https addresses, APP_URL gives {$url}. Run this on the production server.");

            return self::FAILURE;
        }
        if (! config('telegram.webhook_secret')) {
            $this->error('TELEGRAM_WEBHOOK_SECRET is empty.');

            return self::FAILURE;
        }

        $steps = [
            'webhook' => ['setWebhook', [
                'url' => $url,
                'secret_token' => config('telegram.webhook_secret'),
                'allowed_updates' => ['message', 'callback_query'],
            ]],
            // The button left of the message field opens the app inside Telegram.
            'menu button' => ['setChatMenuButton', ['menu_button' => [
                'type' => 'web_app',
                'text' => __('bot.menu_button', [], 'ru'),
                'web_app' => ['url' => $appUrl],
            ]]],
        ];

        // Russian is the default; Uzbek Telegram apps get their own texts.
        foreach (['ru' => null, 'uz' => 'uz'] as $locale => $languageCode) {
            $lang = array_filter(['language_code' => $languageCode]);
            // Telegram allows only a few name changes a day, so the name goes only when it differs.
            $botName = __('bot.name', [], $locale);
            if (($telegram->call('getMyName', $lang)['result']['name'] ?? null) !== $botName) {
                $steps["name ({$locale})"] = ['setMyName', $lang + ['name' => $botName]];
            }
            $steps["commands ({$locale})"] = ['setMyCommands', $lang + ['commands' => collect(__('bot.commands', [], $locale))
                ->map(fn (string $description, string $command) => ['command' => $command, 'description' => $description])
                ->values()->all()]];
            $steps["description ({$locale})"] = ['setMyDescription', $lang + ['description' => __('bot.description', [], $locale)]];
            $steps["short description ({$locale})"] = ['setMyShortDescription', $lang + ['short_description' => __('bot.short_description', [], $locale)]];
        }

        $failed = false;
        foreach ($steps as $name => [$method, $params]) {
            $result = $telegram->call($method, $params);
            if ($result['ok'] ?? false) {
                $this->info("✓ {$name}");
            } else {
                $failed = true;
                $this->error("✗ {$name}: ".($result['description'] ?? 'no answer'));
            }
        }
        $failed = ! $this->avatar($telegram) || $failed;

        if (! $failed) {
            $this->line("Webhook: {$url}");
            $this->line("Mini App: {$appUrl}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Every upload adds one more photo to the bot's profile, so an unchanged file is not sent again. */
    private function avatar(TelegramClient $telegram): bool
    {
        $path = resource_path(self::AVATAR);
        $hash = (string) sha1_file($path);
        if (! $this->option('avatar') && Storage::disk('local')->get(self::AVATAR_SENT) === $hash) {
            $this->line('· profile photo unchanged');

            return true;
        }

        $result = $telegram->upload('setMyProfilePhoto', [
            'photo' => ['type' => 'static', 'photo' => 'attach://avatar'],
        ], ['avatar' => $path]);
        if (! ($result['ok'] ?? false)) {
            $this->error('✗ profile photo: '.($result['description'] ?? 'no answer'));

            return false;
        }

        Storage::disk('local')->put(self::AVATAR_SENT, $hash);
        $this->info('✓ profile photo');

        return true;
    }
}
