<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'pubkey',
        'name',
        'display_name',
        'picture',
        'banner',
        'website',
        'about',
        'nip05',
        'lud16',
        'lud06',
        'deleted',
    ];

    /**
     * The name the profile gives itself: `name`, else `display_name`, null
     * when it has neither.
     */
    public function label(): ?string
    {
        foreach ([$this->name, $this->display_name] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
