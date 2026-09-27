#!/usr/bin/env bash
# hosting_check.sh — can this server host the Kaduna eDQA Docker stack? (DEPLOY.md §2.3, §4, §13)
#
# Run on the candidate server. Read-only by default: it inspects the host and makes outbound
# test connections, and changes nothing. --with-docker-test additionally pulls images and runs
# throwaway containers, then removes the containers, their volumes and any image it pulled.
#
# Usage:
#   sudo bash hosting_check.sh --odk-url https://odk.example.org \
#       [--smtp smtp.example.org[:587]] [--backup-endpoint https://s3.eu-central-003.backblazeb2.com] \
#       [--name hetzner-cpx31] [--with-docker-test] > hosting_<name>.md
#
# The markdown report goes to stdout; progress goes to stderr. sudo is optional but gives full
# firewall and port-owner detail. Exit status: 0 no FAIL, 1 at least one FAIL, 2 usage error.

set -Eeuo pipefail

readonly SCRIPT_VERSION="1.0"
readonly PG_IMAGE="postgres:16-bookworm"
readonly CHROME_IMAGE="debian:bookworm-slim"
readonly LABEL="edqa.hostcheck=1"

ODK_URL=""
SMTP=""
BACKUP_URL=""
NAME=""
DOCKER_TEST=0

usage() {
  sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//' >&2
  exit 2
}

while [ $# -gt 0 ]; do
  case "$1" in
    --odk-url) ODK_URL=${2:-}; shift 2 ;;
    --smtp) SMTP=${2:-}; shift 2 ;;
    --backup-endpoint) BACKUP_URL=${2:-}; shift 2 ;;
    --name) NAME=${2:-}; shift 2 ;;
    --with-docker-test) DOCKER_TEST=1; shift ;;
    -h | --help) usage ;;
    *) echo "Unknown option: $1" >&2; usage ;;
  esac
done
[ -n "$ODK_URL" ] || { echo "--odk-url is required" >&2; usage; }
case "$ODK_URL" in https://*) ;; *) echo "--odk-url must start with https://" >&2; exit 2 ;; esac
[ -n "$NAME" ] || NAME=$(hostname 2>/dev/null || echo unknown)

IS_ROOT=0
[ "$(id -u)" -eq 0 ] && IS_ROOT=1

have() { command -v "$1" >/dev/null 2>&1; }
log() { echo "[hosting_check] $*" >&2; }

# ------------------------------------------------------------------------------ report buffer

REPORT=""
PASS_N=0
WARN_N=0
FAIL_N=0

section() {
  REPORT+=$'\n'"## $1"$'\n\n'"| Result | Check | Detail |"$'\n'"|---|---|---|"$'\n'
}

