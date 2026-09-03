<?php

namespace App\Models;

use App\Support\Locale;
use App\Support\Theme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferencias de interfaz de un usuario. Tabla propia de Laravel; la tabla
 * heredada `users` queda intacta (ver migración `create_user_preferences_table`).
 */
class UserPreference extends Model
{
    protected $fillable = [
        'usr_id',
        'theme',
        'locale',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'theme' => Theme::class,
            'locale' => Locale::class,
            'settings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usr_id', 'usr_id');
    }
}
