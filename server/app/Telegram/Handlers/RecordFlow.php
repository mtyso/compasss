<?php

namespace App\Telegram\Handlers;

use App\Advisor\CanAfford;
use App\Models\Account;
use App\Models\Insight;
use App\Models\Transaction;
use App\Models\User;
use App\Money\ExchangeRates;
use App\Money\TransactionWriter;
use App\Support\Money;
use App\Telegram\Notifier;
use App\Telegram\Phrase\CategoryGuesser;
use App\Telegram\Phrase\ParsedPhrase;
use App\Telegram\Phrase\PhraseParser;
use App\Telegram\Rich\Card;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use App\Telegram\Views\Fmt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Operations from chat phrases («такси 32к») and the buttons under their cards:
 * change the category or account, undo, restore, mark a large one as one-off;
 * and «можно купить наушники 600к?» answered by the advisor (docs/BOT.md).
 */
class RecordFlow
{
    public function __construct(
        private TelegramClient $telegram,
        private TransactionWriter $writer,
        private ExchangeRates $rates,
        private Cards $cards,
        private CanAfford $canAfford,
    ) {}

    public function onText(int $chatId, User $user, string $text): void
    {
        $phrase = PhraseParser::parse($text);

        if ($phrase === null) {
            $this->telegram->sendCard($chatId, $this->cards->message(__('bot.record.not_understood', [], $user->locale)));

            return;
        }

        if ($phrase->kind === 'question') {
            $this->answer($chatId, $user, $phrase);

            return;
        }

        $this->record($chatId, $user, $phrase);
    }

    /** «Можно ли купить?»: the advisor's verdict, and a button that records the purchase if it happens. */
    private function answer(int $chatId, User $user, ParsedPhrase $phrase): void
    {
        if (! $user->accounts()->whereNull('archived_at')->exists()) {
            $this->telegram->sendCard($chatId, $this->cards->noAccounts($user), $this->cards->noAccountsKeyboard($user));

            return;
        }

        $amountUzs = $phrase->amount;
        if ($phrase->currency !== 'UZS') {
            $rate = $this->rates->rateFor($phrase->currency, now());
            if ($rate === null) {
                $this->telegram->sendCard($chatId, $this->cards->message(Card::e(__('bot.record.no_rate', [], $user->locale))));

                return;
            }
            $amountUzs = Money::toUzs($phrase->amount, $phrase->currency, $rate);
        }

        // A category only when the words name one: «Прочее» has no budget to check against.
        $category = (new CategoryGuesser($user))->find($phrase->note, 'expense');
        $verdict = $this->canAfford->check($user, $amountUzs, $category, now());

        // callback_data holds 64 bytes, so the phrase waits in the cache under a short key.
        $key = Str::random(12);
        Cache::put(self::buyKey($user, $key), [
            'amount' => $phrase->amount, 'currency' => $phrase->currency, 'note' => $phrase->note, 'wallet' => $phrase->wallet,
        ], now()->addDays(2));

        $this->telegram->sendCard($chatId, $this->cards->verdict($user, $phrase, $verdict), $this->cards->verdictKeyboard($user, $verdict, $key));
    }

    /** «Купил, записать» under a verdict. */
    public function onBuy(array $query, User $user): void
    {
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        // Pulled, not read: a double tap records the purchase once.
        $saved = preg_match('/^buy:([A-Za-z0-9]{12})$/', $query['data'] ?? '', $m) ? Cache::pull(self::buyKey($user, $m[1])) : null;
        if (! is_array($saved)) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.question.expired', [], $user->locale));

            return;
        }

