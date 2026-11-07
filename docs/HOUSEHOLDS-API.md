# Haushalte: Schnittstelle für die Apps

Stand: 2026-11-07 · Gilt für `HOUSEHOLDS_DRIVER=konto` in RecipeManager, Shoppinglist, Chorelist und jeder weiteren «haushaltsfähigen» App. Hintergrund: `docs/KONZEPT.md`, Abschnitt 5.6.

Das Konto (AccountManager) ist die Quelle der Wahrheit für Haushalte, Mitglieder und Rollen. Die Apps spiegeln diese Daten lokal (abgeglichen über `oidc_sub`) und entscheiden selbst, was sie mit einem Haushalt teilen. Verwaltet werden Haushalte nur im Konto (Portal `/households`); in den Apps ist die Haushaltsverwaltung mit `konto` schreibgeschützt.

## 1. Einrichtung

Im Admin-Bereich des Kontos unter **Dienste → Dienst bearbeiten**:

1. **Webhook-URL** eintragen, z. B. `https://recipes.example.com/api/konto/webhook` (HTTPS, ohne Weiterleitung: das Konto folgt keinen Redirects).
2. **Secret erzeugen** (oben rechts). Das Secret wird genau einmal angezeigt und im Konto verschlüsselt gespeichert. «Secret erneuern» macht das alte sofort ungültig.

In der App (Vorschlag für die Namen, alle über `.env`):

| Variable | Inhalt |
|---|---|
| `HOUSEHOLDS_DRIVER` | `konto` |
| `KONTO_URL` | Basis-URL des Kontos, z. B. `https://konto.example.com` |
| `KONTO_SERVICE_KEY` | Schlüssel des Dienstes im Konto (Feld «Schlüssel», z. B. `recipes`) |
| `KONTO_SECRET` | das erzeugte Secret (64 Hex-Zeichen) |

Ein Secret pro Dienst dient für beides: Es signiert die Webhooks des Kontos und authentifiziert die API-Aufrufe der App. (Entscheid siehe `KONZEPT.md`, Abschnitt 11.)

## 2. Datenmodell aus Sicht der App

Jede Antwort und jedes Ereignis enthält den **vollständigen aktuellen Stand** eines Haushalts («Snapshot»):

```json
{
  "uuid": "019a5e3c-6f1d-7b2a-9c43-0d8e5f6a7b8c",
  "name": "Familie Muster",
  "version": 7,
  "members": [
    { "sub": "4f0c2b9e-…", "role": "admin" },
    { "sub": "a81d7c3f-…", "role": "member" }
  ]
}
```

- `uuid`: stabile ID des Haushalts. Die App speichert sie als `konto_household_uuid`.
- `name`: Name des Haushalts (max. 100 Zeichen).
- `version`: Ganzzahl, steigt bei **jeder** Änderung des Haushalts um 1 (Name, Mitglieder, Rollen). Grundlage für Reihenfolge und Idempotenz (Abschnitt 5).
- `members`: alle Mitglieder mit `sub` (= `oidc_sub`, die User-ID beim Identitätsanbieter) und `role` (`admin` | `member`), sortiert nach Beitritt. Mitglieder ohne Identität (lokale Notfall-Admins des Kontos) fehlen, weil sie die Apps nicht nutzen können.
- **Datenschutz:** Mehr verlässt das Konto nicht, keine Namen, E-Mail-Adressen oder anderen Profildaten. Mitglieder, die die App (noch) nicht nutzen, erscheinen nur mit ihrer `sub`; die App ordnet sie zu, sobald sich jemand mit dieser `sub` anmeldet.
- Nur in `household.deleted`: `"members": []` und `"deleted": true`.

## 3. API (App → Konto)

Basis: `{KONTO_URL}/api`. Antworten sind JSON, Fehler haben die Form `{"message": "…"}` (Validierung zusätzlich `errors`).

### 3.1 Authentifizierung

HTTP Basic mit dem Schlüssel des Dienstes als Benutzername und dem Secret als Passwort:

```
Authorization: Basic base64("recipes:<KONTO_SECRET>")
```

Laravel: `Http::withBasicAuth(config('households.konto.key'), config('households.konto.secret'))->acceptJson()`.

