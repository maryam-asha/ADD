<?php

namespace Add\Hr\Contracts;

/**
 * How the host reaches people. The package names who (holders of an
 * ability) and what (a message key with placeholders); the host decides
 * the channel, the wording and the language.
 */
interface HrNotifier
{
    /**
     * Notify every account granted `$ability`. Keys are the package's own
     * (`alerts_digest`); a host usually namespaces them (ADD: `hr.alerts_digest`).
     *
     * @param  array<string, scalar>  $placeholders
     */
    public function notifyHolders(string $ability, string $key, array $placeholders = []): void;
}
