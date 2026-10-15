import 'vitest';
import type { AxeMatchers } from 'vitest-axe/matchers';

// vitest-axe 0.1 declares its matcher on the old global `Vi` namespace; Vitest 5 reads the
// `vitest` module's interfaces instead.
declare module 'vitest' {
    // eslint-disable-next-line @typescript-eslint/no-empty-object-type, @typescript-eslint/no-unused-vars -- interface merging
    interface Assertion<T = unknown> extends AxeMatchers {}
    // eslint-disable-next-line @typescript-eslint/no-empty-object-type -- interface merging
    interface AsymmetricMatchersContaining extends AxeMatchers {}
}