- Fehlende oder falsche Angaben → `401` mit `WWW-Authenticate: Basic realm="AccountManager"`.
- Rate-Limit: 120 Anfragen pro Minute und IP (`429`).
- **Sichtbarkeit:** Ein Dienst sieht nur Haushalte, von denen mindestens ein Mitglied im Konto einen Zugang zu diesem Dienst hat (beliebiger Status). Für andere Haushalte antwortet die API mit `404` bzw. einer leeren Liste.

### 3.2 `GET /api/households?sub={oidc_sub}`

Alle Haushalte eines Users. Aufruf beim (ersten) Login eines Users und beim periodischen Abgleich (z. B. täglich, für jeden User mit `oidc_sub`).

```http
GET /api/households?sub=4f0c2b9e-… HTTP/1.1
Authorization: Basic …
Accept: application/json
```

```json
{
  "data": [
    {
      "uuid": "019a5e3c-6f1d-7b2a-9c43-0d8e5f6a7b8c",
      "name": "Familie Muster",
      "version": 7,
      "members": [
        { "sub": "4f0c2b9e-…", "role": "admin" },
        { "sub": "a81d7c3f-…", "role": "member" }
      ]
    }
  ]
}
```

- `sub` fehlt → `422`.
- Unbekannte `sub` oder User ohne Zugang zu diesem Dienst → `{"data": []}`.
- Die Liste dient zum Finden von Haushalten, nicht zum Entfernen. Fehlt ein gespiegelter Haushalt, in dem der User lokal Mitglied ist, prüft die App ihn mit 3.3 und wendet den Snapshot an (`404` = gelöscht bzw. betrifft den Dienst nicht mehr). Massgebend für Mitgliedschaften ist immer der Snapshot (Abschnitt 5), auch für Mitglieder ohne Zugang zum Dienst: Sie bleiben im Snapshot und damit Mitglied, können die App aber ohne Zugang nicht nutzen.

### 3.3 `GET /api/households/{uuid}`

Ein einzelner Haushalt, z. B. um ein Ereignis für einen unbekannten Haushalt zu bestätigen (Abschnitt 5).

```json
{ "data": { "uuid": "019a5e3c-…", "name": "Familie Muster", "version": 7, "members": [ … ] } }
```

- `404`: Den Haushalt gibt es nicht mehr, oder er betrifft keinen User dieses Dienstes mehr. In beiden Fällen löscht die App ihre Kopie (Freigaben auf den Haushalt fallen weg, Inhalte bleiben beim Eigentümer).

### 3.4 `PUT /api/households/{uuid}/shares` (optional)

Die App meldet, was sie mit dem Haushalt teilt. Das Konto zeigt es in der Haushaltsübersicht («Geteilt: RecipeManager: Familienrezepte, Backen»). Der Aufruf **ersetzt** alle Einträge dieses Dienstes für den Haushalt; Einträge anderer Dienste bleiben unberührt. Eine leere Liste löscht alle Einträge des Dienstes.

```http
PUT /api/households/019a5e3c-…/shares HTTP/1.1
Authorization: Basic …
Content-Type: application/json

{
  "shares": [
    { "resource_type": "cookbook", "resource_id": "12", "label": "Familienrezepte" },
    { "resource_type": "cookbook", "resource_id": "13", "label": "Backen" }
  ]
}
```

```json
{
  "data": {
    "shares": [
      { "resource_type": "cookbook", "resource_id": "12", "label": "Familienrezepte" },
      { "resource_type": "cookbook", "resource_id": "13", "label": "Backen" }
    ]
  }
}
```

- `resource_type` (max. 50), `resource_id` (**String**, max. 100), `label` (max. 255) sind Pflicht; max. 500 Einträge. Fehler → `422`.
- Aufruf nach jeder Änderung einer Haushalts-Freigabe (Teilen, Entziehen, Umbenennen des Inhalts); Fehlschläge sind unkritisch (nur Übersicht), beim nächsten Abgleich erneut senden.
- Unbekannter oder nicht sichtbarer Haushalt → `404`.

### 3.5 Umstieg lokal → Konto: `POST /api/households/import` und `GET /api/households/import`

