"""
Microservicio NLP de traduccion Español -> Glosas de LSE.

Punto de entrada del pipeline avatar de lengua de señas de Spikia: recibe texto ya
transcrito/traducido (lo llama App\\Jobs\\ProcessSignGlossesJob del lado de Laravel) y
devuelve una secuencia lista para que el orquestador de Node.js la encole y se la mande, seña
por seña, al plugin de C++ que corre dentro de Unreal Engine 5.

Correr en desarrollo:
    uvicorn app.main:app --reload --port 8100

Variables de entorno: ver .env.example en la raiz de este servicio.
"""

from __future__ import annotations

import logging

from fastapi import Depends, FastAPI, Header, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware

from .config import Settings, get_settings
from .gloss_catalog import FINGERSPELLING_CATALOG, GLOSS_CATALOG
from .openai_translator import GlossTranslationError, OpenAiGlossTranslator
from .schemas import GlossFrame, HealthResponse, TranslateRequest, TranslateResponse

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("sign_nlp_service")

app = FastAPI(
    title="Spikia Sign NLP Service",
    description="Traduce texto en español a una secuencia de glosas de Lengua de Señas Española (LSE) lista para animar en Unreal Engine 5.",
    version="1.0.0",
)

# CORS abierto a proposito: este endpoint lo consume el JOB de Laravel (server-to-server, sin
# navegador de por medio) y, en desarrollo, herramientas como curl/Postman. Si en produccion
# se expone a un dominio publico, restringir "allow_origins" a la lista real de origenes.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)

# Un solo cliente OpenAI reutilizado para todo el proceso (evita reabrir conexiones HTTP en
# cada request). FastAPI crea la app una vez por proceso, asi que esto es seguro.
_translator: OpenAiGlossTranslator | None = None


def get_translator(settings: Settings = Depends(get_settings)) -> OpenAiGlossTranslator:
    global _translator
    if _translator is None:
        _translator = OpenAiGlossTranslator(settings)
    return _translator


def verify_internal_token(
    x_internal_token: str = Header(default=""),
    settings: Settings = Depends(get_settings),
) -> None:
    """
    Si SIGN_INTERNAL_SERVICE_TOKEN esta configurado, lo exigimos en el header
    X-Internal-Token. Si se deja vacio (default en desarrollo local), no se exige nada - asi
    el servicio funciona out-of-the-box en dev sin configuracion extra.
    """
    if settings.internal_service_token and x_internal_token != settings.internal_service_token:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Token interno invalido.")


def _resolve_gloss_frame(token: str, settings: Settings) -> GlossFrame:
    if token.startswith("FS:"):
        letter = token[3:]
        animation = FINGERSPELLING_CATALOG.get(letter, "Fingerspell_Unknown")
        return GlossFrame(
            gloss=letter,
            animation=animation,
            duration=settings.fingerspelling_letter_duration_seconds,
            is_fingerspelling=True,
        )

    asset = GLOSS_CATALOG.get(token)
    if asset is None:
        # No deberia pasar (openai_translator.py ya filtra contra el catalogo), pero si pasa
        # preferimos degradar con una duracion default antes que romper toda la secuencia.
        logger.warning("Glosa sin entrada en el catalogo al resolver frame: %s", token)
        return GlossFrame(gloss=token, animation="Sign_Unknown", duration=settings.default_gloss_duration_seconds)

    return GlossFrame(gloss=token, animation=asset.animation, duration=asset.duration)


@app.get("/health", response_model=HealthResponse)
def health(settings: Settings = Depends(get_settings)) -> HealthResponse:
    return HealthResponse(openai_model=settings.openai_model)


@app.post("/translate-to-sign", response_model=TranslateResponse)
async def translate_to_sign(
    payload: TranslateRequest,
    settings: Settings = Depends(get_settings),
    translator: OpenAiGlossTranslator = Depends(get_translator),
    _: None = Depends(verify_internal_token),
) -> TranslateResponse:
    text = payload.text.strip()
    if not text:
        return TranslateResponse(session_id=payload.session_id, original_text=text, sequence=[], total_duration=0.0)

    try:
        raw_tokens = await translator.translate_to_glosses(text)
    except GlossTranslationError as exc:
        logger.error("Fallo traduciendo a glosas: %s", exc)
        raise HTTPException(status_code=status.HTTP_502_BAD_GATEWAY, detail=str(exc)) from exc

    sequence = [_resolve_gloss_frame(token, settings) for token in raw_tokens]
    total_duration = round(sum(frame.duration for frame in sequence), 3)

    return TranslateResponse(
        session_id=payload.session_id,
        original_text=text,
        sequence=sequence,
        total_duration=total_duration,
    )
