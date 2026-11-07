# Freischaltungen: Schnittstelle für die Apps

Stand: 2026-11-07 · Gilt für `ENTITLEMENTS_DRIVER=account-manager` und `AUTH_ADMIN_SOURCE=account-manager` in RecipeManager, Shoppinglist, Chorelist und jeder weiteren App. Hintergrund: `docs/KONZEPT.md`, Abschnitte 5.3, 5.4 und 6. Webhooks und Authentifizierung sind dieselben wie bei den Haushalten (`docs/HOUSEHOLDS-API.md`).

Das Konto (AccountManager) ist die Quelle der Wahrheit dafür, was ein User in einer App darf: Zugangsstatus, Admin-Recht und freigeschaltete Funktionen (z. B. `shoppinglist.ai`). Die App speichert den letzten Stand lokal und prüft ihn bei jeder geschützten Aktion. Ohne Konto gilt in der App die lokale Konfiguration (`ENTITLEMENTS_DRIVER=local`, `AUTH_ADMIN_SOURCE=local`).

## 1. Einrichtung

Im Admin-Bereich des Kontos:

1. **Dienste → Dienst bearbeiten:** Webhook-URL und Secret wie in `HOUSEHOLDS-API.md`, Abschnitt 1 (eine URL und ein Secret pro Dienst für alle Ereignisse). Optional **Admin-Gruppe** (z. B. `svc-recipes-admin`), falls die App das Admin-Recht aus dem `groups`-Claim liest.
2. **Dienste → Funktionen:** Funktionen anlegen. Der **Schlüssel** geht an die App und bleibt stabil (Konvention `<dienst>.<name>`, z. B. `shoppinglist.ai`; erlaubt sind Kleinbuchstaben, Ziffern und `.`, `-`, `_` als Trenner). Optional eine **Gruppe** (z. B. `feat-shoppinglist-ai`), die User mit der Funktion beim Anmeldedienst erhalten.
3. **Pläne:** Plan mit Funktionen eines Dienstes oder als Bündel über mehrere Dienste. Preisfelder sind für die spätere Abrechnung vorgesehen; Rechnungen gibt es noch nicht.
4. **User → User bearbeiten:**
   - **Dienste:** Zugang und «Admin im Dienst».
   - **Pläne:** Plan zuweisen (Beginn, optional Ende), beenden, sperren oder reaktivieren (mit E-Mail an den User).
   - **Direkt freigeschaltete Funktionen:** einzelne Funktion ohne Plan freischalten oder entziehen.
   - Abschnitt **Wirksame Freischaltungen:** was die Apps gerade erhalten.

In der App (gleiche Variablen wie bei den Haushalten):

| Variable | Inhalt |
|---|---|
| `ENTITLEMENTS_DRIVER` | `account-manager` |
| `AUTH_ADMIN_SOURCE` | `account-manager`, wenn das Admin-Recht aus dem Konto kommen soll |
| `ACCOUNT_MANAGER_URL` | Basis-URL des Kontos, z. B. `https://konto.example.com` |
| `ACCOUNT_MANAGER_SERVICE_KEY` | Schlüssel des Dienstes im Konto, z. B. `shoppinglist` |
| `ACCOUNT_MANAGER_SECRET` | Secret des Dienstes (64 Hex-Zeichen) |

## 2. Regeln im Konto

Für einen User und einen Dienst gilt:

- **Funktionen** = Funktionen dieses Dienstes aus allen **wirksamen Abos** plus die **direkt freigeschalteten** Funktionen, jeder Schlüssel einmal, alphabetisch sortiert.
  - Ein Abo ist wirksam, wenn sein Status `active` ist, `starts_at` erreicht und `ends_at` leer oder noch nicht erreicht ist. Gesperrte (`suspended`) und beendete (`ended`) Abos zählen nicht.
  - Ein Bündel-Plan schaltet in jedem Dienst nur dessen eigene Funktionen frei.
- **Admin** = Zugang ist aktiv **und** im Konto als «Admin im Dienst» markiert. Unabhängig von den Funktionen.
- **Nur mit aktivem Zugang:** Ist der Zugang `requested` oder `suspended` (oder fehlt er), sind `features` leer und `admin` ist `false`, auch wenn Abos oder direkte Freischaltungen bestehen.
- **Gruppen beim Anmeldedienst** (für Apps, die den Token lesen): Aktive Zugänge erhalten die Zugangsgruppe (`access_group`), Admins zusätzlich die Admin-Gruppe (`admin_group`), dazu die Gruppen der wirksamen Funktionen. Das Konto verwaltet nur diese Gruppen; andere Gruppen eines Users bleiben unberührt. Gruppen im Token gelten erst ab dem nächsten Login; massgebend für laufende Sitzungen sind Webhook und API.
- **Lokale User ohne Identität** (ohne `oidc_sub`, z. B. Notfall-Admins) kennt die App nicht über das Konto: Für sie gibt es weder Ereignisse noch API-Antworten. Sie erhalten in der App die lokal konfigurierten Standardrechte; lokale Notfall-Admins bleiben immer Admin.

