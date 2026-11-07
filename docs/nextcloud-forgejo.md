# Nextcloud und Forgejo über Pocket ID

Beide Dienste melden sich per OIDC bei Pocket ID an (KONZEPT 5.7). Der AccountManager braucht dafür
keinen eigenen Konnektor: Dienste mit dem Standard-Konnektor «Nur Gruppe beim Anmeldedienst»
(`oidc-group`) setzen bei einem aktiven Zugang die Gruppe `svc-<schlüssel>` in Pocket ID und
entfernen sie beim Sperren oder Entfernen (`SyncUserGroups`, über die Queue, mit dem Cron innerhalb
etwa einer Minute). Pocket ID lässt nur Mitglieder der erlaubten Gruppen an den OIDC-Client; die App
prüft die Gruppe zusätzlich selbst.

Stand der Angaben: Nextcloud `user_oidc` (README und `occ user_oidc:provider --help`, Branch `main`,
November 2026), Forgejo (`forgejo admin auth add-oauth`, `routers/web/auth/oauth.go`), Pocket ID
v2.19 (Client-Beispiele unter pocket-id.org/docs/client-examples). Optionen vor dem Einrichten mit
`--help` gegen die installierte Version prüfen.

## 1. Gruppen

| Dienst | Zugangsgruppe | Admin-Gruppe (optional) |
|---|---|---|
| Nextcloud | `svc-nextcloud` | `svc-nextcloud-admin` |
| Forgejo | `svc-forgejo` | `svc-forgejo-admin` |

- Die Gruppen in Pocket ID unter Administration → User Groups mit genau diesem **Name** anlegen
  (Friendly Name beliebig). Das Konto findet bestehende Gruppen über den Namen; fehlende legt es
  beim ersten Abgleich selbst an, aber für die Einschränkung des Clients (Schritt 3) muss die
  Gruppe schon bestehen.
- Die Admin-Gruppe bekommen nur aktive Zugänge mit «Admin im Dienst». Sie gehört **nicht** zu den
  erlaubten Gruppen des Clients (Admins haben immer auch die Zugangsgruppe).
- Die Gruppen nicht von Hand in Pocket ID zuweisen: Das Konto ist für die verwalteten Gruppen die
  Quelle und überschreibt Änderungen beim nächsten Abgleich (`ddev artisan accounts:sync-groups`
  bzw. auf dem Server `php artisan accounts:sync-groups` gleicht alle User sofort ab).

## 2. Dienste im Konto erfassen

Admin-Bereich → Dienste → Neu:

| Feld | Nextcloud | Forgejo |
|---|---|---|
| Name / Schlüssel | Nextcloud / `nextcloud` | Forgejo / `forgejo` |
| URL | `https://cloud.<domain>` | `https://git.<domain>` |
| Login-URL | `https://cloud.<domain>/apps/user_oidc/login/<provider-id>` (ID aus `occ user_oidc:provider PocketID`) oder `https://cloud.<domain>/login`, wenn nur Pocket ID erlaubt ist | `https://git.<domain>/user/oauth2/PocketID` |
| Typ | Anmeldung über den Anmeldedienst | Anmeldung über den Anmeldedienst |
| Gruppe beim Anmeldedienst | `svc-nextcloud` | `svc-forgejo` |
| Admin-Gruppe | optional `svc-nextcloud-admin` | optional `svc-forgejo-admin` |
| Selbstregistrierung | aus | aus |
| Freigabe nötig | an | an |
| Konnektor | Nur Gruppe beim Anmeldedienst | Nur Gruppe beim Anmeldedienst |

## 3. Pocket ID: OIDC-Clients

Administration → OIDC Clients → Add OIDC Client, je einmal pro Dienst:

| Einstellung | Nextcloud | Forgejo |
|---|---|---|
| Name | Nextcloud | Forgejo |
| Client-Typ | Confidential | Confidential |
| Callback URL | `https://cloud.<domain>/apps/user_oidc/code` | `https://git.<domain>/user/oauth2/PocketID/callback` (der Teil `PocketID` = Name der Quelle in Forgejo) |
| Back-Channel Logout URL | `https://cloud.<domain>/apps/user_oidc/backchannel-logout/PocketID` (Nextcloud zeigt sie nach dem Anlegen des Providers an) | – |
| PKCE | empfohlen | empfohlen |
| Tab «Access» → Allowed User Groups | **nur** `svc-nextcloud` | **nur** `svc-forgejo` |

