// Anchors as GitHub makes them, so links written for GitHub keep working on the site.
export const slugify = (text) =>
    text
        .trim()
        .toLowerCase()
        .replace(/<[^>]+>/g, '')
        .replace(/[^\p{L}\p{N}\s_-]/gu, '')
        .replace(/\s/g, '-')
