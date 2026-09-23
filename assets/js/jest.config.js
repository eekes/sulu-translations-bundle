module.exports = {
    // jsdom rather than node: the store touches localStorage and the ICU helper touches Intl.
    testEnvironment: 'jsdom',
    testMatch: ['<rootDir>/**/tests/**/*.test.js'],
    moduleNameMapper: {
        '\\.(css|scss)$': '<rootDir>/tests/styleMock.js',
        '^sulu-admin-bundle/views/List/toolbarActions/AbstractListToolbarAction$':
            '<rootDir>/tests/abstractListToolbarActionMock.js',
    },
};
