# Installation

[English version →](INSTALL.md)

**Die Reihenfolge ist bindend.** Jeder Schritt hat einen Abbruchpunkt. Bricht ein Schritt ab, wird dieser Schritt behoben — nicht weitermachen und hoffen, dass der nächste es ausgleicht.

---

## 0. Bevor irgendetwas angefasst wird

| Prüfung | Abbruch, wenn |
|---|---|
| Version der Host-Plattform | außerhalb des Bereichs aus [README.de.md](../README.de.md#voraussetzungen) |
| Laufzeit-Version | außerhalb des geprüften Bereichs |
| Schreibrechte im Zielverzeichnis | nur per `sudo` auf einen Webserver-Pfad möglich |
| Rückweg auf den aktuellen Stand | Rollback dauert länger als wenige Minuten |

**Aussperr-Risiko — hier lesen, nicht im Troubleshooting.** *(Benennen, was den Betreiber vom eigenen System aussperren kann: ein Login-Gate, das schließt, eine überschriebene Konfiguration, ein geänderter Auth-Pfad. Kann dieses Projekt niemanden aussperren, gehört genau das hierhin — Abschnitt nicht ersatzlos löschen.)*

**Kill-Switch.** *(Wie man das ohne funktionierende Oberfläche abschaltet — welche Datei umbenannt, welches Flag entfernt, welche Tabellenzeile gelöscht wird. Steht hier, bevor die riskante Option erklärt wird.)*

```bash
# der Befehl, der alles Folgende rückgängig macht
```

---

## 1. Paket beziehen

Artefakt aus dem [letzten Release](https://github.com/studio-prisma/osticket-plugin-ai-actions/releases/latest) laden. Nicht aus einem Quell-Archiv von `main` installieren — `main` ist kein Release.

**Abbruch, wenn** dem Release kein Artefakt anhängt oder die Version im Dateinamen nicht zum Tag passt.

---

## 2. Installieren

```bash
# exakte Befehle, keine Platzhalter zum Raten
```

**Abbruch, wenn** im Zielverzeichnis bereits eine andere Version liegt — erst entfernen, nicht überlagern.

---

## 3. Konfigurieren

| Einstellung | Default | Wirkung |
|---|---|---|
| ... | ... | ... |

In dieser Reihenfolge konfigurieren; spätere Einstellungen können voraussetzen, dass frühere greifen.

**Abbruch, wenn** ein Pflichtwert fehlt. Ein Platzhalter an dieser Stelle wird später zum Produktionsvorfall.

---

## 4. Smoke-Test

Die Installation ist erst fertig, wenn jede Zeile das angegebene Ergebnis liefert.

| Test | Erwartet |
|---|---|
| ... | ... |

**Abbruch, wenn** eine Zeile abweicht. Kill-Switch aus §0 ziehen und offline untersuchen.

---

## 5. Security-Gate

Vor dem Abschluss durchgehen. Jede Zeile ist eine Entscheidung, kein Vorschlag.

| Prüfung | Erwartet | Bei Abweichung |
|---|---|---|
| Verhalten, wenn die externe Abhängigkeit nicht erreichbar ist | schließt | Fail-Mode-Einstellung prüfen |
| Secrets in Logs oder Fehlerausgaben | keine | stoppen, rotieren, dann untersuchen |
| Zugriff aus einer nicht angemeldeten Session | abgewiesen | nicht produktiv setzen |

---

## 6. Deinstallation

```bash
# exakter Rückweg, inklusive Resten außerhalb des Installationsverzeichnisses
```

---

## Bekannte Grenzen

Benennen, was **nicht** getestet ist, nicht nur was getestet ist. Wer die Lücke kennt, arbeitet drumherum; wer sie im Betrieb findet, schreibt einen Bug-Report.

## Troubleshooting

| Symptom | Ursache | Behebung |
|---|---|---|
| ... | ... | ... |
