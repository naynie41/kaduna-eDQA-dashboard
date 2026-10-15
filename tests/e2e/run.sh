#!/bin/sh
# Entrypoint of the playwright service (compose.dev.yml): start the localhost forwarders, give
# them a moment to listen, then run Playwright with any arguments `make e2e c="..."` passed.
set -e
node tests/e2e/support/forwarder.mjs &
sleep 1
exec npx playwright test "$@"
