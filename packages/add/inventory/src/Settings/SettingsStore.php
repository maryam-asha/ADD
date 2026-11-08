<?php

namespace Add\Inventory\Settings;

use Add\Inventory\Support\InventoryException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PDOException;

/**
 * The settings saved from the dashboard: rows of inv_settings laid over
 * the merged config at boot, so everything that reads config('inventory.…')
 * follows them, and the config file stays the default. One instance per
 * application; apply() runs once at boot, save() re-applies.
 */
class SettingsStore
{
    private const TABLE = 'inv_settings';

    /** @var array<string, mixed>|null the config's values before the overlay */
    private ?array $defaults = null;

    public function apply(): void
    {
        $this->defaults ??= $this->currentValues();

        foreach ($this->saved() as $key => $value) {
            config(['inventory.'.$key => $value]);
        }
    }

    /**
     * Every setting as the page shows it.
     *
     * @return list<array{key: string, group: string, type: string, options: list<string>, value: mixed, default: mixed, locked: bool, locked_reason: ?string, locked_options: list<string>}>
     */
    public function describe(): array
    {
        $this->defaults ??= $this->currentValues();

        return array_values(array_map(function (SettingDefinition $setting) {
            $value = config('inventory.'.$setting->key);
            // A switch that is on is locked when it cannot be turned off; a
            // list keeps the options in use (the rest may still change).
            $reason = match ($setting->type) {
                'boolean' => $value === true ? $setting->lockReason(true, false) : null,
                'list' => null,
                default => $setting->lockReason($value, null),
            };
            $lockedOptions = $setting->type !== 'list' ? [] : array_values(array_filter(
                (array) $value,
                fn ($option) => $setting->lockReason($value, array_values(array_diff((array) $value, [$option]))) !== null,
            ));

            return [
                'key' => $setting->key,
                'group' => $setting->group,
                'type' => $setting->type,
                'options' => $setting->options,
                'value' => $value,
                'default' => $this->defaults[$setting->key] ?? null,
                'locked' => $reason !== null,
                'locked_reason' => $reason === null ? null : __($reason),
                'locked_options' => $lockedOptions,
            ];
        }, SettingsRegistry::definitions()));
    }

    /**
     * Validates and saves the values sent (key => value); a value equal to
     * the current one is no change and passes a lock.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException when a key is unknown or a value invalid
     * @throws InventoryException when a change is locked
     */
    public function save(array $values): void
    {
        $this->defaults ??= $this->currentValues();
        $definitions = SettingsRegistry::definitions();
        $errors = [];
        $changes = [];

        foreach ($values as $key => $value) {
            $setting = $definitions[$key] ?? null;

            if ($setting === null) {
                $errors["values.{$key}"] = [__('inventory::settings.unknown')];

                continue;
            }

            $value = $setting->cast($value);
            $validator = Validator::make(['value' => $value], $setting->validationRules(), [], ['value' => $key]);

            if ($validator->fails()) {
                $errors["values.{$key}"] = $validator->errors()->get('value');

                continue;
            }

            if ($value !== config('inventory.'.$key)) {
                $changes[$key] = [$setting, $value];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($changes as $key => [$setting, $value]) {
            if ($reason = $setting->lockReason(config('inventory.'.$key), $value)) {
                throw new LockedSettingException($key, __($reason));
            }
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as $key => [, $value]) {
                DB::table(self::TABLE)->upsert(['key' => $key, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()], ['key'], ['value', 'updated_at']);
            }
        });

        $this->apply();
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(): array
    {
        try {
            return DB::table(self::TABLE)->pluck('value', 'key')->map(fn (string $json) => json_decode($json, true))->all();
        } catch (QueryException|PDOException) {
            // Not migrated yet, or no database at all (an artisan command).
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        return collect(SettingsRegistry::definitions())->map(fn (SettingDefinition $setting) => config('inventory.'.$setting->key))->all();
    }
}
