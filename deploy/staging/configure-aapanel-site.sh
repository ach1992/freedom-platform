#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

if [ "$#" -ne 1 ] || [[ ! "$1" =~ ^[A-Za-z0-9.-]+$ ]]; then
    echo 'Usage: configure-aapanel-site.sh <domain>' >&2
    exit 64
fi

DOMAIN=$1
ROOT="/www/acdomains/$DOMAIN"
DOCROOT="$ROOT/current/public"
PANEL_PY=/www/server/panel/pyenv/bin/python3
PANEL=/www/server/panel
VHOST=/www/server/panel/vhost/openlitespeed/detail/$DOMAIN.conf
SITE_WRAPPER=/www/server/panel/vhost/openlitespeed/$DOMAIN.conf
USER_INI="$DOCROOT/.user.ini"
EVIDENCE=/root/freedom-bootstrap/site-http-evidence.txt

exec 9>/root/freedom-bootstrap/site-configuration.lock
flock -w 300 9 || {
    echo 'Site configuration lock timeout.' >&2
    exit 75
}

test -x "$PANEL_PY"
test -d "$DOCROOT"
test -f "$DOCROOT/index.php"
test -f "$DOCROOT/.htaccess"
test -L "$ROOT/current"
runuser -u www -- test -r "$DOCROOT/index.php"

cd "$PANEL"
DOMAIN="$DOMAIN" DOCROOT="$DOCROOT" "$PANEL_PY" - <<'PY'
import json
import os
import sys

sys.path.insert(0, '/www/server/panel')
sys.path.insert(0, '/www/server/panel/class')

import public
from panelSite import panelSite

domain = os.environ['DOMAIN']
docroot = os.environ['DOCROOT']
site = public.M('sites').where('name=?', (domain,)).find()

if not site:
    args = public.to_dict_obj({
        'webname': json.dumps({'domain': domain, 'domainlist': [], 'count': 0}),
        'path': docroot,
        'ftp': 'false',
        'sql': 'false',
        'codeing': 'utf8mb4',
        'type': 'PHP',
        'version': '84',
        'type_id': '0',
        'port': '80',
        'ps': 'Freedom Platform staging',
        'set_ssl': '0',
        'project_type': 'PHP',
    })
    result = panelSite().AddSite(args)
    if not result.get('siteStatus', False):
        raise SystemExit('aaPanel site creation failed without exposing internal details.')
    site = public.M('sites').where('name=?', (domain,)).find()

if not site:
    raise SystemExit('aaPanel site record is missing after creation.')

site_id = str(site['id'])
if site['path'] != docroot:
    result = panelSite().SetPath(public.to_dict_obj({'id': site_id, 'path': docroot}))
    if not result.get('status', False):
        raise SystemExit('aaPanel site path update failed without exposing internal details.')

print(f'site_id={site_id}')
print(f'site_name={domain}')
print(f'document_root={docroot}')
PY

test -f "$VHOST"
test -f "$SITE_WRAPPER"

chattr -i "$USER_INI" 2>/dev/null || true
printf 'open_basedir=%s/:/tmp/\n' "$ROOT/current/:$ROOT/shared" > "$USER_INI"
chown www:www "$USER_INI"
chmod 0644 "$USER_INI"
chattr +i "$USER_INI" 2>/dev/null || true

python3 - "$VHOST" "$ROOT" <<'PY'
from pathlib import Path
import os
import re
import sys

path = Path(sys.argv[1])
root = sys.argv[2]
content = path.read_text()
expected = f'php_admin_value open_basedir "/tmp/:{root}/current/:{root}/shared/"'
pattern = r'php_admin_value\s+open_basedir\s+"[^"]*"'

if re.search(pattern, content):
    updated, count = re.subn(pattern, expected, content, count=1)
    if count != 1:
        raise SystemExit('Unexpected OpenLiteSpeed open_basedir replacement count.')
else:
    marker = 'phpIniOverride  {'
    if marker not in content:
        raise SystemExit('OpenLiteSpeed phpIniOverride block is missing.')
    updated = content.replace(marker, marker + '\n' + expected, 1)

if updated != content:
    temporary = path.with_name(path.name + '.freedom-new')
    temporary.write_text(updated)
    os.chmod(temporary, path.stat().st_mode & 0o777)
    os.chown(temporary, path.stat().st_uid, path.stat().st_gid)
    temporary.replace(path)
PY

if ! /usr/local/lsws/bin/openlitespeed -t >/root/freedom-bootstrap/ols-config-check.log 2>&1; then
    grep -Eai 'error|failed|invalid|fatal' /root/freedom-bootstrap/ols-config-check.log \
        | tail -n 80 \
        | sed -E 's#https?://[^[:space:]]+#URL_REDACTED#g' >&2 \
        || true
    exit 1
fi

/usr/local/lsws/bin/lswsctrl restart >/dev/null
sleep 5

LOCAL_LIVE=$(curl --silent --show-error --fail \
    --max-time 15 \
    --header "Host: $DOMAIN" \
    http://127.0.0.1/health/live)
LOCAL_READY=$(curl --silent --show-error --fail \
    --max-time 15 \
    --header "Host: $DOMAIN" \
    http://127.0.0.1/health/ready)

python3 - "$LOCAL_LIVE" "$LOCAL_READY" <<'PY'
import json
import sys

live = json.loads(sys.argv[1])
ready = json.loads(sys.argv[2])

if live.get('status') != 'ok':
    raise SystemExit('Local liveness endpoint is not healthy.')
if ready.get('status') != 'ok':
    raise SystemExit('Local readiness endpoint is not healthy.')
PY

{
    echo '# aaPanel OpenLiteSpeed HTTP evidence'
    echo "utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "domain=$DOMAIN"
    echo "document_root=$DOCROOT"
    echo "current_release=$(basename "$(readlink -f "$ROOT/current")")"
    echo "site_record_count=$(cd "$PANEL" && DOMAIN="$DOMAIN" "$PANEL_PY" - <<'PY'
import os
import sys
sys.path.insert(0, '/www/server/panel')
sys.path.insert(0, '/www/server/panel/class')
import public
print(public.M('sites').where('name=?', (os.environ['DOMAIN'],)).count())
PY
)"
    echo "vhost_mode=$(stat -c %a "$VHOST")"
    echo "user_ini_mode=$(stat -c %a "$USER_INI")"
    echo "ols_processes=$(pgrep -fc 'lshttpd|litespeed' || true)"
    echo "listener_80=$(ss -lntH '( sport = :80 )' | wc -l)"
    echo "local_live_status=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["status"])' "$LOCAL_LIVE")"
    echo "local_ready_status=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["status"])' "$LOCAL_READY")"
} > "$EVIDENCE"

cat "$EVIDENCE"
