<?php

/*
 * Socket-level tripwire for the vendor WebSocket client.
 *
 * vendor/phrity/net-stream/src/SocketClient.php calls stream_socket_client()
 * unqualified inside `namespace Phrity\Net;`. PHP looks an unqualified
 * function up in the current namespace first, so defining a function of that
 * name HERE puts this one in front of the built-in for every WebSocket the
 * vendor dials — including dials from code that never heard of
 * App\Services\Nostr\RelayTransport.
 *
 * Two conditions make it hold, and RelayStrayConnectionTest checks both by
 * effect rather than trusting them:
 * - This file is loaded (from tests/Pest.php) before the first dial in the
 *   process. PHP binds the call site on first execution and keeps it.
 * - The vendor keeps calling the function unqualified from that namespace. A
 *   vendor update that changes either turns the stray-connection tests red.
 *
 * Kept free of anything but the decision: RelayTripwire holds the state.
 */

namespace Phrity\Net;

use Tests\Support\RelayTripwire;
use Tests\Support\StrayRelayConnection;

/**
 * @param  resource|null  $context
 * @return resource|false
 */
function stream_socket_client(
    string $address,
    &$error_code = null,
    &$error_message = null,
    ?float $timeout = null,
    int $flags = STREAM_CLIENT_CONNECT,
    $context = null,
) {
    if (! RelayTripwire::permitsSocket($address)) {
        throw StrayRelayConnection::atSocket($address);
    }

    return \stream_socket_client($address, $error_code, $error_message, $timeout, $flags, $context);
}
