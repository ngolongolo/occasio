<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventPackage extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'description',
        'price',
        'capacity',
        'max_per_registration',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'capacity' => 'integer',
            'max_per_registration' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EventPackage $package): void {
            $event = $package->relationLoaded('event')
                ? $package->event
                : Event::query()->find($package->event_id);

            if ($event) {
                // Package prices always share their event's currency. Mixed-currency
                // package checkout is intentionally unsupported.
                $package->currency = strtoupper((string) ($event->participation_currency ?: 'TZS'));
            }
        });
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function invitees()
    {
        return $this->hasMany(Invitee::class);
    }

    public function reservedAdmissions(?Invitee $except = null): int
    {
        return (int) $this->invitees()
            ->reservingCapacity()
            ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->sum('attending_count');
    }

    public function remainingCapacity(?Invitee $except = null): ?int
    {
        if ($this->capacity === null) return null;

        return max(0, (int) $this->capacity - $this->reservedAdmissions($except));
    }
}
