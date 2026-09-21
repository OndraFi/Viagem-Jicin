.PHONY: up down migrate import-cpx test

up:
	docker compose up --build

down:
	docker compose down

migrate:
	docker compose run --rm backend php bin/migrate.php

import-cpx:
	docker compose run --rm backend php bin/import-cpx.php

test:
	docker compose run --rm backend php bin/test.php