Nach «Create» die Client-ID, das Client-Secret (wird nur einmal angezeigt), die OIDC Discovery URL
(`https://login.<domain>/.well-known/openid-configuration`) und für Nextcloud die Logout URL
(«Show more details») kopieren. «All Users» darf nicht gewählt sein, sonst entscheidet allein die
App über den Zugang.

## 4. Nextcloud (`user_oidc`)

App installieren und den Provider anlegen (als Benutzer des Webservers, im Docker-Image z. B.
`docker exec -u www-data nextcloud php occ …`):

```bash
occ app:install user_oidc

occ user_oidc:provider PocketID \
  --clientid="<client-id>" \
  --clientsecret="<client-secret>" \
  --discoveryuri="https://login.<domain>/.well-known/openid-configuration" \
  --endsessionendpointuri="<logout-url aus Pocket ID>" \
  --scope="openid email profile groups" \
  --mapping-uid="sub" \
  --unique-uid=0 \
  --mapping-display-name="name" \
  --mapping-email="email" \
  --mapping-groups="groups" \
  --group-provisioning=1 \
  --group-whitelist-regex='/^svc-nextcloud(-admin)?$/' \
  --group-restrict-login-to-whitelist=1 \
  --send-id-token-hint=0

occ user_oidc:provider PocketID   # zeigt die Einstellungen und die ID für die Login-URL
```

- `--group-restrict-login-to-whitelist=1` mit der Whitelist ist die zweite Sperre neben Pocket ID:
  Ohne `svc-nextcloud` im `groups`-Claim lehnt Nextcloud die Anmeldung ab. Die Gruppen werden in
  Nextcloud angelegt und bei jedem Login gesetzt; andere Nextcloud-Gruppen bleiben unberührt.
- Admins in Nextcloud: Die Gruppe `svc-nextcloud-admin` erscheint in Nextcloud als normale Gruppe.
  Admin-Rechte vergibt Nextcloud nur über die Gruppe `admin`; entweder von Hand vergeben oder die
  Admin-Gruppe weglassen.
- Optional nur noch Pocket ID als Login (lokaler Login dann über `https://cloud.<domain>/login?direct=1`):
  `occ config:app:set --type=string --value=0 user_oidc allow_multiple_user_backends`. Mindestens ein
  lokaler Admin mit Passwort bleibt als Notfallzugang.

**Benutzer-ID und bestehende Konten.** Die Nextcloud-Benutzer-ID kommt aus dem Claim in
`--mapping-uid`:

- `sub` (oben, empfohlen für neue Konten): die unveränderliche Pocket-ID-User-ID. Sicher, aber eine
  UUID als Benutzername; bestehende lokale Nextcloud-Konten werden nicht verknüpft.
- Bestehende Konten weiterverwenden: in Pocket ID beim User unter Custom Claims
  `nextcloud_username` = bestehende Nextcloud-Benutzer-ID setzen und `--mapping-uid="nextcloud_username"`
  verwenden. Mit `soft_auto_provision` (Standard) übernimmt `user_oidc` dann das bestehende Konto.
  Dann braucht **jeder** Nextcloud-User diesen Claim; vorher mit einem Testkonto prüfen, wie sich
  ein fehlender Claim verhält.
- **Nicht** `preferred_username` verwenden: Das Konto leitet den Pocket-ID-Benutzernamen aus dem
  Teil der E-Mail-Adresse vor dem @ ab. Wer sich mit `admin@irgendwo.ch` registriert und Zugang
  erhält, würde sonst mit `soft_auto_provision` im bestehenden Nextcloud-Konto `admin` landen.

## 5. Forgejo

In der Oberfläche (Website-Administration → Identität & Zugriff → Authentifizierungsquellen →
Hinzufügen, Typ OAuth2, Anbieter OpenID Connect) oder per CLI (im Container als Benutzer `git`, z. B.
`docker exec -u git forgejo forgejo admin auth add-oauth …`):

```bash
forgejo admin auth add-oauth \
  --name PocketID \
  --provider openidConnect \
  --key "<client-id>" \
  --secret "<client-secret>" \
  --auto-discover-url "https://login.<domain>/.well-known/openid-configuration" \
  --scopes openid --scopes email --scopes profile --scopes groups \
  --group-claim-name groups \
  --required-claim-name groups \
  --required-claim-value svc-forgejo \
  --skip-local-2fa
```

