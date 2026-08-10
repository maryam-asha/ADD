<?php

namespace App\Services\Otp;

/**
 * Why a verification attempt ended the way it did. A plain bool can't
 * distinguish "that code is wrong" from "that code is real but was issued for
 * a different flow" — and the caller has to tell them apart, because the
 * second one deserves an explicit message telling the member which screen
 * their code actually belongs to.
 */
enum OtpResult
{
    case Verified;
    case InvalidOrExpired;
    case PurposeMismatch;
}
