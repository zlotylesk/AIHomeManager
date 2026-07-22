import Encore from '@symfony/webpack-encore';

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')

    .addEntry('app', './assets/app.js')

    .splitEntryChunks()

    .enableStimulusBridge('./assets/controllers.json')

    .enableSingleRuntimeChunk()

    // PWA manifest + icons copied verbatim into public/build (HMAI-345). They are
    // deliberately NOT content-hashed: the manifest is static JSON that points at
    // its icons by a fixed /build/icons/... path and could never reference a hash.
    .copyFiles([
        { from: './assets/pwa', to: '[path][name].[ext]', pattern: /manifest\.webmanifest$/ },
        { from: './assets/pwa/icons', to: 'icons/[path][name].[ext]', pattern: /\.png$/ },
    ])

    .cleanupOutputBeforeBuild()


    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())


    // Babel 8 dropped preset-env's `useBuiltIns`/`corejs` options; core-js
    // polyfills are now injected by babel-plugin-polyfill-corejs3. `version`
    // tracks the installed core-js so usage-based injection matches the lib.
    .configureBabel((babelConfig) => {
        babelConfig.plugins.push([
            'polyfill-corejs3',
            { method: 'usage-global', version: '3.38' },
        ]);
    })




;

export default await Encore.getWebpackConfig();
