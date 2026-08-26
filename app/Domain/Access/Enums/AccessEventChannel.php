<?php

namespace App\Domain\Access\Enums;

enum AccessEventChannel: string
{
    case KeypadManual = 'keypad_manual';
    case QrScan = 'qr_scan';
    case ReceptionActivation = 'reception_activation';
}
