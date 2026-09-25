"""
Configuracion centralizada del microservicio, leida desde variables de entorno (.env).
Usamos pydantic-settings para que la app falle rapido y con un mensaje claro si falta algo
critico (ej. OPENAI_API_KEY), en vez de fallar recien en el primer request real.
"""

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    # --- OpenAI ---
    openai_api_key: str
    openai_model: str = "gpt-4o"
    # Timeout generoso: GPT-4o con response_format=json_object puede tardar 2-5s en frases
    # largas. Mejor esperar un poco mas que devolverle un timeout al orquestador de Node.
    openai_timeout_seconds: float = 20.0

    # --- Servidor ---
    host: str = "0.0.0.0"
    port: int = 8100

    # --- Seguridad basica entre servicios internos (no expuesto a internet) ---
    # Laravel (ProcessSignGlossesJob) manda este token en el header X-Internal-Token. No es
    # autenticacion de usuario final: es solo para que este endpoint no quede abierto a
    # cualquiera que le pegue en la red interna/VPC.
    internal_service_token: str = ""

    # --- Catalogo de animaciones ---
    # Duracion (segundos) que se usa para cualquier GLOSS que no este en gloss_catalog.py -
    # incluye deletreo dactilologico letra por letra para nombres propios/palabras sin seña
    # dedicada. Es una duracion conservadora (relativamente lenta) para que Unreal Engine
    # nunca corte una animacion de golpe por quedarse corto de tiempo.
    default_gloss_duration_seconds: float = 0.9
    fingerspelling_letter_duration_seconds: float = 0.35


@lru_cache
def get_settings() -> Settings:
    # lru_cache: Settings() parsea el .env UNA sola vez por proceso, no en cada request.
    return Settings()
