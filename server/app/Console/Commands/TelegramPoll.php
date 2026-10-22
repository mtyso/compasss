<?php

namespace App\Console\Commands;

use App\Telegram\BotUsers;
use App\Telegram\TelegramClient;
use App\Telegram\UpdateHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the bot from a computer that has no https address for a webhook (docs/BOT.md, «Проверить до деплоя»):
 * asks Telegram for new updates and hands each one to the same handler the webhook uses.
 */
#[Signature('telegram:poll {--once : Handle what has already arrived and stop}')]
#[Description('Local testing without a webhook: fetch the bot\'s updates from Telegram and answer them here')]
class TelegramPoll extends Command
{
    public function handle(TelegramClient $telegram, UpdateHandler $handler): int
    {
        if ((string) config('telegram.bot_token') === '') {
            $this->error('Set TELEGRAM_BOT_TOKEN in .env first.');

            return self::FAILURE;
        }

        if (config('telegram.allowed_ids') === []) {
            $this->warn('ALLOWED_TELEGRAM_IDS is empty, so the bot only answers «Это личный бот». Write to it, put your ID from the line below into .env and start this command again.');
        }

        $this->info('Listening. Write to the bot in Telegram; Ctrl+C stops.');
        $offset = 0;

        do {
            $answer = $telegram->getUpdates($offset, $this->option('once') ? 0 : 25);

            if (! ($answer['ok'] ?? false)) {
                $code = $answer['error_code'] ?? null;
                // 401 and 404: a wrong token. 409: the bot has a webhook, or another poll is running.
                if (in_array($code, [401, 404, 409], true) || $this->option('once')) {
                    $this->error('Telegram refused: '.($answer['description'] ?? 'no answer'));
                    if ($code === 409) {
                        $this->line('While a webhook is set (telegram:setup), Telegram sends every update there. Test with a bot that has no webhook.');
                    }

                    return self::FAILURE;
                }

                sleep(3);

                continue;
            }

            foreach ($answer['result'] ?? [] as $update) {
                $offset = $update['update_id'] + 1;
                $this->line($this->describe($update));

                try {
                    $handler->handle($update);
                } catch (Throwable $e) {
                    // As in the webhook: one broken update must not stop the bot.
                    report($e);
                    $this->error($e->getMessage());
                }
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** «14:20:05 Elyor (111222333): такси 32к», with a hint when this person is not let in. */
    private function describe(array $update): string
    {
        $query = $update['callback_query'] ?? null;
        $from = $query['from'] ?? $update['message']['from'] ?? [];
        $what = $query ? '['.($query['data'] ?? '').']' : ($update['message']['text'] ?? '(not text)');
        $who = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')).' ('.($from['id'] ?? '?').')';
        $hint = isset($from['id']) && BotUsers::isAllowed($from['id']) ? '' : '  <- not in ALLOWED_TELEGRAM_IDS';

        return now()->format('H:i:s')." {$who}: {$what}{$hint}";
    }
}
