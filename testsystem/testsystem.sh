#!/usr/bin/env bash
# Steuerung des Shopware-5-Testsystems. Hilfe: ./testsystem.sh help
set -euo pipefail

cd "$(dirname "$0")"
if [ ! -f .env ]; then
    if [ "${1:-}" = "neu" ]; then
        cp .env.example .env
    else
        echo "Bitte zuerst .env anlegen: cp .env.example .env (und anpassen)"; exit 1
    fi
fi
set -a; . ./.env; set +a

SHOP_DIR="${SHOP_DIR:-./shop}"
SHOP_HOST="${SHOP_HOST:-localhost:8080}"
ANONYMIZE_EMAILS="${ANONYMIZE_EMAILS:-1}"

DEMO_IMAGE="${DEMO_IMAGE:-dockware/play:5.7.19}"
DEMO_DUMP="sw5-demo.sql.gz"

compose() { docker compose "$@"; }

# Images laden (mit Wiederholung bei Docker-Hub-Limits), dann bauen und starten
compose_up() {
    local try
    for try in 1 2 3 4 5; do
        compose pull --ignore-buildable --quiet && break
        [ "$try" = 5 ] && { echo "Images konnten nicht geladen werden (Docker Hub). Später erneut versuchen."; exit 1; }
        echo "Docker Hub antwortet nicht (Versuch $try/5), neuer Versuch in $((try * 30)) s ..."
        sleep $((try * 30))
    done
    compose up -d --build
}

# Wert in .env setzen (vorhandene Zeile ersetzen oder anhängen)
set_env() {
    if grep -q "^$1=" .env; then sed -i.bak "s|^$1=.*|$1=$2|" .env && rm -f .env.bak; else echo "$1=$2" >> .env; fi
    export "$1=$2"
}
mysql_root() { compose exec -T db mysql -uroot -proot "$@"; }
console() { compose exec -T -u www-data app php bin/console "$@"; }

wait_for_db() {
    echo "Warte auf Datenbank ..."
    for _ in $(seq 1 60); do
        if compose exec -T db mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; then return 0; fi
        sleep 2
    done
    echo "Datenbank startet nicht. Log: docker compose logs db"; exit 1
}

check_shop_dir() {
    if [ ! -f "$SHOP_DIR/shopware.php" ]; then
        echo "In $SHOP_DIR liegt kein Shopware (shopware.php fehlt)."
        echo "Kopie des Live-Webroots dorthin legen, z. B.:"
        echo "  rsync -a --exclude var/cache --exclude web/cache user@live-server:/pfad/zum/shop/ $SHOP_DIR/"
        exit 1
    fi
}

cmd_start() {
    check_shop_dir
    compose_up
    wait_for_db
    echo
    echo "Shop:     http://${SHOP_HOST}/          Backend: http://${SHOP_HOST}/backend/"
    echo "Mailpit:  http://localhost:${MAIL_PORT:-8025}/"
}

cmd_import() {
    local dump="${1:-}"
    [ -n "$dump" ] && [ -f "$dump" ] || { echo "Aufruf: ./testsystem.sh import <dump.sql|dump.sql.gz>"; exit 1; }
    check_shop_dir
    compose_up
    wait_for_db

    echo "Datenbank wird neu angelegt ..."
    mysql_root -e "DROP DATABASE IF EXISTS shopware; CREATE DATABASE shopware CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON shopware.* TO 'shopware'@'%';"

    echo "Importiere $dump (kann dauern) ..."
    case "$dump" in
        *.gz) gunzip -c "$dump" ;;
        *.zip) unzip -p "$dump" ;;
        *) cat "$dump" ;;
    esac | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' | mysql_root --max-allowed-packet=512M shopware

    echo "Passe Daten für das Testsystem an (Host, Mail, Anonymisierung) ..."
    { echo "SET @shop_host = '${SHOP_HOST//\'/}'; SET @anonymize = ${ANONYMIZE_EMAILS};"; cat docker/after-import.sql; } | mysql_root shopware

    after_change
    echo
    echo "Import fertig."
    echo "Shop:     http://${SHOP_HOST}/          Backend: http://${SHOP_HOST}/backend/"
    echo "Mailpit:  http://localhost:${MAIL_PORT:-8025}/"
    echo "Backend-Login: Zugangsdaten aus dem Live-Shop oder ./testsystem.sh admin"
}

