export interface ContentBlock { type: 'paragraph' | 'heading'; text: string }
export interface Blog {
    slug: string;
    title: string;
    category?: string;
    excerpt: string;
    image: string;
    content: ContentBlock[];
}
export interface HomeCopy {
    missionTitle: string; missionDescription: string; bakedGoodsTitle: string; outletsTitle: string;
    collaborationTitle: string; blogTitle: string; blogSubtitle: string;
}
export interface Outlet { name: string; address: string; image: string; alt: string; mapsUrl: string }
export interface HeroSlide { image: string; alt: string }
export interface BakedGood { name: string; image: string }
export interface AboutCopy {
    title: string; description: string; storyTitleFirst: string; storyTitleSecond: string;
    storyLead: string; storyBody: string[]; storyClosing: string; outletsTitle: string;
}
export interface Collaboration { name: string; image: string; headline: string; brief: string }
export interface SocialLinks { instagramUrl: string; tiktokUrl: string; whatsappNumber: string }
export interface Seo {
    title: string; description: string; canonical: string; image: string; indexable: boolean;
}
