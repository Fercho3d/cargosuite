<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;

/**
 * Base de los modelos mapeados sobre el esquema heredado de Yii2.
 *
 * El esquema heredado no sigue las convenciones de Laravel: las llaves primarias
 * llevan el nombre de la tabla (`transc_id`, `booking_id`, …) y la marca de
 * actualización se llama `modified_at`. Aquí se centraliza esa traducción para
 * no repetirla en cada modelo.
 */
abstract class CoreModel extends Model
{
    const UPDATED_AT = 'modified_at';

    protected $guarded = [];
}
