<?php

namespace App\Support;

use RuntimeException;

/** A URL fetch was refused or failed. The message is safe to show to an API caller. */
class SafeFetchException extends RuntimeException {}
