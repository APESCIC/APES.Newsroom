<?php

namespace App\Services\Import;

/**
 * Production-safe mail suppression for import paths.
 *
 * Do not use Mail::fake() here: it swaps the container's mail.manager to a
 * MailFake for the life of a queue:work process. A later job then calls
 * Mail::fake() again and TypeErrors (MailFake passed as MailManager), which
 * is what broke Ghost content Confirm after dry-run (#137).
 */
trait SuppressesOutboundMail
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withoutOutboundMail(callable $callback): mixed
    {
        $previous = config('mail.default');
        config(['mail.default' => 'array']);

        try {
            return $callback();
        } finally {
            config(['mail.default' => $previous]);
        }
    }
}
