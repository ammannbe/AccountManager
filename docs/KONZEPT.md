# Konzept: AccountManager (SSO, Dienste, Abos, Haushalte)

Stand: 2026-11-07 · Status: Phasen 1–5 umgesetzt (Phase 4 als MVP); Phase 7 und Phase 8 (Konto-Teil) auf Branches `feature/households`

**Namen:** Die zentrale App heisst **AccountManager** (Repo und Projektordner), für User erscheint sie als «Konto» unter `konto.<domain>`. Pocket ID läuft unter `login.<domain>`. Im Text steht «Konto» für den AccountManager.

## 1. Ziele

- Ein Login für alle eigenen Dienste (RecipeManager, Shoppinglist, später Nextcloud, Forgejo und weitere).
- Ein zentraler Ort («Konto»), an dem User ihre Dienste, Abos und Rechnungen sehen und Admins Dienste registrieren, Zugänge und Abos verwalten.
- Abrechnung kostenpflichtiger Funktionen (z. B. KI in der Shoppinglist) per Swiss QR-Bill.
- **Alles optional:** Jede App muss weiterhin allein und ohne SSO betreibbar sein, auch von anderen Hostern. Lokale Logins bleiben möglich.
- **Rückbaubar:** SSO und Konto lassen sich wieder entfernen, ohne Datenverlust in den Apps.

## 2. Nicht-Ziele (vorerst)

- Kein selbst gebauter Login-Dienst. Die Identität übernimmt Pocket ID.
- Keine automatische Zahlungsabwicklung (Kreditkarte, TWINT) in der ersten Version.
- Keine zentrale Datenhaltung von App-Inhalten. Rezepte, Listen usw. bleiben in den Apps; zentral sind nur Haushalte und ihre Mitglieder (siehe 5.6).

## 3. Architektur

```
                 ┌────────────────────────┐
                 │  Pocket ID (OIDC)      │  Identität, Passkeys, Gruppen, Claims
                 └───────▲───────▲────────┘
          OIDC-Login     │       │  Admin-API (User, Gruppen, Claims)
   ┌─────────────────────┤       │
   │                     │  ┌────┴──────────────────┐
   │                     │  │  Konto (Laravel)      │  Source of Truth für
   │                     │  │  Dienste, Abos,       │  Dienste, Zugänge, Abos,
   │                     │  │  Rechnungen           │  Freischaltungen
   │                     │  └────┬─────────┬────────┘
   │                     │       │ Webhooks│ Konnektoren
┌──┴───────────┐ ┌───────┴─────┐ │         ▼
│ RecipeManager│◄┤ Shoppinglist│◄┘   Externe Dienste (Emby …)
│ (Passport-API)│ │             │
└──────────────┘ └─────────────┘   Nextcloud, Forgejo: OIDC via Pocket ID
     ▲  «Mit RecipeManager verbinden» (OAuth, Passport) bleibt
```

### Rollen der Bausteine

| Baustein | Aufgabe | Pflicht? |
|---|---|---|
| **Pocket ID** | Login (Passkeys, E-Mail-Login-Code), Profilbasis, Gruppen, Custom Claims, pro OIDC-Client erlaubte Gruppen | Nein, nur für SSO |
| **Konto** (neue Laravel/Filament-App) | Dienstekatalog, Zugänge, Selbstregistrierung pro Dienst, Pläne und Abos, Freischaltungen, Rechnungen mit QR-Bill, Self-Service für User | Nein |
| **Apps** | Eigene User-Tabelle bleibt; lokaler Login und/oder OIDC-Login; Freischaltungen lokal oder vom Konto | — |
| **Passport im RecipeManager** | Bleibt für den API-Zugriff von App zu App, unabhängig vom Login | Ja, wie heute |

### Grundsatz: Login und App-zu-App-Zugriff trennen

- **Login (wer bin ich):** OIDC, generisch umgesetzt (beliebiger Anbieter über `.env`), nicht fest an Pocket ID gebunden.
- **App-zu-App (was darf die Shoppinglist im RecipeManager):** bleibt OAuth mit Passport. Nur so funktionieren Installationen verschiedener Hoster miteinander.
- Nutzen beide Apps denselben OIDC-Anbieter, läuft «Mit RecipeManager verbinden» ohne Eingabe und ohne Zustimmungsseite durch (der User ist im RecipeManager bereits per SSO angemeldet; der Client ist als vertrauenswürdig markiert).

## 4. Pocket ID