Beim Wechsel von `HOUSEHOLDS_DRIVER=local` auf `konto` schlägt die App ihre lokalen Haushalte vor. Das Konto führt **nichts automatisch** zusammen: Ein Admin des Kontos entscheidet pro Vorschlag im Admin-Bereich (**Haushaltsvorschläge**): neuen Haushalt erstellen, in einen bestehenden Haushalt zusammenführen (Vorschlag: gleicher Name und ein gemeinsames Mitglied) oder ablehnen.

```http
POST /api/households/import HTTP/1.1
Authorization: Basic …
Content-Type: application/json

{
  "households": [
    {
      "local_id": "17",
      "name": "Familie Muster",
      "members": [
        { "sub": "4f0c2b9e-…", "role": "admin" },
        { "sub": "a81d7c3f-…", "role": "member" }
      ]
    }
  ]
}
```

Antwort `202`:

```json
{
  "data": [
    { "local_id": "17", "status": "pending", "household_uuid": null, "household": null }
  ]
}
```

- `local_id` (String, max. 100, eindeutig pro Dienst), `name` (max. 100), `members` (1–100, nur Mitglieder mit `oidc_sub`; `role` optional, Standard `member`). Max. 500 Haushalte pro Aufruf.
- Erneutes Senden ist erlaubt (idempotent über `local_id`): offene Vorschläge werden aktualisiert, entschiedene bleiben unverändert.
- `GET /api/households/import` liefert den Stand aller Vorschläge des Dienstes (gleiche Form). Die App fragt periodisch ab (z. B. stündlich), bis kein Vorschlag mehr `pending` ist:
  - `confirmed`: `household_uuid` und `household` (Snapshot) sind gesetzt. Die App speichert `konto_household_uuid` beim lokalen Haushalt, übernimmt Name und Mitglieder aus dem Snapshot und behält ihre Freigaben (z. B. `cookbook_household`).
  - `rejected`: Der lokale Haushalt wird nicht gespiegelt. Empfehlung: in einen lokalen, schreibgeschützten Zustand überführen oder dem Eigentümer anzeigen; die Freigaben entfallen erst, wenn die App den Haushalt löscht.
- Mitglieder ohne `oidc_sub` kann die App nicht vorschlagen. Da nach der Bestätigung der Snapshot massgebend ist, verlieren sie den Haushaltszugriff in der App, bis sie ihren Login mit dem Konto verknüpfen und im Konto Mitglied des Haushalts sind. Die App sollte das vor dem Import anzeigen.
- Vorgeschlagene Mitglieder ohne User im Konto werden nicht übernommen (der Admin sieht ihre Anzahl). Beim Zusammenführen behalten bestehende Mitglieder ihre Rolle; fehlt ein Admin, wird beim Erstellen das erste Mitglied Admin.
- Nach der Bestätigung sendet das Konto die üblichen Ereignisse (`household.created` bzw. `household.updated`).

## 4. Webhooks (Konto → App)

### 4.1 Zustellung

- `POST` an die Webhook-URL des Dienstes, Body JSON (UTF-8), Timeout 10 s, keine Redirects.
- Empfänger: jeder Dienst mit Webhook-URL und Secret, bei dem mindestens ein betroffener User (aktuelle Mitglieder, bei `member_removed` und `deleted` auch die entfernten) im Konto einen Zugang hat.
- Jede Antwort mit Status `2xx` gilt als zugestellt; alles andere (auch `4xx`) und Verbindungsfehler werden wiederholt: insgesamt 8 Versuche, Abstände 10 s, 1 min, 5 min, 15 min, 1 h, 3 h, 6 h (rund 10,5 h). Danach ist die Zustellung «fehlgeschlagen»; der Admin kann sie im Konto (**Webhooks**) erneut senden. Das Protokoll wird nach 30 Tagen gelöscht.
- Die App soll schnell antworten (Verarbeitung idealerweise in die eigene Queue legen) und auch bei bereits verarbeiteten oder veralteten Ereignissen `2xx` antworten.
- Reihenfolge ist **nicht** garantiert (Wiederholungen, parallele Worker). Siehe Abschnitt 5.

### 4.2 Header

