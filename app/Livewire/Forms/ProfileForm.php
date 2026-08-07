<?php

namespace App\Livewire\Forms;

use App\Models\EinundzwanzigPleb;
use Flux\Flux;
use Livewire\Attributes\Validate;
use Livewire\Form;

class ProfileForm extends Form
{
    #[Validate('nullable|email')]
    public ?string $email = '';

    /**
     * The handle rules come from the model so that this path, the benefits
     * screen and the API cannot drift apart — see
     * `EinundzwanzigPleb::NIP05_HANDLE_RULES`. The literal regex that stood
     * here was missing `/D` (a trailing newline slipped through the rule and
     * was only removed by middleware) and allowed the reserved name `_`, with
     * which one appears as the association itself.
     */
    #[Validate('nullable|'.EinundzwanzigPleb::NIP05_HANDLE_RULES.'|unique:einundzwanzig_plebs,nip05_handle')]
    public ?string $nip05Handle = '';

    public ?EinundzwanzigPleb $currentPleb;

    public function updatingEmail(mixed $value): void
    {
        if (! is_string($value) && ! is_null($value)) {
            abort(422);
        }
    }

    public function updatingNip05Handle(mixed $value): void
    {
        if (! is_string($value) && ! is_null($value)) {
            abort(422);
        }
    }

    public function setEmail(EinundzwanzigPleb $currentPleb): void
    {
        $this->currentPleb = $currentPleb;
        $this->email = $currentPleb->email;
    }

    public function setNip05Handle(EinundzwanzigPleb $currentPleb): void
    {
        $this->currentPleb = $currentPleb;
        $this->nip05Handle = $currentPleb->nip05_handle;
    }

    public function setPleb(EinundzwanzigPleb $currentPleb): void
    {
        $this->currentPleb = $currentPleb;
        $this->email = $currentPleb->email;
        $this->nip05Handle = $currentPleb->nip05_handle;
    }

    public function saveEmail(): void
    {
        $this->validateOnly('email');

        $this->currentPleb->update([
            'email' => $this->email,
        ]);

        Flux::toast('E-Mail Adresse gespeichert.');
    }

    public function saveNip05Handle(): void
    {
        $this->validateOnly('nip05Handle');

        $nip05Handle = $this->nip05Handle ? strtolower($this->nip05Handle) : null;

        $this->currentPleb->update([
            'nip05_handle' => $nip05Handle,
        ]);

        $this->nip05Handle = $nip05Handle;

        Flux::toast('NIP-05 Handle gespeichert.');
    }
}