- Betrieb als Docker-Container mit SQLite oder PostgreSQL, eigene Subdomain `login.<domain>`. Passkeys sind an diese Domain gebunden, sie darf sich später nicht mehr ändern.
- **Selbstregistrierung in Pocket ID: aus.** Accounts entstehen nur über das Konto (API `POST /api/users`) oder von Hand. So entscheidet das Konto pro Dienst, wer sich registrieren darf.
- **Gruppen pro Dienst:** z. B. `svc-recipes`, `svc-shoppinglist`, `svc-nextcloud`, `svc-forgejo`. Pro OIDC-Client wird festgelegt, welche Gruppen sich anmelden dürfen. Damit bekommt nur Zugang, wer im Konto für den Dienst freigeschaltet ist.
- **Funktions-Gruppen** für Freischaltungen: z. B. `feat-shoppinglist-ai`. Apps lesen sie aus dem `groups`-Claim.
- **Custom Claims** nur für wenige, stabile Werte (z. B. `locale`). Profildaten wie Geburtstag liegen im Konto.
- **Weitere Funktionen nutzen (zu prüfen):** Pocket ID kann User und Gruppen per SCIM an Apps übertragen und unterstützt Back-Channel-Logout. SCIM könnte einen Teil der eigenen Synchronisation ersetzen, Back-Channel-Logout ein zentrales Abmelden ermöglichen.
- **Notfallzugang:** Jede App behält mindestens einen lokalen Admin-Login, damit ein Ausfall von Pocket ID nicht aussperrt.

## 5. AccountManager («Konto», zentrale App)

Laravel 13, Filament 5, DDEV, MariaDB, wie die anderen Apps. Öffentliches Repo auf GitHub, generisch für andere Hoster (Domain, Gläubiger, Preise, Texte über Konfiguration). Mehrsprachig (de/en) wie die Apps. Login für User und Admins über Pocket ID, dazu ein lokaler Notfall-Admin.

Anders als die Apps braucht der AccountManager einen Identitätsanbieter mit Admin-API, weil er User und Gruppen anlegt. Das wird als Schnittstelle (`IdentityProvider`: User anlegen, Gruppen setzen, Login-Code senden, User sperren/löschen) gebaut; umgesetzt wird zuerst nur Pocket ID.

### 5.1 Datenmodell (Entwurf)

| Tabelle | Zweck | Wichtige Felder |
|---|---|---|
| `users` | Spiegel der Pocket-ID-User plus Profil | `oidc_sub`, `email`, `name`, `birthday` (optional), `address` (für Rechnungen), `locale` |
| `services` | Dienstekatalog | `key` (z. B. `recipes`), `name`, `url`, `type` (`oidc`, `external`), `pocket_client_id`, `access_group`, `admin_group` (optional), `self_signup` (bool), `signup_requires_approval` (bool), `connector` (z. B. `emby`, `manual`), `mailer_id` (optional), `visible` |
| `mailers` | E-Mail-Versand pro Dienst | `name`, SMTP-Host, Port, Benutzer, Passwort (verschlüsselt), Absender; ohne Zuordnung gilt der Standard aus `.env` |
| `service_accounts` | Zugang eines Users zu einem Dienst | `user_id`, `service_id`, `status` (`requested`, `active`, `suspended`), `external_id`, `external_username`, `notes` |
| `features` | Freischaltbare Funktionen | `service_id`, `key` (z. B. `shoppinglist.ai`), `name`, `group` (z. B. `feat-shoppinglist-ai`) |
| `plans` | Angebote | `name`, `price_cents`, `currency`, `tax_rate_id`, `prices_include_tax` (bool), `interval` (`month`, `year`, `once`), `active` |
| `tax_rates` | MWST-Sätze (konfigurierbar, auch «keine MWST») | `name`, `rate`, `active` |
| `plan_features` | Was ein Plan freischaltet | `plan_id`, `feature_id` bzw. `service_id` |
| `subscriptions` | Abo eines Users | `user_id`, `plan_id`, `status` (`active`, `past_due`, `cancelled`), `starts_at`, `ends_at`, `cancelled_at` |
| `invoices`, `invoice_items` | Rechnungen | `number`, `user_id`, `currency`, `net_cents`, `tax_cents`, `total_cents`, `due_at`, `status` (`open`, `paid`, `overdue`, `void`), `qr_reference`, `paid_at`; Positionen mit Satz und Betrag zum Zeitpunkt der Rechnung (Snapshot) |
| `suspensions` | Sperren von Zugängen oder Funktionen | `user_id`, `service_account_id` bzw. `feature_id`, `reason_id`, `custom_reason`, `suspended_by`, `suspended_at`, `lifted_at` |
| `suspension_reasons` | Vordefinierte Begründungen (mehrsprachig) | `key`, `text` (z. B. «Offene Rechnung»), `active` |
| `households` | Haushalte | `uuid`, `name`, `created_by`, `version` (steigt bei jeder Änderung) |
| `household_members` | Mitglieder | `household_id`, `user_id`, `role` (`admin`, `member`), `joined_at` |
| `household_invitations` | Einladungen per E-Mail | `household_id`, `email`, `token_hash`, `role`, `invited_by`, `expires_at`, `accepted_at` |
| `household_imports` | Vorschläge der Apps beim Umstieg lokal → Konto | `service_id`, `local_id`, `name`, `members` (JSON: `sub`, `role`), `status` (`pending`, `confirmed`, `rejected`), `household_id`, `resolved_by` |
| `household_shares` (optional) | Übersicht, was in welcher App geteilt ist (von den Apps gemeldet) | `household_id`, `service_id`, `resource_type`, `resource_id`, `label` |
| `webhooks` | Benachrichtigung der Apps | umgesetzt als Felder von `services`: `webhook_url`, `secret` (verschlüsselt), `secret_created_at` |
| `settings` | Gläubiger und Abrechnung | QR-IBAN bzw. IBAN, Name, Adresse, MWST-Nummer, Standardwährung, Zahlungsfrist, Admin-E-Mail für Benachrichtigungen |
| `webhook_deliveries` | Zustellprotokoll | `service_id`, `event_id`, `event`, `payload` (signierter Body), `attempts`, `status` (`pending`, `delivered`, `failed`), `response_status`, `last_error`, `delivered_at` |
| Audit-Log | Wer hat wann was freigeschaltet | |