| Header | Inhalt |
|---|---|
| `Content-Type` | `application/json` |
| `User-Agent` | `AccountManager-Webhooks` |
| `X-Konto-Event` | Typ, z. B. `household.member_added` |
| `X-Konto-Event-Id` | UUID des Ereignisses (gleich wie `event_id` im Body; bei Wiederholungen unverändert) |
| `X-Konto-Timestamp` | Unix-Zeit (Sekunden) des Versuchs |
| `X-Konto-Signature` | `sha256=` + HMAC-SHA256 (hex) über `"{timestamp}.{body}"` mit dem Secret |

### 4.3 Signatur prüfen

1. Den **rohen** Body verwenden (nicht neu serialisieren).
2. Zeitstempel ablehnen, der mehr als **300 Sekunden** von der eigenen Uhrzeit abweicht (Schutz gegen Wiederholung). Jeder Versuch wird neu signiert, Wiederholungen des Kontos fallen also nicht darunter.
3. Signatur mit `hash_equals` vergleichen.
4. Zusätzlich `event_id` merken (z. B. 7 Tage) und bereits verarbeitete Ereignisse mit `2xx` quittieren, ohne sie erneut anzuwenden.

```php
use Illuminate\Http\Request;

public function __invoke(Request $request)
{
    $body = $request->getContent();
    $timestamp = (string) $request->header('X-Konto-Timestamp');
    $signature = (string) $request->header('X-Konto-Signature');
    $secret = (string) config('households.konto.secret');

    $fresh = ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300;
    $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    if (! $fresh || ! hash_equals($expected, $signature)) {
        abort(401);
    }

    $event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    // … idempotent anwenden (Abschnitt 5), z. B. per Job
    return response()->noContent();
}
```

Die Route braucht keine Session und keinen CSRF-Schutz (z. B. in `routes/api.php`). Die Referenzimplementierung im Konto: `App\Webhooks\WebhookSignature::verify()`.

### 4.4 Format

```json
{
  "event_id": "5b1f0a3e-2c4d-4e6f-8a9b-0c1d2e3f4a5b",
  "type": "household.member_added",
  "occurred_at": "2026-11-07T08:15:42.123Z",
  "data": {
    "household": {
      "uuid": "019a5e3c-6f1d-7b2a-9c43-0d8e5f6a7b8c",
      "name": "Familie Muster",
      "version": 8,
      "members": [
        { "sub": "4f0c2b9e-…", "role": "admin" },
        { "sub": "a81d7c3f-…", "role": "member" }
      ]
    },
    "member": { "sub": "a81d7c3f-…", "role": "member" }
  }
}
```

`occurred_at` ist UTC (ISO 8601 mit Millisekunden), `data.household` immer der Snapshot **nach** der Änderung.

### 4.5 Ereignisse

| Typ | Auslöser | `data` |
|---|---|---|
| `household.created` | Haushalt erstellt (Portal oder bestätigter Import) | `household` |
| `household.updated` | Name geändert; Import in bestehenden Haushalt zusammengeführt; Mitglied ohne Identität kam dazu oder ging | `household` |
| `household.deleted` | Haushalt gelöscht (von einem Admin, oder das letzte Mitglied wurde gelöscht) | `household` mit `members: []`, `deleted: true` |
| `household.member_added` | Einladung angenommen | `household`, `member` (neues Mitglied mit Rolle) |
| `household.member_updated` | Rolle geändert | `household`, `member` (mit neuer Rolle) |
| `household.member_removed` | Mitglied entfernt, ausgetreten oder sein Konto gelöscht | `household`, `member` (entferntes Mitglied mit bisheriger Rolle) |

Hinweise:

- **Rollenwechsel** ist ein eigenes Ereignis `household.member_updated` (statt erneut `member_added`), damit Logs und Benachrichtigungen in den Apps eindeutig sind. Für den Abgleich spielt der Typ keine Rolle (Abschnitt 5).
- **Konto gelöscht:** Das Konto entfernt alle Mitgliedschaften des Users (je ein `household.member_removed`). Bleibt ein Haushalt ohne Admin, wird das am längsten beigetretene Mitglied Admin (im selben Ereignis, im Snapshot sichtbar). Ein Haushalt ohne Mitglieder wird gelöscht (`household.deleted`). Das allgemeine Ereignis `user.deleted` (KONZEPT 11) folgt mit den Webhooks für Freischaltungen.
- **Unbekannte Typen** (spätere Erweiterungen) mit `2xx` quittieren; enthalten sie `data.household`, wie `household.updated` behandeln.

