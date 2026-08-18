<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Feriado nacional. No hay recepción ni entrega. */
class Holiday extends Model
{
    protected $fillable = ['date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public static function esFeriado(Carbon $fecha): bool
    {
        return static::whereDate('date', $fecha->toDateString())->exists();
    }
}
