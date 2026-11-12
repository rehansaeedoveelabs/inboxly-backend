<?php

namespace App\Support\Messaging\Contracts;

use App\Support\Messaging\OutboundMessage;
use App\Support\Messaging\SendResult;

interface EmailSender
{
    public function send(OutboundMessage $message): SendResult;
}
