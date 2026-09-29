<?php

namespace App\Services\CloudHub;

use RuntimeException;

/**
 * The hub could not give a usable answer: no connection, a timeout, a 5xx, or a 401 (clock or key).
 * The sync cycle stops the current channel, records the message and tries again next minute.
 */
class CloudHubUnavailable extends RuntimeException {}
