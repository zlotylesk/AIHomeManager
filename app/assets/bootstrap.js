import { startStimulusApp } from '@symfony/stimulus-bridge';

// Encore 7 + `"type": "module"` makes webpack parse this file as ESM
// (`javascript/esm`), where the CommonJS `require.context` is left as a free
// `require` reference (→ "require is not defined" at runtime, breaking Stimulus
// boot). webpack 5's ESM-native `import.meta.webpackContext` is the equivalent
// the parser transforms inside an ES module.
export const app = startStimulusApp(import.meta.webpackContext(
    '@symfony/stimulus-bridge/lazy-controller-loader!./controllers',
    {
        recursive: true,
        regExp: /\.[jt]sx?$/,
    }
));
