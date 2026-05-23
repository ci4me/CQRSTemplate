# CQRSTemplate developer Makefile.
#
# Convenience targets for the two test lanes (SQLite default + MySQL CI lane).
# The SQLite lane is what `composer test` runs out of the box; the MySQL lane
# spins up the docker-compose service in `docker-compose.yml`, waits for the
# healthcheck, runs the same suite with overridden env vars, and tears the
# container down on exit.
#
# Round-3 audit findings closed by these targets: 13/F1 (SQLite hard-lock),
# 18/F-T1 (engine blindness). The CI workflow mirrors the same env-var
# overrides — `make test-mysql` is the local reproduction of the MySQL CI
# matrix axis.

.PHONY: help test test-sqlite test-mysql test-mysql-up test-mysql-down \
        test-mysql-wait check phpcs phpstan

# Pin the compose project name so the container is easy to find with
# `docker ps` and so concurrent invocations from sibling clones don't
# collide on the same network namespace.
COMPOSE_PROJECT := ci4me-test
COMPOSE := docker compose -p $(COMPOSE_PROJECT)

# Local MySQL container port (mapped to 3306 inside the container in
# docker-compose.yml). Keep in sync with `docker-compose.yml :: ports`.
MYSQL_HOST_PORT := 33060

help:
	@echo "CQRSTemplate make targets:"
	@echo "  make test           — alias for test-sqlite (default fast lane)"
	@echo "  make test-sqlite    — composer test against in-memory SQLite"
	@echo "  make test-mysql     — spin up MySQL 8.0, run composer test, tear down"
	@echo "  make test-mysql-up  — only start the MySQL container (debugging)"
	@echo "  make test-mysql-down— stop + remove the MySQL container"
	@echo "  make check          — composer check (docblocks + phpcs + phpstan + phpunit)"
	@echo "  make phpcs          — PSR-12 + Slevomat"
	@echo "  make phpstan        — PHPStan Level 8"

test: test-sqlite

test-sqlite:
	composer test

# --- MySQL lane -------------------------------------------------------------
#
# The container exposes 3306 inside on host port 33060 (see
# docker-compose.yml). We override the `database.tests.*` env vars phpunit
# reads at boot — `phpunit.xml.dist` no longer forces `DBDriver=SQLite3`
# (round-3 13/F1), so the override takes effect and the same suite runs
# against MySQL 8.

test-mysql: test-mysql-up
	@trap '$(MAKE) test-mysql-down' EXIT INT TERM; \
	echo ">>> Running composer test against MySQL 8 (host port $(MYSQL_HOST_PORT))"; \
	database.tests.hostname=127.0.0.1 \
	database.tests.port=$(MYSQL_HOST_PORT) \
	database.tests.database=ci4me_test \
	database.tests.username=ci4me \
	database.tests.password=ci4me \
	database.tests.DBDriver=MySQLi \
	database.tests.DBPrefix= \
	composer test

test-mysql-up:
	$(COMPOSE) up -d mysql
	@$(MAKE) test-mysql-wait

test-mysql-wait:
	@echo ">>> Waiting for MySQL healthcheck..."
	@for i in $$(seq 1 24); do \
		status=$$($(COMPOSE) ps --format json mysql 2>/dev/null \
			| grep -o '"Health"[[:space:]]*:[[:space:]]*"[a-z]*"' \
			| head -1 | sed 's/.*"\([a-z]*\)"$$/\1/'); \
		if [ "$$status" = "healthy" ]; then \
			echo ">>> MySQL is healthy"; \
			exit 0; \
		fi; \
		echo "    (attempt $$i/24, status=$$status)"; \
		sleep 5; \
	done; \
	echo "!!! MySQL did not become healthy in time"; \
	$(COMPOSE) logs mysql; \
	exit 1

test-mysql-down:
	$(COMPOSE) down -v --remove-orphans

# --- Gates -----------------------------------------------------------------

check:
	composer check

phpcs:
	composer phpcs

phpstan:
	composer phpstan
