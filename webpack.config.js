const Encore = require('@symfony/webpack-encore');

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    // directory where compiled assets will be stored
    .setOutputPath('public/build/')
    // public path used by the web server to access the output path
    .setPublicPath('/build')
    // only needed for CDN's or sub-directory deploy
    //.setManifestKeyPrefix('build/')

    /*
     * ENTRY CONFIG
     *
     * Each entry will result in one JavaScript file (e.g. app.js)
     * and one CSS file (e.g. app.css) if your JavaScript imports CSS.
     */
    .addEntry('app', './assets/app.js')
    // The live result entry page's own bundle, without Turbo and the site's controllers (competition toolkit C2,
    // assets/live_entry.js) - not precached for every visitor (service worker PRECACHE_SKIP_ENTRIES): referees' devices
    // store it with the page's offline copy
    .addEntry('live_entry', './assets/live_entry.js')
    // ...and, only inside the native apps (Hotwire Native), Turbo for it: the apps' WebView finishes a visit only on a
    // page that runs Turbo (assets/live_entry_native.js) - the web page stays without it
    .addEntry('live_entry_native', './assets/live_entry_native.js')
    // A competition display - timer screen or stream overlay (competition toolkit D4, assets/display.js): a standalone
    // page with nothing of the site, never precached for every visitor (service worker PRECACHE_SKIP_ENTRIES)
    .addEntry('display', './assets/display.js')
    // Page stylesheets of the online competitions (docs/features/online-competitions/), linked only on their pages
    .addStyleEntry('compete', './assets/styles/compete.scss')
    .addStyleEntry('online_rounds', './assets/styles/online_rounds.scss')
    .addStyleEntry('online_watch', './assets/styles/online_watch.scss')

    // enables the Symfony UX Stimulus bridge (used in assets/bootstrap.js)
    .enableStimulusBridge('./assets/controllers.json')

    // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
    .splitEntryChunks()
    // ...except the live entry (and its native-app Turbo) and the display: one file of its own each (+ runtime.js),
    // sharing no split chunk with `app` - the site's pages keep exactly the script tags they had, and each of these is
    // complete in itself
    .configureSplitChunks((splitChunks) => {
        splitChunks.chunks = (chunk) => !['live_entry', 'live_entry_native', 'display'].includes(chunk.name);
    })

    // will require an extra script tag for runtime.js
    // but, you probably want this, unless you're building a single-page app
    .enableSingleRuntimeChunk()

    /*
     * FEATURE CONFIG
     *
     * Enable & configure other features below. For a full
     * list of features, see:
     * https://symfony.com/doc/current/frontend.html#adding-more-features
     */
    .cleanupOutputBeforeBuild()
    .enableBuildNotifications()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())

    .configureBabelPresetEnv((config) => {
        config.targets = { esmodules: true };
    })

    // enables Sass/SCSS support
    .enableSassLoader()

    .enableIntegrityHashes(Encore.isProduction())

    .enablePostCssLoader((options) => {
        options.postcssOptions = {
            plugins: [
                // Replace font-display:block with swap for faster text rendering
                {
                    postcssPlugin: 'font-display-swap',
                    Declaration: {
                        'font-display': (decl) => {
                            if (decl.value === 'block') {
                                decl.value = 'swap';
                            }
                        },
                    },
                },
                require('autoprefixer'),
            ],
        };
    })

    .configureTerserPlugin((options) => {
        options.terserOptions = {
            compress: {
                drop_console: true, // Removes console logs in production
            },
        };
    })
;

module.exports = Encore.getWebpackConfig();
