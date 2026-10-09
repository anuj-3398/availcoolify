# Avail log and metrics shipper (Grafana Alloy -> SigNoz)

**Status on the testing VM: switched off.** Nothing starts it: deploying AvailCoolify does not run this. It only runs when someone starts it by hand (below), and it sends logs and metrics to SigNoz from then on until it is stopped (`docker compose down`).

**Production:** the release workflow (`.github/workflows/release.yml`) starts it on every production VM through `scripts/prod/deploy-platform.sh`, but only when the SSM parameter `SIGNOZ_OTLP_ENDPOINT` exists. It sets `HOST_NAME` (the Teleport node name) and `DEPLOYMENT_ENVIRONMENT=production`.

Ships the stdout/stderr of every Docker container on the host (coolify, coolify-proxy, apps) to SigNoz over OTLP/HTTP, plus host metrics (CPU, memory, disk, network) and per-container metrics (cAdvisor), scraped every 60 s. The SigNoz endpoint takes no ingestion key.
Runs standalone, outside Coolify. Docker keeps only 3 x 10 MB per container, so this is the long-term log store.

    cp .env.example .env   # set OTEL_EXPORTER_OTLP_ENDPOINT
    docker compose up -d

Log attributes: `service.name` and `container_name` = container name, plus `container_id`, `coolify_type`, `coolify_name`, `coolify_application_id`, `host_name`, `deployment_environment`. Metrics carry `host_name` and `deployment_environment` too.
For the metrics the container mounts the host's `/proc`, `/sys`, `/`, `/var/lib/docker` and containerd's socket read-only and uses the host's cgroup namespace. Docker 29 keeps images in containerd, so cAdvisor needs that socket to see containers; it uses about 360 MB of memory on the testing VM (limit 768 MB).
Check delivery: `nsenter -t $(docker inspect -f '{{.State.Pid}}' avail-log-shipper) -n curl -s localhost:12345/metrics | grep otelcol_exporter`
Secrets in app output are shipped too; limit who can read the SigNoz logs.
