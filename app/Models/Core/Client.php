<?php

namespace App\Models\Core;

/**
 * Cliente (a quien se le factura). En el módulo de transacciones aparece como
 * `customer` y su nombre visible es `fullName`.
 */
class Client extends CoreModel
{
    protected $table = 'client';

    protected $primaryKey = 'client_id';

    protected $hidden = ['password', 'auth_key', 'password_reset_token', 'verification_code'];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'customer', 'client_id');
    }

    /**
     * Clientes para los selectores: `[client_id => fullName]`.
     * Igual que `Client::getList()` en Yii2: sin filtro, ordenado por nombre.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::orderBy('fullName')->pluck('fullName', 'client_id')->all();
    }

    /**
     * Correos a los que se le avisa al cliente (confirmación del booking y
     * factura timbrada). Une `email` con la lista `email_notification`
     * (separada por comas o punto y coma), sin repetidos ni inválidos, como
     * `Client::getNotificationEmails()` en Yii2.
     *
     * @return array<int, string>
     */
    public function notificationEmails(): array
    {
        $raw = $this->email.','.$this->email_notification;

        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[,;]+/', $raw) ?: []),
            fn ($mail) => filter_var($mail, FILTER_VALIDATE_EMAIL) !== false
        )));
    }
}