- `--required-claim-name groups --required-claim-value svc-forgejo`: Forgejo prüft bei jeder
  OAuth-Anmeldung, ob `svc-forgejo` im (Listen-)Claim `groups` steht, sonst «Anmeldung verboten».
  Das ist die zweite Sperre neben Pocket ID.
- Admin-Rechte aus dem Konto (optional): `--admin-group svc-forgejo-admin`. Forgejo setzt das
  Admin-Flag dann bei **jeder** OAuth-Anmeldung nach dieser Gruppe, nimmt es also auch weg. Der
  lokale Notfall-Admin meldet sich weiter mit Passwort an.
- `app.ini` (Abschnitt `[oauth2_client]`), Empfehlung:

  ```ini
  [service]
  ; Neue Konten nur über externe Anmeldung (Pocket ID), keine lokale Registrierung.
  ALLOW_ONLY_EXTERNAL_REGISTRATION = true

  [oauth2_client]
  ENABLE_AUTO_REGISTRATION = true
  USERNAME = nickname
  ; Bestehende Konten mit gleicher Adresse oder gleichem Namen: einmal mit dem lokalen Passwort
  ; bestätigen statt automatisch verknüpfen (auto würde über den Benutzernamen fremde Konten übernehmen können).
  ACCOUNT_LINKING = login
  ```

  Bestehende Forgejo-User verknüpfen ihr Konto beim ersten Login über Pocket ID mit ihrem lokalen
  Passwort oder später unter Einstellungen → Sicherheit → «Verknüpfte Konten».

## 6. Sperren und Entfernen

1. Admin sperrt den Zugang im Konto (oder entfernt ihn) → das Konto nimmt die Gruppe
   `svc-<schlüssel>` in Pocket ID weg (der User erhält die Sperr-Mail).
2. Beim **nächsten Login** lehnt Pocket ID den Client ab (Gruppe nicht erlaubt); die App würde ihn
   zusätzlich am fehlenden Claim abweisen.
3. Entsperren gibt die Gruppe zurück, danach klappt der Login wieder.

Grenzen (wichtig):

- **Laufende Sitzungen bleiben.** Weder Nextcloud noch Forgejo fragen Pocket ID während einer
  Sitzung erneut; die Gruppe wird nur beim Login geprüft. Back-Channel-Logout sendet Pocket ID nur
  beim Abmelden in Pocket ID, nicht bei einer Gruppenänderung.
- **Nextcloud:** Die Desktop- und Mobil-Clients arbeiten mit App-Passwörtern (Login-Flow), die ohne
  Pocket ID weiterlaufen; ebenso WebDAV mit App-Passwort. Für eine sofortige Sperre den User in
  Nextcloud deaktivieren: `occ user:disable <benutzer-id>` (Entsperren: `occ user:enable`), oder in
  der Benutzerverwaltung.
- **Forgejo:** Web-Sitzungen laufen bis `[session] SESSION_LIFE_TIME` (Standard 24 h), «Angemeldet
  bleiben» bis `[security] LOGIN_REMEMBER_DAYS`; Git über SSH, Zugriffstokens und OAuth-Apps sind
  vom Login unabhängig. Für eine sofortige Sperre beim User «Anmeldung verbieten» setzen
  (Website-Administration → Benutzerkonten).
- Wer bei jeder Sperre daran erinnert werden will: als Konnektor «Von Hand» wählen. Die Gruppe wird
  trotzdem abgeglichen; zusätzlich erhalten die Admins bei jedem Schritt eine Mail (beim Freigeben
  ist nichts zu tun, das Konto in der App entsteht beim ersten Login).
- Den Zugang entfernen löscht das Konto in Nextcloud bzw. Forgejo nicht (Daten bleiben); löschen
  von Hand, falls gewünscht.

Möglicher Ausbau (nicht umgesetzt): eigene Konnektoren, die über die Provisioning-API von Nextcloud
(`PUT /ocs/v1.php/cloud/users/{id}/disable|enable`) bzw. die Admin-API von Forgejo
(`PATCH /api/v1/admin/users/{name}` mit `prohibit_login`) beim Sperren sofort deaktivieren.
