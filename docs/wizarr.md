# Emby über Wizarr anbinden

[Wizarr](https://github.com/wizarrrr/wizarr) legt User per Einladungslink automatisch auf dem
Medienserver an (Emby, Jellyfin, Plex u. a.), mit zeitlich begrenzter Mitgliedschaft und REST-API.
Im Konzept ist das Phase 9 (`docs/KONZEPT.md`, 5.5): zuerst von Hand, später über die Wizarr-API.

## 1. Wizarr in Portainer starten

Portainer → Stacks → Add stack → Web editor:

```yaml
services:
  wizarr:
    image: ghcr.io/wizarrrr/wizarr:latest
    container_name: wizarr
    restart: unless-stopped
    ports:
      # Nur lokal, der Reverse Proxy leitet z. B. https://einladung.<domain> hierher.
      - "127.0.0.1:5690:5690"
    volumes:
      - wizarr-data:/data
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Europe/Zurich
      # Eigene Anmeldung von Wizarr behalten: Pocket ID bietet keinen Forward-Auth-Proxy,
      # und mit true meldet /login jeden Besucher als Admin an.
      - DISABLE_BUILTIN_AUTH=false

volumes:
  wizarr-data:
```

Ohne Reverse Proxy auf dem Docker-Host die Zeile `ports` auf `- "5690:5690"` ändern (dann aber nur
im internen Netz erreichbar machen). Update: Stack → «Pull and redeploy».

## 2. Wizarr einrichten

1. `https://einladung.<domain>` öffnen und das Admin-Konto anlegen (Passkey möglich).
2. In Emby einen API-Schlüssel erstellen (Emby-Dashboard → Erweitert → API-Schlüssel).
3. In Wizarr unter den Einstellungen den Medienserver «Emby» mit URL und API-Schlüssel hinzufügen.
4. Optional den Einladungs-Assistenten anpassen (App-Download, Hinweise) und eine Einladung testen.

## 3. Im AccountManager als Dienst erfassen

Admin-Bereich → Dienste → Neu:

| Feld | Wert |
|---|---|
| Name | Emby |
| Schlüssel | `emby` |
| Typ | Extern (Konten werden dort verwaltet) |
| URL | Adresse des Emby-Servers |
| Gruppe | leer (Emby nutzt kein SSO) |
| Selbstregistrierung | aus |
| Freigabe nötig | an |

Ablauf heute (von Hand): Der User beantragt «Emby» im Portal, der Admin erhält eine Mail, gibt den
Zugang frei, erstellt in Wizarr eine Einladung und schickt den Link. Beim Sperren im AccountManager
den User auch in Wizarr bzw. Emby deaktivieren.

## 4. Später automatisch (Phase 9)

In Wizarr einen API-Schlüssel erstellen (Header `X-API-Key`; Dokumentation unter
`https://einladung.<domain>/api/docs/`). Der AccountManager würde dann beim Freigeben eine Einladung
erstellen und den Link mailen, beim Sperren den User über die API deaktivieren (Konnektor `wizarr`
mit `provision`/`suspend`/`deprovision`). Den genauen API-Umfang vor der Umsetzung prüfen.