## 3. Datenmodell aus Sicht der App («Snapshot»)

Jede Antwort und jedes Ereignis enthält den vollständigen aktuellen Stand eines Users für **den aufrufenden bzw. empfangenden Dienst**:

```json
{
  "sub": "4f0c2b9e-7d1a-4c55-9a0e-2b6f8d3c1a77",
  "service": "shoppinglist",
  "status": "active",
  "admin": false,
  "features": ["shoppinglist.ai"],
  "version": 7
}
```

| Feld | Bedeutung |
|---|---|
| `sub` | `oidc_sub` des Users (User-ID beim Anmeldedienst). Die App ordnet darüber ihren lokalen User zu. |
| `service` | Schlüssel des Dienstes im Konto (zur Kontrolle; immer der eigene). |
| `status` | Zugang im Konto: `active`, `requested` (beantragt, noch nicht freigegeben), `suspended` (gesperrt) oder `none` (kein Zugang mehr, z. B. vom Admin entfernt). |
| `admin` | `true`, wenn der User in der App Admin sein soll (nur bei `status = active`). |
| `features` | Schlüssel der freigeschalteten Funktionen (nur bei `status = active`, sonst `[]`). Unbekannte Schlüssel ignoriert die App. |
| `version` | Ganzzahl pro User **und** Dienst, steigt bei jeder Änderung des Snapshots um 1. Grundlage für Reihenfolge und Idempotenz (Abschnitt 6). |

**Datenschutz:** Mehr verlässt das Konto nicht, keine Namen, E-Mail-Adressen, Pläne, Preise, Notizen oder Sperrgründe.

## 4. API (App → Konto)

Basis und Authentifizierung wie bei den Haushalten (`HOUSEHOLDS-API.md`, Abschnitt 3.1): HTTP Basic mit Schlüssel und Secret des Dienstes, `401` mit `WWW-Authenticate: Basic realm="AccountManager"` bei falschen Angaben, Rate-Limit 120 Anfragen pro Minute und IP.

### 4.1 `GET /api/entitlements/{sub}`

Der aktuelle Snapshot eines Users für den aufrufenden Dienst. Aufruf beim (OIDC-)Login, wenn ein Ereignis fehlt oder unklar ist, und beim periodischen Abgleich.

```http
GET /api/entitlements/4f0c2b9e-7d1a-4c55-9a0e-2b6f8d3c1a77 HTTP/1.1
Authorization: Basic base64("shoppinglist:<ACCOUNT_MANAGER_SECRET>")
Accept: application/json
```

`200`:

```json
{
  "data": {
    "sub": "4f0c2b9e-7d1a-4c55-9a0e-2b6f8d3c1a77",
    "service": "shoppinglist",
    "status": "active",
    "admin": false,
    "features": ["shoppinglist.ai"],
    "version": 7
  }
}
```

Gesperrter Zugang (`200`, nur Status):

```json
{ "data": { "sub": "4f0c…", "service": "shoppinglist", "status": "suspended", "admin": false, "features": [], "version": 8 } }
```

- `404` (`{"message": "Not found."}`): `sub` unbekannt **oder** der User hat bei diesem Dienst keinen Zugang (mehr). Beides sieht gleich aus, damit kein Dienst erfährt, wer sonst im Konto ist. Die App behandelt `404` wie `status = none`: keine Funktionen, kein Admin-Recht; den User und seine Inhalte **nicht** löschen (dafür gibt es `user.deleted`).
- Das Konto rechnet vor der Antwort neu. Ist z. B. ein Abo seit dem letzten Lauf des Schedulers abgelaufen, enthält die Antwort schon den neuen Stand mit neuer Version (und der Webhook dazu geht ebenfalls raus).
- `Laravel`: `Http::withBasicAuth($key, $secret)->acceptJson()->get("{$url}/api/entitlements/".rawurlencode($sub))`.

## 5. Webhooks (Konto → App)

Zustellung, Header, Signatur und Wiederholungen wie bei den Haushalten (`HOUSEHOLDS-API.md`, Abschnitt 4): `POST` an die Webhook-URL des Dienstes, `X-Account-Manager-Event`, `-Event-Id`, `-Timestamp`, `-Signature` (`sha256=` + HMAC-SHA256 über `"{timestamp}.{body}"`), Toleranz 300 s, 8 Versuche über rund 10,5 h, Protokoll im Konto unter **Webhooks**. Reihenfolge ist **nicht** garantiert.

