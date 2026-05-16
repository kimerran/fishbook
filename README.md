# Fishbook

> Your Zen Sanctuary, Powered by Code.

A virtual aquarium web app. Curate a school of pet fishes that swim across a full-viewport canvas, customize the background, and even turn any GitHub repository into a living aquarium.

## Documents

- [SPEC.md](./SPEC.md) — what the product does
- [BRAND.md](./BRAND.md) — how it looks
- [AGENT.md](./AGENT.md) — how to build it
- [docs/superpowers/specs/](./docs/superpowers/specs/) — slice-by-slice designs
- [docs/superpowers/plans/](./docs/superpowers/plans/) — implementation plans

## Quickstart

```bash
make up           # start Postgres, Redis, MinIO, backend, frontend
make migrate      # run Laravel migrations
make test         # run backend + frontend test suites
```

Open:
- Frontend: http://localhost:3000
- Backend: http://localhost:8000/api/v1/health
- MinIO console: http://localhost:9001 (`minioadmin` / `minioadmin`)

## Regenerating the OpenAPI spec & client

```bash
make swagger      # regenerate backend/storage/api-docs/openapi.json
make api-client   # regenerate frontend/src/lib/api-client/
```

Both regenerated artifacts are committed to git. CI verifies they're in sync with the controllers and the spec respectively.
