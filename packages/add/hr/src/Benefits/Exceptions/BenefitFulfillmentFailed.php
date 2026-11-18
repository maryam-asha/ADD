<?php

namespace Add\Hr\Benefits\Exceptions;

use RuntimeException;

/**
 * A grant the host could not deliver; the message is stored on the grant
 * and shown to HR, who may retry once the cause is fixed.
 */
class BenefitFulfillmentFailed extends RuntimeException {}
