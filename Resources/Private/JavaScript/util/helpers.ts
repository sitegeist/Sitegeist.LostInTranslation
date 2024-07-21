const HTML_ESCAPE_MAP: { [index: string]: string } = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
};

/**
 * Highlights the keyword in the given text with the `mark` tag
 *
 * @param text
 * @param keyword
 */
export function highlight(text: string, keyword: string): string {
    if (keyword) {
        const cleanKeyword = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const searchRegExp = new RegExp('(' + cleanKeyword + ')', 'ig');
        return text.replace(searchRegExp, '<mark>$1</mark>');
    }
    return text;
}

/**
 * Replace html special characters
 *
 * @param text
 */
export function escapeHtml(text: string): string {
    return text.replace(/[&<>"']/g, m => HTML_ESCAPE_MAP[m]);
}
