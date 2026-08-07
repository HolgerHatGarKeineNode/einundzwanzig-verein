<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The membership domain cannot operate on the configuration it was given.
 *
 * Raised, so far, for exactly one thing: an annual fee of zero or less. That
 * is not a validation error of the caller's request — the caller sends no
 * amount and cannot fix this — and it is not an upstream outage either. It is
 * this installation being misconfigured, and the only safe reaction is to
 * refuse before anything leaves the house: an empty `MEMBERSHIP_FEE` used to
 * produce a payload with `amount: 0` at BTCPay, i.e. an invoice that is
 * settled the moment it is opened, and since the settled fee constitutes the
 * membership, a free one.
 *
 * A plain RuntimeException and not an HttpException on purpose: choosing the
 * status code is the caller's job, the same rule the service already follows
 * for an unreachable payment provider. Every HTTP caller maps it to 503.
 */
class MembershipUnavailableException extends RuntimeException {}