## 5. Reihenfolge und Idempotenz

Die App speichert pro gespiegeltem Haushalt `konto_household_uuid` und `konto_version` und wendet Snapshots so an:

1. **Snapshot statt Delta:** Bei jedem Ereignis (Typ egal) und jeder API-Antwort wird der lokale Haushalt auf den Snapshot gesetzt: Name übernehmen, Mitgliedschaften für die `sub`s im Snapshot anlegen bzw. Rolle setzen, alle anderen Mitgliedschaften entfernen. Unbekannte `sub`s (User hat die App noch nie benutzt) merkt sich die App (z. B. Tabelle `household_pending_members` oder JSON-Spalte) und ordnet sie beim ersten Login zu; alternativ ruft sie beim Login 3.2 auf.
2. **Veraltete ignorieren:** Ist `version` des Snapshots **kleiner oder gleich** `konto_version`, wird das Ereignis ignoriert (trotzdem `2xx`). Sonst anwenden und `konto_version` setzen.
3. **Löschen:** `household.deleted` (bzw. `404` bei 3.3) → lokalen Haushalt löschen; Freigaben fallen weg, Inhalte bleiben beim Eigentümer. Wegen der fehlenden Reihenfolge kann danach noch ein älteres Ereignis dieses Haushalts eintreffen.
4. **Unbekannte Haushalte:** Kommt ein Ereignis (ausser `household.deleted`) für eine unbekannte `uuid`, holt die App den Stand mit 3.3 und wendet die API-Antwort an; bei `404` wird nichts angelegt. So entstehen keine Haushalte aus veralteten Ereignissen nach einer Löschung. (Optional: Grabstein mit `uuid` und letzter Version statt des API-Aufrufs.)
5. **API-Antworten** (3.2, 3.3) sind massgebend und werden angewendet, wenn ihre `version` grösser oder gleich `konto_version` ist.
6. **Periodischer Abgleich** (z. B. täglich): 3.2 für alle User mit `oidc_sub`, gespiegelte Haushalte ohne Treffer mit 3.3 prüfen. Damit heilen verlorene Webhooks und Haushalte, die den Dienst nicht mehr betreffen.
7. **Nach dem Anwenden** die app-spezifischen Folgen auslösen, z. B. in den PWAs die betroffenen Listen für alle (auch entfernte) Mitglieder neu synchronisieren (`syncHouseholdMembers()`/`touchForMembers()`).

## 6. Verhalten bei Ausfall des Kontos

- Die App arbeitet mit dem letzten gespiegelten Stand weiter; Haushalts-Freigaben gelten weiter.
- Webhooks, die das Konto nicht zustellen konnte, wiederholt es (Abschnitt 4.1); was danach fehlt, holt der periodische Abgleich.
- Rückbau (`HOUSEHOLDS_DRIVER=local`): Die gespiegelten Haushalte werden zu lokalen Haushalten (`konto_household_uuid` darf bleiben), Freigaben bleiben erhalten.

## 7. Checkliste für eine neue App

- [ ] Tabellen `households` (mit `konto_household_uuid`, `konto_version`), `household_user`, Freigabe-Tabelle pro Inhalt.
- [ ] `.env`: `HOUSEHOLDS_DRIVER=konto`, `KONTO_URL`, `KONTO_SERVICE_KEY`, `KONTO_SECRET`; im Konto Webhook-URL und Secret gesetzt.
- [ ] Webhook-Route mit Signaturprüfung (4.3), Verarbeitung nach Abschnitt 5, Antwort `2xx`.
- [ ] Beim Login (OIDC) 3.2 aufrufen und anwenden.
- [ ] Täglicher Abgleich (Scheduler).
- [ ] Optional: Freigaben per 3.4 melden.
- [ ] Umstieg: lokale Haushalte per 3.5 vorschlagen, Stand abfragen, `konto_household_uuid` speichern.
- [ ] Tests: Signatur (gültig, falsch, abgelaufen), veraltete Version, unbekannter Haushalt mit `404`, Löschen, Entfernen eines Mitglieds (Zugriff weg, Offline-Sync).
