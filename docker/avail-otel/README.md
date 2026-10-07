# Avail log shipper (Grafana Alloy -> SigNoz)

**Status: switched off.** Nothing starts it: deploying AvailCoolify does not run this. It only runs when someone starts it by hand (below), and it sends logs to SigNoz from then on until it is stopped (`docker compose down`).

Ships the stdout/stderr of every Docker container on the host (coolify, coolify-proxy, apps) to SigNoz over OTLP/HTTP.
Runs standalone, outside Coolify. Docker keeps only 3 x 10 MB per container, so this is the long-term log store.

    cp .env.example .env   # set OTEL_EXPORTER_OTLP_ENDPOINT and, if required, SIGNOZ_INGESTION_KEY
    docker compose up -d

Attributes: `service.name` and `container_name` = container name, plus `container_id`, `coolify_type`, `coolify_name`, `coolify_application_id`, `host_name`.
Check delivery: `nsenter -t $(docker inspect -f '{{.State.Pid}}' avail-log-shipper) -n curl -s localhost:12345/metrics | grep otelcol_exporter`
Secrets in app output are shipped too; limit who can read the SigNoz logs.
