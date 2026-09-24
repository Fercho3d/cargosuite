<?php

namespace Tests\Feature;

use App\Mail\FueraDeRutaMail;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `MAIL_SIEMPRE_A`: en una instalación de demostración todo correo va a un solo
 * buzón, se haya dirigido a quien se haya dirigido. Así ni los clientes
 * inventados de la demo ni nadie de fuera recibe nada por accidente.
 */
class CorreoDestinoUnicoTest extends TestCase
{
    /** @return list<string> */
    private function destinatariosAlMandarA(string $a): array
    {
        $this->app->getProvider(AppServiceProvider::class)->boot();
        Mail::to($a)->send(new FueraDeRutaMail('T-101', 'VJ-01044', 12.0, 25.6, -100.3));

        $mensaje = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        return array_map(fn ($d) => $d->getAddress(), $mensaje->getTo());
    }

    public function test_con_destino_unico_todo_va_a_ese_buzon(): void
    {
        config(['marca.correo.siempre_a' => 'contacto@juancker.com']);

        $this->assertSame(['contacto@juancker.com'], $this->destinatariosAlMandarA('alguien.de.fuera@cliente.test'));
    }

    public function test_sin_destino_unico_el_correo_va_a_quien_se_dirigio(): void
    {
        config(['marca.correo.siempre_a' => '']);

        $this->assertSame(['trafico@cliente.test'], $this->destinatariosAlMandarA('trafico@cliente.test'));
    }
}
