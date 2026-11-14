<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The database refused a write while a ballot was being taken in, for a
 * reason that is not one of the refusals a voter is told about.
 *
 * Carries the SQLSTATE and nothing else, and deliberately no previous
 * exception: PostgreSQL puts the refused row into its message ("DETAIL:
 * Failing row contains (…)", "Key (…)=(…)"), which for a ballot row is the
 * choice and for a receipt is the hash the voter will later look up — next
 * to the member whose request failed. Masking the bindings does not touch
 * that text, and an error report prints the previous exceptions too.
 */
class ElectionBallotWriteException extends RuntimeException {}
