# HD Custom Forms

Professionelles WordPress Plugin für individuelle HTML-Formulare mit Admin Dashboard und Formular-Management.

![Version](https://img.shields.io/badge/version-3.0.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)
![License](https://img.shields.io/badge/license-MIT-green)

## Features

### Formular-Management
✅ **Formulare im Admin verwalten** - Erstellen, Bearbeiten, Löschen
✅ **Shortcode-System** - Einfache Einbindung via `[custom_form id=X]`
✅ **KI-Integration** - Formulare mit ChatGPT/Claude anpassen lassen
✅ **Template-System** - Sample-Forms als Startpunkt
✅ **Multi-Step Forms** - Timeline, Validierung, Branding

### Submissions
✅ Eigene Datenbank-Tabelle (keine Elementor-Abhängigkeiten)
✅ Eigener Admin-Menüpunkt: **Anfragen**
✅ Dashboard mit Statistiken (Gesamt, Ungelesen, Heute)
✅ Detailansicht für jede Submission
✅ Status-Management (Neu/Gelesen)
✅ Suchfunktion & Filter
✅ IP-Tracking & User Agent

### Sicherheit & Features
✅ **Honeypot-Feld** gegen Spam
✅ **Rate Limiting** (3 Anfragen/Stunde pro IP)
✅ **Datei-Uploads** (bis 3MB, validierte Typen)
✅ **E-Mail Benachrichtigungen** konfigurierbar
✅ **Array-Support** für Mehrfachauswahl
✅ **Automatische Updates** via GitHub

## Installation

### Methode 1: Download von GitHub (empfohlen)
1. Gehe zu [Releases](https://github.com/khdigital94/HD-wp-forms-plugin/releases)
2. Lade die neueste `hd-custom-forms-vX.X.X.zip` herunter
3. WordPress Admin → Plugins → Installieren → Plugin hochladen
4. ZIP hochladen und aktivieren
5. **Automatische Updates** sind aktiviert! ✨

### Methode 2: Manuell
1. Repository klonen oder ZIP herunterladen
2. In `wp-content/plugins/` entpacken
3. Plugin im WordPress Admin aktivieren

## Verwendung

### Formular erstellen (Neu in v3.0!)

**WordPress Admin → Anfragen → Formulare → Neues Formular**

1. **Template kopieren** - Klick auf "Template kopieren" Button
2. **KI nutzen** (optional) - Sende Template + Prompt an ChatGPT/Claude:
   - "Hier ist ein Formular-Template. Erstelle ein Bewerbungsformular mit..."
   - "Hier ist ein Formular-Template. Füge Upload-Felder hinzu für..."
3. **Code einfügen** - Paste den (angepassten) Code ins Textfeld
4. **Speichern** - Formular wird gespeichert
5. **Shortcode kopieren** - z.B. `[custom_form id=1]`
6. **Einbinden** - Shortcode in Elementor Custom HTML Widget pasten

**Das Plugin injiziert automatisch:**
- `formId` (eindeutige ID)
- `formName` (aus Titel)
- `postId` (aktuelle Seite)

### Alte Methode (funktioniert weiterhin)

Kopiere `sample-form.html` oder `career-form.html` komplett in ein **Elementor Custom HTML Widget**.

Passe nur das **FORM_CONFIG Object** an:

```javascript
const FORM_CONFIG = {
  formId: 'mein_kontaktform',
  formName: 'Mein Kontaktformular',

  branding: {
    primary: '#EDB42A',
    primaryHover: '#d9a526',
    btnText: '#00385F',
    // ...
  },

  steps: [
    {
      number: 1,
      label: 'Schritt 1',
      title: 'Deine Frage?',
      description: '...',
      type: 'options',
      fieldId: 'auswahl',
      layout: 'grid-2',
      options: ['Option 1', 'Option 2']
    },
    {
      number: 2,
      label: 'Kontakt',
      type: 'fields',
      fields: [
        {
          id: 'name',
          type: 'text',
          label: 'Name',
          placeholder: 'Max Mustermann',
          required: true
        },
        {
          id: 'email',
          type: 'email',
          label: 'E-Mail',
          required: true
        }
      ]
    }
  ]
};
```

### Submissions anschauen

**WordPress Admin → Form Submissions**

- Statistiken: Gesamt, Ungelesen, Heute
- Liste aller Submissions
- Details aufklappen
- Als gelesen markieren
- Löschen

## Field Types

### Options Step (Multiple Choice)
```javascript
{
  type: 'options',
  fieldId: 'projekttyp',
  layout: 'grid-2',  // oder 'grid-1'
  options: ['Option 1', 'Option 2', 'Option 3']
}
```

### Fields Step (Formular)
```javascript
{
  type: 'fields',
  fields: [
    { id: 'name', type: 'text', label: 'Name', required: true },
    { id: 'email', type: 'email', label: 'E-Mail', required: true },
    { id: 'telefon', type: 'tel', label: 'Telefon' },
    {
      id: 'zeit',
      type: 'time_picker',
      label: 'Wann erreichbar?',
      times: ['08:00', '09:00', '10:00', ...]
    }
  ],
  privacy: {
    required: true,
    text: 'Ich akzeptiere die <a href="/datenschutz">Datenschutzerklärung</a>'
  }
}
```

**Unterstützte Input Types:**
- `text`
- `email`
- `tel`
- `time_picker` (Custom Dropdown mit definierten Zeiten)

### Stars Display (Optional)
```javascript
{
  stars: {
    show: true,
    text: 'Über 100+ zufriedene Kunden'
  }
}
```

## Neues Projekt

1. `sample-form.html` kopieren
2. `FORM_CONFIG` anpassen
3. In Elementor Custom HTML Widget pasten
4. **Fertig!**

## Technische Details

**Datenbank:**
- Tabelle: `wp_custom_form_submissions`
- Felder: id, form_id, form_name, form_data (JSON), user_ip, user_agent, referer, created_at, status

**AJAX Endpoint:**
- Action: `custom_form_submit`
- Method: POST
- Parameter: `formData` (JSON)

**Admin Dashboard:**
- Menu Position: 30 (nach Comments)
- Icon: dashicons-email-alt
- Capability: manage_options

## Beispiel - Bewerber Form

```javascript
const FORM_CONFIG = {
  formId: 'bewerber_form',
  formName: 'Bewerbungsformular',

  steps: [
    {
      number: 1,
      label: 'Position',
      title: 'Welche Position interessiert Sie?',
      type: 'options',
      fieldId: 'position',
      layout: 'grid-2',
      options: ['Projektleiter', 'Polier', 'Baggerfahrer']
    },
    {
      number: 2,
      label: 'Daten',
      type: 'fields',
      fields: [
        { id: 'name', type: 'text', label: 'Vor- und Nachname', required: true },
        { id: 'email', type: 'email', label: 'E-Mail', required: true },
        { id: 'telefon', type: 'tel', label: 'Telefon', required: true }
      ]
    }
  ]
};
```

Submissions landen automatisch in **WP Admin → Anfragen**!

## Updates

Das Plugin prüft **automatisch** auf neue Versionen via GitHub!

**Wenn ein Update verfügbar ist:**
1. WordPress zeigt Benachrichtigung im Dashboard
2. WordPress Admin → Plugins → "Jetzt aktualisieren" klicken
3. Fertig! Daten bleiben erhalten

**Für Entwickler:**
- Version ändern in `custom-form-submissions.php` (Zeile 4)
- `git push` → GitHub Actions erstellt automatisch Release
- Alle Installationen bekommen Update-Benachrichtigung

## Changelog

### v3.0.0
- ✨ **Formular-Management** im Admin
- ✨ **Shortcode-System**
- ✨ **Auto-Updates** via GitHub
- ✨ **KI-Integration** Hilfe-Box mit Beispiel-Prompts
- ✨ **Array-Support** für Mehrfachauswahl
- ✨ **Career-Form Template**
- 🔧 Timeline versteckt sich bei Kontakt-Step
- 🔧 Time Picker undurchsichtig
- 🔧 Redirect zur Übersicht nach Formular-Speichern
- 🔧 Visuelles Feedback statt Browser-Alerts
- 🐛 Daten bleiben bei Plugin-Deinstallation erhalten
