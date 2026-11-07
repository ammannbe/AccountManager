# Emby über Wizarr anbinden

[Wizarr](https://github.com/wizarrrr/wizarr) legt User per Einladungslink automatisch auf dem
Medienserver an (Emby, Jellyfin, Plex u. a.), mit zeitlich begrenzter Mitgliedschaft und REST-API.
Im AccountManager ist das der Konnektor `wizarr` (Phase 9, `docs/KONZEPT.md` 5.5): Beim Freigeben
erstellt das Konto eine Einladung und mailt den Link, beim Sperren deaktiviert es den User, beim
Entfernen löscht es ihn.

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
4. Optional den Einladungs-Assistenten anpassen (App-Download, Hinweise) und von Hand eine Einladung
   testen.
5. Wizarr → Einstellungen → API-Schlüssel: einen Schlüssel «AccountManager» erstellen und kopieren
   (wird nur einmal angezeigt).
6. Die IDs nachschlagen, die der AccountManager braucht (Swagger-Oberfläche unter
   `https://einladung.<domain>/api/docs/`, oder mit curl):

   ```bash
   curl -s -H "X-API-Key: <schlüssel>" https://einladung.<domain>/api/servers    # id des Emby-Servers
   curl -s -H "X-API-Key: <schlüssel>" https://einladung.<domain>/api/libraries  # optional: ids der Bibliotheken
   curl -s -H "X-API-Key: <schlüssel>" https://einladung.<domain>/api/version    # installierte Version
   ```

## 3. Im AccountManager als Dienst erfassen

Admin-Bereich → Dienste → Neu:

| Feld | Wert |
|---|---|
| Name | Emby |
| Schlüssel | `emby` |
| Typ | Extern (Konten werden dort verwaltet) |
| URL | Adresse des Emby-Servers («Öffnen» im Portal, sobald das Konto besteht) |
| Gruppe | leer (Emby nutzt kein SSO) |
| Selbstregistrierung | aus |
| Freigabe nötig | an |
| Konnektor | Wizarr |
| Wizarr-URL | vom AccountManager aus erreichbar, z. B. `https://einladung.<domain>` |
| Öffentliche Wizarr-URL | nur falls die Wizarr-URL intern ist: Basis der Einladungslinks |
| API-Schlüssel | aus Schritt 2.5 (wird verschlüsselt gespeichert und nie wieder angezeigt; leer lassen = behalten) |
| Server-ID in Wizarr | `id` aus `/api/servers` |
| Bibliotheks-IDs | optional, `id`s aus `/api/libraries`; leer = alle aktivierten Bibliotheken |
| Einladung gültig für | 1, 7 oder 30 Tage oder unbegrenzt (Wizarr kennt nur diese Werte) |
| Dauer des Zugangs | optional, Tage; danach beendet Wizarr den Zugang selbst. Leer = unbegrenzt (empfohlen: Sperren läuft über das Konto) |
| Downloads / Live-TV erlauben | nach Wunsch |

## 4. Ablauf

| Im Konto | Beim Konnektor | In Wizarr (API) |
|---|---|---|
| Zugang freigegeben (bzw. aktiv angelegt) | `provision` | Einladung für eine Nutzung erstellen; der User erhält den Link per Mail und sieht ihn im Portal unter «Meine Dienste», bis er ihn benutzt hat |
| alle 15 Minuten (`connectors:check`) | Einladungen prüfen | benutzte Einladung → Wizarr-User suchen, ID und Benutzername am Zugang speichern; abgelaufene Einladung → im Portal «Einladung abgelaufen» |
| gesperrt | `suspend` | User deaktivieren; noch offene Einladung widerrufen (löschen) |
| entsperrt | `resume` | User wieder aktivieren; gibt es keinen User (mehr), neue Einladung |
| Zugang entfernt oder User gelöscht | `deprovision` | User löschen bzw. offene Einladung widerrufen |

- Jeder Schritt läuft über die Queue mit Wiederholungen (6 Versuche über rund 5 Stunden) und steht im
  Konnektor-Protokoll (Admin-Bereich → Konnektor-Protokoll, oder auf der User-Seite beim Zugang).
  Schlägt ein Schritt endgültig fehl, erhalten die Admins eine Mail; «Erneut versuchen» gibt es im
  Protokoll und beim Zugang.
- Fehler, die eine Wiederholung nicht behebt (falscher API-Schlüssel, ungültige Server-ID,
  Weiterleitung, fehlende Einstellungen), schlagen sofort fehl.
- «Erneut versuchen» beim Zugang führt den Schritt zum aktuellen Status nochmals aus: bei offener
  Einladung wird sie nochmals gemailt, nach einer abgelaufenen gibt es eine neue.
- Kann das Konto eine benutzte Einladung keinem Wizarr-User zuordnen, erhalten die Admins einmal
  eine Mail; dann die Wizarr-User-ID beim Zugang unter «ID im Dienst» von Hand eintragen.
- Einen Dienst löschen entfernt die Konten in Wizarr nicht (die Zugänge verschwinden ohne Ereignis);
  vorher die Zugänge einzeln entfernen.

## 5. Annahmen zur Wizarr-API (gegen die installierte Version prüfen)

Umgesetzt nach dem Quellcode von Wizarr **v2026.10.1** (`app/blueprints/api/api_routes.py` und
`models.py`; die API gibt es seit v2025.8). Vor dem produktiven Einsatz mit `/api/version` die
Version prüfen und in `/api/docs/` die folgenden Punkte vergleichen. Die Tests
(`tests/Feature/WizarrConnectorTest.php`) bilden genau diese Antworten nach.

| Annahme | Verwendet für |
|---|---|
| Header `X-API-Key: <schlüssel>`, alle Pfade unter `/api`, JSON | alle Aufrufe |
| `POST /api/invitations` mit `server_ids` (Pflicht), `expires_in_days` (nur 1, 7, 30, sonst «nie»), `duration` (Tage als Text oder `"unlimited"`), `unlimited`, `library_ids`, `allow_downloads`, `allow_live_tv` → `201 {message, invitation: {id, code, url, expires, …}}` | Einladung erstellen |
| `unlimited` bedeutet **beliebig oft verwendbar** (in `is_invite_valid`: benutzt und nicht unlimited = ungültig), nicht «unbegrenzter Zugang», wie die API-Beschreibung sagt; Standard der API ist `true`. Das Konto sendet immer `unlimited: false` | Einladung nur einmal nutzbar |
| `invitation.url` ist ein Pfad (`/j/<code>`), kein absoluter Link; `expires` ist UTC ohne Zeitzone | Link = öffentliche URL (bzw. Wizarr-URL) + Pfad |
| `GET /api/invitations` → `{invitations: [{id, code, status (pending/used/expired), used_at, used_by, …}]}` | Einlösung erkennen |
| `used_by` ist ein Text aus dem User-Objekt; bei v2026.10 die Standarddarstellung `<User 12>` (Flask-SQLAlchemy). Das Konto liest daraus die ID, sonst vergleicht es den Text mit dem Benutzernamen, sonst die E-Mail-Adresse des Users mit der in Wizarr | Wizarr-User zuordnen |
| `GET /api/users` (optional `?email=`) → `{users: [{id, username, email, server, server_type, expires, created_at}]}`; die Liste enthält **keinen** Einladungscode und keinen Status «deaktiviert» | Zuordnung |
| `POST /api/users/{id}/disable` → `200 {message}`; kann der Server keine User deaktivieren, **löscht** Wizarr den User und meldet `… deleted (disable not supported by server)`. Emby und Jellyfin unterstützen Deaktivieren (`Policy.IsDisabled`) | Sperren; bei «deleted» vergisst das Konto die User-ID und lädt beim Entsperren neu ein |
| `POST /api/users/{id}/enable` → `200 {message}`, auch bei Misserfolg (`Enable failed or not supported …`) | Entsperren; Misserfolg = endgültiger Fehler mit Mail an die Admins |
| `DELETE /api/users/{id}`, `DELETE /api/invitations/{id}` → `200`, `404` wenn nicht vorhanden | Entfernen, Einladung widerrufen (404 gilt als erledigt) |
| `401` bei falschem Schlüssel, `400` bei fehlender oder ungültiger Server-ID | sofortiger Fehler |

Bekannte Grenzen: Wizarr synchronisiert die User-Liste bei `GET /api/users` nur, wenn der letzte
Abgleich alt ist; eine eben eingelöste Einladung kann deshalb erst bei einem späteren Lauf zugeordnet
werden (der Lauf wiederholt sich alle 15 Minuten). Der Status «deaktiviert» ist über die API nicht
lesbar; das Konto merkt sich seinen eigenen Stand.

## 6. Ohne API (Rückfall)

Konnektor «Von Hand»: Die Admins erhalten bei jedem Schritt eine Mail (Konto erstellen, sperren,
reaktivieren, löschen), erstellen die Einladung in Wizarr selbst, schicken den Link und tragen den
Benutzernamen beim Zugang ein. Hinweise für die User (z. B. «Die Einladung folgt per Mail») gehen in
«Hinweise für den User» und erscheinen im Portal.
