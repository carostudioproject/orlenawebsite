import js from '@eslint/js';
import vue from 'eslint-plugin-vue';
import ts from 'typescript-eslint';
import globals from 'globals';
export default ts.config(
    js.configs.recommended, ...ts.configs.recommended, ...vue.configs['flat/essential'],
    { files: ['**/*.vue'], languageOptions: { parserOptions: { parser: ts.parser } } },
    { languageOptions: { globals: globals.browser }, rules: { 'vue/multi-word-component-names': 'off' } },
);
