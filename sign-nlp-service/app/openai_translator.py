"""
Traductor Español -> secuencia de GLOSAS de LSE, usando GPT-4o con salida JSON estricta.

Responsabilidad de GPT-4o (y SOLO esto): decidir que glosas usar y en que ORDEN gramatical
de LSE (que es distinto al orden del español - ej. LSE tiende a topico-comentario y ubica
temporales/interrogativos en posiciones especificas). NO le pedimos duraciones ni nombres de
asset: eso lo resuelve gloss_catalog.py de forma deterministica (ver ese archivo para el
porque).
"""

from __future__ import annotations

import json
import logging

from openai import AsyncOpenAI, APIError, APITimeoutError

from .config import Settings
from .gloss_catalog import FINGERSPELLING_CATALOG, GLOSS_CATALOG

logger = logging.getLogger("sign_nlp_service")

# System prompt estricto: le exigimos a GPT-4o que SOLO devuelva glosas del vocabulario que
# le pasamos (para que despues gloss_catalog.py siempre pueda resolver animacion+duracion), y
# que cualquier palabra sin seña dedicada la marque para deletreo dactilologico en vez de
# inventar una glosa que no existe en nuestro catalogo.
SYSTEM_PROMPT_TEMPLATE = """Eres un traductor experto de español a Lengua de Señas Española (LSE), \
especializado en la estructura gramatical de glosas.

REGLAS ESTRICTAS:
1. Traduce el texto en español a una secuencia ORDENADA de GLOSAS en MAYUSCULAS, siguiendo \
la gramática real de LSE (topico-comentario, marcadores temporales al inicio, ausencia de \
articulos/preposiciones que no se señan, etc.) - NO una traduccion palabra por palabra.
2. SOLO podés usar glosas de esta lista (vocabulario disponible con seña propia):
{vocabulario}
3. Si una palabra del texto original NO tiene equivalente en la lista (ej. un nombre propio, \
un tecnicismo), marcala para DELETREO usando el formato especial: cada letra como una glosa \
separada con el prefijo "FS:" (ejemplo, para "Ana" generarías "FS:A", "FS:N", "FS:A").
4. Nunca inventes una glosa que no esté en la lista ni en el formato FS: de deletreo.
5. Si el texto no tiene contenido traducible (saludo vacío, ruido, etc.), devolvé una \
secuencia vacía.

Respondé SIEMPRE en JSON valido con esta forma exacta, sin texto adicional:
{{"glosses": ["GLOSA1", "GLOSA2", "FS:A", "FS:N", "FS:A", ...]}}
"""


def _build_system_prompt() -> str:
    vocabulario = ", ".join(sorted(GLOSS_CATALOG.keys()))
    return SYSTEM_PROMPT_TEMPLATE.format(vocabulario=vocabulario)


class GlossTranslationError(RuntimeError):
    """Se lanza cuando OpenAI falla o devuelve algo que no pudimos interpretar como glosas."""


class OpenAiGlossTranslator:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._client = AsyncOpenAI(api_key=settings.openai_api_key, timeout=settings.openai_timeout_seconds)
        self._system_prompt = _build_system_prompt()

    async def translate_to_glosses(self, text: str) -> list[str]:
        """
        Devuelve la lista ORDENADA de tokens de glosa en bruto (ej. ["HOLA", "TU", "FS:A"]).
        El llamador (main.py) es quien la enriquece con animacion/duracion via el catalogo.
        """
        try:
            completion = await self._client.chat.completions.create(
                model=self._settings.openai_model,
                temperature=0,  # determinismo: la misma frase siempre da la misma secuencia
                response_format={"type": "json_object"},
                messages=[
                    {"role": "system", "content": self._system_prompt},
                    {"role": "user", "content": text},
                ],
            )
        except APITimeoutError as exc:
            raise GlossTranslationError("OpenAI no respondio a tiempo.") from exc
        except APIError as exc:
            raise GlossTranslationError(f"Error de OpenAI: {exc}") from exc

        raw_content = completion.choices[0].message.content or "{}"

        try:
            payload = json.loads(raw_content)
        except json.JSONDecodeError as exc:
            logger.error("GPT-4o devolvio JSON invalido: %s", raw_content)
            raise GlossTranslationError("La respuesta de OpenAI no es JSON valido.") from exc

        glosses = payload.get("glosses")
        if not isinstance(glosses, list):
            raise GlossTranslationError('La respuesta de OpenAI no tiene la clave "glosses" esperada.')

        # Filtramos cualquier token que GPT-4o haya alucinado fuera del vocabulario permitido
        # (no deberia pasar con el prompt estricto de arriba, pero no confiamos ciegamente en
        # la salida de un LLM para algo que despues dispara una animacion en Unreal Engine).
        valid_tokens: list[str] = []
        for token in glosses:
            token_str = str(token).strip().upper()
            if not token_str:
                continue
            if token_str.startswith("FS:"):
                letter = token_str[3:]
                if letter in FINGERSPELLING_CATALOG:
                    valid_tokens.append(token_str)
                else:
                    logger.warning("Letra de deletreo desconocida, se ignora: %s", token_str)
                continue
            if token_str in GLOSS_CATALOG:
                valid_tokens.append(token_str)
            else:
                logger.warning("GPT-4o devolvio una glosa fuera del catalogo, se ignora: %s", token_str)

        return valid_tokens
