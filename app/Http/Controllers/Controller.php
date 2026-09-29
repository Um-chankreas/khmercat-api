<?php

namespace App\Http\Controllers;

use Throwable;

abstract class Controller
{
    /**
     * Runs a side effect — a real-time broadcast or a notification (whose
     * `broadcast` channel also goes through Reverb) — without letting it
     * break the request. The main action (saving the comment, the like, the
     * follow) has already happened by now; if Reverb is down, failing here
     * would report an error for something that actually succeeded, and a
     * retry would create a duplicate. The failure is still logged.
     */
    protected function sideEffect(callable $effect): void
    {
        try {
            $effect();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
