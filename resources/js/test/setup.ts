import { cleanup } from '@testing-library/react';
import { afterEach, expect } from 'vitest';
import * as axeMatchers from 'vitest-axe/matchers';

// axe's toHaveNoViolations on every test file; a clean DOM between tests. (A namespace import:
// vitest-axe's type file re-exports the matcher in a shape isolatedModules reads as type-only.)
expect.extend(axeMatchers);

afterEach(() => {
    cleanup();
});
