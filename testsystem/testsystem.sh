#!/usr/bin/env bash
# Steuerung des Shopware-5-Testsystems. Hilfe: ./testsystem.sh help
set -euo pipefail

cd "$(dirname "$0")"
[ -f .env ] || { echo "Bitte zuerst .env anlegen: cp .env.example .env (und anpassen)"; exit 1; }
set -a; . ./.env; set +a

SHOP_DIR="${SHOP_DIR:-./shop}"
SHOP_HOST="${SHOP_HOST:-localhost:8080}"
ANONYMIZE_EMAILS="${ANONYMIZE_EMAILS:-1}"

compose() { docker compose "$@"; }
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
    compose up -d --build
    wait_for_db
    echo
    echo "Shop:     http://${SHOP_HOST}/          Backend: http://${SHOP_HOST}/backend/"
    echo "Mailpit:  http://localhost:${MAIL_PORT:-8025}/"
}

cmd_import() {
    local dump="${1:-}"
    [ -n "$dump" ] && [ -f "$dump" ] || { echo "Aufruf: ./testsystem.sh import <dump.sql|dump.sql.gz>"; exit 1; }
    check_shop_dir
    compose up -d --build
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
    compose exec -T app sh -c 'rm -rf var/cache/production_* var/cache/*.php web/cache/*.css web/cache/*.js 2>/dev/null; mkdir -p var/cache var/log web/cache files media; chown -R www-data: var web/cache 2>/dev/null || true'
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

cmd_help() {
    cat <<'EOF'
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
