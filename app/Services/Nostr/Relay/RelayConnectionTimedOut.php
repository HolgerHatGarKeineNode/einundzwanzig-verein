<?php

namespace App\Services\Nostr\Relay;

/**
 * The deadline for this relay passed before it answered.
 */
final class RelayConnectionTimedOut extends RelayConnectionFailed {}
