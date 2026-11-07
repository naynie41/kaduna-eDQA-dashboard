#!/bin/sh
# Inside the playwright service (make load): forward localhost to the stack, then load the public
# dashboard with anonymous visitors (tests/perf/load.mjs).
set -e
node tests/e2e/support/forwarder.mjs &
sleep 1
node tests/perf/load.mjs
