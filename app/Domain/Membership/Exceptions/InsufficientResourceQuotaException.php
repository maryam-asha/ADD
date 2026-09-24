<?php

namespace App\Domain\Membership\Exceptions;

/**
 * Thrown by ResourceQuotaService when recording consumption would take a
 * PlanResourceGrant's remaining quota below zero. No special behavior
 * beyond a clear message naming the grant, membership, requested amount and
 * shortfall.
 */
class InsufficientResourceQuotaException extends \RuntimeException {}
