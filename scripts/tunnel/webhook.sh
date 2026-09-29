#!/usr/bin/env bash
set -euo pipefail
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
compose=(docker compose -f "$project_dir/compose.tunnel.yml")
case "${1:-url}" in
  start)
    docker cp "$project_dir/scripts/tunnel/nginx.conf" dev-nginx:/etc/nginx/conf.d/rukun-webhook-tunnel.conf
    docker exec dev-nginx nginx -t
    docker exec dev-nginx nginx -s reload
    "${compose[@]}" up -d
    echo 'Tunnel started. Run scripts/tunnel/webhook.sh url to obtain the notification URL.'
    ;;
  stop) "${compose[@]}" down ;;
  renew)
    "${compose[@]}" restart cloudflared
    echo 'Tunnel renewed. Run scripts/tunnel/webhook.sh url and update the Midtrans notification URL.'
    ;;
  url)
    container_id="$("${compose[@]}" ps --status running -q cloudflared)"
    if [[ -z "$container_id" ]]; then
      echo 'Tunnel is not running. Run scripts/tunnel/webhook.sh start first.' >&2
      exit 1
    fi
    started_at="$(docker inspect --format '{{.State.StartedAt}}' "$container_id")"
    tunnel_url="$("${compose[@]}" logs --no-color --since "$started_at" cloudflared 2>/dev/null | sed -nE 's@.*(https://[a-z0-9-]+\.trycloudflare\.com).*@\1@p' | tail -n 1)"
    if [[ -z "$tunnel_url" ]]; then
      echo 'URL not available yet. Start the tunnel, then retry after a few seconds.' >&2
      exit 1
    fi
    printf '%s/api/payments/webhooks/midtrans\n' "$tunnel_url"
    ;;
  *) echo 'Usage: scripts/tunnel/webhook.sh {start|stop|renew|url}' >&2; exit 2 ;;
esac
