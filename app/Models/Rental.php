<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rental extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'car_id',
        'start_date',
        'end_date',
        'total_price',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_price' => 'decimal:2',
    ];

    /**
     * Get the user that owns the rental.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the car that is rented.
     */
    public function car()
    {
        return $this->belongsTo(Car::class);
    }

    /**
     * Get the payments for the rental.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Calculate the total price for the rental.
     */
    public function calculateTotalPrice()
    {
        $days = $this->start_date->diffInDays($this->end_date) + 1;
        $this->total_price = $days * $this->car->daily_rate;
        return $this->total_price;
    }
}
