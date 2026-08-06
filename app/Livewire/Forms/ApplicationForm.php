<?php

namespace App\Livewire\Forms;

use App\Models\EinundzwanzigPleb;
use Livewire\Attributes\Validate;
use Livewire\Form;

class ApplicationForm extends Form
{
    #[Validate('nullable|string')]
    public $reason = '';

    #[Validate('accepted')]
    public $check = false;

    public ?EinundzwanzigPleb $currentPleb = null;

    public function setPleb(EinundzwanzigPleb $pleb): void
    {
        $this->currentPleb = $pleb;
    }

    /**
     * Record the application. It deliberately takes no status argument and
     * writes none: the payment of the annual fee constitutes the membership,
     * the application only records the data and the consent to the statutes.
     *
     * Livewire exposes every public method as a directly callable endpoint,
     * so any status value accepted here would be a client-supplied one.
     */
    public function apply(): void
    {
        $this->validate();

        $now = now();

        $this->currentPleb->update([
            'applied_at' => $now,
            'statutes_accepted_at' => $this->currentPleb->statutes_accepted_at ?? $now,
        ]);

        $this->reset('check');
    }
}
