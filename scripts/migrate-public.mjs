// One-time source migration; requires the original adjacent Angular checkout.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import ts from 'typescript';
const source = path.resolve('../Orlenalycious/src');
const read = p => fs.readFileSync(path.join(source, p), 'utf8');
const write = (p, value) => { fs.mkdirSync(path.dirname(p), {recursive:true}); fs.writeFileSync(p, value); };
const evaluate = (code, name) => {
    const js = ts.transpile(code.replace(/export /g, ''), {target: ts.ScriptTarget.ES2020});
    return Function(`${js}; return ${name}`)();
};
const blogs = evaluate(read('app/data/blogs.ts'), 'BLOGS');
const outlets = evaluate(read('app/data/outlets.ts'), 'OUTLETS');
const homeScript = read('app/home-page/home-page.component.ts');
const collaborations = evaluate('const data = ' + homeScript.match(/brandCollaborations = ([\s\S]*?);/)[1], 'data');
const normalize = data => JSON.parse(JSON.stringify(data).replaceAll('../../assets/', '/assets/'));
write('resources/content/blogs.json', JSON.stringify(normalize(blogs), null, 2)+'\n');
write('resources/content/outlets.json', JSON.stringify(normalize(outlets), null, 2)+'\n');
write('resources/content/collaborations.json', JSON.stringify(normalize(collaborations), null, 2)+'\n');
function convert(html) {
    return html.replace(/<!--[^]*?-->/g, '')
        .replaceAll('../../assets/', '/assets/').replaceAll('src="assets/', 'src="/assets/')
        .replace(/\[routerLink\]="\['\/'\]"\s+fragment="([^"]+)"/g, 'href="/#$1"')
        .replace(/\[routerLink\]="\['\/blog', blog.slug\]"/g, ':href="\'/blog/\' + blog.slug"')
        .replace(/routerLink=/g, 'href=')
        .replace(/\*ngFor="let (\w+) of ([^"]+)"/g, 'v-for="($1, index) in $2" :key="index"')
        .replace(/\*ngIf=/g, 'v-if=')
        .replace(/\[(src|alt|href)\]=/g, ':$1=')
        .replaceAll('ng-container', 'template')
        .replace('<body id="body">', '<div id="body"><h1 class="sr-only">Orlena</h1>')
        .replace('</body>', '</div>')
        .replace('<main class="about-page">', '<div class="about-page">').replace('</main>', '</div>')
        .replace('container-fluid p-0', 'w-full p-0').replace('row g-0', 'w-full')
        .replace(/<a ([^>]*href="#"[^>]*)>([^]*?)<\/a>/g, '<div $1>$2</div>')
        .replace(/<div href="#"/g, '<div')
        .replace(/\n\s*\n\s*\n/g, '\n\n');
}
const pages = [ ['home-page','Home'], ['our-story-page','About'], ['blog-page','BlogIndex'], ['blog-detail-page','BlogShow'], ['footer','AppFooter'] ];
const manifest = {};
for(const [old, name] of pages) {
    const original = read(`app/${old}/${old}.component.html`);
    manifest[name] = { source: `src/app/${old}/${old}.component.html`, sha256: crypto.createHash('sha256').update(original).digest('hex') };
    const isPage = name !== 'AppFooter';
    let script = '';
    if(name === 'Home') script = `import { usePublicSliders } from '../../Composables/usePublicSliders';\nimport brandCollaborations from '../../../content/collaborations.json';\nimport outlets from '../../../content/outlets.json';\nimport blogs from '../../../content/blogs.json';\nusePublicSliders('home');\nconst truncateText = (text: string, maxLength = 100) => text.length <= maxLength ? text : text.substring(0, maxLength).trim() + '...';`;
    if(name === 'About') script = `import { usePublicSliders } from '../../Composables/usePublicSliders';\nimport outlets from '../../../content/outlets.json';\nusePublicSliders('about');`;
    if(name === 'BlogIndex') script = `import blogs from '../../../content/blogs.json';`;
    if(name === 'BlogShow') script = `import type { Blog } from '../../Types/content';\ndefineProps<{ blog: Blog }>();`;
    if(isPage) script += `\nimport PublicLayout from '../../Layouts/PublicLayout.vue';\ndefineOptions({ layout: PublicLayout });`;
    const css = read(`app/${old}/${old}.component.css`);
    write(`resources/js/${isPage?'Pages/Public':'Components'}/${name}.vue`, `<script setup lang="ts">\n${script}\n</script>\n<template>\n${convert(original)}\n</template>\n${css.trim()?'<style scoped>\n'+css+'\n</style>':''}`);
}
let css = read('assets/style.css').replace(/url\(fonts\//g, 'url(/assets/fonts/').replace('/assets/fonts/jinglers/Jinglers.otf', '/assets/fonts/Jinglers-Font/jinglers.otf');
write('resources/css/brand.css', css);
write('resources/css/header.css', read('app/header/header.component.css'));
write('tests/fixtures/source-manifest.json', JSON.stringify(manifest, null, 2)+'\n');
const refs = new Set();
const text = pages.map(([old]) => read(`app/${old}/${old}.component.html`)).join('\n') + JSON.stringify([blogs,outlets,collaborations]);
for(const match of text.matchAll(/assets\/([^"'<>]+?\.(?:png|jpg|jpeg|svg|webp|ico))/gi)) refs.add(match[1]);
refs.add('images/Orlena-Logo.png');
for(const asset of refs) {
    const dest = 'public/assets/'+asset;
    fs.mkdirSync(path.dirname(dest), {recursive:true});
    fs.copyFileSync(path.join(source, 'assets', asset), dest);
}
fs.cpSync(path.join(source, 'assets/fonts'), 'public/assets/fonts', {recursive:true});
fs.copyFileSync(path.join(source, 'favicon.ico'), 'public/favicon.ico');
console.log(`Migrated ${pages.length} templates, ${blogs.length} articles, ${outlets.length} outlets, ${refs.size} images.`);
