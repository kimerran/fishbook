.PHONY: up down restart migrate seed test lint fmt swagger api-client build-images

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
