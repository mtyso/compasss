<?php

namespace App\Console\Commands;

use App\Advisor\Advisor;
use App\Advisor\Rules\Rule;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('advisor:run {event : morning or evening}')]
#[Description('Run the advisor rules of a time of day for every user')]
class AdvisorRun extends Command
{
    public function handle(Advisor $advisor): int
    {
        $event = $this->argument('event');
        if (! in_array($event, [Rule::EVENT_MORNING, Rule::EVENT_EVENING], true)) {
            $this->error('The event is morning or evening.');

            return self::INVALID;
        }

        foreach (User::all() as $user) {
            $event === Rule::EVENT_MORNING
                ? $advisor->morning($user, now())
                : $advisor->run($user, $event, now());
        }

        return self::SUCCESS;
    }
}
