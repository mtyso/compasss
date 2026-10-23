<?php

namespace App\Push;

/** How a push to one phone went (FcmClient::send). */
enum PushResult: string
{
    case Sent = 'sent';

    // The phone's token is gone for good (app removed, data cleared): forget it.
    case Dead = 'dead';

    // Firebase busy or out of reach: the next minute tries again.
    case Retry = 'retry';

    // Our key or project is wrong, so nothing goes out until it is fixed: the bot stands in meanwhile.
    case Broken = 'broken';

    // Firebase refused this message itself: sending it again would not help.
    case Rejected = 'rejected';

    // The token belongs to another Firebase project than the server's key: an old build, or a setup mistake
    // when no phone takes the message (PushNotifier::deliver decides).
    case Mismatch = 'mismatch';
}
