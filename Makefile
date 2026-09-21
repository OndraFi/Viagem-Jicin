.PHONY: up down migrate import-cpx import-codelists import-cadastral-units enable-jicin-district test

up:
	docker compose up --build

down:
	docker compose down

migrate:
	docker compose run --rm backend php bin/migrate.php

import-cpx:
	docker compose run --rm backend php bin/import-cpx.php

import-codelists:
	docker compose run --rm backend php bin/import-codelists.php

import-cadastral-units:
	docker compose run --rm backend php bin/import-cadastral-units.php

enable-jicin-district:
	docker compose run --rm backend php bin/enable-district.php 3604

test:
	docker compose run --rm backend php bin/test.php
