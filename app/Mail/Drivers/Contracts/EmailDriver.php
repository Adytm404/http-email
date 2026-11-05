<?php

namespace App\Mail\Drivers\Contracts;

use App\Mail\DriverException;
use App\Mail\DriverResult;
use App\Mail\OutgoingMessage;

interface EmailDriver
{
    /** @throws DriverException */
    public function send(OutgoingMessage $message): DriverResult;

    /**
     * Check credentials / connectivity without sending anything.
     *
     * @throws DriverException
     */
    public function test(): void;
}
