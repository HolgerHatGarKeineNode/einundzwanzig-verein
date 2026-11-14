<?php

namespace App\Exceptions;

use LogicException;

/**
 * A round was asked to do something its state rules out: change what is
 * frozen, open twice, close before it was opened, hand out ballots before it
 * is sealed.
 */
class ElectionRoundStateException extends LogicException {}