Gemeinsames Format:

```json
{
  "event_id": "5b1f0a3e-2c4d-4e6f-8a9b-0c1d2e3f4a5b",
  "type": "entitlements.changed",
  "occurred_at": "2026-11-07T08:15:42.123Z",
  "data": { … }
}
```

### 5.1 `entitlements.changed`

```json
{
  "event_id": "5b1f0a3e-2c4d-4e6f-8a9b-0c1d2e3f4a5b",
  "type": "entitlements.changed",
  "occurred_at": "2026-11-07T08:15:42.123Z",
  "data": {
    "entitlements": {
      "sub": "4f0c2b9e-7d1a-4c55-9a0e-2b6f8d3c1a77",
      "service": "shoppinglist",
      "status": "active",
      "admin": false,
      "features": ["shoppinglist.ai"],
      "version": 7
    }
  }
}
```

- **Empfänger:** nur der betroffene Dienst (ein Ereignis pro Dienst, jedes mit eigener `event_id`), und nur, wenn der User dort einen Zugang hat oder hatte und eine Identität (`oidc_sub`) besitzt.
- **Auslöser:** jede Änderung, die den Snapshot dieses Dienstes ändert, u. a.
  - Zugang erstellt, beantragt, freigegeben, gesperrt, reaktiviert, gelöscht (`status: "none"`), «Admin im Dienst» geändert;
  - Abo zugewiesen, geändert, gesperrt, reaktiviert, beendet oder abgelaufen; Abo beginnt (Scheduler, siehe unten);
  - Funktion direkt freigeschaltet oder entzogen;
  - Funktionen eines Plans geändert, Funktion umbenannt (neuer Schlüssel) oder gelöscht;
  - Identität verknüpft (`oidc_sub` gesetzt): erster Snapshot für die App.
- **Nichts** wird gesendet, wenn sich der Snapshot nicht ändert (z. B. Notiz geändert, Abo eines gesperrten Zugangs zugewiesen). Die Version steigt nur mit einer tatsächlichen Änderung.
- **Zeitgesteuert:** `entitlements:refresh` läuft alle 15 Minuten, beendet Abos nach `ends_at` und meldet Abos, deren `starts_at` erreicht ist. Zwischen zwei Läufen liefert die API bereits den aktuellen Stand.

### 5.2 `user.deleted`

```json
{
  "event_id": "9d2c6e1b-0a4f-4b3e-8c7d-6e5f4a3b2c1d",
  "type": "user.deleted",
  "occurred_at": "2026-11-07T09:00:00.000Z",
  "data": { "user": { "sub": "4f0c2b9e-7d1a-4c55-9a0e-2b6f8d3c1a77" } }
}
```

- **Auslöser:** Ein Admin löscht den User im Konto (Entscheid KONZEPT 11, «Account löschen»).
- **Empfänger:** jeder Dienst mit Webhook, in dem der User einen Zugang hat (beliebiger Status) oder früher einen hatte; ein Ereignis mit derselben `event_id` für alle. User ohne Identität lösen nichts aus.
- **Vorher** verlässt der User alle Haushalte (`household.member_removed` bzw. `household.deleted`, siehe `HOUSEHOLDS-API.md` 4.5); diese Ereignisse kommen unabhängig von `user.deleted` an.
- **Was die App tut:**
  1. Lokalen User mit dieser `sub` suchen; unbekannt → mit `2xx` quittieren, nichts tun.
  2. **Private Inhalte löschen** (nur dem User gehörend und mit niemandem geteilt), z. B. eigene Rezepte und Kochbücher ohne Freigaben, eigene Listen ohne weitere Mitglieder, Tokens und Sitzungen, Verknüpfungen (Passport-Clients/-Tokens), hochgeladene Dateien.
  3. **Geteilte Inhalte** je nach App-Einstellung an einen anderen Admin des Haushalts bzw. ein anderes Mitglied übertragen oder löschen; Einzelfreigaben an den User entfernen.
  4. Den lokalen User löschen. Ein lokaler Notfall-Admin (ohne `sub`) ist nie betroffen.
  5. Idempotent: Ein erneut zugestelltes `user.deleted` findet keinen User mehr und wird quittiert.
- Den Zugang zu einem Dienst entfernen ist **kein** `user.deleted`, sondern `entitlements.changed` mit `status: "none"`: Die App sperrt den User, löscht aber keine Daten.

### 5.3 Unbekannte Typen