row() {
  local status=$1 check=$2 detail=${3:-}
  case "$status" in
    PASS) PASS_N=$((PASS_N + 1)) ;;
    WARN) WARN_N=$((WARN_N + 1)) ;;
    FAIL) FAIL_N=$((FAIL_N + 1)) ;;
  esac
  detail=${detail//|/\\|}
  detail=${detail//$'\n'/<br>}
  REPORT+="| **${status}** | ${check} | ${detail} |"$'\n'
}

note() {
  REPORT+=$'\n'"$1"$'\n'
}

block() {
  local body=${1:-"(none)"}
  REPORT+=$'\n''```text'$'\n'"${body}"$'\n''```'$'\n'
}

version_ge() { # version_ge 6.8.0 5.15 -> true
  [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
}

# ------------------------------------------------------------------------------ 1. host

check_host() {
  log "host"
  section "1. Host"

  local os="unknown"
  if [ -r /etc/os-release ]; then
    os=$(sed -n 's/^PRETTY_NAME=//p' /etc/os-release | tr -d '"')
  fi
  case "$os" in
    "Ubuntu 24.04"*) row PASS "Operating system" "$os" ;;
    Ubuntu* | Debian*) row WARN "Operating system" "$os — DEPLOY.md §13.1 targets Ubuntu 24.04 LTS" ;;
    *) row WARN "Operating system" "$os — not Ubuntu/Debian; the Ansible roles target Ubuntu 24.04 LTS" ;;
  esac

  local cpus model
  cpus=$(nproc 2>/dev/null || echo 0)
  model=$(awk -F': ' '/^model name/ {print $2; exit}' /proc/cpuinfo 2>/dev/null || true)
  if [ "$cpus" -ge 4 ]; then
    row PASS "CPU" "$cpus vCPU ${model:+($model)}"
  elif [ "$cpus" -ge 2 ]; then
    row WARN "CPU" "$cpus vCPU ${model:+($model)} — staging size; production needs 4"
  else
    row FAIL "CPU" "$cpus vCPU — below the 2 vCPU staging minimum"
  fi

  local mem_kb mem_gib swap_kb
  mem_kb=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)
  swap_kb=$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)
  mem_gib=$(awk -v k="$mem_kb" 'BEGIN {printf "%.1f", k / 1048576}')
  local swap_note
  swap_note="swap $(awk -v k="$swap_kb" 'BEGIN {printf "%.1f", k / 1048576}') GiB (§13.1 sets 2 GB)"
  if [ "$mem_kb" -ge 7340032 ]; then
    row PASS "RAM" "$mem_gib GiB; $swap_note"
  elif [ "$mem_kb" -ge 3670016 ]; then
    row WARN "RAM" "$mem_gib GiB — staging size; production needs 8 GB; $swap_note"
  else
    row FAIL "RAM" "$mem_gib GiB — below the 4 GB staging minimum"
  fi

  local target=/ size_kb free_kb
  [ -d /var/lib/docker ] && target=/var/lib/docker
  read -r size_kb free_kb < <(df -Pk "$target" | awk 'NR == 2 {print $2, $4}')
  local disk
  disk=$(awk -v s="$size_kb" -v f="$free_kb" -v t="$target" \
    'BEGIN {printf "%.0f GiB free of %.0f GiB on %s", f / 1048576, s / 1048576, t}')
  if [ "$free_kb" -ge 94371840 ]; then
    row PASS "Disk" "$disk"
  elif [ "$free_kb" -ge 47185920 ]; then
    row WARN "Disk" "$disk — staging size; production needs 120 GB SSD"
  else
    row FAIL "Disk" "$disk — below the 60 GB staging size"
  fi

  local virt=""
  if have systemd-detect-virt; then
    virt=$(systemd-detect-virt 2>/dev/null || true)
  fi
  if [ -z "$virt" ]; then
    if [ -e /proc/user_beancounters ] || [ -d /proc/vz ]; then virt="openvz"; fi
  fi
  case "$virt" in
    openvz | lxc | lxc-libvirt)
      row FAIL "Virtualisation" "$virt — a container-based VPS; Docker won't run properly here. Choose a KVM VPS" ;;
    docker | podman | rkt | systemd-nspawn | wsl)
      row FAIL "Virtualisation" "$virt — this is running inside a container/WSL, not on the server itself" ;;
    none) row PASS "Virtualisation" "bare metal" ;;
    "") row WARN "Virtualisation" "could not determine (no systemd-detect-virt); ask the provider: KVM required" ;;
    *) row PASS "Virtualisation" "$virt" ;;
  esac
}

# ------------------------------------------------------------------------------ 2. kernel

check_kernel() {
  log "kernel"
  section "2. Kernel"

  local release kver
  release=$(uname -r)
  kver=${release%%-*}
  if version_ge "$kver" "5.15"; then
    row PASS "Kernel ≥ 5.15" "$release"
  else
    row FAIL "Kernel ≥ 5.15" "$release"
  fi

  local cg
  cg=$(stat -fc %T /sys/fs/cgroup 2>/dev/null || echo unknown)
  if [ "$cg" = "cgroup2fs" ]; then
    row PASS "cgroups v2" "unified hierarchy"
  else
    row FAIL "cgroups v2" "/sys/fs/cgroup is '$cg' (v1 or hybrid). Fixable with the kernel parameter systemd.unified_cgroup_hierarchy=1 only if the provider allows it"
  fi

  if grep -qw overlay /proc/filesystems 2>/dev/null; then
    row PASS "overlay filesystem" "loaded"
  elif have modinfo && modinfo overlay >/dev/null 2>&1; then
    row PASS "overlay filesystem" "module available (not loaded yet; Docker loads it)"
  else
    row FAIL "overlay filesystem" "not in /proc/filesystems and no overlay module — overlay2 storage driver unavailable"
  fi
}

