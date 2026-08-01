<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when a purchase asks for more tickets than remain. Its message is
 * written for the buyer and is safe to render on the public page.
 */
class SoldOutException extends RuntimeException {}
