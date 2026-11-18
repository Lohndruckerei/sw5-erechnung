#!/usr/bin/env bash
# Holt eine Kopie des Live-Shops displayhaus.de per SSH ins Testsystem: Datenbank-Dump + Shop-Dateien.
# Am Live-Server wird nichts verändert (nur lesen).
#
#   ./live-holen.sh                      Live-Shop holen (Vorgaben siehe unten)
#   ./live-holen.sh aktualisieren        frühere Live-Kopie in ./shop auf den neuesten Stand bringen
#
# Weitere Angaben (optional, kombinierbar): ohne <ordner>   zusätzlich ausschließen
#                                          ziel <ssh-ziel>  anderer Server, z. B. benutzer@server
#                                          pfad <ordner>    anderer Shop-Ordner auf dem Server
#                                          port <nr>        anderer SSH-Port
# Ergebnis: ./shop-dump.sql.gz und ./shop/  →  danach: ./testsystem.sh import shop-dump.sql.gz
set -euo pipefail
cd "$(dirname "$0")"

# ---- Vorgaben für displayhaus.de --------------------------------------------------------------
# SSH-Ziel: Kurzname aus ~/.ssh/config (Host displayhaus → HostName, User, IdentityFile ...)
ZIEL="displayhaus"
# Stammordner des Shops auf dem Server
PFAD="/home/discfyxp/www.displayhaus.de"
# Ordner (relativ zum Stammordner), die nie kopiert werden
OHNE=(
    var/cache web/cache var/log       # Caches und Logs: im Testsystem nicht nötig
    dev                               # Entwicklungs-/Testkopie auf dem Server
    files/basketUploads/final         # Kunden-Uploads (sehr groß)
    files/backup                      # Server-Backups
)
# -----------------------------------------------------------------------------------------------

AKTUALISIEREN=0
PORT=""   # leer = Port aus ~/.ssh/config bzw. 22
while [ $# -gt 0 ]; do
    case "$1" in
        ohne|ziel|pfad|port) [ $# -ge 2 ] || { echo "Nach '$1' fehlt eine Angabe."; exit 1; } ;;&
        ohne) OHNE+=("$2"); shift 2 ;;
        ziel) ZIEL="$2"; shift 2 ;;
        pfad) PFAD="$2"; shift 2 ;;
        port) PORT="$2"; shift 2 ;;
        aktualisieren) AKTUALISIEREN=1; shift ;;
        hilfe|help|-h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unbekannte Angabe: $1   (Hilfe: ./live-holen.sh hilfe)"; exit 1 ;;
    esac
done
PFAD="${PFAD%/}"
EXCLUDES=()
for d in "${OHNE[@]}"; do
    # absolute Angaben (/home/.../www.displayhaus.de/dev/) auf den Stammordner beziehen
    d="${d#"$PFAD"}"; d="${d#/}"; d="${d%/}"
    [ -n "$d" ] || continue
    EXCLUDES+=("--exclude=/$d/")
done
echo "Server: $ZIEL   Shop-Ordner: $PFAD"
echo "Ausgeschlossen: ${OHNE[*]}"

if [ -d shop ] && [ -n "$(ls -A shop 2>/dev/null)" ] && [ "$AKTUALISIEREN" = 0 ]; then
    echo "Der Ordner shop/ ist nicht leer (z. B. der Demo-Shop)."
    echo "Vorher beiseitelegen:  mv shop shop-demo"
    echo "Oder eine frühere Live-Kopie auf den neuesten Stand bringen: ./live-holen.sh aktualisieren"
    exit 1
fi

# Eine SSH-Verbindung für alle Schritte (Passwort/Passphrase nur einmal eingeben)
SOCK="$(mktemp -u /tmp/live-holen-XXXXXX)"
# LIVE_SSH_OPTS: zusätzliche SSH-Optionen, z. B. LIVE_SSH_OPTS="-i ~/.ssh/displayhaus_key"
# shellcheck disable=SC2206
SSH=(ssh ${PORT:+-p "$PORT"} -o ControlMaster=auto -o ControlPath="$SOCK" -o ControlPersist=600 ${LIVE_SSH_OPTS:-})
trap 'ssh -o ControlPath="$SOCK" -O exit "$ZIEL" 2>/dev/null || true; rm -f shop-dump.sql.gz.tmp' EXIT

