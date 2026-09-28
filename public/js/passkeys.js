// public/js/passkeys.js
//
// Passkeys im Browser (#353).
//
// Die Zeremonie selbst macht der Browser; diese Datei übersetzt nur zwischen
// dem, was der Server als JSON schickt, und dem, was `navigator.credentials`
// erwartet. Das ist mehr Arbeit als es klingt: Die WebAuthn-Schnittstelle
// nimmt und liefert ArrayBuffer, JSON kennt aber nur Text. Also wandert alles
// Binäre als base64url durch die Leitung und wird hier hin- und zurückgedreht.
//
// Bewusst ohne Bibliothek und ohne Bündler - das Projekt liefert sein
// JavaScript unverändert aus (siehe public/js/qrcode.js als Vorbild).

(function () {
    'use strict';

    if (!window.PublicKeyCredential || !navigator.credentials) {
        // Kein WebAuthn. Die Knöpfe bleiben sichtbar, melden aber beim Klick
        // etwas Verständliches - besser als ein Knopf, der nichts tut.
        return;
    }

    // ---- base64url <-> ArrayBuffer -------------------------------------

    function vonBase64Url(text) {
        var normal = String(text).replace(/-/g, '+').replace(/_/g, '/');
        while (normal.length % 4 !== 0) {
            normal += '=';
        }
        var roh = window.atob(normal);
        var puffer = new Uint8Array(roh.length);
        for (var i = 0; i < roh.length; i++) {
            puffer[i] = roh.charCodeAt(i);
        }
        return puffer.buffer;
    }

    function nachBase64Url(puffer) {
        var bytes = new Uint8Array(puffer);
        var text = '';
        for (var i = 0; i < bytes.length; i++) {
            text += String.fromCharCode(bytes[i]);
        }
        return window.btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // ---- Optionen für den Browser aufbereiten ---------------------------

    function optionenFuerErstellung(o) {
        o.challenge = vonBase64Url(o.challenge);
        o.user.id = vonBase64Url(o.user.id);
        if (Array.isArray(o.excludeCredentials)) {
            o.excludeCredentials = o.excludeCredentials.map(function (c) {
                c.id = vonBase64Url(c.id);
                return c;
            });
        }
        return o;
    }

    function optionenFuerAnfrage(o) {
        o.challenge = vonBase64Url(o.challenge);
        if (Array.isArray(o.allowCredentials)) {
            o.allowCredentials = o.allowCredentials.map(function (c) {
                c.id = vonBase64Url(c.id);
                return c;
            });
        }
        return o;
    }

    // ---- Antwort des Authenticators verpacken ---------------------------

    function verpacken(credential) {
        var a = credential.response;
        var daten = {
            id: credential.id,
            rawId: nachBase64Url(credential.rawId),
            type: credential.type,
            clientExtensionResults: credential.getClientExtensionResults
                ? credential.getClientExtensionResults()
                : {},
            response: {
                clientDataJSON: nachBase64Url(a.clientDataJSON)
            }
        };

        if (a.attestationObject) {
            daten.response.attestationObject = nachBase64Url(a.attestationObject);
        }
        if (a.authenticatorData) {
            daten.response.authenticatorData = nachBase64Url(a.authenticatorData);
        }
        if (a.signature) {
            daten.response.signature = nachBase64Url(a.signature);
        }
        // userHandle darf leer sein - bei einem Passkey, der nicht
        // "discoverable" ist, gibt es keines. Ein leerer String und "kein
        // Wert" sind dabei verschiedene Dinge, deshalb die Unterscheidung.
        if (a.userHandle !== undefined && a.userHandle !== null) {
            daten.response.userHandle = nachBase64Url(a.userHandle);
        }

        return daten;
    }

    // ---- Netzwerk --------------------------------------------------------

    function senden(pfad, felder) {
        var koerper = new URLSearchParams();
        Object.keys(felder).forEach(function (k) {
            koerper.append(k, felder[k]);
        });

        return fetch(pfad, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: koerper.toString()
        }).then(function (antwort) {
            return antwort.json().then(function (daten) {
                if (!antwort.ok || daten.ok === false) {
                    var fehler = new Error(daten.fehler || 'Der Vorgang ist fehlgeschlagen.');
                    // Weg zur Bestätigung (Audit M15). Er kommt ausschliesslich
                    // aus der eigenen JSON-Antwort derselben Herkunft - und
                    // wird trotzdem nur als Pfad dieser Seite übernommen.
                    if (typeof daten.stepup === 'string' && daten.stepup.charAt(0) === '/' && daten.stepup.charAt(1) !== '/') {
                        fehler.stepup = daten.stepup;
                    }
                    throw fehler;
                }
                return daten;
            }).catch(function (fehler) {
                // Keine JSON-Antwort bekommen. Das passiert, wenn die Sitzung
                // abgelaufen ist und der Server auf die Anmeldeseite
                // umleitet - dann ist "abgelaufen" die richtige Auskunft und
                // nicht der Parserfehler.
                if (fehler instanceof SyntaxError) {
                    throw new Error('Die Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.');
                }
                throw fehler;
            });
        });
    }

    function melden(feld, text, istFehler) {
        if (!feld) {
            return;
        }
        feld.textContent = text;
        feld.className = istFehler ? 'passkey-meldung passkey-fehler' : 'passkey-meldung';
        feld.hidden = false;
    }

    // ---- Registrierung ---------------------------------------------------

    // Nur der Knopf, der die Zeremonie wirklich starten darf. Ohne frische
    // Bestätigung steht an seiner Stelle ein gewöhnlicher Link zur
    // Bestätigungsseite (data-passkey-registrieren="stepup").
    var registrieren = document.querySelector('[data-passkey-registrieren="bereit"]');
    if (registrieren) {
        registrieren.addEventListener('click', function (ereignis) {
            ereignis.preventDefault();
            var meldung = document.querySelector('[data-passkey-meldung]');
            var bezeichnungsfeld = document.querySelector('[data-passkey-bezeichnung]');
            var csrf = registrieren.getAttribute('data-csrf') || '';

            registrieren.disabled = true;
            melden(meldung, 'Bitte bestätigen Sie am Gerät …', false);

            senden('/passkeys/optionen', { csrf_token: csrf })
                .then(function (optionen) {
                    return navigator.credentials.create({
                        publicKey: optionenFuerErstellung(optionen)
                    });
                })
                .then(function (credential) {
                    return senden('/passkeys/registrieren', {
                        csrf_token: csrf,
                        bezeichnung: bezeichnungsfeld ? bezeichnungsfeld.value : '',
                        antwort: JSON.stringify(verpacken(credential))
                    });
                })
                .then(function () {
                    window.location.reload();
                })
                .catch(function (fehler) {
                    // Die Bestätigung ist abgelaufen oder fehlt: dorthin, wo
                    // sie sich holen lässt, statt nur die Meldung zu zeigen.
                    if (fehler && fehler.stepup) {
                        window.location.href = fehler.stepup;
                        return;
                    }
                    registrieren.disabled = false;
                    melden(meldung, deutlich(fehler), true);
                });
        });
    }

    // ---- Step-up: eine Änderung mit dem Passkey bestätigen (Audit N10) ----

    var stepup = document.querySelector('[data-passkey-stepup]');
    if (stepup) {
        stepup.addEventListener('click', function (ereignis) {
            ereignis.preventDefault();
            var meldung = document.querySelector('[data-passkey-meldung]');
            var passwortfeld = document.getElementById('password');
            var passwort = passwortfeld ? passwortfeld.value : '';
            var csrf = stepup.getAttribute('data-csrf') || '';
            var fuer = stepup.getAttribute('data-fuer') || '';

            // Das Passwort gehört zum Nachweis. Ohne gar nicht erst den
            // Authenticator bemühen - der Server lehnte ohnehin ab.
            if (passwort === '') {
                melden(meldung, 'Bitte geben Sie zuerst Ihr aktuelles Passwort ein.', true);
                if (passwortfeld) {
                    passwortfeld.focus();
                }
                return;
            }

            stepup.disabled = true;
            melden(meldung, 'Bitte bestätigen Sie am Gerät …', false);

            senden('/2fa/reauth/passkey/optionen', { csrf_token: csrf })
                .then(function (optionen) {
                    return navigator.credentials.get({
                        publicKey: optionenFuerAnfrage(optionen)
                    });
                })
                .then(function (credential) {
                    return senden('/2fa/reauth/passkey', {
                        csrf_token: csrf,
                        password: passwort,
                        fuer: fuer,
                        antwort: JSON.stringify(verpacken(credential))
                    });
                })
                .then(function (daten) {
                    var weiter = typeof daten.weiter === 'string' && daten.weiter.charAt(0) === '/' && daten.weiter.charAt(1) !== '/'
                        ? daten.weiter
                        : '/profil';
                    window.location.href = weiter;
                })
                .catch(function (fehler) {
                    stepup.disabled = false;
                    melden(meldung, deutlich(fehler), true);
                });
        });
    }

    // ---- Anmeldung -------------------------------------------------------

    var anmelden = document.querySelector('[data-passkey-anmelden]');
    if (anmelden) {
        var starten = function () {
            var meldung = document.querySelector('[data-passkey-meldung]');
            var csrf = anmelden.getAttribute('data-csrf') || '';

            anmelden.disabled = true;
            melden(meldung, 'Bitte bestätigen Sie am Gerät …', false);

            senden('/login/passkey/optionen', { csrf_token: csrf })
                .then(function (optionen) {
                    return navigator.credentials.get({
                        publicKey: optionenFuerAnfrage(optionen)
                    });
                })
                .then(function (credential) {
                    return senden('/login/passkey/pruefen', {
                        csrf_token: csrf,
                        antwort: JSON.stringify(verpacken(credential))
                    });
                })
                .then(function (daten) {
                    window.location.href = daten.weiter || '/admin';
                })
                .catch(function (fehler) {
                    anmelden.disabled = false;
                    melden(meldung, deutlich(fehler), true);
                });
        };

        anmelden.addEventListener('click', function (ereignis) {
            ereignis.preventDefault();
            starten();
        });

        // Ohne Klick loslegen, wenn die Seite es verlangt. Der Knopf bleibt
        // trotzdem da: Bricht der Benutzer ab oder greift der Browser gar
        // nicht, ist ein zweiter Versuch sonst nur über Neuladen möglich.
        if (anmelden.hasAttribute('data-passkey-sofort')) {
            starten();
        }
    }

    /**
     * Die Fehler des Browsers sind für Entwickler geschrieben. Die drei, die
     * Benutzer tatsächlich auslösen, bekommen einen verständlichen Satz; der
     * Rest wird durchgereicht, statt hinter einem Sammeltext zu verschwinden.
     */
    function deutlich(fehler) {
        var name = fehler && fehler.name ? fehler.name : '';

        if (name === 'NotAllowedError') {
            return 'Abgebrochen oder zu lange gewartet. Bitte erneut versuchen.';
        }
        if (name === 'InvalidStateError') {
            return 'Dieser Sicherheitsschlüssel ist für dieses Konto bereits hinterlegt.';
        }
        if (name === 'SecurityError') {
            return 'Passkeys brauchen eine gesicherte Verbindung (HTTPS) und die richtige Adresse.';
        }

        return (fehler && fehler.message) ? fehler.message : 'Der Vorgang ist fehlgeschlagen.';
    }
})();
