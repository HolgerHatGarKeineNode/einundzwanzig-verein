<?php

namespace App\Enums;

/**
 * Why an incoming payment was refused and put in front of a human.
 *
 * A closed vocabulary rather than free text, because these rows are meant to
 * be queried — "show me everything that came in for the wrong amount" has to
 * be one `where`, not a guess at how the string was phrased that day.
 */
enum PaymentReviewReason: string
{
    /** BTCPay's invoice is for a different amount than the fee that was billed. */
    case AmountMismatch = 'amount_mismatch';

    /** BTCPay's invoice is denominated in a different currency than the fee. */
    case CurrencyMismatch = 'currency_mismatch';

    /**
     * A settled invoice at BTCPay that no payment event in this database
     * points at. Money arrived against a claim the association cannot find.
     */
    case UnknownInvoice = 'unknown_invoice';

    /**
     * A payment event that is supposed to be settled but carries no invoice
     * id, so there is nothing to verify it against. Refused rather than
     * trusted: an unverifiable payment is not a verified one.
     */
    case MissingInvoiceReference = 'missing_invoice_reference';

    /**
     * BTCPay answered, but the answer carried no usable amount or currency —
     * or the fee that was billed is zero or negative. Same rule as above: what
     * cannot be checked is not booked.
     */
    case UnverifiableAmount = 'unverifiable_amount';

    /**
     * The invoice handed in does not belong to the fee it was handed in for.
     * Checked because verifying an amount says nothing unless the thing the
     * amount came from is the right thing.
     */
    case InvoiceMismatch = 'invoice_mismatch';

    /** BTCPay does not consider this invoice settled at all. */
    case NotSettled = 'not_settled';

    /**
     * Is this a verdict, or is it noise on the line?
     *
     * The distinction decides the HTTP answer the BTCPay webhook gets, and
     * that in turn decides whether BTCPay tries again (it retries on 5xx, 429,
     * 408 and connection errors; a 2xx ends the delivery for good —
     * WebhookSender.cs:194-206).
     *
     * Getting it backwards is not symmetrical. Acknowledging a TRANSIENT
     * failure turns a momentary upstream hiccup into a permanent loss: the
     * delivery is marked done, the redelivery that would have carried the
     * right answer finds the marker and does nothing, and the payment is never
     * booked. Asking for a retry on a FINAL one merely costs eight useless
     * requests over an hour.
     *
     * So `unverifiable_amount` (BTCPay answered with something unreadable) and
     * `not_settled` (BTCPay has not caught up with its own event yet) ask for
     * a retry, while a wrong amount or a wrong currency are answers, not
     * accidents, and are acknowledged.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::UnverifiableAmount, self::NotSettled => true,
            default => false,
        };
    }
}
