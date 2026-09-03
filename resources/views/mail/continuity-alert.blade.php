{{--
    Aviso de tarea pendiente de un booking. Copia de `notificationLv1Mail.php`
    de Yii2; el nivel 2 usaba una plantilla idéntica y solo cambiaba el asunto.
--}}
<div class="container">
    <h3>{{ $taskLabel }} task for booking {{ $bookingNumber }} has not completed yet</h3>
    <p>Estimaded date:<strong>{{ \Illuminate\Support\Carbon::parse($date)->format('d-m-Y H:i') }}</strong></p>
    <p>Please login into the system and complete this task</p>
    <a href="{{ config('app.url') }}">{{ \App\Support\Marca::nombre() }}</a>
</div>
