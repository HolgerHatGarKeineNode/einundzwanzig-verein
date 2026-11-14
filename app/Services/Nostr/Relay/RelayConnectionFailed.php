<?php

namespace App\Services\Nostr\Relay;

use RuntimeException;

/**
 * The connection to a relay could not be opened or broke off. Whatever the
 * WebSocket library threw underneath — including a TypeError — ends up as this
 * class, so the transport has exactly one thing to catch.
 */
class RelayConnectionFailed extends RuntimeException {}
