<?php

namespace App\Push;

/** One notification: what the phone shows, and the screen a tap opens. */
final class PushMessage
{
    /**
     * @param  string  $url  the app's path a tap opens, as the Telegram buttons use them
     * @param  ?string  $tag  a newer notification with the same tag replaces the one still showing
     * @param  int  $ttl  seconds Firebase keeps it for a phone that is off: a stale reminder is worse than none
     * @param  array<string, scalar>  $data  more for the app, beside the path
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url = '/',
        public readonly ?string $tag = null,
        public readonly int $ttl = 86400,
        public readonly array $data = [],
    ) {}
}
