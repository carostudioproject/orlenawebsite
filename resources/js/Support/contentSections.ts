export interface ContentField { key: string; label: string; multiline?: boolean; paragraphs?: boolean; optional?: boolean; max: number; type?: string; hint?: string; placeholder?: string }
export interface ContentSection { title: string; description: string; page: 'Global' | 'Home' | 'About'; list: boolean; itemLabel?: string; maxItems?: number; imageLabel?: string; preview: string; fields: ContentField[] }

export const contentSections: Record<string, ContentSection> = {
    social: {
        title: 'Social media & WhatsApp', page: 'Global', list: false, preview: '/',
        description: 'Instagram and TikTok links in the header, mobile menu and footer, plus the WhatsApp number for the chat button and order confirmations.',
        fields: [
            { key: 'instagramUrl', label: 'Instagram link', max: 500, type: 'url', optional: true, placeholder: 'https://www.instagram.com/…', hint: 'Leave empty to hide the Instagram link.' },
            { key: 'tiktokUrl', label: 'TikTok link', max: 500, type: 'url', optional: true, placeholder: 'https://www.tiktok.com/@…', hint: 'Leave empty to hide the TikTok link.' },
            { key: 'whatsappNumber', label: 'Orlena WhatsApp number', max: 15, type: 'tel', placeholder: '6282145809558', hint: 'International format without + or spaces, e.g. 6282145809558. Used for the WhatsApp button and order messages.' },
        ],
    },
    hero: {
        title: 'Hero slider', page: 'Home', list: true, itemLabel: 'Slide', maxItems: 10, imageLabel: 'Slide image', preview: '/',
        description: 'The large sliding images at the very top of the homepage. Use landscape images of the same size for every slide.',
        fields: [{ key: 'alt', label: 'Image alt text', max: 160, hint: 'Short description of the image for screen readers and SEO.' }],
    },
    home: {
        title: 'Homepage text', page: 'Home', list: false, preview: '/',
        description: 'Brand Mission and the title of each homepage section.',
        fields: [
            { key: 'missionTitle', label: 'Brand Mission — title', max: 200, multiline: true },
            { key: 'missionDescription', label: 'Brand Mission — description', max: 600, multiline: true },
            { key: 'bakedGoodsTitle', label: 'Baked Goods title', max: 200 },
            { key: 'outletsTitle', label: 'Outlets title', max: 200 },
            { key: 'collaborationTitle', label: 'Brand Collaboration title', max: 200 },
            { key: 'blogTitle', label: 'Journal title', max: 200 },
            { key: 'blogSubtitle', label: 'Journal subtitle', max: 200 },
        ],
    },
    collaborations: {
        title: 'Brand Collaboration', page: 'Home', list: true, itemLabel: 'Collaboration', maxItems: 20, imageLabel: 'Image', preview: '/#collaboration',
        description: 'Collaboration cards on the homepage. The brand name shows as "Orlena x Name".',
        fields: [
            { key: 'name', label: 'Brand name', max: 80 },
            { key: 'headline', label: 'Headline', max: 200, multiline: true },
            { key: 'brief', label: 'Short description', max: 500, multiline: true },
        ],
    },
    about: {
        title: 'About page', page: 'About', list: false, preview: '/about',
        description: 'Title and text on the About page, including Our Story.',
        fields: [
            { key: 'title', label: 'Page title', max: 200 },
            { key: 'description', label: 'Intro description', max: 1500, multiline: true },
            { key: 'storyTitleFirst', label: 'Story title — line 1', max: 40 },
            { key: 'storyTitleSecond', label: 'Story title — line 2', max: 40 },
            { key: 'storyLead', label: 'Story lead paragraph (large text)', max: 600, multiline: true },
            { key: 'storyBody', label: 'Story body', max: 1500, paragraphs: true },
            { key: 'storyClosing', label: 'Closing sentence', max: 300, multiline: true },
            { key: 'outletsTitle', label: 'Outlets title', max: 200 },
        ],
    },
};