echo "== 1/3 Verbindung zu $ZIEL und Shop prüfen =="
"${SSH[@]}" "$ZIEL" "cd '$PFAD' && test -f shopware.php && test -f config.php" \
    || { echo "Anmeldung bei '$ZIEL' fehlgeschlagen oder in $PFAD liegt kein Shopware (shopware.php/config.php)."
         [ "$ZIEL" = displayhaus ] && echo "Prüfen: Gibt es in ~/.ssh/config den Eintrag 'Host displayhaus'? Test: ssh displayhaus"
         exit 1; }
# Benötigte Programme auf dem Server suchen (Hoster haben oft abweichende Namen/Pfade)
REMOTE_RSYNC=""; REMOTE_DUMP=""; REMOTE_PHP=""; REMOTE_TAR=""
while IFS='=' read -r key val; do
    case "$key" in
        RSYNC) REMOTE_RSYNC="$val" ;; DUMP) REMOTE_DUMP="$val" ;; PHP) REMOTE_PHP="$val" ;; TAR) REMOTE_TAR="$val" ;;
    esac
done < <("${SSH[@]}" "$ZIEL" 'sh -s' <<'SH'
PATH="$PATH:/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin"
find_cmd() { for c in "$@"; do [ -x "$c" ] && { echo "$c"; return; }; p=$(command -v "$c" 2>/dev/null) && [ -n "$p" ] && { echo "$p"; return; }; done; }
echo "RSYNC=$(find_cmd rsync)"
echo "DUMP=$(find_cmd mysqldump mariadb-dump /usr/local/mysql/bin/mysqldump)"
echo "TAR=$(find_cmd tar)"
echo "PHP=$(find_cmd php php-cli php8.4 php8.3 php8.2 php8.1 php8.0 php7.4 php84 php83 php82 php81 php80 php74 \
    $(ls -1d /usr/local/bin/php* /opt/php*/bin/php /opt/plesk/php/*/bin/php /usr/local/php*/bin/php 2>/dev/null | sort -r))"
SH
)
echo "Auf dem Server gefunden:"
echo "  php:       ${REMOTE_PHP:-FEHLT}"
echo "  mysqldump: ${REMOTE_DUMP:-FEHLT}"
echo "  rsync:     ${REMOTE_RSYNC:-fehlt (stattdessen tar)}"
if [ -z "$REMOTE_PHP" ] || [ -z "$REMOTE_DUMP" ] || { [ -z "$REMOTE_RSYNC" ] && [ -z "$REMOTE_TAR" ]; }; then
    echo "Ohne php und mysqldump (bzw. rsync/tar) auf dem Server geht es nicht. Bitte diese Ausgabe an den Entwickler"
    echo "schicken oder beim Hoster nachfragen, wie PHP-CLI und mysqldump per SSH heißen."
    exit 1
fi
if [ -n "$REMOTE_RSYNC" ] && ! command -v rsync >/dev/null; then
    echo "rsync fehlt lokal, Dateien werden per tar übertragen (schneller wiederholbar mit: sudo apt-get install -y rsync)"
    REMOTE_RSYNC=""
fi
echo "PHP auf dem Server (Konsole): $("${SSH[@]}" "$ZIEL" "'$REMOTE_PHP' -r 'echo PHP_VERSION;'")"
echo "  → in .env PHP_VERSION passend setzen (die Web-PHP-Version steht in der Hoster-Oberfläche)."

echo "== 2/3 Datenbank sichern =="
# Zugangsdaten werden auf dem Server aus config.php gelesen und nie angezeigt.
"${SSH[@]}" -C "$ZIEL" "cd '$PFAD' && LIVE_DUMP='$REMOTE_DUMP' '$REMOTE_PHP'" <<'PHP' | gzip > shop-dump.sql.gz.tmp || true
<?php
$err = fopen('php://stderr', 'w');   // STDERR ist nicht definiert, wenn PHP das Skript von stdin liest
$c = require 'config.php';
$d = $c['db'];
$host = $d['host'] ?? 'localhost';
$port = (int) ($d['port'] ?? 3306);
$socket = $d['socket'] ?? '';

// 1. Verbinden wie Shopware selbst (PDO) und den Server nach seinem tatsächlichen Socket/Port fragen.
//    Hintergrund: Bei manchen Hostern laufen mehrere MySQL-Server; "localhost" führt bei PHP (php.ini:
//    pdo_mysql.default_socket) und bei mysqldump (eigener Standard-Socket) dann zu verschiedenen Servern.
$dsn = $socket !== '' ? "mysql:unix_socket=$socket;dbname={$d['dbname']}"
                      : "mysql:host=$host;port=$port;dbname={$d['dbname']}";
