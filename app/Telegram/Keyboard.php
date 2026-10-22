<?php

namespace App\Telegram;

/**
 * Inline keyboards. Buttons can be coloured (Bot API 9.4 `style`): primary, success, danger;
 * clients that predate it just show a plain button.
 */
final class Keyboard
{
    /** @var list<list<array<string, mixed>>> */
    private array $rows = [];

    public static function make(): self
    {
        return new self;
    }

    /** @param  list<array<string, mixed>|null>  $buttons  nulls are skipped */
    public function row(array $buttons): self
    {
        $buttons = array_values(array_filter($buttons));
        if ($buttons !== []) {
            $this->rows[] = $buttons;
        }

        return $this;
    }

    /** @param  list<array<string, mixed>>  $buttons */
    public function grid(array $buttons, int $perRow): self
    {
        foreach (array_chunk($buttons, $perRow) as $row) {
            $this->row($row);
        }

        return $this;
    }

    public static function callback(string $text, string $data, ?string $style = null): array
    {
        return array_filter(['text' => $text, 'callback_data' => $data, 'style' => $style]);
    }

    /**
     * Opens a screen of the app inside Telegram. Null when the app address is not https:
     * Telegram rejects the whole message for such a button (local and test setups).
     */
    public static function webApp(string $text, string $path = '/', ?string $style = null): ?array
    {
        if (! self::webAppAvailable()) {
            return null;
        }

        $base = rtrim((string) config('telegram.webapp_url'), '/');

        return array_filter(['text' => $text, 'web_app' => ['url' => $base.$path], 'style' => $style]);
    }

    /** Telegram opens the Mini App only from an https address. */
    public static function webAppAvailable(): bool
    {
        return str_starts_with((string) config('telegram.webapp_url'), 'https://');
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>}|null */
    public function toMarkup(): ?array
    {
        return $this->rows === [] ? null : ['inline_keyboard' => $this->rows];
    }
}
