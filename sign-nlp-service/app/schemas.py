"""
Modelos Pydantic de entrada/salida del endpoint /translate-to-sign. Son el "contrato" que
consume la capa de animacion (el orquestador Node.js y, en definitiva, el plugin de C++ en
Unreal Engine) - cualquier cambio aca es un cambio de contrato entre los 3 servicios.
"""

from __future__ import annotations

from pydantic import BaseModel, Field


class TranslateRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=2000, description="Texto en español a traducir a glosas de LSE.")
    # Id de sesion de Spikia (slug): el orquestador de Node lo usa para saber a que instancia
    # de Unreal Engine (conexion WS) hay que enrutar la secuencia resultante. Opcional aca
    # porque este microservicio tambien tiene que poder probarse/usarse suelto, sin sesion.
    session_id: str | None = Field(default=None, max_length=120)


class GlossFrame(BaseModel):
    """Una sola seña dentro de la secuencia, lista para reproducir en Unreal Engine."""

    # Nombre de la glosa en mayusculas (convencion LSE): "HOLA", "TU", "NOMBRE", "QUE".
    gloss: str
    # Nombre del asset de animacion en el Content Browser de Unreal (sin extension), que el
    # plugin de C++ resuelve por convencion de nombres: /Game/Animations/Signs/<animation>.
    # Ver gloss_catalog.py para el mapeo real GLOSS -> asset.
    animation: str
    # Duracion estimada en segundos - el orquestador de Node la usa para saber cuanto esperar
    # antes de mandar la SIGUIENTE seña de la cola (pacing), sin necesitar que Unreal le
    # confirme "termine" de vuelta (mas simple y mas robusto a que se pierda un mensaje).
    duration: float
    # true si esta glosa es deletreo letra por letra (dactilologico) - util para que el
    # AnimInstance en Unreal pueda, si quiere, aplicar una transicion/blend distinta.
    is_fingerspelling: bool = False


class TranslateResponse(BaseModel):
    session_id: str | None = None
    original_text: str
    # Secuencia ORDENADA - el orden importa, es la gramatica de LSE ya reestructurada
    # (distinta al orden gramatical del español), no una traduccion palabra por palabra.
    sequence: list[GlossFrame]
    # Duracion total estimada de toda la secuencia (suma de cada frame). Le sirve al frontend
    # de Spikia para, por ejemplo, mostrar un indicador de progreso del avatar en pantalla.
    total_duration: float


class HealthResponse(BaseModel):
    status: str = "ok"
    openai_model: str