try {
    $pdo = new PDO($dsn, $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $srv = $pdo->query('SELECT @@socket AS s, @@port AS p, @@version AS v')->fetch(PDO::FETCH_ASSOC);
    fwrite($err, "Datenbank-Server des Shops: MySQL/MariaDB {$srv['v']}, Socket {$srv['s']}, Port {$srv['p']}\n");
    if ($socket === '' && in_array(strtolower($host), ['localhost', ''], true)) {
        // genau den Socket nehmen, über den PDO eben verbunden hat; ersatzweise den, den der Server meldet
        $ini = (string) ini_get('pdo_mysql.default_socket');
        if ($ini !== '' && @file_exists($ini)) { $socket = $ini; }
        elseif ($srv['s'] !== '' && @file_exists($srv['s'])) { $socket = $srv['s']; }
        else { $host = '127.0.0.1'; $port = (int) $srv['p']; }
    }
} catch (Throwable $e) {
    fwrite($err, "Auch PHP kann sich mit den Zugangsdaten aus config.php nicht verbinden: " . $e->getMessage() . "\n");
    fwrite($err, "Verwendet: Benutzer {$d['username']}, Datenbank {$d['dbname']}, " . ($socket ?: "$host:$port") . "\n");
    exit(1);
}

// 2. Zugangsdaten in eine eigene, nur für uns lesbare Optionsdatei. --defaults-file sorgt dafür, dass
//    mysqldump KEINE anderen Optionsdateien liest (z. B. ~/.my.cnf des Hosters mit anderem Passwort).
$q = function ($v) { return '"' . addcslashes((string) $v, "\\\"") . '"'; };
$cnf = "[client]\nuser=" . $q($d['username']) . "\npassword=" . $q($d['password']) . "\n";
if ($socket !== '') { $cnf .= 'socket=' . $q($socket) . "\n"; }
else { $cnf .= 'host=' . $q($host) . "\nport=$port\n"; }
$old = umask(077);
$file = tempnam(sys_get_temp_dir(), 'ldcnf');
umask($old);
chmod($file, 0600);
file_put_contents($file, $cnf);
register_shutdown_function(function () use ($file) { @unlink($file); });
$args = [getenv('LIVE_DUMP') ?: 'mysqldump', '--defaults-file=' . $file, '--single-transaction', '--quick',
         '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4', $d['dbname']];
passthru(implode(' ', array_map('escapeshellarg', $args)), $rc);
@unlink($file);
if ($rc !== 0) {
    fwrite($err, "mysqldump verwendet: Benutzer {$d['username']}, Datenbank {$d['dbname']}, "
        . ($socket !== '' ? "Socket $socket" : "$host:$port") . " (PHP konnte sich verbinden)\n");
}
exit($rc);
PHP
gunzip -c shop-dump.sql.gz.tmp | tail -c 200 | grep -q 'Dump completed' \
    || { echo "Der Datenbank-Dump ist unvollständig (siehe Fehler oben)."; rm -f shop-dump.sql.gz.tmp; exit 1; }
if [ -e shop-dump.sql.gz ]; then
    ALT="shop-dump.$(date -r shop-dump.sql.gz +%Y%m%d-%H%M%S).sql.gz"
    mv shop-dump.sql.gz "$ALT"
    echo "Vorheriger Dump aufbewahrt als $ALT"
fi
mv shop-dump.sql.gz.tmp shop-dump.sql.gz
echo "Datenbank: shop-dump.sql.gz ($(du -h shop-dump.sql.gz | cut -f1))"

echo "== 3/3 Shop-Dateien kopieren =="
mkdir -p shop
if [ -n "$REMOTE_RSYNC" ]; then
    rsync -az --info=progress2 --rsync-path="$REMOTE_RSYNC" -e "${SSH[*]}" "${EXCLUDES[@]}" "$ZIEL:$PFAD/" shop/
else
    # Ersatz ohne rsync auf dem Server: tar-Strom über SSH (überträgt immer alles)
    TAR_EXCLUDES=""
    for e in "${EXCLUDES[@]}"; do d="${e#--exclude=/}"; d="${d%/}"; TAR_EXCLUDES+=" --exclude='./$d'"; done
    "${SSH[@]}" "$ZIEL" "cd '$PFAD' && '$REMOTE_TAR' -czf - $TAR_EXCLUDES ." | tar -xzf - -C shop
fi
echo "Dateien: shop/ ($(du -sh shop | cut -f1))"

echo
echo "Fertig. Weiter mit:  ./testsystem.sh import shop-dump.sql.gz"
echo "Achtung: Die Kopie enthält echte Kundendaten – vertraulich behandeln."
