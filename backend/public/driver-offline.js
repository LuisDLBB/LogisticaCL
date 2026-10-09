(() => {
    const banner = document.getElementById('offline-banner');
    if (!banner || !('indexedDB' in window)) return;

    const open = () => new Promise((resolve, reject) => {
        const request = indexedDB.open('4n-driver-route-queue', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('requests', {keyPath: 'id', autoIncrement: true});
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
    const transaction = async (mode, action) => {
        const database = await open();
        return new Promise((resolve, reject) => {
            const work = database.transaction('requests', mode);
            const request = action(work.objectStore('requests'));
            let result;
            request.onsuccess = () => { result = request.result; };
            work.oncomplete = () => { database.close(); resolve(result); };
            work.onerror = () => { database.close(); reject(work.error || request.error); };
            work.onabort = () => { database.close(); reject(work.error || request.error); };
        });
    };
    const all = () => transaction('readonly', store => store.getAll());
    const add = value => transaction('readwrite', store => store.add(value));
    const update = value => transaction('readwrite', store => store.put(value));
    const remove = id => transaction('readwrite', store => store.delete(id));
    let syncing = false;

    const status = async (message = '') => {
        const queued = await all();
        const count = queued.length;
        const failed = queued.find(item => item.error);
        document.querySelectorAll('.offline-only').forEach(element => { element.hidden = navigator.onLine && count === 0; });
        banner.classList.toggle('show', !navigator.onLine || count > 0 || !!message);
        banner.textContent = message || (failed ? `Un registro pendiente no pudo enviarse: ${failed.error}` : !navigator.onLine ? `Sin señal · ${count} registro${count === 1 ? '' : 's'} pendiente${count === 1 ? '' : 's'} de enviar.` : `${count} registro${count === 1 ? '' : 's'} pendiente${count === 1 ? '' : 's'} de confirmar.`);
        if (failed) {
            const discard = document.createElement('button');
            discard.className = 'secondary'; discard.style.marginLeft = '10px'; discard.textContent = 'Descartar intento fallido';
            discard.addEventListener('click', async () => { await remove(failed.id); await status(); await flush(); });
            banner.appendChild(discard);
        } else if (count > 0 && navigator.onLine && !message) {
            const retry = document.createElement('button');
            retry.className = 'secondary'; retry.style.marginLeft = '10px'; retry.textContent = 'Sincronizar';
            retry.addEventListener('click', flush); banner.appendChild(retry);
        }
    };

    async function flush() {
        if (syncing || !navigator.onLine) return;
        syncing = true;
        try {
            const queued = await all();
            for (const item of queued) {
                if (item.error) { await status(); return; }
                const body = new FormData();
                for (const [name, value] of item.entries) body.append(name, value);
                let response;
                try {
                    response = await fetch(item.action, {
                        method: 'POST', body, credentials: 'same-origin', redirect: 'follow',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}
                    });
                } catch (_) {
                    await status('Sin conexión. Los registros siguen guardados en este teléfono.');
                    return;
                }
                if (!response.ok) {
                    if (response.status === 422) {
                        let detail = '';
                        try { const error = await response.json(); detail = Object.values(error.errors || {}).flat().join(' '); } catch (_) {}
                        item.error = `Los datos no pudieron validarse.${detail ? ` ${detail}` : ''} Descarta este intento para ingresarlo de nuevo.`;
                        await update(item);
                        await status();
                    } else if (response.status === 404) {
                        item.error = 'El recorrido ya no existe. Descarta este intento de prueba.';
                        await update(item);
                        await status();
                    } else {
                        await status(`No se pudo confirmar un registro (${response.status}). El registro sigue guardado; revisa tu sesión o vuelve a sincronizar más tarde.`);
                    }
                    return;
                }
                await remove(item.id);
            }
            if (queued.length) window.location.reload();
            else await status();
        } finally { syncing = false; }
    }

    document.querySelectorAll('form[data-offline]').forEach(form => form.addEventListener('submit', async event => {
        if (!form.checkValidity()) return;
        event.preventDefault();
        const latitude = form.querySelector('[name="latitude"]');
        const longitude = form.querySelector('[name="longitude"]');
        if ((latitude && !latitude.value) || (longitude && !longitude.value)) {
            const manual = form.querySelector('[name="location_source"]')?.value === 'manual';
            await status(manual
                ? 'Falta ingresar la latitud o longitud manual de esta prueba.'
                : window.isSecureContext
                    ? 'Falta capturar la ubicación. Pulsa «Capturar ubicación» y espera la confirmación antes de guardar.'
                    : 'Esta dirección HTTP de la red local no permite capturar GPS, aunque el permiso esté activado.');
            window.scrollTo({top: 0, behavior: 'smooth'});
            return;
        }
        if (form.action.endsWith('/completar') && !form.querySelector('[name="signed_guide_photo"]').files.length && !form.querySelector('[name="signature_data"]').value) {
            alert('Adjunta la guía firmada o recoge la firma en pantalla antes de continuar.'); return;
        }
        if (form.action.endsWith('/completar') && Number(form.querySelector('[name="return_count"]').value) > 0 && !form.querySelector('[name="return_photo"]').files.length) {
            alert('Fotografía las devoluciones recogidas antes de continuar.'); return;
        }
        if (form.action.endsWith('/iniciar') && !form.querySelector('[name="vehicle_no_observations"]').checked && !form.querySelector('[name="vehicle_observation"]').value.trim()) {
            alert('Describe el estado del vehículo o marca «Sin observaciones».'); return;
        }
        if (form.action.endsWith('/iniciar') && form.querySelector('[name="vehicle_photos[]"]').files.length > 5) {
            alert('Puedes adjuntar un máximo de 5 fotos del vehículo.'); return;
        }
        const button = form.querySelector('button[type="submit"], button:not([type])');
        if (button) button.disabled = true;
        const body = new FormData(form);
        body.set('occurred_at', new Date().toISOString());
        body.set('request_key', globalThis.crypto?.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
            const value = Math.floor(Math.random() * 16);
            return (character === 'x' ? value : (value & 3) | 8).toString(16);
        }));
        try {
            await add({action: form.action, entries: [...body.entries()]});
            await status();
            await flush();
            if (!navigator.onLine) form.reset();
        } catch (_) {
            await status('No se pudo guardar el registro en este teléfono. Conserva esta pantalla abierta y vuelve a intentarlo.');
        } finally { if (button) button.disabled = false; }
    }));
    window.addEventListener('online', flush);
    window.addEventListener('offline', () => status());
    status().then(flush);
})();
