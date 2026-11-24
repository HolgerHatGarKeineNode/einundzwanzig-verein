<?php

namespace App\Enums;

use ArchTech\Enums\From;
use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;

#[Meta(Label::class, Color::class)]
enum AssociationStatus: int
{
    use From;
    use InvokableCases;
    use Metadata;
    use Names;
    use Options;
    use Values;

    #[Label('kein Mitglied')] #[Color('cyan')]
    case DEFAULT = 1;
    #[Label('Passiv')] #[Color('orange')]
    case PASSIVE = 2;
    #[Label('Aktiv')] #[Color('purple')]
    case ACTIVE = 3;
    #[Label('Ehrenmitglied')] #[Color('negative')]
    case HONORARY = 4;

    /**
     * Is this a membership (Passiv, Aktiv, Ehrenmitglied)? Whether it is a
     * paid one for a year is `EinundzwanzigPleb::hasPaidMembership()`.
     */
    public function isMembership(): bool
    {
        return $this !== self::DEFAULT;
    }

    public static function selectOptions()
    {
        return collect(self::options())
            ->map(
                fn (
                    $option,
                    $name
                ) => [
                    'value' => $option,
                    'label' => __(
                        self::fromName($name)
                            ->label()
                    ),
                ]
            )
            ->values()
            ->toArray();
    }
}
