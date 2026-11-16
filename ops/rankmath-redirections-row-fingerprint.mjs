import crypto from 'node:crypto';

function normalizedSources(value) {
	if (typeof value === 'string') {
		try {
			return normalizedSources(JSON.parse(value));
		} catch {
			return value;
		}
	}
	if (!Array.isArray(value)) {
		return value ?? null;
	}
	return value.map((source) => ({
		pattern: String(source?.pattern ?? ''),
		comparison: String(source?.comparison ?? 'exact').toLowerCase(),
		ignore: String(source?.ignore ?? ''),
	}));
}

/**
 * Exact semantic payload attached by Rank Math to its official list UI,
 * excluding transient row IDs and list position.
 *
 * `ignore` is deliberately retained as a literal. Rank Math persists the
 * supported case-insensitive mode as `case`; historical rows can contain the
 * invalid legacy value `exact`. Keeping both values distinct lets the official
 * UI runner normalize that legacy state instead of silently accepting it.
 */
export function visibleRowPayload(row) {
	return {
		sources: normalizedSources(row?.sources),
		destination: String(row?.url_to ?? row?.destination ?? ''),
		type: String(row?.header_code ?? row?.type ?? ''),
		status: String(row?.status ?? '').toLowerCase(),
	};
}

export function rowPayloadSha256(payloadOrRow) {
	const payload = Object.hasOwn(payloadOrRow ?? {}, 'destination')
		&& Object.hasOwn(payloadOrRow ?? {}, 'type')
		? payloadOrRow
		: visibleRowPayload(payloadOrRow);
	return crypto.createHash('sha256').update(JSON.stringify(payload), 'utf8').digest('hex');
}

/**
 * The reviewed malformed rows have no usable source payload. Their approval is
 * deliberately based on every other visible field, not on IDs 223/228.
 */
export function reviewedMalformedReason(payloadOrRow) {
	const payload = Object.hasOwn(payloadOrRow ?? {}, 'destination')
		&& Object.hasOwn(payloadOrRow ?? {}, 'type')
		? payloadOrRow
		: visibleRowPayload(payloadOrRow);
	let destination;
	try {
		const url = new URL(payload.destination);
		const hostname = url.hostname.toLowerCase();
		const productionDestination = url.protocol === 'https:'
			&& ['akibara.cl', 'www.akibara.cl'].includes(hostname);
		const localDockerDestination = ['http:', 'https:'].includes(url.protocol)
			&& ['localhost', '127.0.0.1', 'wordpress'].includes(hostname);
		if (
			(!productionDestination && !localDockerDestination)
			|| `${url.pathname}${url.search}${url.hash}` !== '/mi-cuenta/'
		) {
			return null;
		}
		destination = url.href;
	} catch {
		return null;
	}
	if (
		payload.sources !== false
		|| payload.type !== '301'
		|| payload.status !== 'active'
		|| !destination
	) {
		return null;
	}
	return 'reviewed_missing_sources_mi_cuenta';
}