# Caches leeren, Plugins neu einlesen, LdZugferd installieren, Theme kompilieren
after_change() {
    compose exec -T app sh -c 'rm -rf var/cache/production_* var/cache/*.php web/cache/*.css web/cache/*.js 2>/dev/null; mkdir -p var/cache var/log web/cache files media; chown -R www-data: var web/cache files 2>/dev/null || true'
    console sw:plugin:refresh || true
    if console sw:plugin:list 2>/dev/null | grep -q "LdZugferd"; then
        console sw:plugin:install --activate LdZugferd || console sw:plugin:update LdZugferd || true
    fi
    console sw:cache:clear || true
    console sw:theme:cache:generate || true
}

cmd_admin() {
    local user="${1:-test}" pass="${2:-test1234test}"
    console sw:admin:create --username="$user" --password="$pass" --email="$user@example.invalid" --name="Testsystem" --locale=de_DE -n
    echo "Backend-Login: $user / $pass"
}

cmd_rechnung() {
    [ -n "${1:-}" ] || { echo "Aufruf: ./testsystem.sh rechnung <Bestellnummer> [Belegtyp-ID]"; exit 1; }
    compose exec -T -u www-data app php /opt/testsystem/create-document.php "$@"
    echo "PDF im Backend unter Kunden > Bestellungen > Belege öffnen oder direkt aus $SHOP_DIR/files/documents/ nehmen."
}

# Frische Shopware-5.7-Installation mit Testdaten aufsetzen und das E-Rechnungs-Plugin testen
cmd_neu() {
    if [ -f "$SHOP_DIR/shopware.php" ]; then
        echo "In $SHOP_DIR liegt bereits ein Shop. Für einen kompletten Neuanfang:"
        echo "  ./testsystem.sh destroy && rm -rf $SHOP_DIR $DEMO_DUMP && ./testsystem.sh neu"
        exit 1
    fi
    # Shopware 5.7.19 aus dem Demo-Image läuft mit PHP 8.0; Dateien sollen dem eigenen Benutzer gehören
    set_env PHP_VERSION 8.0
    set_env HOST_UID "$(id -u)"
    set_env HOST_GID "$(id -g)"

    echo "== 1/5 Shopware 5.7.19 herunterladen ($DEMO_IMAGE, ca. 1 GB) =="
    local try
    for try in 1 2 3 4 5; do
        docker pull "$DEMO_IMAGE" && break
        [ "$try" = 5 ] && { echo "Download fehlgeschlagen (Docker Hub). Später erneut versuchen."; exit 1; }
        echo "Docker Hub antwortet nicht (Versuch $try/5), neuer Versuch in $((try * 30)) s ..."
        sleep $((try * 30))
    done
    docker rm -f sw5-entwicklung-quelle >/dev/null 2>&1 || true
    docker run -d --name sw5-entwicklung-quelle "$DEMO_IMAGE" >/dev/null
    echo "Warte auf die Shopware-Installation ..."
    for _ in $(seq 1 100); do
        docker exec sw5-entwicklung-quelle mysqladmin ping -h127.0.0.1 -uroot -proot --silent >/dev/null 2>&1 && break
        sleep 3
    done

    echo "== 2/5 Testkunden und Testbestellungen anlegen, Datenbank und Dateien übernehmen =="
    docker exec -i sw5-entwicklung-quelle mysql -h127.0.0.1 -uroot -proot --default-character-set=utf8mb4 shopware < docker/demo-data.sql 2>/dev/null
    docker exec sw5-entwicklung-quelle sh -c 'mysqldump -h127.0.0.1 -uroot -proot --single-transaction --routines --triggers --default-character-set=utf8mb4 shopware 2>/dev/null' | gzip > "$DEMO_DUMP"
    mkdir -p "$SHOP_DIR"
    docker cp sw5-entwicklung-quelle:/var/www/html/. "$SHOP_DIR/"
    docker rm -f sw5-entwicklung-quelle >/dev/null

    echo "== 3/5 Testsystem sw5-entwicklung aufbauen (wie bei einem Live-Dump) =="
    cmd_import "$DEMO_DUMP"

    echo "== 4/5 E-Rechnungs-Plugin mit Testdaten konfigurieren =="
    local kv
    for kv in "sellerName=Muster Druckerei GmbH" "sellerStreet=Musterstraße 1" "sellerZip=12345" "sellerCity=Musterstadt" \
              "sellerVatId=DE123456789" "sellerContactName=Max Muster" "sellerPhone=+49 123 456789" \
              "sellerEmail=rechnung@muster-druckerei.example" "sellerIban=DE02120300000000202051" "sellerRegister=HRB 12345, AG Musterstadt"; do
        console sw:plugin:config:set LdZugferd "${kv%%=*}" "${kv#*=}" >/dev/null
    done
    # Kunde 2 ist eine Behörde: Leitweg-ID (offizielle Test-ID) -> XRechnung
    mysql_root shopware -e "INSERT INTO s_user_attributes (userID, ld_leitweg_id) VALUES (2, '991-33333TEST-33') ON DUPLICATE KEY UPDATE ld_leitweg_id = VALUES(ld_leitweg_id);" 2>/dev/null
    console sw:cache:clear >/dev/null

    echo "== 5/5 E-Rechnungen erzeugen und prüfen =="
    cmd_test
}

