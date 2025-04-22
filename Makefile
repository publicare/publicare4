# Makefile para Publicare 4
# Executa comandos úteis com uma única palavra

.PHONY: migrar instalar limpar dump test subir parar reiniciar

# Executa as migrações via bin/migrar.php
migrar:
	docker-compose exec php ./bin/migrar.php

# Instala dependências PHP via Composer
instalar:
	docker-compose exec php composer install

# Remove cache e arquivos temporários
limpar:
	docker-compose exec php rm -rf var/cache vendor composer.lock

# Regera autoload do Composer
dump:
	docker-compose exec php composer dump-autoload

# Executa testes (se usar PHPUnit no futuro)
test:
	docker-compose exec php ./vendor/bin/phpunit

# Sobe o ambiente dockerizado
subir:
	docker-compose up -d --build

# Para o ambiente
parar:
	docker-compose down

# Reinicia o ambiente inteiro
reiniciar:
	docker-compose down && docker-compose up -d --build