### 5.2 Selbstregistrierung pro Dienst

- `services.self_signup = true` (z. B. RecipeManager): Öffentliche Seite «Für RecipeManager registrieren». Zuerst bestätigt der User seine E-Mail über einen Link (Double-Opt-in im Konto). Erst danach legt das Konto den Pocket-ID-User an (falls neu), fügt ihn der Gruppe `svc-recipes` hinzu und schickt einen Login-Code zum Einrichten des Passkeys. So landen keine Bot-Accounts in Pocket ID. Schutz: Honeypot, Rate-Limit, unbestätigte Registrierungen nach 7 Tagen löschen.
- **Lokale Registrierung in angebundenen Apps:** Ist eine App am Konto angebunden, führt «Registrieren» in der App zur Registrierungsseite des Kontos (bzw. ist ausgeblendet). Sonst entstehen doppelte Identitäten. Lokale Registrierung bleibt nur für eigenständig betriebene Apps.
- `self_signup = false` (z. B. Nextcloud): User können den Zugang nur beantragen (`status = requested`), oder ein Admin erteilt ihn direkt.
- Bestehende Konto-User sehen im Self-Service alle sichtbaren Dienste mit «Zugang aktivieren» bzw. «Zugang beantragen».

### 5.3 Freischaltungen (Source of Truth: Konto)

1. Änderung im Konto (Zugang, Abo aktiv/beendet, Rechnung überfällig).
2. Das Konto berechnet die Gruppen des Users neu und setzt sie per API in Pocket ID.
3. Das Konto schickt einen signierten Webhook (`entitlements.changed`, HMAC) an die betroffenen Apps.
4. Apps übernehmen die Gruppen beim nächsten Login aus dem Token. Per Webhook bzw. API `GET /api/entitlements/{sub}` (Client-Credentials: HTTP Basic mit Schlüssel und Secret des Dienstes, wie bei den Haushalten) aktualisieren sie sofort, z. B. um eine laufende Sitzung zu sperren.

Ohne Konto gilt in jeder App die lokale Konfiguration (`ENTITLEMENTS_DRIVER=local`, z. B. KI für alle an oder aus).

Regeln für `ENTITLEMENTS_DRIVER=konto`:

- Apps speichern den letzten Stand lokal. Ist das Konto nicht erreichbar, gilt dieser Stand weiter; die Apps funktionieren also auch bei einem Ausfall des Kontos.
- Zusätzlich gleichen die Apps periodisch ab (z. B. täglich), falls ein Webhook verloren ging.
- Lokale User ohne `oidc_sub` (z. B. der Notfall-Admin) erhalten die lokal konfigurierten Standardrechte.

### 5.4 Abos und Rechnungen

- Pläne pro Dienst oder Funktion, z. B. «Shoppinglist KI» CHF x/Monat oder ein Bündel.
- Rechnungslauf per Scheduler: erzeugt Rechnungen mit Swiss QR-Bill (QR-Referenz bei QR-IBAN, sonst SCOR-Referenz). Kandidat: `sprain/php-swiss-qr-bill`, dazu ein PDF-Generator (Paketwahl offen, Installation nur nach Freigabe).
- Zahlungen: anfangs von Hand als bezahlt markieren. Später Import der Bankgutschriften (camt.054) mit Abgleich über die Referenz.
- Preise, MWST-Sätze und Währung sind konfigurierbar. Einschränkung: Die Swiss QR-Bill erlaubt nur CHF und EUR; für andere Währungen gibt es eine Rechnung ohne QR-Teil.
- **Zahlungsverzug (von Hand):** Nach Ablauf der Zahlungsfrist wird die Rechnung `overdue` und der Admin erhält eine Benachrichtigung (E-Mail und Liste im Admin-Bereich). Der Admin entscheidet: Erinnerung senden, Zugang oder Funktion sperren oder nichts tun. Beim Sperren wählt er eine vordefinierte Begründung oder schreibt eine eigene; der User erhält eine E-Mail mit dieser Begründung. Entsperren läuft genauso (mit E-Mail). Nichts wird automatisch gesperrt.
- Rechnungen und Buchungsbelege werden 10 Jahre aufbewahrt (OR), auch nach Löschung des Users.
- Zu klären vor dem Verkauf: AGB, Datenschutzerklärung, Kündigungsfristen.

### 5.5 Externe Dienste (z. B. Emby)

