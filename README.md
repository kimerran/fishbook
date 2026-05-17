# Fishbook

![v1](https://img.shields.io/badge/v1-rc1-blue)

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

## API Docs

- Live Swagger UI: <http://localhost:3000/api-docs> (lazy-loaded `swagger-ui-react`).
- Raw spec: <http://localhost:8000/api/v1/openapi.json>.

## Deployment

Production deploys are tag-triggered. Push a `v*` tag to fire [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml):

```bash
git tag v1.0.0
git push origin v1.0.0
```

The workflow runs `railway up` for backend → waits for health → `php artisan migrate --force` → `railway up` for frontend → waits for health. Provisioning the Railway services and configuring envvars (see workflow header for the full list) is a one-time operator step.

A `make deploy-prod` target is documentation-only — it does not deploy. The tag push is the deploy gesture.

A `make smoke` target curls the public endpoints (`/api/v1/health`, `/api/v1/fishes/breeds`, `/api/v1/openapi.json`, `/api/v1/repos/vercel/next.js/aquarium`). Use it after a deploy to verify the stack is responsive: `make smoke HOST=https://api.fishbook.neri.ph`.

## Changelog

See [CHANGELOG.md](./CHANGELOG.md) for slice-by-slice release notes.
