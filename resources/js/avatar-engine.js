// Avatar 3D en Lengua de Señas — motor de renderizado (modulo opcional).
//
// Este archivo SOLO se carga cuando el bloque @if en transmision.blade.php (o la vista
// dedicada avatar.blade.php) es verdadero (config('spikia.features.sign_avatar') &&
// $sesion->has_sign_avatar) Y ademas $sesion->avatar_mode === '3d'. Si el modulo esta
// apagado, o la sala usa otro modo de avatar, Vite/Blade nunca inyecta este script y el
// navegador del oyente no gasta ni un byte ni un ciclo en el, por diseño.
//
// Se suscribe al mismo canal que listener.js pero a un evento propio e independiente
// (SignLanguageBroadcast, emitido por ProcessSignGlossesJob) para no interferir jamas con
// el pipeline de traduccion por voz/texto.
//
// Estado actual: carga uno de los personajes de AVATAR_CHARACTERS via GLTFLoader, lo centra
// y encuadra automaticamente de cintura hacia arriba segun su propio bounding box (funciona
// con cualquier modelo que se agregue al registro, no asume una escala fija), y reproduce su
// animacion embebida (mas rapido mientras hay una glosa activa). Ninguno de los dos modelos
// de prueba (ver AVATAR_CHARACTERS) tiene un rig de lengua de senas real: son modelos
// genericos con licencia clara (repo oficial de three.js, MIT) usados como placeholder --
// NO son avatares personalizados. El punto de extension real es reemplazar sus URLs por
// modelos con animaciones por glosa y disparar la animacion correspondiente en
// `playGlossGesture()` en vez de solo acelerar el clip existente.

import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { getEcho } from './echo';

// Registro de personajes disponibles para el modo '3d'. Cada uno declara que clip de
// animacion usar como "movimiento" (el que se acelera mientras hay una glosa activa); si no
// se encuentra por nombre, cae al primer clip que traiga el archivo.
const AVATAR_CHARACTERS = {
    avatar_femenino: {
        url: '/models/avatar-human-femenino.glb',
        animationName: 'SambaDance',
        label: 'Avatar 1 (femenino)',
    },
    avatar_masculino: {
        url: '/models/avatar-human-masculino.glb',
        animationName: 'Run',
        label: 'Avatar 2 (masculino)',
    },
};

const DEFAULT_CHARACTER = 'avatar_femenino';

// Fraccion de la altura total del modelo, medida desde los pies, que debe quedar FUERA del
// encuadre (todo lo que este por debajo de esta linea no se ve). Mas bajo que "0.5 exacto"
// a proposito: los modelos de prueba traen animaciones con bastante vaiven de brazos, y este
// margen extra evita que las manos se corten arriba/abajo del cuadro durante el movimiento.
// 0.42 = un poco mas abajo de la cintura real, para dar aire. Se conserva igual sin importar
// que personaje este cargado, para que cambiar de avatar no salte de encuadre.
const WAIST_LINE_RATIO = 0.42;

function frameWaistUp(camera, object) {
    const box = new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());

    if (size.y <= 0) {
        return;
    }

    // Centrar el modelo en X/Z y apoyar los pies en y=0, sea cual sea su escala/origen original.
    object.position.x -= center.x;
    object.position.z -= center.z;
    object.position.y -= box.min.y;

    const topY = size.y;
    const waistY = size.y * WAIST_LINE_RATIO;
    const frameHeight = topY - waistY;
    const targetY = waistY + frameHeight / 2;

    const fovRadians = THREE.MathUtils.degToRad(camera.fov);
    const margin = 1.35; // aire extra para que la cabeza/manos no queden pegadas al borde
    const distance = (frameHeight * margin) / (2 * Math.tan(fovRadians / 2));

    camera.position.set(0, targetY, Math.max(distance, 0.4));
    camera.lookAt(0, targetY, 0);
}

function disposeModel(model) {
    model.traverse((node) => {
        if (node.isMesh) {
            node.geometry?.dispose();
            const materials = Array.isArray(node.material) ? node.material : [node.material];
            materials.forEach((material) => {
                if (!material) {
                    return;
                }
                Object.values(material).forEach((value) => {
                    if (value && value.isTexture) {
                        value.dispose();
                    }
                });
                material.dispose();
            });
        }
    });
}

