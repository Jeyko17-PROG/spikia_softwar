<x-mail::message>
# Hola {{ $name }}

Tu cuenta de **Spikia** necesita verificacion.

<x-mail::panel>
{{ $code }}
</x-mail::panel>

Este codigo de 6 digitos expira en {{ $expiresInMinutes }} minutos.

Si no solicitaste este codigo, puedes ignorar este mensaje.

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