- Typ `external` mit Konnektor:
  - `manual`: Das Konto zeigt den Zugang und die Zugangsdaten-Hinweise. Der Admin legt den Account von Hand an und erfasst `external_username`.
  - `wizarr` (später): Medienserver (Emby, Jellyfin, Plex u. a.) laufen über [Wizarr](https://github.com/wizarrrr/wizarr) statt über eine eigene Emby-Anbindung. Wizarr legt User per Einladungslink automatisch auf dem Medienserver an und unterstützt zeitlich begrenzte Mitgliedschaften und eine REST-API. Zuerst verlinkt das Konto nur auf Wizarr bzw. zeigt dem User einen vom Admin erstellten Einladungslink; später erstellt das Konto Einladungen und sperrt User über die Wizarr-API (Umfang der API vor der Umsetzung prüfen).
- Konnektoren als Interface (`provision`, `suspend`, `deprovision`), damit weitere Dienste ohne Umbau dazukommen.

### 5.6 Haushalte

Ziel: Ein Haushalt (z. B. «Familie Muster») teilt app-übergreifend Inhalte, z. B. ein Kochbuch im RecipeManager, zwei Einkaufslisten in der Shoppinglist und später eine Liste mit Haushaltsaufgaben in einer weiteren App. Wer dem Haushalt beitritt, sieht alles automatisch; wer austritt, verliert den Zugriff überall.

**Grundsätze**

- **Haushalt und Mitglieder zentral, Inhalte in den Apps.** Das Konto verwaltet Haushalte, Mitglieder, Rollen und Einladungen. Was geteilt wird, entscheidet jede App selbst («Mit Haushalt teilen» beim Kochbuch bzw. bei der Liste).
- **Teilen mit dem Haushalt, nicht mit einzelnen Mitgliedern.** Die App speichert eine Freigabe `resource ↔ household` (mit Rechten, z. B. beim Kochbuch `can_read`, `can_update`). Der Zugriff wird zur Laufzeit über die Mitgliedschaft aufgelöst. Neue Mitglieder brauchen deshalb keine einzelnen Freigaben.
- **Bestehende Einzelfreigaben bleiben.** Kochbuch-Freigaben pro Person (`cookbook_user`) und Listen-Mitgliedschaften (`shopping_list_user`) funktionieren weiter. Haushalte sind eine zusätzliche Art von Freigabe.
- **Eigentum bleibt beim Ersteller.** Wird ein Mitglied entfernt oder der Haushalt gelöscht, entfallen nur die Freigaben; die Inhalte bleiben beim Eigentümer.
- **Rollen:** `admin` (Mitglieder einladen und entfernen, Haushalt umbenennen oder löschen), `member`. Mindestens ein Admin pro Haushalt.

**Ablauf**

1. Ein User erstellt im Konto einen Haushalt und lädt Personen per E-Mail ein.
2. Die eingeladene Person nimmt an. Hat sie noch keinen Account, kann sie sich über den Link registrieren; für Dienste ohne Selbstregistrierung braucht es trotzdem eine Freigabe (siehe 5.2).
3. Das Konto meldet die Änderung per Webhook (`household.created`, `household.updated`, `household.deleted`, `household.member_added`, `household.member_updated`, `household.member_removed`) an die angebundenen Apps, in denen ein betroffenes Mitglied einen Zugang hat.
4. Die Apps aktualisieren ihre lokale Kopie der Mitgliedschaften (abgeglichen über `oidc_sub`). Mitglieder, die eine App noch nie benutzt haben, werden beim ersten Login in dieser App zugeordnet.
5. Optional melden die Apps ihre Haushalts-Freigaben an das Konto zurück, damit der Haushalt im Konto eine Übersicht zeigt («Kochbuch Familienrezepte, Listen Wocheneinkauf und Getränke»).

**Webhooks allgemein** (gilt auch für 5.3)

- Zustellung über die Queue mit Wiederholungen (exponentiell), Protokoll in `webhook_deliveries`.
- Jedes Ereignis hat eine `event_id` und einen Zeitstempel; Apps verarbeiten Ereignisse idempotent und ignorieren veraltete.
- Signatur per HMAC mit Secret pro Dienst, Schutz gegen Wiederholung über den Zeitstempel.
- Umsetzung (Haushalte): `X-Konto-Signature: sha256=<HMAC-SHA256 über "{timestamp}.{body}">`, `X-Konto-Timestamp`, Toleranz 300 s; 8 Versuche über rund 10 h; jedes Ereignis enthält den vollständigen Stand des Haushalts mit `version`, die Apps ignorieren Ereignisse mit kleinerer oder gleicher Version. Details: [`docs/HOUSEHOLDS-API.md`](HOUSEHOLDS-API.md).

**Schnittstelle für «haushaltsfähige» Apps**

- Webhook-Empfang (signiert) für die Haushalts-Ereignisse oben.
- Abgleich beim Start und als Fallback: `GET /api/households?sub=<oidc_sub>` am Konto (Client-Credentials).
- Optional: `PUT /api/households/{uuid}/shares` am Konto für die Übersicht.
- Umstieg: `POST /api/households/import` (Vorschläge) und `GET /api/households/import` (Stand, Zuordnung lokale ID → UUID).
- Jede neue App (z. B. Haushaltsaufgaben) implementiert dieselbe Schnittstelle und bekommt Haushalte damit ohne Änderungen am Konto.
- Vertrag für die Apps (Authentifizierung, Beispiele, Signatur, Ereignisse, Reihenfolge): [`docs/HOUSEHOLDS-API.md`](HOUSEHOLDS-API.md).

> **Notiz (2026-11-01): Chorelist.** In Arbeit unter `~/Projekte/Chorelist`, die geplante App für Haushaltsaufgaben, aufgebaut auf der Codebasis der Shoppinglist (Laravel, offline-first PWA mit RxDB). Sie soll von Anfang an haushaltsfähig sein (Schnittstelle oben, `HOUSEHOLDS_DRIVER`) und dieselben Auth-Einstellungen wie die anderen Apps bekommen (lokaler Login, optional OIDC, siehe Abschnitt 6). Damit ist sie der dritte Dienst im Konto (`svc-chorelist`).

**Ohne Konto (eigenständiger Betrieb)**

- Die Apps behalten eine lokale Haushaltsverwaltung (`HOUSEHOLDS_DRIVER=local`): Haushalt anlegen, per E-Mail einladen, wie heute bei Kochbüchern. Diese Haushalte gelten nur in der jeweiligen App.
- Mit `HOUSEHOLDS_DRIVER=konto` ist die lokale Verwaltung schreibgeschützt; die App spiegelt nur die Haushalte aus dem Konto.
- Umstieg von lokal auf Konto: Die App exportiert ihre lokalen Haushalte an das Konto (Zusammenführung über Namen und Mitglieder, mit manueller Bestätigung durch einen Admin des Kontos unter «Haushaltsvorschläge») und speichert danach die `konto_household_uuid`.
- Rückbau: Die gespiegelten Haushalte werden zu lokalen Haushalten; Freigaben bleiben erhalten.

**Besonderheiten**

- **Shoppinglist offline (RxDB):** Ändert sich eine Mitgliedschaft, müssen die betroffenen Listen für alle Mitglieder neu synchronisiert werden (wie heute `touchForMembers()`). Entfernte Mitglieder müssen die Liste beim nächsten Sync lokal verlieren.
- **Verschiedene Hoster:** Haushalte über Apps verschiedener Hoster hinweg funktionieren nur, wenn alle Apps am selben Konto hängen. Sonst bleiben es lokale Haushalte pro App.
- **Datenschutz:** Apps erhalten nur Haushalts-UUID, Name sowie `oidc_sub` und Rolle der Mitglieder, keine weiteren Profildaten.

### 5.7 Nextcloud, Forgejo

- Beide per OIDC an Pocket ID, Zugang nur mit Gruppe (`svc-nextcloud`, `svc-forgejo`), keine Selbstregistrierung.
- Nextcloud: App `user_oidc`. Forgejo: OAuth2-Authentifizierungsquelle. Wie sich dort die Anmeldung auf eine Gruppe einschränken lässt und wie bestehende Accounts verknüpft werden, ist vor der Umsetzung zu prüfen.

## 6. Änderungen in den Apps

Für RecipeManager, Shoppinglist und Chorelist gleich aufgebaut, alles über `.env` schaltbar:

| Einstellung | Bedeutung | Standard |
|---|---|---|
| `AUTH_LOCAL_ENABLED` | Lokaler Login mit Passwort | `true` |
| `AUTH_LOCAL_REGISTRATION` | Lokale Selbstregistrierung | `true` |
| `AUTH_OIDC_ENABLED`, `AUTH_OIDC_ISSUER`, `AUTH_OIDC_CLIENT_ID`, `AUTH_OIDC_CLIENT_SECRET`, `AUTH_OIDC_LABEL` | «Anmelden mit …» | aus |
| `AUTH_OIDC_REQUIRED_GROUP` | Zusätzliche Prüfung des `groups`-Claims | leer |
| `AUTH_ADMIN_SOURCE` | Admin-Rechte `local` (in der App) oder `konto` (Gruppe `admin_group`, z. B. `svc-recipes-admin`); lokale Notfall-Admins bleiben immer Admin | `local` |
| `ENTITLEMENTS_DRIVER` | `local` oder `konto` | `local` |
| `HOUSEHOLDS_DRIVER` | `local` oder `konto` | `local` |

- **User-Tabelle:** neue Spalte `oidc_sub` (eindeutig, nullable). Passwort wird nullable für reine SSO-User.
- **Verknüpfen:** Beim ersten OIDC-Login wird ein lokaler Account mit gleicher, bestätigter E-Mail verknüpft. Sonst entsteht ein neuer Account (nur wenn die Registrierung erlaubt ist). Zusätzlich im Profil «Konto verknüpfen» bzw. «Verknüpfung lösen».
- **RecipeManager:** Filament-Login um Button «Anmelden mit …» erweitern; Registrierung, Passwort-Reset und Honeypot bleiben für den lokalen Login. Passport bleibt. Vertrauenswürdige Clients überspringen die Zustimmungsseite.
- **Shoppinglist:** Login-Seite (auch PWA, offline bleibt die Sitzung gültig) um OIDC erweitern. KI-Wochenplanung über eine Freischaltung `shoppinglist.ai` absichern.
- **Haushalte:** neue Tabellen `households`, `household_user` (lokal oder gespiegelt, mit `konto_household_uuid`) und eine Freigabe-Tabelle pro App (RecipeManager: `cookbook_household` mit denselben Rechten wie `cookbook_user`; Shoppinglist: `shopping_list_household`). `Recipe::visibleTo()`, `RecipePolicy` und die Listen-Abfragen der Shoppinglist berücksichtigen zusätzlich die Haushalts-Freigaben.
- **OIDC-Library:** Kandidaten Laravel Socialite mit OIDC-Provider oder `league/oauth2-client`; Wahl und Installation nur nach Freigabe.

## 7. Migration bestehender User

Der Umstieg ist **freiwillig und pro User** (Entscheid 2026-11-02). Lokale Logins bleiben aktiv, niemand muss migrieren.

1. Der User erstellt im Konto ein Konto (`/register`), mit derselben E-Mail-Adresse wie in der App, und erhält den Dienst.
2. In der App verknüpft er sein bestehendes Konto: entweder «Anmelden mit …» bzw. «Öffnen» im Portal (Verknüpfung über die bestätigte E-Mail-Adresse) oder im Profil der App «Mit … verknüpfen» (auch bei anderer Adresse). Daten und Freigaben bleiben erhalten.
3. Die Apps zeigen den SSO-Knopf zuoberst bei Login, Registrierung und Profil, darunter «Noch kein …-Konto?» mit Link zur Registrierung im Konto (`AUTH_OIDC_SIGNUP_URL`). Den Namen des Anbieters holen sie aus Pocket ID (`APP_NAME`), sofern `AUTH_OIDC_LABEL` leer ist.
4. User ohne Verknüpfung erhalten in den Apps weiterhin die lokal konfigurierten Standardrechte (Abschnitt 4).

Option für später, falls doch alle umgestellt werden sollen: Export aus den Apps (E-Mail, Name, bestätigt ja/nein), Zusammenführen über die bestätigte E-Mail-Adresse (Konflikte manuell), Import ins Konto mit Anlage in Pocket ID (`accounts:import` für bestehende Pocket-ID-User), `oidc_sub` in den Apps setzen, Ankündigung und Login-Codes per Mail.

## 8. Rückbau

- Apps: `AUTH_OIDC_ENABLED=false`, `AUTH_LOCAL_ENABLED=true`, `ENTITLEMENTS_DRIVER=local`, `HOUSEHOLDS_DRIVER=local` (gespiegelte Haushalte werden lokal). Reine SSO-User setzen per Passwort-Reset ein Passwort. Keine Daten gehen verloren, weil die User-Tabellen in den Apps bleiben.
- Konto: Export von Usern, Abos, Haushalten und Rechnungen (CSV). Rechnungen 10 Jahre aufbewahren.
- Pocket ID: Export der User über die API, danach abschalten.

## 9. Umsetzung in Phasen

| Phase | Inhalt | Ergebnis | Abhängig von |
|---|---|---|---|
| 0 | Entscheidungen aus Abschnitt 11 | Freigegebenes Konzept | — |
| 1 | OIDC-Login optional im RecipeManager, Tests | Login mit beliebigem OIDC-Anbieter, lokal unverändert | 0 |
| 2 | Pocket ID aufsetzen (Testinstanz), RecipeManager anbinden | SSO für den RecipeManager | 1 |
| 3 | OIDC-Login in der Shoppinglist, Verbinden ohne Rückfrage | SSO für beide Apps | 2 |
| 4 | Konto MVP: Dienste, User, Zugänge, Gruppen-Sync, Selbstregistrierung pro Dienst | Zentrale Verwaltung | 2 |
| 5 | Freiwillige User-Migration (Abschnitt 7) | Bestehende User wechseln selbst auf SSO | 3, 4 |
| 6 | Abos, Freischaltungen, Webhooks, QR-Rechnungen; KI-Freischaltung in der Shoppinglist | Kostenpflichtige Funktionen | 4 |
| 7 | Haushalte lokal in Shoppinglist, RecipeManager und Chorelist (`HOUSEHOLDS_DRIVER=local`) | Haushalte pro App, auch ohne Konto | — (jederzeit möglich) |
| 8 | Haushalte im Konto, Webhooks, Spiegelung in den Apps, Übersicht der Freigaben | App-übergreifende Haushalte | 4, 7 |
| 9 | Externe Dienste (Emby über Wizarr, zuerst manuell, danach per Wizarr-API), Nextcloud, Forgejo | Weitere Dienste | 4 |

Jede Phase in einem eigenen Feature-Branch, mit Tests und einzeln deploybar.

**Stand 2026-11-01:** Phasen 1–3 umgesetzt. OIDC-Login optional in RecipeManager, Shoppinglist und Chorelist (`config/login.php`, gleiche `AUTH_*`-Variablen; in den Fortify-Apps Notfallzugang über `AUTH_LOCAL_EMERGENCY_EMAILS` statt Admin-Flag), Pocket ID produktiv unter `login.<domain>`, «Mit RecipeManager verbinden» ohne Eingabe über `PASSPORT_TRUSTED_CLIENTS`.

**Phase 4 (MVP):** Dienstekatalog, User, Zugänge (beantragt/aktiv/gesperrt) mit Freigabe im Admin-Bereich, Gruppen-Abgleich mit Pocket ID (nur verwaltete Gruppen), Selbstregistrierung pro Dienst mit Double-Opt-in, Honeypot und Login-Code, Portal «Meine Dienste», Import bestehender Pocket-ID-User (`accounts:import`). Dazu (2026-11-03): Konto ohne Dienst erstellen (`/register`), Profil (Name, E-Mail mit Bestätigung, Abgleich mit Pocket ID), Link zu den Passkeys, «Öffnen» startet den SSO-Login der App. **Phase 5 (2026-11-02):** freiwilliger Umstieg pro User, SSO-Knopf zuoberst bei Login, Registrierung und Profil aller Apps, Name des Anbieters aus Pocket ID. **Phase 7 (2026-11-06, Branch `feature/households`, noch nicht gemergt):** lokale Haushalte in RecipeManager (`cookbook_household`, Zugriff zur Laufzeit über `Cookbook::grants()`), Shoppinglist (`shopping_list_household`) und Chorelist (`task_list_household`); in den PWAs wird der Haushaltszugriff als Listen-Mitgliedschaft mit `direct = false` gespiegelt, damit der Sync den Entzug mitbekommt. Einladungen brauchen immer eine Zusage, `HOUSEHOLDS_DRIVER=konto` sperrt nur die lokale Verwaltung. Noch nicht: Webhooks an die Apps, Mailer pro Dienst, Profilfelder, Abos/Rechnungen, Haushalte.

**Phase 8, Konto-Teil (2026-11-07, Branch `feature/households`, noch nicht gemergt):** Haushalte im Konto (Portal `/households`: erstellen, umbenennen, löschen, per E-Mail mit Rolle einladen, Einladung zurückziehen, annehmen/ablehnen, Rolle ändern, entfernen, austreten; mindestens ein Admin; Einladungen nur für die eingeladene, bestätigte Adresse, Link funktioniert auch für Gäste über Login bzw. Registrierung). Webhooks per Queue mit Protokoll, API für die Apps (`GET /api/households?sub=`, `GET /api/households/{uuid}`, `PUT …/shares`, Import-Vorschläge), Webhook-URL und Secret pro Dienst im Admin-Bereich, Übersichten für Admins (Haushalte, Haushaltsvorschläge, Webhooks). Wird ein User gelöscht, verlässt er alle Haushalte (ohne Admin wird das am längsten beigetretene Mitglied Admin, leere Haushalte werden gelöscht). Vertrag: [`docs/HOUSEHOLDS-API.md`](HOUSEHOLDS-API.md). Offen: die App-Seite (`HOUSEHOLDS_DRIVER=konto` in RecipeManager, Shoppinglist, Chorelist), `user.deleted` und `entitlements.changed` (Phase 6).

In den Phasen 2–3 gibt es noch kein Konto und in Pocket ID keine Selbstregistrierung. SSO wird dort nur mit von Hand angelegten Test- und Admin-Accounts genutzt; alle anderen User melden sich weiter lokal an. Ab Phase 5 wechseln bestehende User freiwillig (Abschnitt 7).

**Lokale Entwicklung:** Passkeys brauchen HTTPS und eine feste Domain. Pocket ID läuft lokal als zusätzlicher DDEV-Dienst (z. B. `login.<projekt>.ddev.site`); für automatisierte Tests wird der OIDC-Anbieter gemockt.

## 10. Risiken

- **Passkey-only:** Für weniger technikaffine User eine Hürde. Ausweg: Login-Codes per E-Mail, lokaler Login bleibt möglich.
- **Zentraler Ausfallpunkt:** Fällt Pocket ID aus, geht kein SSO-Login. Abhilfe: Backups, Monitoring, lokaler Notfall-Admin pro App.
- **Veraltete Freischaltungen:** Gruppen aus dem Token gelten nur beim Login; danach laufen die Sitzungen der Apps weiter. Abhilfe: Webhooks, periodischer Abgleich und Prüfung der lokal gespeicherten Freischaltungen bei jeder geschützten Aktion.
- **Ausfall des Kontos:** Apps arbeiten mit dem letzten bekannten Stand weiter (Freischaltungen, Haushalte); neue Registrierungen und Änderungen sind bis zur Wiederherstellung nicht möglich.
- **Account-Übernahme beim Verknüpfen:** Nur über bestätigte E-Mail-Adressen verknüpfen.
- **Haushalte und Rechte:** Freigaben werden über zwei Wege aufgelöst (Einzelperson und Haushalt). Policies und Abfragen müssen beide konsequent berücksichtigen; dafür braucht es gezielte Tests, auch für das Entfernen von Mitgliedern und den Offline-Sync.
- **Aufwand:** Konto mit Abrechnung ist ein eigenes Projekt. Die Phasen 1–3 bringen schon ohne Konto Nutzen.

## 11. Entscheidungen

### Entschieden (2026-11-01)

- **Namen und Subdomains:** AccountManager, für User «Konto» unter `konto.<domain>`; Pocket ID unter `login.<domain>`.
- **Server:** Pocket ID läuft auf Benjamins privatem Server (Portainer); der AccountManager läuft auf KeyHelp wie die anderen Apps (Forgejo-Deploy).
- **Passkeys:** Passkeys mit E-Mail-Login-Code als Ausweg sind in Ordnung, Pocket ID ist gesetzt.
- **Fremde Hoster:** Auch der AccountManager muss von anderen Hostern betrieben werden können, also generisch, ohne feste Bezüge zu einer Domain oder einem Server (alles über Konfiguration).
- **Reihenfolge:** zuerst SSO (Phasen 1–3), direkt danach AccountManager (Phase 4 ff.). Die Aufgabenliste entsteht in einer separaten Session.
- **Haushalte:** Ein User kann in mehreren Haushalten sein; Rechte werden pro geteiltem Inhalt vergeben (wie bei Kochbüchern).

- **Repo:** öffentlich auf GitHub wie die anderen Apps.
- **Preise, MWST, Währung:** konfigurierbar (siehe 5.4).
- **Zahlungsverzug:** keine automatische Sperre. Benachrichtigung an den Admin, Sperre von Hand mit vordefinierter oder eigener Begründung, E-Mail an den User (siehe 5.4).

- **Account löschen:** Das Konto schickt `user.deleted`; jede App löscht den User und seine privaten Inhalte, geteilte Inhalte gehen je nach App-Einstellung an einen anderen Haushalts-Admin über oder werden gelöscht.
- **Admin-Rechte in den Apps:** standardmässig lokal. Optional zentral über das Konto (`AUTH_ADMIN_SOURCE=konto`, Gruppe pro Dienst).
- **E-Mail-Versand:** standardmässig derselbe SMTP-Server wie die Apps, pro Dienst im Konto überschreibbar (`mailers`).
- **Identitätsanbieter im AccountManager:** austauschbare Schnittstelle `IdentityProvider`, zuerst nur mit Pocket ID umgesetzt. Ohne passenden Treiber läuft ein reduzierter Betrieb: Login per OIDC, Zugänge und Gruppen pflegt der Admin von Hand im Identitätsanbieter; Abos, Rechnungen und Haushalte funktionieren weiter. Weitere Treiber (z. B. Authentik) erst bei konkretem Bedarf.

- **Bestehende Tools (geprüft 2026-11-01):** Wizarr wird für Medienserver übernommen. Authentik (statt Pocket ID) und Invoice Ninja (statt eigener Abrechnung) werden nicht verwendet; Login bleibt Pocket ID, die Abrechnung wird selbst gebaut.

### Entschieden (2026-11-07, Phase 8)

- **Authentifizierung der Apps:** Die «Client-Credentials» sind ein Secret pro Dienst (64 Hex-Zeichen, im Konto verschlüsselt gespeichert, einmal angezeigt, erneuerbar). Die Apps rufen die API mit HTTP Basic auf (Schlüssel des Dienstes als Benutzer, Secret als Passwort); dasselbe Secret signiert die Webhooks. Kein OAuth-Server und kein neues Paket nötig; ein Wert pro App genügt. Das Secret muss im Klartext verfügbar sein (HMAC), daher verschlüsselt statt gehasht, Vergleich mit `hash_equals`.
- **Webhook-Felder statt Tabelle `webhooks`:** pro Dienst eine URL (`services.webhook_url`), Protokoll in `webhook_deliveries` mit Bezug zum Dienst.
- **Empfänger und Sichtbarkeit:** Ereignisse und API-Antworten nur an bzw. für Dienste, in denen mindestens ein betroffenes Mitglied einen Zugang hat. Der Snapshot enthält trotzdem alle Mitglieder (nur `sub` und Rolle), damit alle Dienste denselben Stand pro Version sehen.
- **Reihenfolge:** Jedes Ereignis trägt den vollständigen Stand mit einer Versionsnummer pro Haushalt; die Apps setzen ihren Stand darauf und ignorieren ältere Versionen. Unbekannte Haushalte bestätigen sie per API (`404` = gelöscht).
- **Rollenwechsel:** eigenes Ereignis `household.member_updated`.
- **Import beim Umstieg:** bestätigt ein Admin des Kontos (nicht die Haushalts-Admins), weil dabei Mitglieder aus der App in einen bestehenden Haushalt kommen können.
- **Mitglieder ohne Identität** (lokale Notfall-Admins) können Haushalten beitreten, erscheinen aber nicht in den Snapshots.

### Offen

Keine Grundsatzfragen mehr. Details klären die Aufgabenliste und die einzelnen Phasen.