Spätere Ereignistypen mit `2xx` quittieren und ignorieren.

## 6. Reihenfolge und Idempotenz

Die App speichert pro lokalem User (mit `oidc_sub`) die zuletzt angewendeten Werte: `account_manager_status`, `account_manager_admin`, `account_manager_features` (JSON), `account_manager_entitlements_version`, optional `account_manager_entitlements_synced_at`.

1. **Snapshot statt Delta:** Jedes `entitlements.changed` und jede API-Antwort ersetzt den gespeicherten Stand vollständig.
2. **Veraltete ignorieren:** Ist `version` **kleiner oder gleich** der gespeicherten Version, wird das Ereignis ignoriert (trotzdem `2xx`). API-Antworten werden angewendet, wenn `version` **grösser oder gleich** ist.
3. **Versionen laufen weiter:** Wird ein Zugang gelöscht und später neu erstellt, setzt das Konto die Version fort; eine App sieht für denselben User nie wieder eine kleinere Nummer.
4. **`404` der API** = kein Zugang: Status `none` setzen (Funktionen leer, kein Admin), Version unverändert lassen.
5. **Unbekannte `sub`** im Ereignis (User hat die App noch nie benutzt): Snapshot merken (z. B. Tabelle `account_manager_pending_entitlements`) und beim ersten Login anwenden, oder beim Login einfach 4.1 aufrufen.
6. **`event_id`** wie bei den Haushalten einige Tage merken und Doppelte ohne erneute Verarbeitung quittieren.
7. **Nach dem Anwenden** laufende Sitzungen prüfen: Bei `status != active` die Sitzungen des Users beenden bzw. die App sperren; bei entzogener Funktion die Funktion sofort ausblenden (in PWAs beim nächsten Sync).

**Prüfung in der App** (Empfehlung):

- Zugriff auf die App: `status === 'active'` (zusätzlich zum `groups`-Claim beim Login).
- Funktion: `in_array('shoppinglist.ai', $features, true)`.
- Admin mit `AUTH_ADMIN_SOURCE=account-manager`: `admin === true`; lokale Notfall-Admins bleiben Admin. Ohne gespeicherten Stand (noch nie abgeglichen) gilt: kein Admin, keine kostenpflichtigen Funktionen.

## 7. Verhalten bei Ausfall des Kontos

- Die App arbeitet mit dem **letzten gespeicherten Stand** weiter (Funktionen, Admin-Recht, Status). Ein Ausfall sperrt niemanden aus und schaltet nichts frei.
- Webhooks, die das Konto nicht zustellen konnte, wiederholt es (Abschnitt 5); was danach fehlt, holt der Abgleich.
- **Täglicher Abgleich empfohlen:** 4.1 für alle lokalen User mit `oidc_sub` (bei vielen Usern verteilt oder nur für in den letzten Tagen aktive). Fehler (Timeout, `5xx`) ändern nichts am gespeicherten Stand; `404` setzt `status = none`.
- Rückbau (`ENTITLEMENTS_DRIVER=local`, `AUTH_ADMIN_SOURCE=local`): Es gilt wieder die lokale Konfiguration; die gespeicherten Werte dürfen bleiben.

## 8. Checkliste für eine App

- [ ] Spalten bzw. Tabelle für den gespeicherten Stand (Abschnitt 6).
- [ ] `.env`: `ENTITLEMENTS_DRIVER=account-manager`, optional `AUTH_ADMIN_SOURCE=account-manager`, `ACCOUNT_MANAGER_URL`, `ACCOUNT_MANAGER_SERVICE_KEY`, `ACCOUNT_MANAGER_SECRET`; im Konto Webhook-URL, Secret, Funktionen und ggf. Admin-Gruppe gesetzt.
- [ ] Webhook-Route (dieselbe wie für die Haushalte) verarbeitet `entitlements.changed` und `user.deleted`, Signaturprüfung nach `HOUSEHOLDS-API.md` 4.3, Antwort `2xx`.
- [ ] Beim OIDC-Login 4.1 aufrufen und anwenden.
- [ ] Täglicher Abgleich (Scheduler).
- [ ] Prüfung bei jeder geschützten Aktion aus dem gespeicherten Stand (nicht nur aus dem Token).
- [ ] `user.deleted`: private Inhalte löschen, geteilte nach App-Einstellung übertragen oder löschen, User löschen.
- [ ] Tests: Signatur, veraltete Version, `status` `suspended`/`none`, Admin-Flag mit beiden `AUTH_ADMIN_SOURCE`, `404` der API, `user.deleted` (auch doppelt und für unbekannte `sub`), Verhalten bei nicht erreichbarem Konto.
