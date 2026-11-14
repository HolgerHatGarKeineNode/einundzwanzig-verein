<?php

namespace App\Enums;

use ArchTech\Enums\From;
use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;

/**
 * Where a member proposal stands. Stored, and only ever set by the board.
 *
 * The labels double as translation keys, so output them through `__()`.
 */
#[Meta(Label::class, Color::class, Icon::class)]
enum MemberProposalState: string
{
    use From;
    use InvokableCases;
    use Metadata;
    use Names;
    use Options;
    use Values;

    #[Label('Offen')] #[Color('zinc')] #[Icon('chat-bubble-left-right')]
    case Open = 'open';

    #[Label('Für die Sitzung vorgemerkt')] #[Color('amber')] #[Icon('calendar-days')]
    case Scheduled = 'scheduled';

    #[Label('Beschlossen')] #[Color('blue')] #[Icon('check-circle')]
    case Adopted = 'adopted';

    #[Label('Abgelehnt')] #[Color('red')] #[Icon('x-circle')]
    case Rejected = 'rejected';

    #[Label('Erledigt')] #[Color('green')] #[Icon('check-badge')]
    case Done = 'done';

    /**
     * Adopted, rejected and done proposals live in the archive: readable,
     * but closed for votes, comments and edits.
     */
    public function isArchived(): bool
    {
        return in_array($this, self::archived(), true);
    }

    /**
     * @return list<self>
     */
    public static function archived(): array
    {
        return [self::Adopted, self::Rejected, self::Done];
    }

    /**
     * @return list<self>
     */
    public static function current(): array
    {
        return [self::Open, self::Scheduled];
    }
}
