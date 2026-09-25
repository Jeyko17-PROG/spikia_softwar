"""
Catalogo GLOSS -> {animation, duration}.

Por que esto NO se le pide a GPT-4o: un LLM puede razonar muy bien la GRAMATICA de LSE (que
glosas usar y en que orden), pero no tiene forma de saber la duracion real en segundos de un
clip de animacion que vive en el Content Browser de Unreal Engine, ni el nombre EXACTO del
asset - eso es un dato de nuestro propio proyecto de Unreal, no algo que un modelo de lenguaje
pueda inventar de forma confiable. Este catalogo es la fuente de verdad para esos dos datos;
GPT-4o solo decide la SECUENCIA de glosas (ver openai_translator.py).

En produccion, este diccionario deberia poblarse desde el mismo lugar donde el equipo de
Unreal registra sus animaciones (un export del Content Browser, una tabla en base de datos,
etc.) en vez de mantenerse a mano aca. Se deja como diccionario en memoria para que el
servicio sea autocontenido y facil de correr/probar sin dependencias extra.
"""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class GlossAsset:
    animation: str
    duration: float


# Vocabulario base de LSE (Lengua de Señas Española) mas frecuente en contexto de
# conferencias/charlas, que es el caso de uso principal de Spikia. Se puede extender sin
# tocar el resto del servicio.
GLOSS_CATALOG: dict[str, GlossAsset] = {
    "HOLA": GlossAsset("Sign_Hola", 0.8),
    "GRACIAS": GlossAsset("Sign_Gracias", 0.9),
    "POR-FAVOR": GlossAsset("Sign_PorFavor", 1.0),
    "SI": GlossAsset("Sign_Si", 0.5),
    "NO": GlossAsset("Sign_No", 0.5),
    "TU": GlossAsset("Sign_Tu", 0.5),
    "YO": GlossAsset("Sign_Yo", 0.5),
    "NOSOTROS": GlossAsset("Sign_Nosotros", 0.7),
    "NOMBRE": GlossAsset("Sign_Nombre", 0.7),
    "QUE": GlossAsset("Sign_Que", 0.6),
    "QUIEN": GlossAsset("Sign_Quien", 0.6),
    "COMO": GlossAsset("Sign_Como", 0.6),
    "CUANDO": GlossAsset("Sign_Cuando", 0.6),
    "DONDE": GlossAsset("Sign_Donde", 0.6),
    "PORQUE": GlossAsset("Sign_Porque", 0.7),
    "BIEN": GlossAsset("Sign_Bien", 0.6),
    "MAL": GlossAsset("Sign_Mal", 0.6),
    "AYUDA": GlossAsset("Sign_Ayuda", 0.8),
    "TRABAJO": GlossAsset("Sign_Trabajo", 0.9),
    "CASA": GlossAsset("Sign_Casa", 0.8),
    "TIEMPO": GlossAsset("Sign_Tiempo", 0.7),
    "HOY": GlossAsset("Sign_Hoy", 0.6),
    "MAÑANA": GlossAsset("Sign_Manana", 0.7),
    "AYER": GlossAsset("Sign_Ayer", 0.7),
    "BUENO": GlossAsset("Sign_Bueno", 0.6),
    "MALO": GlossAsset("Sign_Malo", 0.6),
    "GRANDE": GlossAsset("Sign_Grande", 0.7),
    "PEQUEÑO": GlossAsset("Sign_Pequeno", 0.7),
    "COMENZAR": GlossAsset("Sign_Comenzar", 0.8),
    "TERMINAR": GlossAsset("Sign_Terminar", 0.8),
    "PREGUNTA": GlossAsset("Sign_Pregunta", 0.8),
    "RESPUESTA": GlossAsset("Sign_Respuesta", 0.8),
    "ENTENDER": GlossAsset("Sign_Entender", 0.8),
    "IMPORTANTE": GlossAsset("Sign_Importante", 0.9),
    "BIENVENIDO": GlossAsset("Sign_Bienvenido", 1.0),
    "ADIOS": GlossAsset("Sign_Adios", 0.7),
    # NEUTRAL: pose de "reposo"/transicion entre frases, para que el avatar no quede
    # congelado en la ultima seña mientras espera la proxima secuencia.
    "NEUTRAL": GlossAsset("Sign_Neutral_Idle", 0.5),
}

# Letras del alfabeto dactilologico (deletreo), para palabras sin seña dedicada en el
# catalogo (nombres propios, tecnicismos). Asset por letra: /Game/Animations/Fingerspelling/.
FINGERSPELLING_CATALOG: dict[str, str] = {
    letter: f"Fingerspell_{letter}"
    for letter in "ABCDEFGHIJKLMNÑOPQRSTUVWXYZ"
}