        $this->telegram->answerCallbackQuery($query['id']);
        $this->telegram->editKeyboard($chatId, $messageId, $this->cards->startKeyboard($user));
        $this->record($chatId, $user, new ParsedPhrase('expense', $saved['amount'], $saved['currency'], $saved['note'], $saved['wallet']));
    }

    private static function buyKey(User $user, string $key): string
    {
        return "bot:buy:{$user->id}:{$key}";
    }

    private function record(int $chatId, User $user, ParsedPhrase $phrase): void
    {
        $account = $this->pickAccount($user, $phrase);
        if ($account === null) {
            $this->telegram->sendCard($chatId, $this->cards->noAccounts($user), $this->cards->noAccountsKeyboard($user));

            return;
        }

        [$amount, $note] = [$phrase->amount, $phrase->note];
        if ($account->currency !== $phrase->currency) {
            // «$20 подписка» without a dollar account: record it in som at today's CBU rate.
            $rate = $this->rates->rateFor($phrase->currency, now());
            if ($rate === null) {
                $this->telegram->sendCard($chatId, $this->cards->message(Card::e(__('bot.record.no_rate', [], $user->locale))));

                return;
            }
            $amount = Money::toUzs($phrase->amount, $phrase->currency, $rate);
            $note = trim($note.' ('.Fmt::money($phrase->amount, $phrase->currency, $user->locale).')');
        }

        $kind = $phrase->kind === 'expense' && CategoryGuesser::soundsLikeIncome($phrase->note) ? 'income' : $phrase->kind;
        $category = (new CategoryGuesser($user))->guess($phrase->note, $kind);

        $lastInsight = $this->lastInsightId($user);
        $tx = $this->writer->save($user, new Transaction, [
            'type' => $kind,
            'account_id' => $account->id,
            'amount' => $amount,
            'category_id' => $category->id,
            'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
            'occurred_at' => now()->toDateTimeString(),
            'source' => 'bot',
        ]);

        // The advisor has just looked at the operation (App\Listeners\RunAdvisor): its findings go into the card.
        $insights = $this->insightsSince($user, $lastInsight);
        $this->telegram->sendCard($chatId, $this->cards->transaction($tx, $user, insights: $insights->take(3)), $this->cards->transactionKeyboard($tx, $user));
        Notifier::markSent($insights);
    }

    public function onCallback(array $query, User $user): void
    {
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        if (! preg_match('/^tx:(cats|sub|cat|accs|acc|del|restore|card|oneoff):(\d+)(?::(\d+))?$/', $query['data'] ?? '', $m)) {
            $this->telegram->answerCallbackQuery($query['id']);

            return;
        }
        [$action, $id, $arg] = [$m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null];

        $tx = $user->transactions()->withTrashed()->find($id);
        if (! $tx || ($tx->trashed() && $action !== 'restore')) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.record.gone', [], $user->locale));

            return;
        }

        $toast = null;
        switch ($action) {
            case 'cats':
                $this->telegram->editKeyboard($chatId, $messageId, $this->cards->categoryKeyboard($tx, $user));
                $toast = __('bot.record.pick_category', [], $user->locale);
                break;

            case 'sub':
                $parent = $user->categories()->where('kind', $tx->type)->whereNull('parent_id')->find($arg);
                if ($parent) {
                    $this->telegram->editKeyboard($chatId, $messageId, $this->cards->subcategoryKeyboard($tx, $user, $parent));
                }
                break;

            case 'accs':
                $this->telegram->editKeyboard($chatId, $messageId, $this->cards->accountKeyboard($tx, $user));
                $toast = __('bot.record.pick_account', [], $user->locale);
                break;

            case 'card':
                $this->telegram->editKeyboard($chatId, $messageId, $this->cards->transactionKeyboard($tx, $user));
                break;

            case 'cat':
                $category = $user->categories()->where('kind', $tx->type)->whereNull('archived_at')->find($arg);
                if ($category) {
                    $this->resave($chatId, $messageId, $user, $tx, ['category_id' => $category->id]);
                    $toast = __('bot.record.saved', [], $user->locale);
                }
                break;

            case 'acc':
                $account = $user->accounts()->whereNull('archived_at')->where('currency', $tx->currency)->find($arg);
                if ($account) {
                    $this->resave($chatId, $messageId, $user, $tx, ['account_id' => $account->id]);
                    $toast = __('bot.record.saved', [], $user->locale);
                }
                break;

            case 'del':
                $tx->delete();
                $this->redraw($chatId, $messageId, $tx, $user);
                break;

            case 'restore':
                $tx->restore();
                $this->redraw($chatId, $messageId, $tx, $user);
                break;

            case 'oneoff':
                // Same as the button on the insight in the app (InsightController::act).
                $tx->update(['is_one_off' => true]);
                $user->insights()->where('rule', 'large_spend')->whereIn('status', ['new', 'seen'])
                    ->where('payload->transaction_id', $tx->id)->update(['status' => 'useful']);
                $this->redraw($chatId, $messageId, $tx, $user);
                $toast = __('bot.insight.one_off_done', [], $user->locale);
                break;
        }

        $this->telegram->answerCallbackQuery($query['id'], $toast);
    }

    /** @param  Collection<int, Insight>|null  $insights */
    private function redraw(int $chatId, int $messageId, Transaction $tx, User $user, ?Collection $insights = null): void
    {
        $tx->unsetRelations();
        $this->telegram->editCard($chatId, $messageId, $this->cards->transaction($tx, $user, insights: $insights?->take(3) ?? []), $this->cards->transactionKeyboard($tx, $user));
    }

    /**
     * Saves the operation again with one field changed and redraws its card with what the advisor
     * now finds. The rate stays the one it was recorded at.
     */
    private function resave(int $chatId, int $messageId, User $user, Transaction $tx, array $changes): void
    {
        $lastInsight = $this->lastInsightId($user);
        $tx = $this->writer->save($user, $tx, [
            'type' => $tx->type,
            'account_id' => $tx->account_id,
            'amount' => $tx->amount,
            'category_id' => $tx->category_id,
            'note' => $tx->note,
            'occurred_at' => $tx->occurred_at->toDateTimeString(),
            'is_one_off' => $tx->is_one_off,
            'rate' => $tx->rate,
            ...$changes,
        ]);

        $insights = $this->insightsSince($user, $lastInsight);
        $this->redraw($chatId, $messageId, $tx, $user, $insights);
        Notifier::markSent($insights);
    }

    private function lastInsightId(User $user): int
    {
        return (int) $user->insights()->max('id');
    }

    /** @return Collection<int, Insight> most important first */
    private function insightsSince(User $user, int $lastId): Collection
    {
        $rank = array_flip(Insight::SEVERITIES);

        return $user->insights()->where('id', '>', $lastId)->get()
            ->sortBy(fn (Insight $i) => [$rank[$i->severity] ?? 9, $i->id])
            ->values();
    }

    /**
     * The account a phrase goes to: one in its currency (a dollar phrase falls back to som accounts),
     * a cash one for «налик», a card for «картой», otherwise the first everyday card.
     */
    private function pickAccount(User $user, ParsedPhrase $phrase): ?Account
    {
        /** @var Collection<int, Account> $accounts */
        $accounts = $user->accounts()->whereNull('archived_at')->orderBy('sort')->orderBy('id')->get();
        $pool = $accounts->where('currency', $phrase->currency);
        if ($pool->isEmpty()) {
            $pool = $accounts->where('currency', 'UZS');
        }
        $everyday = $pool->filter(fn (Account $a) => $a->spendable && ! $a->is_cushion);

        return match ($phrase->wallet) {
            'cash' => $pool->firstWhere('type', 'cash'),
            'card' => $everyday->firstWhere('type', 'card') ?? $pool->firstWhere('type', 'card'),
            default => null,
        } ?? $everyday->firstWhere('type', 'card') ?? $everyday->first() ?? $pool->first();
    }
}
