# Todos os comandos rodam no container (o host não precisa de PHP).
DC   = docker compose
RUN  = $(DC) run --rm -u $(shell id -u):$(shell id -g) app

.PHONY: build instalar subir parar teste analise qa pbl shell

build:
	$(DC) build app

instalar: build
	$(RUN) composer install --no-interaction

subir:
	$(DC) up -d app

parar:
	$(DC) down

teste:
	$(RUN) vendor/bin/phpunit

analise:
	$(RUN) vendor/bin/phpstan analyse --memory-limit=512M

qa: teste analise

pbl:
	$(RUN) php bin/pbl $(ARGS)

shell:
	$(RUN) bash
