<?php

namespace App\Services;

/**
 * Genera access tokens de LiveKit a mano (JWT HS256 firmado con el API secret), sin agregar
 * el SDK oficial de PHP como dependencia de Composer - el formato es simple y estable, y esto
 * evita instalar un paquete entero solo para firmar un JWT. Ver:
 * https://docs.livekit.io/home/get-started/authentication/
 */
class LiveKitTokenService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {}

    public function enabled(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /**
     * @param  string  $identity  Identificador unico del participante dentro de la sala.
     * @param  string  $room  Nombre de la sala (usamos el slug de la sesion).
     * @param  bool  $canPublish  true para el interprete, false para los oyentes.
     * @param  int  $ttlSeconds  Vigencia del token.
     */
    public function createToken(string $identity, string $room, bool $canPublish, int $ttlSeconds = 21600): string
    {
        $now = time();

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $this->apiKey,
            'sub' => $identity,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'name' => $identity,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $canPublish,
                'canPublishData' => $canPublish,
                'canSubscribe' => true,
            ],
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $this->apiSecret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
