## —— Doshka Backend Makefile ——————————————————————————————————————————————
.PHONY: help build up down logs shell composer install migrate test jwt-keys

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker images
	docker compose build

up: ## Start all services
	docker compose up -d

down: ## Stop all services
	docker compose down

logs: ## Show logs
	docker compose logs -f

shell: ## Open PHP container shell
	docker compose exec php bash

composer: ## Run composer (usage: make composer CMD="require package/name")
	docker compose exec php composer $(CMD)

install: ## Install dependencies
	docker compose exec php composer install

jwt-keys: ## Generate JWT key pair
	docker compose exec php mkdir -p config/jwt
	docker compose exec php openssl genrsa -out config/jwt/private.pem -passout pass:$$(grep JWT_PASSPHRASE .env | cut -d= -f2) 4096
	docker compose exec php openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem -passin pass:$$(grep JWT_PASSPHRASE .env | cut -d= -f2)
	@echo "JWT keys generated in config/jwt/"

migrate: ## Run database migrations
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

migrate-diff: ## Generate migration from entity changes
	docker compose exec php php bin/console doctrine:migrations:diff

cache-clear: ## Clear Symfony cache
	docker compose exec php php bin/console cache:clear

test: ## Run PHPUnit tests
	docker compose exec php php bin/phpunit --testdox

test-setup: ## Setup test database
	docker compose exec php php bin/console doctrine:database:create --env=test --if-not-exists
	docker compose exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction

worker: ## Start Messenger worker
	docker compose exec php php bin/console messenger:consume async -vv

scheduler: ## Start Scheduler worker
	docker compose exec php php bin/console messenger:consume scheduler_main -vv

setup: up install jwt-keys migrate ## Full setup: up + install + jwt-keys + migrate
	@echo ""
	@echo "✅ Setup complete!"
	@echo "   API:     http://localhost/api"
	@echo "   Swagger: http://localhost/api/docs"
	@echo "   Mail:    http://localhost:8025"
