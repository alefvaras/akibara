const videoUrlPattern =
	/(?:https?:\/\/|)(?:player\.|www\.)?(?:vimeo\.com|youtu(?:be\.com|\.be|be\.googleapis\.com))\/(?:video\/|embed\/|watch\?v=|v\/)?[A-Za-z0-9._%-]*(?:&\S+)?/giu;

function countMatches(value, pattern) {
	return [...value.matchAll(pattern)].length;
}

function exactLower(value) {
	return String(value ?? '').toLocaleLowerCase('es-CL');
}

function removeAccents(value) {
	return value.normalize('NFD').replace(/\p{Diacritic}/gu, '');
}

export function cleanForSlug(value) {
	if (!value) return '';
	return removeAccents(String(value))
		.replace(/(&nbsp;|&ndash;|&mdash;)/gu, '-')
		.replace(/[\s./]+/gu, '-')
		.replace(/&\S+?;/gu, '')
		.replace(/[^\p{L}\p{N}_-]+/gu, '')
		.toLocaleLowerCase('es-CL')
		.replace(/-+/gu, '-')
		.replace(/(^-+)|(-+$)/gu, '');
}

export function rankMathKeywordPermalink(focusKeyword) {
	return cleanForSlug(
		String(focusKeyword ?? '')
			.replace(/'/gu, '')
			.replace(/[-_.]+/gu, '-')
	);
}

export function rankMathPermalinkAudit({
	contentKey,
	focusKeyword,
	origin = 'https://akibara.cl',
}) {
	const productionUrl = (
		`${origin.replace(/\/+$/u, '')}/`
		+ `${String(contentKey ?? '').replace(/^\/+|\/+$/gu, '')}/`
	);
	const cleanUrl = cleanForSlug(productionUrl.replace(/[-_]/gu, '-'));
	const keywordPermalink = rankMathKeywordPermalink(focusKeyword);
	return {
		production_url: productionUrl,
		production_url_length: productionUrl.length,
		production_url_length_pass: productionUrl.length <= 75,
		clean_url: cleanUrl,
		keyword_permalink: keywordPermalink,
		focus_contiguous_in_permalink: (
			keywordPermalink.length > 0
			&& cleanUrl.includes(keywordPermalink)
		),
	};
}

function rankMathVideoCount(content) {
	let remaining = String(content ?? '');
	let count = 0;
	for (const pattern of [
		/<iframe(?:[^>]+)?>/giu,
		/<video(?:[^>]+)?>/giu,
		/\[video( [^\]]+?)?\]/giu,
	]) {
		remaining = remaining.replace(pattern, (tag) => {
			if (!videoUrlPattern.test(tag)) {
				videoUrlPattern.lastIndex = 0;
				return tag;
			}
			videoUrlPattern.lastIndex = 0;
			count += 1;
			return '';
		});
	}
	videoUrlPattern.lastIndex = 0;
	count += countMatches(remaining, videoUrlPattern);
	videoUrlPattern.lastIndex = 0;
	return count;
}

export function rankMathMediaAudit({
	content,
	featuredImageAlt,
	focusKeyword,
}) {
	const html = String(content ?? '');
	const focusLower = exactLower(focusKeyword);
	const featuredAltLower = exactLower(featuredImageAlt);
	const imageTags = [...html.matchAll(/<img(?:[^>]+)?>/giu)]
		.map((match) => match[0]);
	const contentImageAlts = imageTags.flatMap((tag) => {
		const match = /\salt=(["'])(.*?)\1/iu.exec(tag);
		return match ? [match[2]] : [];
	});
	const galleryShortcodeCount = countMatches(
		html,
		/\[gallery( [^\]]+?)?\]/giu
	);
	const featuredImageCount = String(featuredImageAlt ?? '').trim() ? 1 : 0;
	const featuredAltFocusExactSubstring = (
		focusLower.length > 0
		&& featuredAltLower.includes(focusLower)
	);
	const contentImageAltFocusPattern = focusLower
		.split(' ')
		.filter(Boolean)
		.filter((token, index, tokens) => tokens.indexOf(token) === index)
		.map((token) => token.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&'))
		.join('.*');
	const contentImageAltFocusMatchCount = contentImageAltFocusPattern
		? contentImageAlts.filter((alt) => (
			new RegExp(contentImageAltFocusPattern, 'iu').test(exactLower(alt))
		)).length
		: 0;
	const videoCount = rankMathVideoCount(html);
	const rankMathImageCount = (
		imageTags.length
		+ galleryShortcodeCount
		+ featuredImageCount
	);
	return {
		featured_image_count: featuredImageCount,
		content_image_tag_count: imageTags.length,
		content_image_alt_count: contentImageAlts.length,
		gallery_shortcode_count: galleryShortcodeCount,
		rank_math_image_count: rankMathImageCount,
		rank_math_video_count: videoCount,
		rank_math_media_count: rankMathImageCount + videoCount,
		featured_alt_focus_exact_substring:
			featuredAltFocusExactSubstring,
		content_image_alt_focus_match_count:
			contentImageAltFocusMatchCount,
		keyword_in_image_alt_pass: (
			featuredAltFocusExactSubstring
			|| contentImageAltFocusMatchCount > 0
			|| galleryShortcodeCount > 0
		),
	};
}

export function strongTagAudit(content) {
	let depth = 0;
	let openingCount = 0;
	let closingCount = 0;
	let unmatchedClosingCount = 0;
	for (const match of String(content ?? '').matchAll(/<\/?strong\b[^>]*>/giu)) {
		if (/^<\//u.test(match[0])) {
			closingCount += 1;
			if (depth === 0) {
				unmatchedClosingCount += 1;
			} else {
				depth -= 1;
			}
		} else {
			openingCount += 1;
			depth += 1;
		}
	}
	return {
		strong_opening_count: openingCount,
		strong_closing_count: closingCount,
		strong_unmatched_opening_count: depth,
		strong_unmatched_closing_count: unmatchedClosingCount,
		strong_tags_balanced: (
			depth === 0
			&& unmatchedClosingCount === 0
			&& openingCount === closingCount
		),
	};
}
