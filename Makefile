.PHONY: up down restart migrate seed test lint fmt swagger api-client build-images deploy-prod smoke

up:
	docker compose up -d
	@echo "Backend:  http://localhost:8000/api/v1/health"
	@echo "Frontend: http://localhost:3000"
	@echo "MinIO:    http://localhost:9001 (minioadmin/minioadmin)"

down:
	docker compose down

restart: down up

migrate:
	docker compose exec backend php artisan migrate --force

seed:
	docker compose exec backend php artisan db:seed --force

test:
	docker compose exec backend ./vendor/bin/pest
	docker compose exec frontend npm test -- --run

lint:
	docker compose exec backend ./vendor/bin/pint --test
	docker compose exec backend ./vendor/bin/phpstan analyse --memory-limit=512M
	docker compose exec frontend npm run lint
	docker compose exec frontend npm run typecheck

fmt:
	docker compose exec backend ./vendor/bin/pint
	docker compose exec frontend npx prettier --write .

swagger:
	docker compose exec backend php artisan l5-swagger:generate

api-client:
	docker compose exec frontend npm run generate:api

build-images:
	docker build -t fishbook-backend:slice1 backend/
	docker build -t fishbook-frontend:slice1 frontend/

deploy-prod:
	@echo "Production deploys are tag-triggered. See README §Deployment."
	@echo "Push a v* tag (e.g. 'git tag v1.0.0 && git push origin v1.0.0')"
	@echo "to fire .github/workflows/deploy.yml."

# HOST defaults to local; override for prod, e.g. `make smoke HOST=https://api.fishbook.neri.ph`.
HOST ?= http://localhost:8000
smoke:
	@set -e; \
	echo "[smoke] $(HOST)/api/v1/health"; \
	curl -fsS "$(HOST)/api/v1/health" > /dev/null; \
	echo "[smoke] $(HOST)/api/v1/openapi.json"; \
	curl -fsS "$(HOST)/api/v1/openapi.json" > /dev/null; \
	echo "[smoke] $(HOST)/api/v1/fishes/breeds"; \
	curl -fsS "$(HOST)/api/v1/fishes/breeds" > /dev/null; \
	echo "[smoke] All probes returned 200."
	@echo "Optional: GET /api/v1/repos/{owner}/{repo}/aquarium requires Redis + GitHub access; run by hand if needed."
