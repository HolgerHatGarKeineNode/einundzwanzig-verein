<?php

namespace App\Livewire\Forms;

use App\Models\ProjectProposal;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * The fields of a project-support proposal, shared by the create and the edit
 * page so both accept and refuse the same input and write the same columns.
 *
 * What a page may do with them (create, or update while the proposal is not
 * locked by a board vote) stays with the page and `ProjectProposalPolicy`.
 * The rules are not named `rules()` on purpose: Livewire would then validate
 * the form on its own, without the proposal whose name may stay as it is.
 */
class ProjectProposalForm extends Form
{
    public const IMAGE_RULES = 'nullable|file|mimes:jpeg,png,jpg,gif,webp|mimetypes:image/jpeg,image/png,image/gif,image/webp|max:10240';

    public ?string $name = '';

    public ?string $description = '';

    /** @var int|string|null */
    public $support_in_sats = '';

    public ?string $website = '';

    /** @var bool|int|string|null */
    public $contact_via_nostr_dm = true;

    public ?string $contact_alternative = '';

    /**
     * Keyed by the form's property name (`form.name`, ...), so a page can
     * merge them with its own rules.
     *
     * @return array<string, mixed>
     */
    public function rulesFor(?ProjectProposal $proposal = null): array
    {
        $uniqueName = Rule::unique('project_proposals', 'name');

        return $this->prefixed([
            'name' => ['required', 'string', 'max:255', $proposal === null ? $uniqueName : $uniqueName->ignore($proposal->getKey())],
            'description' => 'required|string',
            'support_in_sats' => 'required|integer|min:0',
            'website' => 'required|url|max:255',
            'contact_via_nostr_dm' => 'boolean',
            'contact_alternative' => 'nullable|string|max:255',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function validationMessages(): array
    {
        return $this->prefixed([
            'name.unique' => 'Ein Antrag mit diesem Namen existiert bereits. Bitte wähle einen anderen Namen.',
        ]);
    }

    public function fillFrom(ProjectProposal $proposal): void
    {
        $this->name = $proposal->name;
        $this->description = $proposal->description;
        $this->support_in_sats = (string) $proposal->support_in_sats;
        $this->website = $proposal->website ?? '';
        $this->contact_via_nostr_dm = (bool) $proposal->contact_via_nostr_dm;
        $this->contact_alternative = $proposal->contact_alternative ?? '';
    }

    /**
     * The columns the submitter writes, from validated input.
     *
     * @return array{name: string, description: string, support_in_sats: int, website: string, contact_via_nostr_dm: bool, contact_alternative: string|null}
     */
    public function toAttributes(): array
    {
        return [
            'name' => (string) $this->name,
            'description' => (string) $this->description,
            'support_in_sats' => (int) $this->support_in_sats,
            'website' => (string) $this->website,
            'contact_via_nostr_dm' => (bool) ($this->contact_via_nostr_dm ?? true),
            'contact_alternative' => filled($this->contact_alternative) ? trim($this->contact_alternative) : null,
        ];
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $entries
     * @return array<string, T>
     */
    private function prefixed(array $entries): array
    {
        $prefix = $this->getPropertyName().'.';

        return collect($entries)->mapWithKeys(fn ($value, string $key): array => [$prefix.$key => $value])->all();
    }
}