# ------------------------------------------------------------------------------ 3. docker

DOCKER_OK=0

check_docker() {
  log "docker"
  section "3. Docker"

  # Only trust output that looks like a version: WSL and some images ship a `docker` stub that
  # prints an explanation to stdout.
  local client=""
  if have docker; then
    client=$(docker version --format '{{.Client.Version}}' 2>/dev/null || true)
  fi
  if ! [[ $client =~ ^[0-9]+\. ]]; then
    row WARN "Docker Engine" "not installed — we install it from Docker's apt repo (§13.1). Confirm the provider permits Docker"
    row WARN "Compose v2" "not installed — comes with docker-compose-plugin"
    return 0
  fi

  local server
  if ! server=$(docker version --format '{{.Server.Version}}' 2>/dev/null) || [ -z "$server" ]; then
    row WARN "Docker Engine" "client ${client:-?} present but the daemon is unreachable (not running, or run with sudo / as a docker-group member)"
  else
    DOCKER_OK=1
    local origin="unknown package"
    if have dpkg-query; then
      if dpkg-query -W -f='${Status}' docker-ce 2>/dev/null | grep -q "ok installed"; then
        origin="docker-ce (Docker's repo)"
      elif dpkg-query -W -f='${Status}' docker.io 2>/dev/null | grep -q "ok installed"; then
        origin="docker.io (Ubuntu package; §13.1 wants Docker's official repo)"
      fi
    fi
    row PASS "Docker Engine" "$server, $origin"

    local driver cgv
    driver=$(docker info --format '{{.Driver}}' 2>/dev/null || echo "?")
    cgv=$(docker info --format '{{.CgroupVersion}}' 2>/dev/null || echo "?")
    case "$driver" in
      overlay2) row PASS "Storage driver" "$driver" ;;
      overlayfs) row PASS "Storage driver" "$driver (containerd image store, the default from Docker 29; overlay-based like overlay2)" ;;
      *) row WARN "Storage driver" "$driver — overlay2 expected" ;;
    esac
    if [ "$cgv" = "2" ]; then
      row PASS "Docker cgroup version" "$cgv"
    else
      row WARN "Docker cgroup version" "$cgv — 2 expected"
    fi
  fi

  local compose legacy=""
  compose=$(docker compose version --short 2>/dev/null || true)
  have docker-compose && legacy=$(docker-compose version --short 2>/dev/null || true)
  if [[ $compose =~ ^v?([2-9]|[1-9][0-9])\. ]]; then
    row PASS "Compose v2" "$compose"
  elif [[ $legacy =~ ^[0-9]+\. ]]; then
    row WARN "Compose v2" "only legacy docker-compose $legacy; install docker-compose-plugin"
  else
    row WARN "Compose v2" "not installed — comes with docker-compose-plugin"
  fi
}

# ------------------------------------------------------------------------------ 4. control panels

check_panels() {
  log "control panels"
  section "4. Control panels"

  if [ -d /usr/local/cpanel ] || [ -f /etc/cpupdate.conf ] || pgrep -x cpsrvd >/dev/null 2>&1; then
    local v=""
    [ -r /usr/local/cpanel/version ] && v=" $(cat /usr/local/cpanel/version)"
    row FAIL "cPanel/WHM" "detected${v}. Not supported (DEPLOY.md §4): cPanel's services, firewall and port ownership conflict with a Docker edge proxy. If only a cPanel box exists, provision a separate small VPS"
  else
    row PASS "cPanel/WHM" "not detected"
  fi

  if [ -d /usr/local/psa ]; then
    row WARN "Plesk" "detected — like cPanel it owns ports 80/443 and the firewall; not covered by DEPLOY.md §4, treat as unsupported"
  fi
  if [ -d /usr/local/directadmin ]; then
    row WARN "DirectAdmin" "detected — like cPanel it owns ports 80/443 and the firewall; not covered by DEPLOY.md §4, treat as unsupported"
  fi
}

# ------------------------------------------------------------------------------ 5. docker test

PG_CTR="edqa-hostcheck-pg-$$"
CHROME_CTR="edqa-hostcheck-chrome-$$"
PULLED_IMAGES=()
VOLUMES_BEFORE=""
CLEANED=0

