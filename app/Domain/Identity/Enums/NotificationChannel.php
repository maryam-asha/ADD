<?php

namespace App\Domain\Identity\Enums;

enum NotificationChannel: string
{
    case Push = 'push';
    case Database = 'database';
    case Sms = 'sms';
    case Email = 'email';
}
