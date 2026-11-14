<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A privileged election action, or a candidacy, was refused for a reason
 * other than the state of a round or missing rights: a candidate who has not
 * paid, a second submission, a member already on the roll.
 *
 * Never carries a ballot choice or a receipt; nothing that throws it holds
 * either.
 */
class ElectionActionRefusedException extends RuntimeException {}