# Rechnungen der Testbestellungen erzeugen und prüfen
cmd_test() {
    local result=0
    compose exec -T -u www-data app php /opt/testsystem/create-document.php 20001 >/dev/null
    compose exec -T app php /opt/testsystem/check-einvoice.php 20001 zugferd || result=1
    compose exec -T -u www-data app php /opt/testsystem/create-document.php 20002 >/dev/null
    compose exec -T app php /opt/testsystem/check-einvoice.php 20002 xrechnung || result=1
    echo
    if [ "$result" = 0 ]; then
        echo "ERGEBNIS: Alle Prüfungen bestanden."
    else
        echo "ERGEBNIS: Es gab Fehler (siehe oben, Details in $SHOP_DIR/var/log/)."
    fi
    echo "Shop:     http://${SHOP_HOST}/          Backend: http://${SHOP_HOST}/backend/  (./testsystem.sh admin legt einen Login an)"
    echo "Mailpit:  http://localhost:${MAIL_PORT:-8025}/"
    echo "PDFs:     $SHOP_DIR/files/documents/   XRechnung-Download: http://${SHOP_HOST}/backend/LdZugferdXml/download?order=20002"
    return $result
}

cmd_help() {
    cat <<'EOF'
./testsystem.sh neu                   Frisches Shopware 5.7 mit Testdaten aufsetzen und E-Rechnung testen
./testsystem.sh test                  E-Rechnungen der Testbestellungen 20001/20002 neu erzeugen und prüfen
./testsystem.sh start                 Container bauen und starten
./testsystem.sh import <dump>         Datenbank-Dump (.sql, .sql.gz, .zip) einspielen und Testsystem vorbereiten
./testsystem.sh refresh               Caches leeren, Plugins neu einlesen, LdZugferd installieren/aktualisieren
./testsystem.sh admin [user] [pass]   Backend-Benutzer anlegen (Standard: test / test1234test)
./testsystem.sh rechnung <bestellnr>  Rechnung erzeugen wie Kuzo/Backend (Test des E-Rechnungs-Plugins)
./testsystem.sh console <befehl>      Shopware-Konsole, z. B. ./testsystem.sh console sw:plugin:list
./testsystem.sh shell                 Shell im Shop-Container
./testsystem.sh logs                  Logs aller Container
./testsystem.sh stop                  Container anhalten (Daten bleiben)
./testsystem.sh destroy               Container UND Datenbank löschen
EOF
}

case "${1:-help}" in
    neu) cmd_neu ;;
    test) cmd_test ;;
    start) cmd_start ;;
    import) shift; cmd_import "$@" ;;
    refresh) after_change ;;
    admin) shift; cmd_admin "$@" ;;
    rechnung) shift; cmd_rechnung "$@" ;;
    console) shift; console "$@" ;;
    shell) compose exec -u www-data app bash ;;
    logs) compose logs -f --tail=100 ;;
    stop) compose stop ;;
    destroy) compose down -v ;;
    *) cmd_help ;;
esac
