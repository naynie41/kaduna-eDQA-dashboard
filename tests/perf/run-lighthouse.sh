#!/bin/sh
# Inside the playwright service (make lighthouse): forward localhost to the stack, then run
# Lighthouse against the build, on the public pages as an anonymous visitor.
set -e
node tests/e2e/support/forwarder.mjs &
sleep 1
node tests/perf/lighthouse.mjs
