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
// The public pages are server-rendered Blade templates. Their rendered copy is checked word for word against
// tests/fixtures/public-pages.json (captured from the approved version that matched the Angular source) by
// tests/Feature/PublicWebsiteTest.php. This file keeps the checks that do not need PHP.
const views = ['pages/home', 'pages/about', 'pages/blog-index', 'pages/blog-show', 'partials/site-header', 'partials/site-footer', 'layouts/site'];
const view = name => read(`resources/views/${name}.blade.php`);

test('editable content defaults match the committed Angular baseline even without the old repository', () => {
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
test('all referenced images exist with exact case for Linux hosting', () => {
    const files = fs.readdirSync('public/assets', {recursive:true}).map(f => f.replaceAll('\\', '/'));
    const text = views.map(view).join('\n') + read('resources/content/blogs.json') + read('resources/content/outlets.json') + read('resources/content/collaborations.json') + read('resources/content/hero.json') + read('resources/content/baked-goods.json');
    for (const match of text.matchAll(/\/assets\/([^"'<>]+?\.(?:png|jpg|jpeg|webp))/gi)) assert.ok(files.includes(match[1]), match[1]);
});
test('section anchors survive; public pages need no Inertia; no Angular or Bootstrap runtime', () => {
    const home = view('pages/home');
    for (const id of ['baked-goods', 'outlet', 'collaboration', 'blog']) assert.match(home, new RegExp(`id="${id}"`), id);
    assert.doesNotMatch(views.map(view).join('\n'), /@inertia|data-page/);
    assert.doesNotMatch(read('package.json'), /bootstrap|@angular/);
});
