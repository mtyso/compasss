<?php

namespace App\Telegram\Rich;

/**
 * A bot message built once and rendered two ways:
 *  - rich(): HTML for sendRichMessage (Bot API 10.1+): headings, tables, quotes, footers;
 *  - legacy(): HTML for sendMessage with parse_mode=HTML, used when rich messages are off
 *    or Telegram refuses one.
 *
 * Text arguments named $text are plain and get escaped here. Arguments named $html are
 * inline HTML built with Card::e() and the inline tags both formats share: b, i, u, s, code, a.
 */
final class Card
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $blocks = [];

    public static function make(): self
    {
        return new self;
    }

    /** Escapes plain text for both formats. Numeric quotes: the legacy format has no &apos;. */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    public function heading(string $text, int $level = 3): self
    {
        $this->blocks[] = ['heading', [$text, max(1, min(6, $level))]];

        return $this;
    }

    public function paragraph(string $html): self
    {
        $this->blocks[] = ['paragraph', $html];

        return $this;
    }

    /**
     * Label and value pairs, values aligned to the right in the rich form.
     *
     * @param  list<array{0: string, 1: string}>  $rows  [label text, value html]
     */
    public function facts(array $rows): self
    {
        if ($rows !== []) {
            $this->blocks[] = ['facts', $rows];
        }

        return $this;
    }

    /**
     * A table with a header row; every column after the first is aligned right.
     *
     * @param  list<string>  $header  plain text
     * @param  list<list<string>>  $rows  html cells
     */
    public function table(array $header, array $rows): self
    {
        if ($rows !== []) {
            $this->blocks[] = ['table', [$header, $rows]];
        }

        return $this;
    }

    /** @param  list<string>  $items  html */
    public function list(array $items): self
    {
        if ($items !== []) {
            $this->blocks[] = ['list', $items];
        }

        return $this;
    }

    public function quote(string $html): self
    {
        $this->blocks[] = ['quote', $html];

        return $this;
    }

    public function divider(): self
    {
        $this->blocks[] = ['divider', null];

        return $this;
    }

    public function footer(string $html): self
    {
        $this->blocks[] = ['footer', $html];

        return $this;
    }

    public function rich(): string
    {
        return implode('', array_map(fn (array $block) => match ($block[0]) {
            'heading' => "<h{$block[1][1]}>".self::e($block[1][0])."</h{$block[1][1]}>",
            'paragraph' => "<p>{$block[1]}</p>",
            'facts' => '<table compact>'.implode('', array_map(
                fn (array $row) => '<tr><td>'.self::e($row[0]).'</td><td align="right">'.$row[1].'</td></tr>',
                $block[1],
            )).'</table>',
            'table' => '<table compact striped><tr>'
                .implode('', array_map(fn (string $h, int $i) => '<th'.($i > 0 ? ' align="right"' : '').'>'.self::e($h).'</th>', $block[1][0], array_keys($block[1][0])))
                .'</tr>'
                .implode('', array_map(
                    fn (array $row) => '<tr>'.implode('', array_map(fn (string $cell, int $i) => '<td'.($i > 0 ? ' align="right"' : '').">{$cell}</td>", $row, array_keys($row))).'</tr>',
                    $block[1][1],
                ))
                .'</table>',
            'list' => '<ul>'.implode('', array_map(fn (string $item) => "<li>{$item}</li>", $block[1])).'</ul>',
            'quote' => "<blockquote>{$block[1]}</blockquote>",
            'divider' => '<hr/>',
            'footer' => "<footer>{$block[1]}</footer>",
        }, $this->blocks));
    }

    public function legacy(): string
    {
        $parts = array_map(fn (array $block) => match ($block[0]) {
            'heading' => '<b>'.self::e($block[1][0]).'</b>',
            'paragraph' => $block[1],
            'facts' => implode("\n", array_map(fn (array $row) => self::e($row[0]).': '.$row[1], $block[1])),
            'table' => implode("\n", array_map(fn (array $row) => implode(' · ', $row), $block[1][1])),
            'list' => implode("\n", array_map(fn (string $item) => "• {$item}", $block[1])),
            'quote' => "<blockquote>{$block[1]}</blockquote>",
            'divider' => '',
            'footer' => "<i>{$block[1]}</i>",
        }, $this->blocks);

        // A blank line between blocks reads like the spacing of the rich form.
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n\n", $parts)));
    }
}
