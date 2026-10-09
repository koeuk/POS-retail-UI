<?php

namespace App\Payments;

use RuntimeException;

/**
 * The provider could not be reached or refused us. The message is written
 * for the person at the till or on the settings screen, so keep it plain.
 */
class GatewayException extends RuntimeException {}
