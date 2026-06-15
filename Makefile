# slot4u — fejlesztői parancsok rövidítései (WSL2 + Docker).
# Használat: `make up`, `make sh`, `make migrate`, ...
.DEFAULT_GOAL := help
DC := docker compose
ART := $(DC) exec app php artisan

help: ## Elérhető parancsok listája
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Image-ek build-elése
	$(DC) build

up: ## Stack indítása háttérben
	$(DC) up -d

down: ## Stack leállítása
	$(DC) down

logs: ## Logok követése
	$(DC) logs -f --tail=100

sh: ## Shell az app konténerben
	$(DC) exec app bash

install: ## Composer + npm függőségek telepítése
	$(DC) exec app composer install
	$(DC) run --rm vite npm install

key: ## APP_KEY generálás
	$(ART) key:generate

migrate: ## Migrációk futtatása
	$(ART) migrate

fresh: ## DB újraépítés seedekkel
	$(ART) migrate:fresh --seed

test: ## Pest tesztek
	$(DC) exec app php artisan test

pint: ## Pint formázás-ellenőrzés
	$(DC) exec app vendor/bin/pint --test

stan: ## Larastan statikus analízis
	$(DC) exec app vendor/bin/phpstan analyse

lint: ## ESLint a frontend kódon
	$(DC) exec vite npm run lint

types: ## TypeScript típusellenőrzés
	$(DC) exec vite npm run types

format: ## Prettier formázás
	$(DC) exec vite npm run format

.PHONY: help build up down logs sh install key migrate fresh test pint stan lint types format
