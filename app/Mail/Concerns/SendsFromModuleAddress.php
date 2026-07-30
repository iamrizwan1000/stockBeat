<?php

namespace App\Mail\Concerns;

/**
 * Lets a Mailable declare which of `config('mail.senders')`'s per-module
 * addresses it goes out from (billing / support / notifications / no_reply),
 * rather than every message inheriting the single global `MAIL_FROM_ADDRESS`.
 *
 * Falls back to Laravel's global `mail.from` when the module's own address
 * isn't configured, so a half-configured environment still sends from a valid
 * sender instead of throwing.
 */
trait SendsFromModuleAddress
{
    protected function fromModule(string $module): self
    {
        /** @var array{address: ?string, name: ?string}|null $sender */
        $sender = config("mail.senders.{$module}");

        $address = $sender['address'] ?? null;
        $name = $sender['name'] ?? null;

        if (! is_string($address) || $address === '') {
            return $this;
        }

        return $this->from($address, is_string($name) && $name !== '' ? $name : null);
    }
}
