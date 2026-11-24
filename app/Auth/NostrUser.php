<?php

namespace App\Auth;

use App\Models\EinundzwanzigPleb;
use Illuminate\Contracts\Auth\Authenticatable;

class NostrUser implements Authenticatable
{
    protected string $pubkey;

    protected ?EinundzwanzigPleb $pleb;

    public function __construct(string $pubkey)
    {
        $this->pubkey = $pubkey;
        $this->pleb = EinundzwanzigPleb::query()
            ->where('pubkey', $pubkey)
            ->first();
    }

    public function getAuthIdentifierName(): string
    {
        return 'pubkey';
    }

    public function getAuthIdentifier(): string
    {
        return $this->pubkey;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        //
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    public function getPleb(): ?EinundzwanzigPleb
    {
        return $this->pleb;
    }

    public function isBoardMember(): bool
    {
        return $this->pleb?->isBoardMember() ?? false;
    }
}