function init() {
    const config = window.__SPIKIA_LISTENER__;
    const canvas = document.getElementById('avatar-canvas');
    const stage = canvas ? canvas.parentElement : null;
    const captionEl = document.getElementById('avatar-caption');

    if (!config || !config.slug || !canvas || !stage) {
        return;
    }

    const scene = new THREE.Scene();
    scene.background = null; // Fondo transparente: se ve el fondo oscuro de la interfaz detras.

    const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 100);
    camera.position.set(0, 1.4, 1.8);

    const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;

    // Luz ambiental suave: rellena las sombras para que no queden zonas negras puras.
    scene.add(new THREE.AmbientLight(0xffffff, 0.6));

    // Key light: la luz principal, inclinada, la que le da volumen y profundidad al personaje.
    const keyLight = new THREE.DirectionalLight(0xffffff, 2.2);
    keyLight.position.set(1.6, 2.6, 2.2);
    keyLight.castShadow = true;
    keyLight.shadow.mapSize.set(1024, 1024);
    keyLight.shadow.bias = -0.002;
    scene.add(keyLight);

    // Fill light: mas tenue y del lado opuesto, para que el lado sin key light no quede plano.
    const fillLight = new THREE.DirectionalLight(0xbfd4ff, 0.5);
    fillLight.position.set(-1.8, 1.1, 1.4);
    scene.add(fillLight);

    let mixer = null;
    let activeModel = null;
    let currentCharacterId = null;
    let loadToken = 0;

    function loadCharacter(characterId) {
        const character = AVATAR_CHARACTERS[characterId];
        if (!character) {
            console.warn('avatar-engine: personaje desconocido "' + characterId + '"');
            return;
        }

        // Token de carga: si el usuario cambia de personaje varias veces seguidas y una
        // carga anterior todavia no terminaba, esto evita que un GLTFLoader viejo pise al
        // que cargo despues (condicion de carrera al cambiar rapido).
        const myToken = ++loadToken;

        const loader = new GLTFLoader();
        loader.load(
            character.url,
            (gltf) => {
                if (myToken !== loadToken) {
                    disposeModel(gltf.scene);
                    return;
                }

                if (activeModel) {
                    scene.remove(activeModel);
                    disposeModel(activeModel);
                }
                if (mixer) {
                    mixer.stopAllAction();
                }

                const model = gltf.scene;
                model.traverse((node) => {
                    if (node.isMesh) {
                        node.castShadow = true;
                        node.receiveShadow = true;
                    }
                });

                // Misma logica de encuadre/anclaje para cualquier personaje: al cambiar de
                // avatar la camara no salta.
                frameWaistUp(camera, model);
                scene.add(model);
                activeModel = model;
                currentCharacterId = characterId;

                mixer = null;
                if (gltf.animations && gltf.animations.length > 0) {
                    mixer = new THREE.AnimationMixer(model);
                    const clip = THREE.AnimationClip.findByName(gltf.animations, character.animationName)
                        || gltf.animations[0];
                    mixer.clipAction(clip).play();
                }
            },
            undefined,
            (error) => {
                // Si el modelo no esta disponible (todavia no se coloco el archivo, o la ruta
                // esta mal) el avatar se queda vacio en vez de romper la pagina: es un modulo
                // opcional, un modelo faltante no puede tumbar la vista.
                console.warn('avatar-engine: no se pudo cargar el personaje "' + characterId + '" (' + character.url + '):', error);
            }
        );
    }

    function switchAvatar(characterId) {
        if (characterId === currentCharacterId) {
            return;
        }
        loadCharacter(characterId);
    }

    function resizeToContainer() {
        const width = Math.max(1, stage.clientWidth);
        const height = Math.max(1, stage.clientHeight);

        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    }

    const resizeObserver = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(resizeToContainer) : null;
    if (resizeObserver) {
        resizeObserver.observe(stage);
    } else {
        window.addEventListener('resize', resizeToContainer);
    }

    const glossQueue = [];
    let gesturing = false;
    let currentGloss = '';

    function updateCaption() {
        if (!captionEl) {
            return;
        }
        captionEl.textContent = currentGloss;
    }

    function playNextGloss() {
        if (glossQueue.length === 0) {
            currentGloss = '';
            gesturing = false;
            updateCaption();
            return;
        }

        currentGloss = glossQueue.shift();
        gesturing = true;
        updateCaption();
        window.setTimeout(playNextGloss, 700);
    }

    function handleSignBroadcast(payload) {
        const glosses = Array.isArray(payload?.glosses) ? payload.glosses : [];
        if (glosses.length === 0) {
            return;
        }

        const wasEmpty = glossQueue.length === 0 && currentGloss === '';
        glossQueue.push(...glosses);

        if (wasEmpty) {
            playNextGloss();
        }
    }

    function subscribeEcho() {
        const echo = getEcho();
        if (!echo) {
            // Sin Echo/Pusher configurado el avatar simplemente se queda inactivo: no hay
            // fallback de polling a proposito, para no sumar carga extra al servidor por un
            // modulo que ya de por si es opcional.
            return;
        }

        try {
            echo.channel(`transmision.${config.slug}`)
                .listen('.SignLanguageBroadcast', (payload) => handleSignBroadcast(payload));
        } catch (error) {
            console.warn('avatar-engine: no se pudo suscribir a Echo:', error);
        }
    }

    const clock = new THREE.Clock();

    function animate() {
        requestAnimationFrame(animate);

        const delta = clock.getDelta();
        if (mixer) {
            // Mientras hay una glosa activa el clip corre a velocidad normal ("esta senando");
            // en reposo corre bien despacio, casi congelado, para no distraer.
            mixer.timeScale = gesturing ? 1 : 0.15;
            mixer.update(delta);
        }

        if (activeModel) {
            // Varios de los clips de prueba tienen desplazamiento de raiz (caminar, correr,
            // bailar): esto "clava" al personaje en su sitio para que nunca se salga del
            // encuadre de cintura hacia arriba. frameWaistUp() ya lo centro en x=0/z=0 al
            // cargar cada personaje.
            activeModel.position.x = 0;
            activeModel.position.z = 0;
        }

        renderer.render(scene, camera);
    }

    resizeToContainer();
    updateCaption();
    subscribeEcho();

    const initialCharacter = AVATAR_CHARACTERS[config.avatarCharacter] ? config.avatarCharacter : DEFAULT_CHARACTER;
    loadCharacter(initialCharacter);
    requestAnimationFrame(animate);

    // Expuesto para que los botones del selector de personaje (ver avatar.blade.php /
    // transmision.blade.php) puedan cambiar de avatar sin recargar la pagina.
    window.SpikiaAvatarEngine = {
        switchAvatar,
        characters: AVATAR_CHARACTERS,
        getCurrentCharacter: () => currentCharacterId,
    };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
