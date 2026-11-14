#!/usr/bin/env bash
# Nightly database dump to S3 (installed as a systemd timer by deploy.sh). Needs BUCKET and AWS_REGION in the environment.
set -euo pipefail

: "${BUCKET:?}" "${AWS_REGION:?}"
cd "${APP_DIR:-/opt/pluginxchange}"

name="dumps/pluginxchange-$(date -u +%Y-%m-%d-%H%M).sql.gz"
# pipefail makes a failed dump fail the whole command, so an empty file is never uploaded
docker compose exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines pluginxchange' \
  | gzip \
  | aws s3 cp - "s3://$BUCKET/$name" --region "$AWS_REGION"

echo "backup written to s3://$BUCKET/$name"
