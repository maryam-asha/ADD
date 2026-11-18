<?php

namespace App\Domain\Kitchen\Enums;

/** Whether an order was sent after scanning a place's QR or from the app's menu. */
enum OrderSource: string
{
    case Qr = 'qr';
    case App = 'app';
}
