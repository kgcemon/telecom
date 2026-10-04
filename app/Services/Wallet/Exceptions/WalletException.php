<?php

declare(strict_types=1);

namespace App\Services\Wallet\Exceptions;

use RuntimeException;

/** A wallet movement could not be completed. */
class WalletException extends RuntimeException {}
