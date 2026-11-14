#!/usr/bin/env bash
# Deploys one image on the instance. Run as root by the release workflow through SSM Run Command, or by hand in a Session
# Manager shell. Needs IMAGE, BUCKET and AWS_REGION in the environment.
#
#   1. fetches the compose files from S3 and the secrets from SSM Parameter Store
#   2. pulls the image, then restarts the stack on it
#   3. waits for the API to answer /health with the database up
#   4. if it does not, puts the previous image back and exits non-zero, which fails the release job
set -euo pipefail

: "${IMAGE:?}" "${BUCKET:?}" "${AWS_REGION:?}"
# SSM Run Command starts scripts without HOME, and docker login needs somewhere to keep its config
export HOME="${HOME:-/root}"
# Where things go on the instance (the defaults are right there; they are variables so the script can be tested elsewhere)
APP_DIR="${APP_DIR:-/opt/pluginxchange}"
BIN_DIR="${BIN_DIR:-/usr/local/bin}"
SYSTEMD_DIR="${SYSTEMD_DIR:-/etc/systemd/system}"
mkdir -p "$APP_DIR" "$BIN_DIR" "$SYSTEMD_DIR"
cd "$APP_DIR"

compose() { docker compose "$@"; }

if ! docker compose version > /dev/null 2>&1; then
  echo "Docker Compose is not installed yet: the instance is probably still doing its first-boot setup. Retry in a few minutes."
  exit 1
fi

# True when the API container answers /health with the database up (works with or without TLS in front)
healthy() {
  for _ in $(seq 1 30); do
    if compose exec -T app php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health") === false ? 1 : 0);' 2>/dev/null; then
      return 0
    fi
    sleep 2
  done
  return 1
}

param() {
  aws ssm get-parameter --name "/pluginxchange/$1" --with-decryption --query Parameter.Value --output text \
    --region "$AWS_REGION"
}

# Writes .env for an image. Secrets only ever live in this file (root-only) and in SSM.
# IMAGE is also exported: a variable in the environment beats .env for every later compose call, so it must always
# name the image we mean to run (the rollback would otherwise keep the new image).
use_image() {
  export IMAGE="$1"
  write_env "$1"
}

write_env() {
  (
    umask 077
    {
      echo "IMAGE=$1"
      echo "DB_PASSWORD=$(param db_password)"
      echo "DB_ROOT_PASSWORD=$(param db_root_password)"
      echo "ADMIN_API_KEY=$(param admin_api_key)"
      echo "SITE_ADDRESS=$(param site_address)"
    } > .env.new
  )
  mv .env.new .env
}

echo "== fetching deploy files"
aws s3 cp "s3://$BUCKET/deploy/docker-compose.prod.yml" docker-compose.yml --region "$AWS_REGION" --only-show-errors
aws s3 cp "s3://$BUCKET/deploy/Caddyfile" Caddyfile --region "$AWS_REGION" --only-show-errors
aws s3 cp "s3://$BUCKET/deploy/schema.sql" schema.sql --region "$AWS_REGION" --only-show-errors
aws s3 cp "s3://$BUCKET/deploy/backup.sh" "$BIN_DIR/pxc-backup" --region "$AWS_REGION" --only-show-errors
chmod 755 "$BIN_DIR/pxc-backup"

echo "== nightly backup timer"
cat > "$SYSTEMD_DIR/pxc-backup.service" <<UNIT
[Unit]
Description=PluginXchange database backup to S3

[Service]
Type=oneshot
Environment=BUCKET=$BUCKET
Environment=AWS_REGION=$AWS_REGION
ExecStart=$BIN_DIR/pxc-backup
UNIT
cat > "$SYSTEMD_DIR/pxc-backup.timer" <<'UNIT'
[Unit]
Description=Nightly PluginXchange database backup

[Timer]
OnCalendar=*-*-* 03:15:00 UTC
RandomizedDelaySec=10m
Persistent=true

[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now pxc-backup.timer

previous=""
if [ -f .env ]; then
  previous="$(grep '^IMAGE=' .env | cut -d= -f2- || true)"
fi

echo "== pulling $IMAGE"
aws ecr get-login-password --region "$AWS_REGION" | docker login --username AWS --password-stdin "${IMAGE%%/*}"
# IMAGE in the environment wins over .env, so a bad tag fails here and the running version is not touched
if ! IMAGE="$IMAGE" compose pull; then
  echo "cannot pull $IMAGE, nothing was changed"
  exit 1
fi

echo "== starting $IMAGE"
use_image "$IMAGE"
# A failed "up" is handled like an unhealthy start: fall through to the health check and the rollback
compose up -d --remove-orphans || echo "docker compose up failed"

if healthy; then
  for f in docker-compose.yml Caddyfile; do cp "$f" "$f.good"; done
  echo "== deployed $IMAGE (previous: ${previous:-none})"
  docker image prune -f > /dev/null
  exit 0
fi

echo "== $IMAGE did not become healthy"
compose logs --tail 40 app || true
if [ -n "$previous" ] && [ "$previous" != "$IMAGE" ]; then
  echo "== rolling back to $previous"
  # The last known good files go back together with the last known good image (a broken compose file must not survive)
  for f in docker-compose.yml Caddyfile; do
    if [ -f "$f.good" ]; then cp "$f.good" "$f"; fi
  done
  use_image "$previous"
  compose up -d --remove-orphans || echo "docker compose up failed"
  if healthy; then
    echo "== rolled back to $previous"
  else
    echo "== rollback to $previous is not healthy either"
  fi
fi
exit 1
