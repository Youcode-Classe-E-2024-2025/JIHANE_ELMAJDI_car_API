<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Car extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'brand',
        'model',
        'year',
        'color',
        'license_plate',
        'daily_rate',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'year' => 'integer',
        'daily_rate' => 'decimal:2',
    ];

    /**
     * Get the rentals for the car.
     */
    public function rentals()
    {
        return $this->hasMany(Rental::class);
    }

    /**
     * Scope a query to only include available cars.
     */
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }
}
