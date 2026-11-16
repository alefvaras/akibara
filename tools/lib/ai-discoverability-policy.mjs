function normalizeSignalValue(value) {
	return String(value ?? '').trim().toLowerCase();
}

export function parseContentSignal(raw) {
	if (!raw) return null;

	const values = {};
	const errors = [];
	for (const item of String(raw).split(',')) {
		const trimmed = item.trim();
		if (!trimmed) continue;
		const separator = trimmed.indexOf('=');
		if (separator <= 0 || separator === trimmed.length - 1) {
			errors.push(`directiva invalida: ${trimmed}`);
			continue;
		}

		const key = normalizeSignalValue(trimmed.slice(0, separator));
		const value = normalizeSignalValue(trimmed.slice(separator + 1));
		if (!/^[a-z][a-z0-9-]*$/.test(key)) {
			errors.push(`clave invalida: ${key || '(vacia)'}`);
			continue;
		}
		if (!['yes', 'no'].includes(value)) {
			errors.push(`valor invalido para ${key}: ${value || '(vacio)'}`);
			continue;
		}
		if (Object.hasOwn(values, key) && values[key] !== value) {
			errors.push(`valor conflictivo para ${key}`);
			continue;
		}
		values[key] = value;
	}

	if (Object.keys(values).length === 0 && errors.length === 0) return null;
	return { raw: String(raw), values, errors };
}

export function parseRobots(text) {
	const groups = [];
	const sitemaps = [];
	const unscopedContentSignals = [];
	let group = null;
	let sawRule = false;

	for (const [index, originalLine] of String(text ?? '').split(/\r?\n/).entries()) {
		const line = originalLine.replace(/#.*$/, '').trim();
		if (!line) continue;
		const separator = line.indexOf(':');
		if (separator === -1) continue;
		const directive = line.slice(0, separator).trim().toLowerCase();
		const value = line.slice(separator + 1).trim();

		if (directive === 'sitemap') {
			if (value) sitemaps.push(value);
			continue;
		}
		if (directive === 'user-agent') {
			if (!group || sawRule) {
				group = { agents: [], rules: [], contentSignals: [] };
				groups.push(group);
				sawRule = false;
			}
			group.agents.push(value.toLowerCase());
			continue;
		}
		if (directive === 'content-signal') {
			const signal = { line: index + 1, value };
			if (group) group.contentSignals.push(signal);
			else unscopedContentSignals.push(signal);
			sawRule = true;
			continue;
		}
		if (['allow', 'disallow'].includes(directive) && group) {
			group.rules.push({ directive, pattern: value });
			sawRule = true;
		}
	}

	return { groups, sitemaps, unscopedContentSignals };
}

function applicableRobotGroups(parsed, robotToken) {
	const userAgent = String(robotToken ?? '').toLowerCase();
	let bestSpecificity = -1;
	const applicable = [];
	for (const group of parsed.groups) {
		const matches = group.agents
			.map((agent) => (agent === '*' ? 0 : (userAgent.includes(agent) ? agent.length : -1)))
			.filter((specificity) => specificity >= 0);
		if (matches.length === 0) continue;
		const specificity = Math.max(...matches);
		if (specificity > bestSpecificity) {
			bestSpecificity = specificity;
			applicable.length = 0;
			applicable.push(group);
		} else if (specificity === bestSpecificity) {
			applicable.push(group);
		}
	}
	return applicable;
}

export function robotsContentSignal(parsed, robotToken) {
	const scoped = applicableRobotGroups(parsed, robotToken)
		.flatMap((group) => group.contentSignals ?? []);
	const signals = scoped.length > 0 ? scoped : (parsed.unscopedContentSignals ?? []);
	const parsedSignals = signals.map((signal) => ({
		line: signal.line,
		...parseContentSignal(signal.value),
	}));
	const errors = parsedSignals.flatMap((signal) =>
		(signal.errors ?? []).map((error) => `linea ${signal.line}: ${error}`)
	);
	const values = {};
	for (const signal of parsedSignals) {
		for (const [key, value] of Object.entries(signal.values ?? {})) {
			if (Object.hasOwn(values, key) && values[key] !== value) {
				errors.push(`directivas conflictivas para ${key}`);
				continue;
			}
			values[key] = value;
		}
	}

	return {
		present: signals.length > 0,
		raw: signals,
		values,
		errors: [...new Set(errors)],
	};
}

function robotsPatternMatches(pattern, target) {
	if (pattern === '') return false;
	const anchored = pattern.endsWith('$');
	const source = (anchored ? pattern.slice(0, -1) : pattern)
		.replace(/[.+?^${}()|[\]\\]/g, '\\$&')
		.replaceAll('*', '.*');
	return new RegExp(`^${source}${anchored ? '$' : ''}`).test(target);
}

export function robotsAllows(parsed, robotToken, target) {
	const applicable = applicableRobotGroups(parsed, robotToken);
	if (applicable.length === 0) return { allowed: true, matchedRule: null };

	const matchingRules = applicable
		.flatMap((group) => group.rules)
		.filter((rule) => robotsPatternMatches(rule.pattern, target))
		.sort((left, right) => {
			if (right.pattern.length !== left.pattern.length) return right.pattern.length - left.pattern.length;
			return left.directive === 'allow' ? -1 : 1;
		});
	if (matchingRules.length === 0) return { allowed: true, matchedRule: null };
	return { allowed: matchingRules[0].directive === 'allow', matchedRule: matchingRules[0] };
}
