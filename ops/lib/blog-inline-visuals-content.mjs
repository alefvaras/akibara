/**
 * Pure helpers for deterministic Gutenberg placement. These functions do not
 * access WordPress and are intentionally dependency-free so their source can
 * be embedded in the Playwright CLI browser transaction.
 */

export function normalizeInlineVisualText(value) {
	return String(value)
		.replace(/\r\n?/gu, '\n')
		.replace(/\s+/gu, ' ')
		.trim();
}

export function inlineVisualPlainText(value) {
	return normalizeInlineVisualText(
		String(value)
			.replace(/<!--[\s\S]*?-->/gu, ' ')
			.replace(/<[^>]*>/gu, ' ')
			.replace(/&nbsp;/giu, ' ')
			.replace(/&amp;/giu, '&')
			.replace(/&quot;/giu, '"')
			.replace(/&#0*39;|&apos;/giu, "'")
			.replace(/&lt;/giu, '<')
			.replace(/&gt;/giu, '>')
	);
}

export function topLevelGutenbergBlocks(content) {
	const source = String(content);
	const expression = /<!--\s+(\/)?wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(?:\s+\{[\s\S]*?\})?\s*(\/)?-->/giu;
	const blocks = [];
	const stack = [];
	let match;
	while ((match = expression.exec(source)) !== null) {
		const closing = Boolean(match[1]);
		const name = match[2].toLocaleLowerCase('en-US');
		const selfClosing = Boolean(match[3]);
		if (closing) {
			const current = stack.pop();
			if (!current || current.name !== name) {
				throw new Error(`GUTENBERG_BLOCK_NESTING_INVALID:${name}`);
			}
			if (stack.length === 0) {
				blocks.push({
					name,
					start: current.start,
					end: expression.lastIndex,
					html: source.slice(current.start, expression.lastIndex),
				});
			}
			continue;
		}
		if (selfClosing) {
			if (stack.length === 0) {
				blocks.push({
					name,
					start: match.index,
					end: expression.lastIndex,
					html: source.slice(match.index, expression.lastIndex),
				});
			}
			continue;
		}
		stack.push({ name, start: match.index });
	}
	if (stack.length !== 0) {
		throw new Error('GUTENBERG_BLOCK_NESTING_UNCLOSED');
	}
	return blocks.sort((left, right) => left.start - right.start);
}

export function resolveInlineVisualAnchor(content, anchor) {
	const source = String(content);
	if (anchor?.occurrence !== 1 || anchor?.relation !== 'after') {
		throw new Error('INLINE_VISUAL_ANCHOR_POLICY_INVALID');
	}
	if (anchor.kind === 'gutenberg_block_text') {
		const wanted = normalizeInlineVisualText(anchor.value);
		const matches = topLevelGutenbergBlocks(source).filter((block) => (
			inlineVisualPlainText(block.html).includes(wanted)
		));
		if (matches.length !== 1) {
			throw new Error(
				`INLINE_VISUAL_BLOCK_ANCHOR_MATCHES:${matches.length}`
			);
		}
		return {
			offset: matches[0].end,
			container: 'top_level',
			block_name: matches[0].name,
		};
	}
	if (anchor.kind !== 'custom_html_fragment') {
		throw new Error('INLINE_VISUAL_ANCHOR_KIND_UNSUPPORTED');
	}
	const matches = [];
	let cursor = 0;
	while (true) {
		const index = source.indexOf(anchor.value, cursor);
		if (index === -1) break;
		matches.push(index);
		cursor = index + anchor.value.length;
	}
	if (matches.length !== 1) {
		throw new Error(
			`INLINE_VISUAL_HTML_ANCHOR_MATCHES:${matches.length}`
		);
	}
	const offset = matches[0] + anchor.value.length;
	const container = topLevelGutenbergBlocks(source).find((block) => (
		block.name === 'html'
		&& matches[0] >= block.start
		&& offset <= block.end
	));
	if (!container) {
		throw new Error('INLINE_VISUAL_HTML_ANCHOR_OUTSIDE_CUSTOM_HTML');
	}
	return { offset, container: 'custom_html', block_name: container.name };
}

export function escapeInlineVisualHtml(value) {
	return String(value)
		.replaceAll('&', '&amp;')
		.replaceAll('<', '&lt;')
		.replaceAll('>', '&gt;')
		.replaceAll('"', '&quot;')
		.replaceAll("'", '&#039;');
}

export function buildInlineVisualMarkup(asset, uploaded, container) {
	const classNames = `akb-blog-inline-visual ${asset.marker_class}`;
	const figure = [
		`<figure class="${classNames}">`,
		`<img src="${escapeInlineVisualHtml(uploaded.url)}"`,
		` alt="${escapeInlineVisualHtml(asset.alt)}"`,
		` width="${asset.width}" height="${asset.height}"`,
		' loading="lazy" decoding="async"',
		` class="wp-image-${uploaded.attachment_id}"/>`,
		`<figcaption>${escapeInlineVisualHtml(asset.caption)}</figcaption>`,
		'</figure>',
	].join('');
	if (container === 'custom_html') return `\n${figure}\n`;
	return `\n\n<!-- wp:html -->\n${figure}\n<!-- /wp:html -->`;
}

export function insertInlineVisuals(content, assets, uploadedBySha256) {
	const source = String(content);
	const groups = new Map();
	for (const asset of assets) {
		const uploaded = uploadedBySha256[asset.sha256];
		if (
			!uploaded
			|| !Number.isSafeInteger(uploaded.attachment_id)
			|| uploaded.attachment_id < 1
			|| typeof uploaded.url !== 'string'
			|| !uploaded.url
		) {
			throw new Error(`INLINE_VISUAL_UPLOAD_MISSING:${asset.sha256}`);
		}
		const anchor = resolveInlineVisualAnchor(source, asset.anchor);
		const key = `${anchor.offset}:${anchor.container}`;
		if (!groups.has(key)) {
			groups.set(key, {
				offset: anchor.offset,
				container: anchor.container,
				assets: [],
			});
		}
		groups.get(key).assets.push({ asset, uploaded });
	}
	let result = source;
	const orderedGroups = [...groups.values()]
		.sort((left, right) => right.offset - left.offset);
	for (const group of orderedGroups) {
		const insertion = group.assets
			.sort((left, right) => left.asset.sequence - right.asset.sequence)
			.map(({ asset, uploaded }) => (
				buildInlineVisualMarkup(asset, uploaded, group.container)
			))
			.join('');
		result = `${result.slice(0, group.offset)}${insertion}${
			result.slice(group.offset)
		}`;
	}
	return result;
}

export function extractInlineVisualState(content, assets) {
	const source = String(content);
	const present = [];
	for (const asset of assets) {
		const classCount = source.split(asset.marker_class).length - 1;
		const filenameCount = source.split(asset.expected_filename).length - 1;
		const altCount = source.split(escapeInlineVisualHtml(asset.alt)).length - 1;
		const captionCount = source
			.split(escapeInlineVisualHtml(asset.caption)).length - 1;
		present.push({
			sha256: asset.sha256,
			present: classCount === 1
				&& filenameCount === 1
				&& altCount === 1
				&& captionCount === 1,
			class_count: classCount,
			filename_count: filenameCount,
			alt_count: altCount,
			caption_count: captionCount,
		});
	}
	return present;
}

export function stripInlineVisuals(content, assets) {
	let result = String(content);
	for (const asset of [...assets].sort((left, right) => (
		right.sequence - left.sequence
	))) {
		const escaped = asset.marker_class.replace(
			/[.*+?^${}()|[\]\\]/gu,
			'\\$&'
		);
		const wrapped = new RegExp(
			'\\n\\n<!-- wp:html -->\\n'
				+ `<figure class="akb-blog-inline-visual ${escaped}">`
				+ '[\\s\\S]*?</figure>\\n<!-- /wp:html -->',
			'u'
		);
		const embedded = new RegExp(
			`\\n<figure class="akb-blog-inline-visual ${escaped}">`
				+ '[\\s\\S]*?</figure>\\n',
			'u'
		);
		const wrappedMatches = result.match(wrapped) ?? [];
		const embeddedMatches = result.match(embedded) ?? [];
		const matchCount = wrappedMatches.length === 1
			? 1
			: embeddedMatches.length;
		if (matchCount !== 1) {
			throw new Error(
				`INLINE_VISUAL_STRIP_MATCHES:${matchCount}`
			);
		}
		result = wrappedMatches.length === 1
			? result.replace(wrapped, '')
			: result.replace(embedded, '');
	}
	return result;
}
