<?php

namespace App\Services\Auth\Exceptions;

use RuntimeException;

/**
 * Raised for every way a refresh token can fail to buy a new session —
 * unknown, already spent, expired, or belonging to an account that is no
 * longer active. Deliberately undifferentiated: the client gets one answer for
 * all of them, so a caller holding a guessed token learns nothing about which
 * part of the guess was wrong.
 */
class InvalidRefreshTokenException extends RuntimeException {}
