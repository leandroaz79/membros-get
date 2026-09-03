export function getPostInitials(name) {
    if (!name) return 'A';
    return name.split(/\s+/).map((n) => n[0]).slice(0, 2).join('').toUpperCase() || 'A';
}

export function scrollToCommunityPost(postId) {
    const el = document.getElementById(`post-${postId}`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
