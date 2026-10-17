#!/bin/sh
# Inside the playwright service (make lighthouse): forward localhost to the stack, refresh the
# e2e administrator's session (the setup project), then run Lighthouse against the build.
set -e
node tests/e2e/support/forwarder.mjs &
sleep 1
npx playwright test --project=setup
node tests/perf/lighthouse.mjs