cleanup_docker() {
  [ "$DOCKER_TEST" -eq 1 ] && [ "$DOCKER_OK" -eq 1 ] && [ "$CLEANED" -eq 0 ] || return 0
  CLEANED=1
  log "removing test containers and pulled images"
  docker rm -fv "$PG_CTR" "$CHROME_CTR" >/dev/null 2>&1 || true
  local img
  for img in ${PULLED_IMAGES[@]+"${PULLED_IMAGES[@]}"}; do
    docker image rm "$img" >/dev/null 2>&1 || true
  done
}
trap cleanup_docker EXIT
trap 'exit 130' INT TERM
trap 'echo "[hosting_check] unexpected error at line ${LINENO}; report incomplete" >&2' ERR

ensure_image() {
  local img=$1
  if docker image inspect "$img" >/dev/null 2>&1; then
    return 0
  fi
  log "pulling $img"
  docker pull -q "$img" >/dev/null 2>&1 || return 1
  PULLED_IMAGES+=("$img")
}

pg_run() { # pg_run LABEL <<SQL ; records PASS/FAIL
  local label=$1 out
  if out=$(docker exec -i "$PG_CTR" psql -X -q -tA -v ON_ERROR_STOP=1 -U postgres -d edqa_check 2>&1); then
    row PASS "$label" "${out:-ok}"
  else
    row FAIL "$label" "$(echo "$out" | tail -n 3)"
  fi
}

check_postgres() {
  if ! ensure_image "$PG_IMAGE"; then
    row FAIL "Pull $PG_IMAGE" "docker pull failed (outbound to Docker Hub blocked?)"
    return 0
  fi
  # No network and a tmpfs data directory: nothing can reach it and nothing is left on disk.
  if ! docker run -d --name "$PG_CTR" --label "$LABEL" --network none \
    --tmpfs /var/lib/postgresql/data:rw,size=512m \
    -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_DB=edqa_check \
    "$PG_IMAGE" >/dev/null 2>&1; then
    row FAIL "Postgres 16 container" "docker run failed"
    return 0
  fi

  local i ready=0
  for i in $(seq 1 60); do
    # TCP on 127.0.0.1 only answers once the init-time server has been replaced by the real one.
    if docker exec "$PG_CTR" pg_isready -q -h 127.0.0.1 -U postgres >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 1
  done
  if [ "$ready" -ne 1 ]; then
    row FAIL "Postgres 16 container" "not ready after ${i}s: $(docker logs --tail 3 "$PG_CTR" 2>&1 | tr '\n' ' ')"
    return 0
  fi
  row PASS "Postgres 16 container" "$(docker exec "$PG_CTR" psql -XtA -U postgres -c 'SHOW server_version' 2>&1)"

  pg_run "CHECK constraint rejects 347.66" <<'SQL'
CREATE TABLE scores (id serial PRIMARY KEY, score numeric(5,2) CHECK (score BETWEEN 0 AND 100));
DO $$
BEGIN
  INSERT INTO scores (score) VALUES (347.66);
  RAISE EXCEPTION 'CHECK constraint was not enforced';
EXCEPTION WHEN check_violation THEN
  NULL;
END $$;
SELECT 'rejected with SQLSTATE 23514';
SQL

  pg_run "jsonb GIN index" <<'SQL'
CREATE TABLE submissions (id serial PRIMARY KEY, payload jsonb NOT NULL);
INSERT INTO submissions (payload) SELECT jsonb_build_object('lga', 'Chikun', 'n', g) FROM generate_series(1, 1000) g;
CREATE INDEX submissions_payload_gin ON submissions USING gin (payload jsonb_path_ops);
SELECT count(*) || ' rows match via @>' FROM submissions WHERE payload @> '{"lga": "Chikun"}';
SQL

  pg_run "pg_trgm" <<'SQL'
CREATE EXTENSION pg_trgm;
CREATE TABLE facilities (id serial PRIMARY KEY, name text);
INSERT INTO facilities (name) VALUES ('PHC Kawo'), ('Barnawa Health Centre');
CREATE INDEX facilities_name_trgm ON facilities USING gin (name gin_trgm_ops);
SELECT 'similarity(PHC Kawo, P.H.C Kawo) = ' || round(similarity('PHC Kawo', 'P.H.C Kawo')::numeric, 2);
SQL

  pg_run "Materialised view + unique index + REFRESH CONCURRENTLY" <<'SQL'
CREATE TABLE assessment_scores (facility_id int, score numeric(5,2));
INSERT INTO assessment_scores SELECT g % 50, (g % 100)::numeric FROM generate_series(1, 5000) g;
CREATE MATERIALIZED VIEW round_aggregates AS
  SELECT facility_id, avg(score) AS overall, count(*) AS n FROM assessment_scores GROUP BY facility_id;
CREATE UNIQUE INDEX round_aggregates_key ON round_aggregates (facility_id);
INSERT INTO assessment_scores VALUES (999, 42);
REFRESH MATERIALIZED VIEW CONCURRENTLY round_aggregates;
SELECT count(*) || ' rows after concurrent refresh' FROM round_aggregates;
SQL

  docker rm -fv "$PG_CTR" >/dev/null 2>&1 || true
}

