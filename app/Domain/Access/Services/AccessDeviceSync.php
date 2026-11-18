<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Foundation\Models\Device;
use Illuminate\Support\Carbon;

/**
 * Brings each lock and gateway row in line with the TTLock account, matched
 * on external_ref (lockId / gatewayId): a gateway is online when TTLock says
 * so; a lock is online when it is paired with a gateway and that gateway,
 * when we know it, is online — the remote unlock, the door buttons and the
 * code deletes all go through it. A lock's battery is kept in metadata. A row
 * whose id the account does not know is `faulted`. Read-only towards TTLock.
 *
 * Also what the dashboard's device form picks a TTLock id from: account()
 * lists every lock and gateway with the device row already linked to it.
 */
class AccessDeviceSync
{
    public function __construct(private readonly TTLockClient $ttlock) {}

    public function configured(): bool
    {
        return filled(config('services.ttlock.client_id'));
    }

    /**
     * @return array{gateways: int, locks: int, faulted: int}
     *
     * @throws TTLockException
     */
    public function sync(): array
    {
        $gateways = collect($this->ttlock->listGateways())->keyBy(fn ($g) => (string) $g['gatewayId']);
        $locks = collect($this->ttlock->listLocks())->keyBy(fn ($l) => (string) $l['lockId']);
        $syncedAt = now()->toIso8601String();
        $counts = ['gateways' => 0, 'locks' => 0, 'faulted' => 0];

        Device::query()->where('type', 'gateway')->whereNotNull('external_ref')->get()
            ->each(function (Device $device) use ($gateways, $syncedAt, &$counts) {
                $vendor = $gateways->get((string) $device->external_ref);

                if ($vendor === null) {
                    $device->update(['status' => 'faulted']);
                    $counts['faulted']++;

                    return;
                }

                $device->update([
                    'status' => (int) ($vendor['isOnline'] ?? 0) === 1 ? 'online' : 'offline',
                    'metadata' => array_merge($device->metadata ?? [], [
                        'lock_count' => (int) ($vendor['lockNum'] ?? 0),
                        'network_name' => $vendor['networkName'] ?? null,
                        'synced_at' => $syncedAt,
                    ]),
                ]);
                $counts['gateways']++;
            });

        Device::query()->where('type', 'lock')->whereNotNull('external_ref')->with('parent')->get()
            ->each(function (Device $device) use ($locks, $syncedAt, &$counts) {
                $vendor = $locks->get((string) $device->external_ref);

                if ($vendor === null) {
                    $device->update(['status' => 'faulted']);
                    $counts['faulted']++;

                    return;
                }

                $paired = (int) ($vendor['hasGateway'] ?? 0) === 1;
                // The parent row was refreshed above; without one, being
                // paired is all TTLock tells us about the lock.
                $online = $paired && ($device->parent === null || $device->parent->fresh()->status === 'online');

                $device->update([
                    'status' => $online ? 'online' : 'offline',
                    'metadata' => array_merge($device->metadata ?? [], [
                        'battery' => isset($vendor['electricQuantity']) ? (int) $vendor['electricQuantity'] : null,
                        'battery_updated_at' => isset($vendor['electricQuantityUpdateDate'])
                            ? Carbon::createFromTimestampMs((int) $vendor['electricQuantityUpdateDate'])->toIso8601String()
                            : null,
                        'has_gateway' => $paired,
                        'synced_at' => $syncedAt,
                    ]),
                ]);
                $counts['locks']++;
            });

        return $counts;
    }

    /**
     * Every lock and gateway on the account, in the few fields the device
     * form needs, each with the device row already using its id.
     *
     * @return array{locks: list<array<string, mixed>>, gateways: list<array<string, mixed>>}
     *
     * @throws TTLockException
     */
    public function account(): array
    {
        $linked = fn (string $type) => Device::query()->where('type', $type)->whereNotNull('external_ref')
            ->pluck('id', 'external_ref')->mapWithKeys(fn ($id, $ref) => [(string) $ref => $id]);
        $lockRows = $linked('lock');
        $gatewayRows = $linked('gateway');

        return [
            'locks' => array_map(fn (array $lock) => [
                'lock_id' => (string) $lock['lockId'],
                'name' => $lock['lockAlias'] ?? $lock['lockName'] ?? null,
                'mac' => $lock['lockMac'] ?? null,
                'battery' => isset($lock['electricQuantity']) ? (int) $lock['electricQuantity'] : null,
                'has_gateway' => (int) ($lock['hasGateway'] ?? 0) === 1,
                'linked_device_id' => $lockRows->get((string) $lock['lockId']),
            ], $this->ttlock->listLocks()),
            'gateways' => array_map(fn (array $gateway) => [
                'gateway_id' => (string) $gateway['gatewayId'],
                'name' => $gateway['gatewayName'] ?? null,
                'mac' => $gateway['gatewayMac'] ?? null,
                'is_online' => (int) ($gateway['isOnline'] ?? 0) === 1,
                'lock_count' => (int) ($gateway['lockNum'] ?? 0),
                'network_name' => $gateway['networkName'] ?? null,
                'linked_device_id' => $gatewayRows->get((string) $gateway['gatewayId']),
            ], $this->ttlock->listGateways()),
        ];
    }
}
