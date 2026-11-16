<?php

namespace App\Enums;

enum ElectionRoundMode: string
{
    /** Opens and closes at times fixed in advance. */
    case Window = 'window';

    /** Opened by hand in the assembly; closes by hand or after a maximum duration. */
    case Live = 'live';
}
