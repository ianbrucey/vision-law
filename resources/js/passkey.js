/**
 * passkey.js — spec 008 (008-D05): the two WebAuthn browser ceremonies.
 *
 * Our own thin layer over navigator.credentials — options JSON comes from
 * the server flat (Laragear's shape), is wrapped in { publicKey }, binary
 * fields are base64url-decoded before the call and re-encoded after.
 * Errors are always the generic sentences from 05-ui.md: the browser
 * never learns (or shows) why a ceremony failed.
 *
 * Config arrives as window.__passkeyConfig from the rendering view
 * (the document-upload.js pattern): { csrf, registerOptionsUrl,
 * registerUrl, assertOptionsUrl, assertUrl } — each view sets only the
 * URLs its page uses, and each flow below wires itself only when its
 * button exists.
 */
(() => {
    'use strict';

    const cfg = window.__passkeyConfig || {};

    const b64urlToBuffer = (value) => {
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
    };

    const bufferToB64url = (buffer) => {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        bytes.forEach((b) => { binary += String.fromCharCode(b); });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };

    const postJson = async (url, body) => fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': cfg.csrf || '',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const showError = (id, message) => {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = message;
            el.hidden = false;
        }
    };

    // ── Registration (enrollment page) ────────────────────────────────
    const addButton = document.getElementById('passkey-add');

    if (addButton && cfg.registerOptionsUrl && cfg.registerUrl) {
        if (!window.PublicKeyCredential) {
            addButton.disabled = true;
            const note = document.getElementById('passkey-unsupported');
            if (note) note.hidden = false;
        } else {
            addButton.addEventListener('click', async () => {
                addButton.disabled = true;
                try {
                    const optionsResponse = await postJson(cfg.registerOptionsUrl);
                    if (!optionsResponse.ok) throw new Error('options');
                    const options = await optionsResponse.json();

                    options.challenge = b64urlToBuffer(options.challenge);
                    if (options.user && options.user.id) {
                        options.user.id = b64urlToBuffer(options.user.id);
                    }
                    (options.excludeCredentials || []).forEach((cred) => {
                        cred.id = b64urlToBuffer(cred.id);
                    });

                    const credential = await navigator.credentials.create({ publicKey: options });
                    if (!credential) throw new Error('cancelled');

                    const aliasInput = document.querySelector('[name="passkey_alias"]');
                    const response = await postJson(cfg.registerUrl, {
                        id: credential.id,
                        rawId: bufferToB64url(credential.rawId),
                        type: credential.type,
                        response: {
                            clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
                            attestationObject: bufferToB64url(credential.response.attestationObject),
                        },
                        alias: aliasInput ? aliasInput.value : '',
                    });
                    if (!response.ok) throw new Error('store');

                    const data = await response.json();
                    window.location.assign(data.redirect || window.location.href);
                } catch (e) {
                    showError('passkey-error', "That didn't complete — check that this device can create a passkey, then try again.");
                    addButton.disabled = false;
                }
            });
        }
    }

    // ── Assertion (login challenge page) ──────────────────────────────
    const assertButton = document.getElementById('passkey-assert');

    if (assertButton && cfg.assertOptionsUrl && cfg.assertUrl) {
        if (!window.PublicKeyCredential) {
            assertButton.disabled = true;
            const note = document.getElementById('passkey-unsupported');
            if (note) note.hidden = false;
        } else {
            assertButton.addEventListener('click', async () => {
                assertButton.disabled = true;
                try {
                    const optionsResponse = await postJson(cfg.assertOptionsUrl);
                    if (!optionsResponse.ok) throw new Error('options');
                    const options = await optionsResponse.json();

                    options.challenge = b64urlToBuffer(options.challenge);
                    (options.allowCredentials || []).forEach((cred) => {
                        cred.id = b64urlToBuffer(cred.id);
                    });

                    const credential = await navigator.credentials.get({ publicKey: options });
                    if (!credential) throw new Error('cancelled');

                    const response = await postJson(cfg.assertUrl, {
                        id: credential.id,
                        rawId: bufferToB64url(credential.rawId),
                        type: credential.type,
                        response: {
                            clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
                            authenticatorData: bufferToB64url(credential.response.authenticatorData),
                            signature: bufferToB64url(credential.response.signature),
                            userHandle: credential.response.userHandle
                                ? bufferToB64url(credential.response.userHandle)
                                : null,
                        },
                    });
                    if (!response.ok) throw new Error('assert');

                    const data = await response.json();
                    window.location.assign(data.redirect || '/');
                } catch (e) {
                    showError('passkey-error', "That didn't work. Try again, or use a recovery code.");
                    assertButton.disabled = false;
                }
            });
        }
    }
})();