check_chromium() {
  if ! ensure_image "$CHROME_IMAGE"; then
    row FAIL "Pull $CHROME_IMAGE" "docker pull failed (outbound to Docker Hub blocked?)"
    return 0
  fi
  local out rc=0
  # Mirrors worker-exports: 256 MB /dev/shm, 1.5 GB memory limit, --no-sandbox, a data: URL
  # (Chromium only ever renders the app's own pages, never external URLs — DEPLOY.md §12).
  # shellcheck disable=SC2016  # the inner script is expanded by the container's sh
  out=$(timeout 900 docker run --rm --name "$CHROME_CTR" --label "$LABEL" \
    --shm-size=256m --memory=1536m "$CHROME_IMAGE" sh -c '
      export DEBIAN_FRONTEND=noninteractive
      apt-get update -qq >/dev/null 2>&1 &&
        apt-get install -y -qq --no-install-recommends chromium fonts-liberation >/dev/null 2>&1 ||
        { echo "STEP=install-failed"; exit 3; }
      echo "STEP=installed $(chromium --version 2>/dev/null)"
      echo "SHM_KB=$(df -Pk /dev/shm | awk "NR == 2 {print \$2}")"
      chromium --headless --no-sandbox --disable-gpu --print-to-pdf=/tmp/edqa.pdf \
        "data:text/html,<h1>Kaduna eDQA host check</h1><p>PDF render test</p>" >/dev/null 2>&1
      [ "$(head -c 4 /tmp/edqa.pdf 2>/dev/null)" = "%PDF" ] ||
        { echo "STEP=render-failed"; exit 4; }
      echo "PDF_BYTES=$(wc -c < /tmp/edqa.pdf)"
    ' 2>&1) || rc=$?

  local shm version bytes
  shm=$(echo "$out" | sed -n 's/^SHM_KB=//p')
  version=$(echo "$out" | sed -n 's/^STEP=installed //p')
  bytes=$(echo "$out" | sed -n 's/^PDF_BYTES=//p')
  if [ "$rc" -eq 0 ] && [ -n "$bytes" ]; then
    row PASS "Chromium PDF render" "$version, --no-sandbox, /dev/shm ${shm} kB: ${bytes}-byte PDF"
  elif echo "$out" | grep -q "STEP=install-failed"; then
    row FAIL "Chromium PDF render" "apt install inside the container failed (outbound to deb.debian.org blocked?)"
  elif echo "$out" | grep -q "STEP=render-failed"; then
    row FAIL "Chromium PDF render" "$version installed but PDF rendering failed (/dev/shm ${shm} kB)"
  else
    row FAIL "Chromium PDF render" "exit $rc: $(echo "$out" | tail -n 3)"
  fi
}

check_docker_test() {
  section "5. Docker workload test"
  if [ "$DOCKER_TEST" -ne 1 ]; then
    row WARN "Docker workload test" "not run (pass --with-docker-test once Docker is installed)"
    return 0
  fi
  if [ "$DOCKER_OK" -ne 1 ]; then
    row FAIL "Docker workload test" "requested, but the Docker daemon is not available"
    return 0
  fi
  log "docker workload test (pulls images; takes a few minutes)"
  VOLUMES_BEFORE=$(docker volume ls -q | sort)
  check_postgres
  check_chromium
  cleanup_docker

  local left new_volumes
  left=$(docker ps -aq --filter "label=$LABEL" | tr '\n' ' ')
  new_volumes=$(comm -13 <(echo "$VOLUMES_BEFORE") <(docker volume ls -q | sort) | tr '\n' ' ')
  if [ -z "${left// /}" ] && [ -z "${new_volumes// /}" ]; then
    row PASS "Cleanup" "test containers and volumes removed; pulled images removed: ${PULLED_IMAGES[*]:-none (all were already present)}"
  else
    row FAIL "Cleanup" "left behind — containers: ${left:-none}; volumes: ${new_volumes:-none}"
  fi
}

# ------------------------------------------------------------------------------ 6. outbound

https_check() { # https_check LABEL URL
  local label=$1 url=$2 out
  if ! have curl; then
    row WARN "$label" "curl not installed; cannot test $url"
    return 0
  fi
  if out=$(curl -sS -o /dev/null --proto '=https' --max-time 15 \
    -w 'HTTP %{http_code} in %{time_total}s from %{remote_ip}' "$url" 2>&1); then
    row PASS "$label" "$url — $out"
  else
    row FAIL "$label" "$url — ${out:-no response}"
  fi
}

check_outbound() {
  log "outbound connectivity"
  section "6. Outbound connectivity"

  local odk=${ODK_URL%/}
  https_check "ODK Central" "$odk/"
  if have curl; then
    local ver
    if ver=$(curl -sSf --proto '=https' --max-time 15 "$odk/version.txt" 2>/dev/null); then
      ver=${ver//$'\n'/ }
      ver=${ver:0:300}
      row PASS "ODK Central version" "${ver:-empty} (answers Q-01: Central, not Aggregate)"
    else
      row WARN "ODK Central version" "$odk/version.txt not available — confirm the server is ODK Central (Q-01)"
    fi
  fi

  https_check "GitHub Container Registry" "https://ghcr.io/v2/"

  if [ -z "$SMTP" ]; then
    row WARN "SMTP relay" "not tested (pass --smtp host[:port])"
  else
    check_smtp
  fi

  if [ -z "$BACKUP_URL" ]; then
    row WARN "Backup storage" "not tested (pass --backup-endpoint https://…)"
  else
    https_check "Backup storage" "$BACKUP_URL"
  fi
}

check_smtp() {
  local host=${SMTP%%:*} port=587
  [ "$host" != "$SMTP" ] && port=${SMTP##*:}
  local blocked="many VPS providers block outbound SMTP until you ask (DigitalOcean always; Hetzner on new accounts)"

  # shellcheck disable=SC2016  # $1/$2 are expanded by the inner bash
  if ! timeout 10 bash -c 'exec 3<>"/dev/tcp/$1/$2"' _ "$host" "$port" 2>/dev/null; then
    row FAIL "SMTP relay" "$host:$port — TCP connection failed; $blocked"
    return 0
  fi
  if ! have openssl; then
    row PASS "SMTP relay" "$host:$port — TCP connection OK (openssl not installed, TLS not tested)"
    return 0
  fi
  local tls_args=(-starttls smtp)
  [ "$port" = "465" ] && tls_args=()
  local out
  out=$(timeout 20 openssl s_client "${tls_args[@]}" -connect "$host:$port" -servername "$host" \
    -verify_return_error -brief </dev/null 2>&1 || true)
  if echo "$out" | grep -qi "Protocol version"; then
    row PASS "SMTP relay" "$host:$port — TLS OK ($(echo "$out" | grep -i 'Protocol version' | head -n1 | sed 's/^ *//'))"
  else
    row FAIL "SMTP relay" "$host:$port — TCP OK but TLS failed: $(echo "$out" | tail -n 2 | tr '\n' ' ')"
  fi
}

# ------------------------------------------------------------------------------ 7. ports

LISTENERS=""

check_ports() {
  log "ports"
  section "7. Ports 80 and 443"

  if have ss; then
    LISTENERS=$(ss -H -ltnup 2>/dev/null || true)
  else
    row WARN "Listening ports" "ss (iproute2) is not installed"
    return 0
  fi

  local port proto busy
  for spec in tcp:80 tcp:443 udp:443; do
    proto=${spec%%:*}
    port=${spec##*:}
    busy=$(echo "$LISTENERS" | awk -v p="$proto" -v port="$port" \
      '$1 ~ p { n = split($5, a, ":"); if (a[n] == port) print }')
    if [ -z "$busy" ]; then
      row PASS "$port/$proto free" ""
    else
      row FAIL "$port/$proto free" "in use: $(echo "$busy" | awk '{print $5, $7}' | tr '\n' ' ')"
    fi
  done
  [ "$IS_ROOT" -eq 1 ] || note "Run with sudo to see which process owns each port."
  note "All listening sockets:"
  block "$LISTENERS"
  note "Inbound reachability can only be tested from outside. From another machine: \`nc -vz <server> 80\`, \`nc -vz <server> 443\`, and after go-live \`nmap -p- <server>\` should show only 22, 80, 443 (DEPLOY.md §13.4)."
}

# ------------------------------------------------------------------------------ 8. firewall

check_firewall() {
  log "firewall"
  section "8. Firewall"

  local active=0 detail=""
  if have ufw; then
    if [ "$IS_ROOT" -eq 1 ]; then
      detail=$(ufw status verbose 2>&1 || true)
      echo "$detail" | grep -q "Status: active" && active=1
      row INFO "ufw" "$(echo "$detail" | head -n 1)"
    else
      row INFO "ufw" "installed; run with sudo to read its status"
    fi
  else
    row INFO "ufw" "not installed"
  fi

  if have systemctl && systemctl is-active --quiet firewalld 2>/dev/null; then
    active=1
    row INFO "firewalld" "active"
  fi

  local ipt=""
  if have iptables && [ "$IS_ROOT" -eq 1 ]; then
    ipt=$(iptables -S 2>/dev/null || true)
    row INFO "iptables" "$(echo "$ipt" | grep -c '^-A' || true) rules; DOCKER-USER chain $(echo "$ipt" | grep -q '^-N DOCKER-USER' && echo present || echo absent)"
    echo "$ipt" | grep -q '^-P INPUT DROP' && active=1
  elif have iptables; then
    row INFO "iptables" "run with sudo to read the rules"
  fi

  if [ "$active" -eq 1 ]; then
    row PASS "Host firewall" "active"
  elif [ "$IS_ROOT" -eq 1 ]; then
    row WARN "Host firewall" "no active firewall found; the Ansible firewall role configures ufw (22, 80, 443/tcp, 443/udp; default deny)"
  else
    row WARN "Host firewall" "unknown without sudo"
  fi
  [ -n "$detail" ] && block "$detail"

  note "**Reminder:** Docker writes its own iptables rules and **bypasses ufw** for any published port. Only \`web\` may publish ports (80, 443/tcp, 443/udp), and a \`DOCKER-USER\` rule drops other inbound container traffic (DEPLOY.md §13.4)."
}

# ------------------------------------------------------------------------------ main

main() {
  check_host
  check_kernel
  check_docker
  check_panels
  check_docker_test
  check_outbound
  check_ports
  check_firewall

  local verdict="No blockers found"
  [ "$WARN_N" -gt 0 ] && verdict="No blockers; review the warnings"
  [ "$FAIL_N" -gt 0 ] && verdict="**Not suitable as it stands** — see FAIL rows"

  cat <<EOF
# Hosting check: ${NAME}

| | |
|---|---|
| Generated | $(date -u '+%Y-%m-%d %H:%M UTC') by \`discovery/hosting_check.sh\` v${SCRIPT_VERSION} |
| Mode | $([ "$DOCKER_TEST" -eq 1 ] && echo "read-only checks + Docker workload test (throwaway containers, removed)" || echo "read-only") |
| Ran as | $([ "$IS_ROOT" -eq 1 ] && echo "root" || echo "unprivileged user (some firewall/port detail unavailable)") |
| ODK Central | ${ODK_URL} |
| Result | ${verdict}: ${PASS_N} PASS · ${WARN_N} WARN · ${FAIL_N} FAIL |

Requirements: DEPLOY.md §2.3 (sizing: production 4 vCPU / 8 GB / 120 GB SSD; staging 2 / 4 / 60)
and §4 (KVM, kernel ≥ 5.15, cgroups v2, overlay2, outbound HTTPS, ports 80/443).
EOF
  printf '%s' "$REPORT"
}

main
[ "$FAIL_N" -eq 0 ] || exit 1
