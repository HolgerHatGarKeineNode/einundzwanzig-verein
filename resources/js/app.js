import {Alpine, Livewire} from '../../vendor/livewire/livewire/dist/livewire.esm';

import nostrDefault from "./nostrDefault.js";
import nostrApp from "./nostrApp.js";
import nostrLogin from "./nostrLogin.js";
import nostrZap from "./nostrZap.js";
import projectChatRoom from "./projectChatRoom.js";
import projectChatFeed from "./projectChatFeed.js";
import nostrLogout from "./nostrLogout.js";

/*
 * Die Markdown-Werkzeugleiste (<markdown-toolbar>), ein Web Component von
 * GitHub ohne eigene Abhaengigkeiten.
 *
 * KEIN EDITOR, und das ist der Grund fuer die Wahl. Es haengt sich an ein
 * gewoehnliches <textarea> und schreibt Markdown-Syntax hinein — per
 * execCommand('insertText'), womit die native Rueckgaengig-Kette erhalten
 * bleibt — und feuert dabei ein aufsteigendes `input`-Event. Damit greift
 * `wire:model` ohne eine Zeile Klebecode.
 *
 * Was daran haengt: Es gibt keine Editor-Instanz, die ein Livewire-DOM-Patch
 * verlieren oder doppelt anlegen koennte. Der Zustand IST der Textarea-Wert,
 * und den kennt Livewire ohnehin. Genau dieses Problem macht jeden
 * JS-Editor in Livewire teuer; hier existiert es nicht.
 */
import '@github/markdown-toolbar-element';

import './bootstrap';

// Light switcher
document.querySelectorAll('.light-switch').forEach(lightSwitch => lightSwitch.checked = true);
document.documentElement.classList.add('dark');
document.querySelector('html').style.colorScheme = 'dark';
localStorage.setItem('dark-mode', true);
document.dispatchEvent(new CustomEvent('darkMode', { detail: { mode: 'on' } }));

Alpine.store('nostr', {
    user: null,
});

Alpine.data('nostrDefault', nostrDefault);
Alpine.data('nostrApp', nostrApp);
Alpine.data('nostrLogin', nostrLogin);
Alpine.data('nostrZap', nostrZap);
// Registrierung hier, nicht in einem seitenspezifischen Entry: Alpine startet
// unten mit Livewire.start(); wer eine Komponente bereitstellt, muss vorher
// registriert sein. Das Chat-SDK laedt projectChatRoom selbst per dynamischem
// Import erst beim Klick — hier faellt nichts Schweres an.
Alpine.data('projectChatRoom', projectChatRoom);
// Dasselbe Prinzip fuer die eingebettete Raum-Ansicht: Die Huelle ist leicht,
// das SDK und die Package-Komponenten kommen per dynamischem Import — und damit
// erst NACH Alpines Start (siehe Kopf von projectChatFeed.js).
Alpine.data('projectChatFeed', projectChatFeed);
// Abmelden raeumt den Klartext-Cache des Chats vom Geraet. Leicht und ohne
// Import des SDK — der Knopf steht auf jeder Seite (siehe nostrLogout.js).
Alpine.data('nostrLogout', nostrLogout);

Livewire.start();
