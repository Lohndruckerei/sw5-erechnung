# sw5-entwicklung – Testsystem für den Shopware-5-Shop

Docker-Umgebung, in die du eine Kopie des Live-Shops (Dateien + Datenbank-Dump) einspielst, um dort
Plugins wie `LdZugferd` mit echten Daten und dem Kuzo-Plugin zu testen, ohne dass etwas nach außen dringt.

| Container | Aufgabe | Adresse |
|---|---|---|
| `sw5-entwicklung` | Shopware (PHP + Apache), **ohne Internetzugang** | über den Proxy |
| `sw5-entwicklung-proxy` | einziger Zugang zum Shop | http://localhost:8080 |
| `sw5-entwicklung-db` | MySQL mit dem importierten Dump | nur intern |
| `sw5-entwicklung-mailpit` | fängt **alle** Mails ab | http://localhost:8025 |

## Sicherheitsvorkehrungen

Ein Klon des Live-Shops kann echte Vorgänge auslösen. Kuzo reagiert zum Beispiel auf Statuswechsel, und je nach
installierten Plugins könnten Zahlungen erfasst, Daten ans ERP übertragen oder Versandlabels erzeugt werden.
Deshalb gilt:

* **Kein Internet für den Shop:** Der Shop-Container hängt nur in einem internen Netz. Externe Dienste
  (Zahlungsanbieter, ERP, DHL ...) sind nicht erreichbar.
* **Keine Mails nach draußen:** Mails gehen immer an Mailpit. Das gilt für SMTP aus der `config.php`, die
  beim Import gelöschten SMTP-Daten aus dem Backend und PHP `mail()` über msmtp.
* **Anonymisierte Kunden-E-Mails:** Beim Import werden alle Adressen zu `kunde-<id>@example.invalid`
  (abschaltbar mit `ANONYMIZE_EMAILS=0`).
* **Nur lokal erreichbar:** Shop und Mailpit lauschen nur auf `127.0.0.1`.
* **Live-Dateien bleiben unverändert:** Die `config.php` der Shop-Kopie wird nicht überschrieben, sondern im
  Container durch die des Testsystems überlagert.

Trotzdem enthält das Testsystem echte Kundendaten. Den Dump vertraulich behandeln und das System nicht öffentlich machen.

## Schnellstart ohne Live-Daten

```bash
./testsystem.sh neu
```
Setzt ein frisches Shopware 5.7.19 mit zwei Testbestellungen auf (20001 Firma, 20002 Behörde mit Leitweg-ID),
konfiguriert das E-Rechnungs-Plugin mit Testdaten, erzeugt beide Rechnungen und prüft sie (`./testsystem.sh test`).
Bricht der Lauf ab (z. B. Docker-Hub-Limit), setzt ein erneutes `./testsystem.sh neu` nach dem Download bei Schritt 3 fort.

## Einrichtung

Voraussetzung: Docker mit Docker Compose. Unter Windows `testsystem.sh` in WSL oder Git Bash ausführen.

1. **Einstellungen**
   ```bash
   cd testsystem
   cp .env.example .env
   ```
   In `.env` vor allem **`PHP_VERSION` auf die PHP-Version des Live-Servers** setzen (`php -v` auf dem Server).
   Verschlüsselte Plugins (ionCube) laufen nur mit passender PHP-Version.
   Unter Linux `HOST_UID`/`HOST_GID` auf die Ausgabe von `id -u` / `id -g` setzen.

2. **Shop-Dateien kopieren** (Webroot des Live-Shops nach `testsystem/shop/`), zum Beispiel:
   ```bash
   rsync -a --exclude 'var/cache/*' --exclude 'web/cache/*' user@live-server:/pfad/zum/shop/ shop/
   ```
   `media/` und `files/` können sehr groß sein. Ohne `media/` fehlen nur die Bilder. `files/documents/` braucht man nur
   für alte Belege.

3. **Datenbank-Dump erstellen** (auf dem Live-Server):
   ```bash
   mysqldump --single-transaction --routines --triggers -u USER -p DATENBANK | gzip > shop-dump.sql.gz
   ```

4. **Importieren**
   ```bash
   ./testsystem.sh import shop-dump.sql.gz
   ```
   Der Import baut die Container, spielt den Dump ein und stellt auf das Testsystem um: Domain `localhost:8080`,
   kein HTTPS, Mails an Mailpit, anonymisierte E-Mails. Danach leert er die Caches, installiert und aktiviert
   `LdZugferd` und kompiliert das Theme.

