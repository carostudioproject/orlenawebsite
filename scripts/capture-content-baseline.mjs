import fs from 'node:fs';
import crypto from 'node:crypto';
import ts from 'typescript';
const read = p => fs.readFileSync(p, 'utf8');
const checksum = value => crypto.createHash('sha256').update(value).digest('hex');
const old = '../Orlenalycious/src/app';
const templates = {};
for (const [source,target] of [['home-page','Pages/Public/Home'],['our-story-page','Pages/Public/About'],['blog-page','Pages/Public/BlogIndex'],['blog-detail-page','Pages/Public/BlogShow'],['footer','Components/AppFooter']]) {
    const text = read(`${old}/${source}/${source}.component.html`).replace(/<!--[^]*?-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    templates[target] = checksum(text);
}
const data = {};
for(const [name,symbol] of [['blogs','BLOGS'],['outlets','OUTLETS']]) {
    const source = read(`${old}/data/${name}.ts`).replace(/export /g, '');
    const value = JSON.parse(JSON.stringify(Function(ts.transpile(source)+`; return ${symbol}`)()).replaceAll('../../assets/', '/assets/'));
    data[name] = checksum(JSON.stringify(value));
}
fs.writeFileSync('tests/fixtures/content-baseline.json', JSON.stringify({templates,data}, null, 2)+'\n');
