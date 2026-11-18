<?php

namespace App\Domain\Kitchen\Models;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Kitchen\Enums\QrPointPlace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The random code stored for one desk or one space, printed as a QR and
 * scanned by a member to order from the kitchen. The scanned text carries a
 * prefix so the app can tell it from a door or device code.
 */
class QrPoint extends Model
{
    use HasFactory;

    public const PREFIX = 'add-place:v1';

    protected $fillable = [
        'branch_id',
        'place_type',
        'place_id',
        'qr_value',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'place_type' => QrPointPlace::class,
            'place_id' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** What the printed QR encodes. */
    public function scanValue(): string
    {
        return self::PREFIX.':'.$this->qr_value;
    }

    /**
     * The point a scanned text names, or null for anything else — including a
     * point left behind by a deleted desk or space, which is no QR at all.
     */
    public static function fromScan(string $value): ?self
    {
        $prefix = self::PREFIX.':';

        if (! str_starts_with($value, $prefix)) {
            return null;
        }

        $point = self::query()->where('qr_value', substr($value, strlen($prefix)))->first();

        return $point?->placeModel() === null ? null : $point;
    }

    /**
     * The desk or space this point names, or null once that place is gone —
     * a point left behind by a deleted desk or space counts as no QR at all.
     */
    public function placeModel(): SeatDesk|Space|null
    {
        return match ($this->place_type) {
            QrPointPlace::SeatDesk => SeatDesk::query()->find($this->place_id),
            QrPointPlace::Space => Space::query()->find($this->place_id),
        };
    }

    /** The desk's label or the space's name, in the request locale. */
    public function placeLabel(): string
    {
        return match ($this->place_type) {
            QrPointPlace::SeatDesk => (string) SeatDesk::query()->whereKey($this->place_id)->value('label'),
            QrPointPlace::Space => (string) Space::query()->find($this->place_id)?->translate('name'),
        };
    }
}