5. **Anmelden:** http://localhost:8080/backend/ mit den Zugangsdaten des Live-Shops, oder einen Testbenutzer anlegen:
   ```bash
   ./testsystem.sh admin          # test / test1234test
   ```

## E-Rechnung testen

1. Backend → Einstellungen → Plugin-Manager → „ZUGFeRD / Factur-X E-Rechnung“ → Konfiguration ausfüllen, dann Caches leeren.
2. **Mit Kuzo:** Bei einer Bestellung den Status setzen, der die Rechnung auslöst. Die Mail mit dem PDF erscheint in Mailpit.
3. **Ohne Kuzo:** `./testsystem.sh rechnung <Bestellnummer>` erzeugt die Rechnung auf demselben Weg.
4. PDF prüfen: In der Seitenleiste „Anlagen“ (Firefox/Acrobat) muss `factur-x.xml` erscheinen.
5. XRechnung: Beim Kunden das Freitextfeld „Leitweg-ID (XRechnung)“ füllen, Rechnung neu erzeugen und die XML unter
   http://localhost:8080/backend/LdZugferdXml/download?order=<Bestellnummer> herunterladen.

Änderungen am Plugin-Code (`../LdZugferd`) sind sofort im Container sichtbar, danach `./testsystem.sh refresh`.

## Befehle

```
./testsystem.sh start                 Container bauen und starten
./testsystem.sh import <dump>         Dump (.sql, .sql.gz, .zip) einspielen; löscht die bisherige Testdatenbank
./testsystem.sh refresh               Caches leeren, Plugins neu einlesen, LdZugferd installieren/aktualisieren
./testsystem.sh admin [user] [pass]   Backend-Benutzer anlegen
./testsystem.sh rechnung <bestellnr>  Rechnung erzeugen wie Kuzo/Backend
./testsystem.sh console <befehl>      Shopware-Konsole, z. B. console sw:plugin:list
./testsystem.sh shell                 Shell im Shop-Container
./testsystem.sh logs                  Logs aller Container
./testsystem.sh stop                  Anhalten (Daten bleiben)
./testsystem.sh destroy               Container und Datenbank löschen
```

## Häufige Probleme

* **Plugin meldet Lizenzfehler / läuft nicht:** Store-Plugins prüfen teils Domain oder Lizenzserver.
  Im Notfall Internet für den Shop freischalten (`COMPOSE_FILE=docker-compose.yml:docker-compose.internet.yml` in `.env`),
  aber dann vorher Plugins mit externen Aktionen (Zahlung, ERP, Versand) deaktivieren.
* **Weiße Seite / 500:** `./testsystem.sh logs` und `shop/var/log/` ansehen. Meist passt die PHP-Version nicht oder es fehlt eine Erweiterung.
* **Mehrere Hauptshops:** Der Standardshop liegt unter `/`, weitere unter `/shop<ID>`.
* **Import bricht ab:** MySQL-Version wie live wählen (`DB_IMAGE=mysql:5.7` oder `mariadb:10.6` in `.env`).
* **Elasticsearch / CDN / Redis** des Live-Shops werden im Testsystem bewusst nicht verwendet (siehe `docker/config.php`).

## Getestet

Komplett durchgespielt mit Shopware 5.7.19 (PHP 8.0, MySQL 8.0) und einem Dump mit HTTPS-Domain, SMTP mit TLS und einer
Testbestellung. Geprüft wurden:

* Import und Umstellung von Domain, HTTPS und Mail
* Anonymisierung der Kunden-E-Mails
* Shop und Backend unter `localhost:8080`, alle Links zeigen auf das Testsystem
* Kein Internetzugang aus dem Shop-Container
* Mail mit PDF-Anhang landet in Mailpit
* E-Rechnung und XRechnung über `rechnung` erzeugt und validiert
* XML-Download im Backend

`./testsystem.sh neu` inklusive Build des Images aus `docker/Dockerfile` (PHP 8.0 auf Debian Bullseye, Pakete aus
archive.debian.org) unter Docker Desktop/WSL2 komplett durchgelaufen: „ERGEBNIS: Alle Prüfungen bestanden.“
Für PHP 8.0 gibt es keinen ionCube-Loader; er wird dann übersprungen.
