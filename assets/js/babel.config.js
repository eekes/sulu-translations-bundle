/*
 * Only what the tests need. The admin build itself is done by sulu-admin-bundle's webpack
 * configuration, which brings its own Babel setup - this file never takes part in it.
 */
module.exports = {
    presets: [
        ['@babel/preset-env', {targets: {node: 'current'}}],
        '@babel/preset-react',
        '@babel/preset-flow',
    ],
    plugins: [
        // mobx 4 decorators, the ones sulu-admin-bundle uses
        ['@babel/plugin-proposal-decorators', {legacy: true}],
        ['@babel/plugin-proposal-class-properties', {loose: true}],
    ],
};
