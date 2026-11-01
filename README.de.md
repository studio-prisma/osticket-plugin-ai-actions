# AI Actions für osTicket

[![Validate](https://github.com/studio-prisma/osticket-plugin-ai-actions/actions/workflows/validate.yml/badge.svg)](https://github.com/studio-prisma/osticket-plugin-ai-actions/actions/workflows/validate.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

Ein Chat im Agenten-Bereich von osTicket, der Anfragen in Alltagssprache über die Claude-API in Ticket-Änderungen übersetzt. Aus „Setze die Fälligkeit auf morgen und weise es Philipp zu“ wird eine Vorschau mit genau diesen zwei Änderungen. Geändert wird erst, wenn der Agent auf **Ausführen** klickt.

**[English version →](README.md)**

---

## Was es kann

| | |
|---|---|
| Wo | Eine Bubble unten rechts im Agenten-Bereich. Das Gespräch gehört zum offenen Ticket: Ticketansicht oder eine Karte in der Schnellansicht des [Ticket Boards](https://github.com/studio-prisma/osticket-plugin-board) |
| Fragen | „Was will die Kundin eigentlich?“, „Fasse den Verlauf zusammen“. Beantwortet aus Ticketdaten und Verlauf, es ändert sich nichts |
| Änderungen | Fälligkeit, Priorität, Zuweisung an Agent oder Team, Abteilung, Status, interne Notiz, Antwortentwurf, Unter-Ticket, gesendete Antwort (Einstellung, Standard aus), Aktionen anderer Plugins ([EXTENSIONS](docs/EXTENSIONS.md)) |
| Lesen | Eltern-Ticket und Unter-Tickets, die der Agent öffnen darf; Anhänge (Textdateien; PDFs und Bilder mit Claude) |
| Anbieter | Anthropic (Claude Sonnet 5.5, Opus 5.5), OpenAI (GPT-6.1 Sol, Terra), Mistral (Large, Medium; EU) |
| Vorschau | Jede geplante Änderung als Zeile mit osTickets eigenen Bezeichnungen, Notizen und Entwürfe mit vollem Text. **Ausführen** oder **Verwerfen** |
| Rückgängig | 30 Sekunden nach dem Ausführen, für jede Feldänderung (nicht für Notizen, Entwürfe und Unter-Tickets). Danach bleibt der Knopf ausgegraut stehen |
| Aktualisieren | Nach Ausführen oder Rückgängig lädt die Ticketansicht neu, auf dem Board öffnet sich die Schnellansicht mit den neuen Werten wieder |
| Folgefragen | Ein Gespräch pro Agent und Ticket: „und jetzt eine Woche später“ bezieht sich auf das gerade gesetzte Datum. Das Modell erfährt, ob sein letzter Plan ausgeführt, verworfen oder zurückgenommen wurde |
| Frühere Gespräche | „Neu“ oder 12 Stunden Pause beendet ein Gespräch; es bleibt unter dem Uhr-Symbol 90 Tage lesbar (Einstellung), nur für den eigenen Agenten |
| Kontext | Ticketdaten, Verlauf, Eltern-Ticket und Unter-Tickets (Nummer, Betreff, Status, Zuständig, Fälligkeit; nur die, die der Agent öffnen darf). Alle Zeiten in der Zeitzone des Agenten, Sommer- und Winterzeit eingeschlossen |
| Fenster | Große Ansicht, Größe über die linke obere Ecke ziehbar (wird gemerkt), auf dem Handy Vollbild |
| Aussehen | Name, Icon oder eigenes Bild, Akzentfarbe und Begrüßungstext aus den Einstellungen. Das Modell nennt das System beim Helpdesk-Namen aus Einstellungen → System |
| Protokoll | Jedes Ausführen und jedes Rückgängig hinterlässt eine interne Notiz „AI-Aktion“ mit Anfrage und Ergebnis jeder Änderung |
| Grenzen | Nachrichten und Tokens pro Agent und Tag, eine Nachricht gleichzeitig pro Agent |

**Änderungen laufen über osTickets eigenen Code.** Jede Aktion nutzt dieselbe Core-Methode und dieselbe Rechteprüfung wie ihr osTicket-Dialog: `updateField()` für Fälligkeit und Priorität, `assign()`, `release()`, `transfer()`, `setStatus()` mit der Rechte-Matrix des Status-Dialogs, `postNote()`. Die Werte, die das Modell verwenden darf, stammen aus den Listen, die diese Dialoge dem Agenten zeigen, und werden beim Ausführen noch einmal geprüft. Über den Chat kann ein Agent nichts, was er nicht auch von Hand könnte.

**Das Modell plant, der Agent entscheidet.** Schreib-Tools werden nur vorgemerkt. Ein Plan liegt auf dem Server, gebunden an Agent und Ticket, 10 Minuten gültig und einmal ausführbar; der Browser schickt nur seine ID. Es gibt kein Tool für andere Tickets, keins zum Senden von Mails, keins für Netz oder Dateien.

**Ticketinhalt ist Datum, keine Anweisung.** Ticketdaten und Verlauf gehen in einem als nicht vertrauenswürdig markierten Block an das Modell; Block-Tags im Tickettext werden neutralisiert, damit ein Kunde den Block weder beenden noch eine Anfrage fälschen kann. In den Tests hat das Modell jede eingeschleuste Anweisung erkannt und benannt (siehe [SECURITY.md](SECURITY.md)). Die Vorschau ist in jedem Fall das Sicherheitsnetz.

**Kein SDK, keine Abhängigkeiten.** Das Plugin spricht die Messages API über PHPs cURL an (API-Version `2026-10-21`). Es bringt keine Fremdbibliothek mit, die sich im selben PHP-Prozess mit anderen Plugins beißen könnte.

Keine Core-Datei wird verändert. Die Routen laufen über osTickets `scp/ajax.php` (Signal `ajax.scp`); Skript und Stylesheet des Chats kommen per Output-Buffer in die Agenten-Seiten.

---

## Voraussetzungen

- osTicket **1.18.x** (getestet: 1.18.4)
- PHP **8.1 bis 8.4** mit cURL-Erweiterung (Syntax auf allen vier geprüft; die End-to-End-Läufe nutzen 8.3)
- ausgehendes HTTPS vom Server zu `api.anthropic.com`
- ein Anthropic-API-Key. Am besten aus einem eigenen Workspace mit Ausgabenlimit
- optional: [Ticket Board](https://github.com/studio-prisma/osticket-plugin-board) ab 0.3 für den Chat in dessen Schnellansicht

**Datenschutz.** Ticketdaten und Verlauf (Kundennachrichten, Antworten, interne Notizen, bis zur eingestellten Länge) gehen mit jeder Nachricht an Anthropic. Vor dem Einschalten Auftragsverarbeitung mit Anthropic und Datenschutzhinweise prüfen. Die eigenen Tabellen des Plugins behalten Gespräche 12 Stunden, Pläne mit der Anfrage des Agenten 2 Tage und Zählerstände 90 Tage.

---

## Installation

Vollständige Anleitung: **[docs/INSTALL.de.md](docs/INSTALL.de.md)** ([english](docs/INSTALL.md)).

Kurzfassung:

1. `ai-actions-<version>.zip` aus dem [letzten Release](https://github.com/studio-prisma/osticket-plugin-ai-actions/releases/latest) laden und nach `include/plugins/` entpacken.
2. Festlegen, wo der Key liegt: in einer Datei **außerhalb** des osTicket-Verzeichnisses (empfohlen), in der Umgebung des Webservers, oder eingefügt in die Plugin-Einstellungen, wo osTicket ihn verschlüsselt speichert.
3. Admin-Bereich → Verwalten → Plugins → Neues Plugin hinzufügen → **AI Actions** → Installieren, Instanz anlegen, Key-Quelle wählen, Rollen eintragen, aktivieren.

---

## Konfiguration

| Einstellung | Standard | Zweck |
|---|---|---|
| AI assistant in the agent panel | aus | Hauptschalter |
| Agents with these primary roles | keine | Wer die Bubble sieht. Nichts ausgewählt heißt niemand |
| Where the API key comes from | Key file | Key-Datei, Umgebungsvariable `ANTHROPIC_API_KEY` oder hier gespeichert. Nur die gewählte Quelle zählt |
| API key file | leer | Für „Key file“: absoluter Pfad zu einer Datei, die nur den Key enthält. Dateien im osTicket-Verzeichnis werden abgelehnt |
| API key | leer | Für „Stored here“: der Key, von osTicket mit `SECRET_SALT` aus `ost-config.php` verschlüsselt; wird nie wieder angezeigt, leeres Feld behält ihn |
| Remove the stored key | aus | Löscht den gespeicherten Key beim Speichern |
| Allow sending replies | aus | Die AI darf eine E-Mail-Antwort planen; gesendet erst nach Ausführen, nicht rückgängig zu machen |
| Model | Claude Sonnet 5.5 | Auch Claude Opus 5.5, GPT-6.1 Sol/Terra, Mistral Large/Medium. Das Modell bestimmt den Anbieter; der Key muss von diesem Anbieter sein (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `MISTRAL_API_KEY`) |
| Effort | Low | Wie lange das Modell nachdenkt. Low reicht für die meisten Anfragen |
| Messages | 100 | Pro Agent und Tag, 0 = kein Limit |
| Tokens | 500.000 | Input plus Output pro Agent und Tag, 0 = kein Limit |
| Thread length | 12.000 | Zeichen des Verlaufs, die das Modell liest, neueste zuerst (2.000 bis 50.000) |
| Name of the assistant | leer | Fenstertitel und Bubble-Beschriftung. Leer: „AI-Assistent“ |
| Icon | Sparkles | Funkeln, Roboter, Sprechblase, Glühbirne, Blitz, Anfangsbuchstabe des Namens oder eigenes Bild |
| Own image | leer | Für „Own image“: https://-Adresse oder Pfad auf diesem Server, rund angezeigt |
| Accent colour | leer | Hex-Farbe für Bubble, eigene Nachrichten und Hauptknöpfe. Leer: die des Themes |
| Welcome text | leer | Steht in einem leeren Gespräch |
| Keep earlier conversations (days) | 90 | Wie lange beendete Gespräche für ihren Agenten lesbar bleiben. 0 = nicht aufbewahren |

Welche Quelle? Eine Key-Datei hält den Key ganz aus der Datenbank und ihren Backups heraus. In den Einstellungen gespeichert ist er verschlüsselt, aber wer Datenbank und osTicket-Dateien zusammen hat, kann ihn entschlüsseln. Die Umgebungsvariable liegt dort, wo dein Server oder Panel die PHP-Einstellungen hält. Die Installationsanleitung vergleicht die drei.

Eine Nachricht kostet meist 1.000 bis 5.000 Tokens, mit Opus 5.5 bei niedrigem Effort etwa einen Cent. Eine Antwort dauert 3 bis 15 Sekunden.

**Theming.** Alle Farben sind CSS-Custom-Properties ohne Spezifität (`--spa-accent`, `--spa-surface` und weitere, aufgelistet oben in `assets/ai-actions.css`). Ein Theme kann sie mit einer einfachen Regel überschreiben, auch für einen Dunkelmodus.

---

## Tests

```bash
php tests/unit.php                                # 127 Prüfungen, ohne osTicket, ohne Netz
./dev/up.sh                                       # osTicket-1.18.4-Teststack auf 127.0.0.1:8089
cd tests/e2e && npm ci && npx playwright test     # 35 End-to-End-Tests
```

Der Stack läuft mit einem Offline-Ersatz für das Modell (`SPA_AI_FAKE=1`), die Suite braucht also weder Key noch Geld. Sie deckt jedes Abnahmekriterium der Spec ab und dazu eine Angriffs-Suite (CSRF auf jedem Endpoint, Race Conditions, manipulierte Parameter, fremde Tickets und Gespräche). Live-Läufe gegen das echte Modell beschreibt `dev/README.md`.

Nicht geprüft: PHP 8.1, 8.2 und 8.4 zur Laufzeit (nur Lint), osTicket mit erzwungener 2FA, Verläufe über 50.000 Zeichen mit dem echten Modell.

---

## Bekannte Grenzen

- **Nur das offene Ticket.** Keine Anfragen über mehrere Tickets („schließe alle Tickets von Kunde X“), keine Suche.
- **Entwürfe werden nie gesendet**, und ein vorhandener Entwurf des Agenten bleibt stehen.
- **Kein Rückgängig für Notizen, Entwürfe und Unter-Tickets.** Rückgängig gilt 30 Sekunden lang für Feldänderungen.
- **Anhänge werden nicht gelesen.**
- **Schließen nach einer Zuweisung im selben Plan behält die neue Zuweisung.** osTicket selbst würde den schließenden Agenten zuweisen; das Plugin folgt der Anfrage des Agenten.
- **Auf dem Board kann der offene Chat Karten** in der rechten Spalte verdecken, solange die Schnellansicht offen ist.
- **Frühere Gespräche sind nur lesbar** und nur für den eigenen Agenten sichtbar; was ein Kollege zum selben Ticket gefragt hat, bleibt privat.
- **Oberfläche Deutsch und Englisch.** Das Modell antwortet in der Sprache der Anfrage.

---

## Mitarbeit

Siehe [CONTRIBUTING.md](CONTRIBUTING.md). Sicherheitsprobleme: siehe [SECURITY.md](SECURITY.md), nie als öffentliches Issue.

## Lizenz

[GPL-2.0-or-later](LICENSE) © studio-prisma. Das Plugin erweitert osTicket-Klassen und folgt deshalb osTickets Lizenz.
