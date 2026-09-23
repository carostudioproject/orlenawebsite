import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';
import crypto from 'node:crypto';
const oldRoot = '../Orlenalycious/src/app';
const sourceAvailable = fs.existsSync(oldRoot);
const read = file => fs.readFileSync(file, 'utf8');
const checksum = value => crypto.createHash('sha256').update(value).digest('hex');
const baseline = JSON.parse(read('tests/fixtures/content-baseline.json'));
// Editable copy renders from resources/content/*.json. Templates are rendered with those approved defaults
// (placeholders filled, repeated items expanded) so the checksums still prove the copy matches the source exactly.
const content = {
    home: JSON.parse(read('resources/content/home.json')), about: JSON.parse(read('resources/content/about.json')),
    hero: JSON.parse(read('resources/content/hero.json')), bakedGoods: JSON.parse(read('resources/content/baked-goods.json')),
};
const lookup = path => path.split('.').reduce((value, key) => { assert.ok(value && key in value, path); return value[key]; }, content);
const fill = (html, scope) => html.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (match, path) => {
    const [head, ...rest] = path.split('.');
    if (scope && head === scope.name) return rest.reduce((value, key) => value[key], scope.value);
    return head in content ? lookup(path) : match;
});
const expand = html => html.replace(/<(\w+)([^>]*?)\sv-for="\((\w+), index\) in ([\w.]+)"([^>]*)>([\s\S]*?)<\/\1>/g, (match, tag, before, name, source, after, inner) => {
    if (!(source.split('.')[0] in content)) return match;
    return lookup(source).map(value => `<${tag}${before}${after}>${fill(inner, { name, value })}</${tag}>`).join('\n');
});
const template = file => fill(expand(read(`resources/js/${file}.vue`)));
test('content matches the committed Angular baseline even without the old repository', () => {
    for (const [file, expected] of Object.entries(baseline.templates)) {
        assert.equal(checksum(copy(template(file)).replace(/^Orlena /, '')), expected, file);
    }
    for (const [name, expected] of Object.entries(baseline.data)) {
        assert.equal(checksum(JSON.stringify(JSON.parse(read(`resources/content/${name}.json`)))), expected, name);
    }
});
function data(name, symbol) {
    const source = read(`${oldRoot}/data/${name}.ts`).replace(/export /g, '');
    return JSON.parse(JSON.stringify(Function(ts.transpile(source) + `;return ${symbol}`)()).replaceAll('../../assets/', '/assets/'));
}
test('three articles and outlet content match Angular exactly', {skip: !sourceAvailable}, () => {
    assert.deepEqual(JSON.parse(read('resources/content/blogs.json')), data('blogs', 'BLOGS'));
    assert.deepEqual(JSON.parse(read('resources/content/outlets.json')), data('outlets', 'OUTLETS'));
});
function copy(html) {
    return html.replace(/<script[^]*?<\/script>/g, '').replace(/<style[^]*?<\/style>/g, '')
        .replace(/<!--[^]*?-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
}
test('all migrated template copy is preserved', {skip: !sourceAvailable}, () => {
    for (const [source, target] of [['home-page','Pages/Public/Home'],['our-story-page','Pages/Public/About'],['blog-page','Pages/Public/BlogIndex'],['blog-detail-page','Pages/Public/BlogShow'],['footer','Components/AppFooter']]) {
        const original = copy(read(`${oldRoot}/${source}/${source}.component.html`));
        const migrated = copy(template(target)).replace(/^Orlena /, '');
        assert.equal(migrated, original, source);
    }
});
test('all referenced images exist with exact case for Linux hosting', () => {
    const files = fs.readdirSync('public/assets', {recursive:true}).map(f => f.replaceAll('\\', '/'));
    const text = fs.readdirSync('resources/js/Pages/Public').map(f=>read('resources/js/Pages/Public/'+f)).join('\n') + read('resources/content/blogs.json') + read('resources/content/outlets.json') + read('resources/content/collaborations.json') + read('resources/content/hero.json') + read('resources/content/baked-goods.json');
    for (const match of text.matchAll(/\/assets\/([^"'<>]+?\.(?:png|jpg|jpeg|webp))/gi)) assert.ok(files.includes(match[1]), match[1]);
});
test('baked-goods anchor survives migration; no Angular or Bootstrap runtime', () => {
    assert.match(read('resources/js/Pages/Public/Home.vue'), /id="baked-goods"/);
    assert.doesNotMatch(read('package.json'), /bootstrap|@angular/);
});
