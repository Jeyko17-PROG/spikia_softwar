const root = document.getElementById('glossaryRoot');

if (root) {
    let templates = {};

    try {
        templates = JSON.parse(root.dataset.templates || '{}');
    } catch (error) {
        templates = {};
    }

    const form = document.getElementById('glosarioForm');
    const formTitle = document.getElementById('formTitle');
    const submitBtn = document.getElementById('submitBtn');
    const formMethod = document.getElementById('formMethod');
    const tema = document.getElementById('tema');
    const titulo = document.getElementById('titulo');
    const terminos = document.getElementById('terminos');
    const idioma = document.getElementById('idioma');
    const storeUrl = root.dataset.storeUrl || '/glosarios/guardar';
    const updateUrlTemplate = root.dataset.updateUrlTemplate || '/glosarios/__ID__';

    // Antes "+ Nuevo glosario" en realidad precargaba la plantilla de medicina (no abria
    // un formulario vacio), y el mismo panel se usaba para crear Y editar sin avisar antes
    // de pisar texto sin guardar. "dirty" rastrea si el usuario escribio algo a mano (no
    // cuenta el llenado programatico de resetForm()/editGlosario()) para poder confirmar
    // antes de perderlo.
    let dirty = false;
    const markDirty = () => { dirty = true; };
    titulo?.addEventListener('input', markDirty);
    terminos?.addEventListener('input', markDirty);
    idioma?.addEventListener('change', markDirty);

    const confirmDiscardIfDirty = () => {
        if (!dirty) return true;
        return confirm('Tenés cambios sin guardar en este glosario. ¿Querés descartarlos?');
    };

    let lastAppliedTema = 'personalizado';

    const fillTemplate = (templateKey) => {
        const template = templates[templateKey] || templates.personalizado || {};

        if (titulo) titulo.value = template.titulo || '';
        if (terminos) terminos.value = template.terminos || '';
        if (idioma) idioma.value = template.idioma || 'es';
        if (tema) tema.value = templateKey;
        lastAppliedTema = templateKey;
        dirty = false;
    };

    const resetForm = (templateKey = 'personalizado') => {
        if (formTitle) formTitle.innerText = templateKey === 'personalizado' ? 'Nuevo glosario' : 'Nuevo glosario desde plantilla';
        if (submitBtn) submitBtn.innerText = 'Guardar glosario';
        if (form) form.action = storeUrl;
        if (formMethod) formMethod.value = 'POST';

        if (form) form.reset();
        fillTemplate(templateKey);
    };

    const editGlosario = (button) => {
        if (!button || !form || !formTitle || !submitBtn || !formMethod || !titulo || !terminos || !idioma || !tema) {
            return;
        }

        formTitle.innerText = 'Editar glosario';
        submitBtn.innerText = 'Actualizar glosario';
        form.action = updateUrlTemplate.replace('__ID__', button.dataset.id || '');
        formMethod.value = 'PUT';
        titulo.value = button.dataset.titulo || '';
        terminos.value = button.dataset.terminos || '';
        idioma.value = button.dataset.idioma || 'es';
        tema.value = 'personalizado';
        lastAppliedTema = 'personalizado';
        dirty = false;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    // El boton "+ Nuevo glosario" (data-new-glosario) siempre abre un formulario en blanco;
    // los botones de la grilla de plantillas (data-template-trigger) precargan un tema, y
    // viven aparte, marcados como punto de partida opcional, no como la accion principal.
    root.querySelectorAll('[data-new-glosario]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!confirmDiscardIfDirty()) return;
            resetForm('personalizado');
        });
    });

    root.querySelectorAll('[data-template-trigger]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!confirmDiscardIfDirty()) return;
            const templateKey = button.dataset.templateTrigger || 'personalizado';
            resetForm(templateKey);
        });
    });

    root.querySelectorAll('[data-edit-glosary]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!confirmDiscardIfDirty()) return;
            editGlosario(button);
        });
    });

    if (tema) {
        tema.addEventListener('change', () => {
            if (tema.value === 'personalizado') return;

            // El <select> "Tema preconfigurado" es una segunda forma de disparar la misma
            // accion que los botones de plantilla (data-template-trigger) - necesitaba el
            // mismo chequeo de "dirty", si no se saltaba la confirmacion y pisaba texto sin
            // guardar en silencio. Si cancela, hay que devolver el <select> a su valor previo
            // porque el cambio nativo ya ocurrio antes de que corra este handler.
            if (!confirmDiscardIfDirty()) {
                tema.value = lastAppliedTema;
                return;
            }

            fillTemplate(tema.value);
        });
    }

    resetForm('personalizado');
}
