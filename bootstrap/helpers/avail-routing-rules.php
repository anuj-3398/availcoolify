<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Support\Collection;

/**
 * Avail: routing rules (headers and redirects) from `availcoolify.json` in the repo, or, as a
 * fallback, from the `headers` and `redirects` of a `vercel.json`.
 *
 * The file is read during a deploy, checked here (a pure function, no I/O), and turned into
 * Traefik labels on the application's container by availRoutingRulesLabels().
 */
const AVAIL_ROUTING_FILE = 'availcoolify.json';

const AVAIL_ROUTING_FALLBACK_FILE = 'vercel.json';

const AVAIL_ROUTING_MAX_BYTES = 65536;

const AVAIL_ROUTING_MAX_RULES = 100;

const AVAIL_ROUTING_FORBIDDEN_HEADERS = [
    'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer',
    'transfer-encoding', 'upgrade', 'content-length', 'host', 'set-cookie',
];

/**
 * Pick the file to use and check it. $primary is the content of availcoolify.json, $fallback
 * the content of vercel.json (only read when there is no availcoolify.json). Returns:
 *   ok     false only when the file as a whole is unusable (the previous rules stay in place)
 *   rules  the checked rules, or null when there is nothing to apply
 *   notes  one line each for things that were skipped or changed
 *   errors why the file as a whole was refused
 *
 * @return array{ok: bool, rules: ?array, notes: array<int, string>, errors: array<int, string>}
 */
function availRoutingParseRepoFiles(string $primary, string $fallback = ''): array
{
    if (trim($primary) !== '') {
        return availRoutingParse($primary, AVAIL_ROUTING_FILE);
    }
    if (trim($fallback) !== '') {
        return availRoutingParse($fallback, AVAIL_ROUTING_FALLBACK_FILE);
    }

    return ['ok' => true, 'rules' => null, 'notes' => [], 'errors' => []];
}

/**
 * @return array{ok: bool, rules: ?array, notes: array<int, string>, errors: array<int, string>}
 */
function availRoutingParse(string $contents, string $file): array
{
    $notes = [];
    $fail = fn (string $error) => ['ok' => false, 'rules' => null, 'notes' => $notes, 'errors' => ["{$file}: {$error}"]];

    if (strlen($contents) > AVAIL_ROUTING_MAX_BYTES) {
        return $fail('the file is larger than '.(AVAIL_ROUTING_MAX_BYTES / 1024).' KB.');
    }
    $data = json_decode($contents, true);
    if (! is_array($data) || array_is_list($data)) {
        return $fail('not a valid JSON object ('.json_last_error_msg().').');
    }

    $ignored = collect(array_keys($data))
        ->reject(fn ($key) => in_array($key, ['headers', 'redirects', 'rewrites', 'cleanUrls', 'trailingSlash', '$schema'], true))
        ->values();
    if ($ignored->isNotEmpty()) {
        $notes[] = "{$file}: ignored keys ({$ignored->take(10)->join(', ')}), only headers, redirects, rewrites, cleanUrls and trailingSlash are used.";
    }

    $headerRules = [];
    $redirectRules = [];
    $rewriteRules = [];

    $headers = $data['headers'] ?? [];
    if (! is_array($headers) || (! array_is_list($headers) && $headers !== [])) {
        return $fail('"headers" must be a list.');
    }
    $redirects = $data['redirects'] ?? [];
    if (! is_array($redirects) || (! array_is_list($redirects) && $redirects !== [])) {
        return $fail('"redirects" must be a list.');
    }
    $rewrites = $data['rewrites'] ?? [];
    if (! is_array($rewrites) || (! array_is_list($rewrites) && $rewrites !== [])) {
        return $fail('"rewrites" must be a list.');
    }
    if (count($headers) > AVAIL_ROUTING_MAX_RULES || count($redirects) > AVAIL_ROUTING_MAX_RULES || count($rewrites) > AVAIL_ROUTING_MAX_RULES) {
        return $fail('more than '.AVAIL_ROUTING_MAX_RULES.' header, redirect or rewrite rules.');
    }

    foreach (['cleanUrls', 'trailingSlash'] as $flag) {
        if (isset($data[$flag]) && ! is_bool($data[$flag])) {
            $notes[] = "{$file}: {$flag} ignored, it must be true or false.";
            unset($data[$flag]);
        }
    }
    $cleanUrls = ($data['cleanUrls'] ?? false) === true ? true : null;
    $trailingSlash = array_key_exists('trailingSlash', $data) ? $data['trailingSlash'] : null;

    foreach ($rewrites as $i => $rule) {
        $parsed = availRoutingParseRewriteRule($rule, 'rewrites['.$i.']', $notes);
        if ($parsed) {
            $rewriteRules[] = $parsed;
        }
    }

    foreach ($headers as $i => $rule) {
        $label = 'headers['.$i.']';
        $parsed = availRoutingParseHeaderRule($rule, $label, $notes);
        if ($parsed) {
            $headerRules[] = $parsed;
        }
    }

    $permanentSeen = false;
    $codeSeen = false;
    foreach ($redirects as $i => $rule) {
        $label = 'redirects['.$i.']';
        $parsed = availRoutingParseRedirectRule($rule, $label, $notes, $permanentSeen, $codeSeen);
        if ($parsed) {
            $redirectRules[] = $parsed;
        }
    }
    if ($permanentSeen) {
        $notes[] = 'Permanent redirects are sent as 301 (Traefik cannot send 308).';
    }
    if ($codeSeen) {
        $notes[] = 'Redirect status codes 307 and 308 are sent as 302 and 301 (Traefik cannot send 307 or 308); a POST may change to GET.';
    }

    if ($headerRules === [] && $redirectRules === [] && $rewriteRules === [] && $cleanUrls === null && $trailingSlash === null) {
        return ['ok' => true, 'rules' => null, 'notes' => $notes, 'errors' => []];
    }

    return [
        'ok' => true,
        'rules' => [
            'version' => 2,
            'file' => $file,
            'headers' => $headerRules,
            'redirects' => $redirectRules,
            'rewrites' => $rewriteRules,
            'cleanUrls' => $cleanUrls,
            'trailingSlash' => $trailingSlash,
        ],
        'notes' => $notes,
        'errors' => [],
    ];
}

/**
 * Checks the "has" and "missing" lists of a rule. Returns the conditions (possibly none), or null
 * when the rule has to be skipped (the reason is added to $notes).
 *
 * @return array<int, array{mode: string, type: string, key: ?string, value: ?string}>|null
 */
function availRoutingParseConditions(array $rule, string $label, array &$notes): ?array
{
    $conditions = [];
    foreach (['has', 'missing'] as $mode) {
        if (! isset($rule[$mode])) {
            continue;
        }
        if (! is_array($rule[$mode]) || ! array_is_list($rule[$mode])) {
            $notes[] = "{$label} ({$rule['source']}): skipped, \"{$mode}\" must be a list.";

            return null;
        }
        foreach ($rule[$mode] as $condition) {
            $type = is_array($condition) ? ($condition['type'] ?? null) : null;
            $key = is_array($condition) ? ($condition['key'] ?? null) : null;
            $value = is_array($condition) ? ($condition['value'] ?? null) : null;
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            $valid = in_array($type, ['header', 'cookie', 'query', 'host'], true)
                && ($value === null || (is_string($value) && strlen($value) <= 200 && ! preg_match('/[`\x00-\x1F\x7F]/', $value)));
            if ($valid && $type === 'host') {
                $valid = $key === null && is_string($value) && preg_match('/^[A-Za-z0-9.-]+$/', $value);
            } elseif ($valid) {
                $valid = is_string($key) && preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $key);
            }
            if (! $valid) {
                $notes[] = "{$label} ({$rule['source']}): skipped, a \"{$mode}\" condition is not supported (use type header, cookie, query or host with a plain text value).";

                return null;
            }
            $conditions[] = ['mode' => $mode, 'type' => $type, 'key' => $key, 'value' => $value];
        }
    }
    if (count($conditions) > 5) {
        $notes[] = "{$label} ({$rule['source']}): skipped, more than 5 conditions.";

        return null;
    }

    return $conditions;
}

/**
 * Router predicates (Traefik rule syntax) for a list of conditions.
 *
 * @param  array<int, array{mode: string, type: string, key: ?string, value: ?string}>  $conditions
 * @return array<int, string>
 */
function availRoutingConditionPredicates(array $conditions): array
{
    $escape = fn (string $text) => preg_replace('/([\\\\.+*?()|\[\]{}^$])/', '\\\\$1', $text);
    $predicates = [];
    foreach ($conditions as $condition) {
        $not = $condition['mode'] === 'missing' ? '!' : '';
        $key = $condition['key'];
        $value = $condition['value'];
        $predicates[] = match ($condition['type']) {
            'host' => $not.'Host(`'.$value.'`)',
            'header' => $value === null ? $not.'HeaderRegexp(`'.$key.'`, `.*`)' : $not.'Header(`'.$key.'`, `'.$value.'`)',
            'query' => $value === null ? $not.'QueryRegexp(`'.$key.'`, `.*`)' : $not.'Query(`'.$key.'`, `'.$value.'`)',
            'cookie' => $not.'HeaderRegexp(`Cookie`, `(^|;\s*)'.$escape($key).'='.($value === null ? '' : $escape($value).'(;|$)').'`)',
        };
    }

    return $predicates;
}

/**
 * A rewrite (static sites only): the visitor keeps the URL, the web server serves another file.
 * Only paths on the same site are supported.
 */
function availRoutingParseRewriteRule(mixed $rule, string $label, array &$notes): ?array
{
    if (! is_array($rule) || ! isset($rule['source'], $rule['destination']) || ! is_string($rule['source']) || ! is_string($rule['destination'])) {
        $notes[] = "{$label}: skipped, it needs a \"source\" and a \"destination\".";

        return null;
    }
    if (isset($rule['has']) || isset($rule['missing'])) {
        $notes[] = "{$label} ({$rule['source']}): skipped, conditions on rewrites are not supported yet.";

        return null;
    }
    if (str_starts_with($rule['source'], '/.well-known/acme-challenge')) {
        $notes[] = "{$label} ({$rule['source']}): skipped, certificate issuance paths cannot have rules.";

        return null;
    }
    if (! str_starts_with($rule['destination'], '/')) {
        $notes[] = "{$label} ({$rule['source']}): skipped, rewrites to another site are not supported.";

        return null;
    }
    if (! preg_match('#^/(?:[A-Za-z0-9._~/%@+,=:&?-]|\$[1-9])*$#', $rule['destination']) || strlen($rule['destination']) > 1024) {
        $notes[] = "{$label} ({$rule['source']}): skipped, the destination has characters that are not allowed.";

        return null;
    }

    try {
        $pattern = availRoutingPattern($rule['source']);
    } catch (InvalidArgumentException $exception) {
        $notes[] = "{$label} ({$rule['source']}): skipped, {$exception->getMessage()}";

        return null;
    }
    if (count($pattern['groups']) > 9) {
        $notes[] = "{$label} ({$rule['source']}): skipped, more than 9 captured parts.";

        return null;
    }

    $groups = $pattern['groups'];
    $replacement = preg_replace_callback('/:([A-Za-z_][A-Za-z0-9_]*)/', function ($match) use ($groups) {
        $index = array_search($match[1], $groups, true);

        return $index === false ? $match[0] : '$'.($index + 1);
    }, $rule['destination']);
    if (str_ends_with($replacement, '/')) {
        $replacement .= 'index.html';
    }

    $regex = $pattern['regex'];
    if ($pattern['excludes'] !== []) {
        $escape = fn (string $text) => preg_replace('/([\\\\.+*?()|\[\]{}^$])/', '\\\\$1', $text);
        $regex = '^(?!'.implode('|', array_map($escape, $pattern['excludes'])).')'.substr($regex, 1);
    }
    foreach ([$regex, $replacement] as $text) {
        if (preg_match('/["\'\x00-\x1F\x7F]|\\\\[nrt"\'\\\\]/', $text)) {
            $notes[] = "{$label} ({$rule['source']}): skipped, the pattern has characters that are not allowed.";

            return null;
        }
    }

    return ['source' => $rule['source'], 'regex' => $regex, 'replacement' => $replacement];
}

function availRoutingParseHeaderRule(mixed $rule, string $label, array &$notes): ?array
{
    if (! is_array($rule) || ! isset($rule['source']) || ! is_string($rule['source'])) {
        $notes[] = "{$label}: skipped, it needs a \"source\" path.";

        return null;
    }
    $conditions = availRoutingParseConditions($rule, $label, $notes);
    if ($conditions === null) {
        return null;
    }

    if (str_starts_with($rule['source'], '/.well-known/acme-challenge')) {
        $notes[] = "{$label} ({$rule['source']}): skipped, certificate issuance paths cannot have rules.";

        return null;
    }

    try {
        $pattern = availRoutingPattern($rule['source']);
    } catch (InvalidArgumentException $exception) {
        $notes[] = "{$label} ({$rule['source']}): skipped, {$exception->getMessage()}";

        return null;
    }

    $entries = [];
    $raw = $rule['headers'] ?? null;
    if (! is_array($raw)) {
        $notes[] = "{$label} ({$rule['source']}): skipped, it has no \"headers\".";

        return null;
    }
    if (! array_is_list($raw)) {
        $raw = collect($raw)->map(fn ($value, $key) => ['key' => (string) $key, 'value' => $value])->values()->all();
    }
    foreach ($raw as $entry) {
        $name = is_array($entry) ? ($entry['key'] ?? null) : null;
        $value = is_array($entry) ? ($entry['value'] ?? null) : null;
        if (! is_string($name) || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/', $name)) {
            $notes[] = "{$label} ({$rule['source']}): skipped a header with an invalid name.";

            continue;
        }
        if (in_array(strtolower($name), AVAIL_ROUTING_FORBIDDEN_HEADERS, true)) {
            $notes[] = "{$label} ({$rule['source']}): skipped header {$name}, it cannot be set here.";

            continue;
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)) {
            $notes[] = "{$label} ({$rule['source']}): skipped header {$name}, the value is not valid.";

            continue;
        }
        $entries[] = [$name, $value];
    }
    if ($entries === []) {
        return null;
    }

    return [
        'source' => $rule['source'],
        'matcher' => [
            'all' => $pattern['all'],
            'regex' => $pattern['all'] ? null : $pattern['regex'],
            'excludes' => $pattern['excludes'],
        ],
        'conditions' => $conditions,
        'headers' => $entries,
    ];
}

function availRoutingParseRedirectRule(mixed $rule, string $label, array &$notes, bool &$permanentSeen, bool &$codeSeen): ?array
{
    if (! is_array($rule) || ! isset($rule['source'], $rule['destination']) || ! is_string($rule['source']) || ! is_string($rule['destination'])) {
        $notes[] = "{$label}: skipped, it needs a \"source\" and a \"destination\".";

        return null;
    }
    $conditions = availRoutingParseConditions($rule, $label, $notes);
    if ($conditions === null) {
        return null;
    }

    if (str_starts_with($rule['source'], '/.well-known/acme-challenge')) {
        $notes[] = "{$label} ({$rule['source']}): skipped, certificate issuance paths cannot have rules.";

        return null;
    }
    $destination = $rule['destination'];
    if (! preg_match('#^(/|https?://)[^\s`\x00-\x1F\x7F]*$#', $destination) || strlen($destination) > 2048) {
        $notes[] = "{$label} ({$rule['source']}): skipped, the destination must be a path or an http(s) URL.";

        return null;
    }

    try {
        $pattern = availRoutingPattern($rule['source']);
    } catch (InvalidArgumentException $exception) {
        $notes[] = "{$label} ({$rule['source']}): skipped, {$exception->getMessage()}";

        return null;
    }
    if ($pattern['excludes'] !== []) {
        $notes[] = "{$label} ({$rule['source']}): skipped, exclusion patterns work for headers only.";

        return null;
    }

    $permanent = false;
    if (isset($rule['statusCode'])) {
        $code = (int) $rule['statusCode'];
        if (! in_array($code, [301, 302, 303, 307, 308], true)) {
            $notes[] = "{$label} ({$rule['source']}): skipped, statusCode must be 301, 302, 303, 307 or 308.";

            return null;
        }
        $permanent = in_array($code, [301, 308], true);
        if (in_array($code, [307, 308], true)) {
            $codeSeen = true;
        }
    } elseif (! empty($rule['permanent'])) {
        $permanent = true;
        $permanentSeen = true;
    }

    // A rule that redirects a plain path to itself would loop.
    if ($pattern['literal'] !== null && rtrim($destination, '/') === rtrim($pattern['literal'], '/')) {
        $notes[] = "{$label} ({$rule['source']}): skipped, it redirects to itself.";

        return null;
    }

    $groups = $pattern['groups'];
    $replacement = preg_replace_callback('/:([A-Za-z_][A-Za-z0-9_]*)/', function ($match) use ($groups) {
        $index = array_search($match[1], $groups, true);

        return $index === false ? $match[0] : '${'.($index + 1).'}';
    }, $destination);
    $replacement = preg_replace_callback('/\$(\d+)/', fn ($match) => '${'.$match[1].'}', $replacement);
    $tail = count($groups) + 1;
    if (! str_contains($destination, '?')) {
        $replacement .= '${'.$tail.'}';
    }

    return [
        'source' => $rule['source'],
        'regex' => '^https?://[^/]+'.$pattern['body'].'(\?.*)?$',
        'path_regex' => $pattern['regex'],
        'conditions' => $conditions,
        'replacement' => $replacement,
        'permanent' => $permanent,
    ];
}

/**
 * Translate a Vercel / path-to-regexp path pattern into what Traefik (Go regex, no lookahead)
 * can match. Returns:
 *   regex     anchored regex for the path
 *   body      the same without the anchors, for redirect regexes
 *   groups    capture group names in order (null for unnamed groups)
 *   all       true when the pattern matches every path
 *   excludes  path prefixes that must not match (the Next.js `/((?!api|_next).*)` form)
 *   literal   the path when the pattern has no variables
 *
 * @return array{regex: string, body: string, groups: array<int, ?string>, all: bool, excludes: array<int, string>, literal: ?string}
 *
 * @throws InvalidArgumentException when the pattern is not supported
 */
function availRoutingPattern(string $source): array
{
    if ($source === '' || $source[0] !== '/' || strlen($source) > 512 || preg_match('/[\s`\x00-\x1F\x7F]/', $source)) {
        throw new InvalidArgumentException('the source must be a path that starts with / (no spaces).');
    }

    $length = strlen($source);
    $body = '';
    $groups = [];
    $excludes = [];
    $literalOnly = true;
    $openEnded = false;
    $literalPrefix = '';
    $i = 0;

    $escape = fn (string $char) => str_contains('\\.+*?()|[]{}^$', $char) ? '\\'.$char : $char;
    $modifierAt = function (int &$i) use ($source, $length): string {
        if ($i < $length && in_array($source[$i], ['*', '+', '?'], true)) {
            return $source[$i++];
        }

        return '';
    };
    // A group with * or ? swallows the slash before it, so "/assets/:path*" also matches "/assets".
    $wrap = function (string $inner, string $modifier) use (&$body): void {
        if ($modifier === '*' || $modifier === '?') {
            if (str_ends_with($body, '/')) {
                $body = substr($body, 0, -1);
                $body .= "(?:/({$inner}))?";
            } else {
                $body .= "({$inner})?";
            }
        } else {
            $body .= "({$inner})";
        }
    };

    while ($i < $length) {
        $char = $source[$i];

        if ($char === '\\') {
            $i++;
            if ($i >= $length) {
                throw new InvalidArgumentException('the pattern ends with a backslash.');
            }
            $body .= $escape($source[$i]);
            $literalPrefix = $literalPrefix === null ? null : $literalPrefix.$source[$i];
            $i++;
            $openEnded = false;

            continue;
        }

        if ($char === ':' && $i + 1 < $length && preg_match('/[A-Za-z_]/', $source[$i + 1])) {
            $literalOnly = false;
            $i++;
            $name = '';
            while ($i < $length && preg_match('/[A-Za-z0-9_]/', $source[$i])) {
                $name .= $source[$i++];
            }
            $custom = null;
            if ($i < $length && $source[$i] === '(') {
                $custom = availRoutingCheckRegex(availRoutingGroupContent($source, $i, $length));
            }
            $modifier = $modifierAt($i);
            $inner = $custom ?? match ($modifier) {
                '*' => '.*',
                '+' => '.+',
                default => '[^/?]+',
            };
            $groups[] = $name;
            $wrap($inner, $modifier);
            $literalPrefix = null;
            $openEnded = $modifier === '*' || $modifier === '+' || $inner === '.*';

            continue;
        }

        if ($char === '(') {
            $literalOnly = false;
            $content = availRoutingGroupContent($source, $i, $length);

            if (preg_match('/^\(\?!([^()]+)\)(\.\*|\.\+)?$/', $content, $match)) {
                if ($i < $length || $literalPrefix === null) {
                    throw new InvalidArgumentException('a "(?!...)" exclusion must be the last part of the pattern, after plain text.');
                }
                foreach (explode('|', $match[1]) as $alternative) {
                    if (! preg_match('#^[A-Za-z0-9_./-]+$#', $alternative)) {
                        throw new InvalidArgumentException('only plain path names are supported inside "(?!...)".');
                    }
                    $excludes[] = $literalPrefix.$alternative;
                }
                $groups[] = null;
                $body .= '('.($match[2] ?? '.*').')';
                $openEnded = true;

                continue;
            }

            $inner = availRoutingCheckRegex($content);
            $modifier = $modifierAt($i);
            $groups[] = null;
            $wrap($inner, $modifier);
            $literalPrefix = null;
            $openEnded = $modifier === '*' || $modifier === '+' || in_array($inner, ['.*', '.+'], true);

            continue;
        }

        if ($char === '*') {
            $literalOnly = false;
            $i++;
            $groups[] = null;
            $body .= '(.*)';
            $literalPrefix = null;
            $openEnded = true;

            continue;
        }

        if (in_array($char, ['?', '+', '{', '}'], true)) {
            throw new InvalidArgumentException("the character \"{$char}\" is not supported here.");
        }

        $body .= $escape($char);
        if ($literalPrefix !== null) {
            $literalPrefix .= $char;
        }
        $openEnded = false;
        $i++;
    }

    $literal = $literalOnly ? $source : null;
    if ($literalOnly) {
        $body = rtrim($body, '/').'/?';
    } elseif (! $openEnded) {
        $body = rtrim($body, '/').'/?';
    }
    $regex = '^'.$body.'$';

    $all = in_array($regex, ['^/(.*)$', '^(?:/(.*))?$'], true);

    return [
        'regex' => $regex,
        'body' => $body,
        'groups' => $groups,
        'all' => $all,
        'excludes' => array_values(array_unique($excludes)),
        'literal' => $literal,
    ];
}

/**
 * Reads a balanced "(...)" starting at $i and returns its inside; $i ends after the ")".
 */
function availRoutingGroupContent(string $source, int &$i, int $length): string
{
    $depth = 0;
    $start = $i;
    while ($i < $length) {
        $char = $source[$i];
        if ($char === '\\') {
            $i += 2;

            continue;
        }
        if ($char === '(') {
            $depth++;
        } elseif ($char === ')') {
            $depth--;
            if ($depth === 0) {
                $i++;

                return substr($source, $start + 1, $i - $start - 2);
            }
        }
        $i++;
    }

    throw new InvalidArgumentException('a "(" has no matching ")".');
}

/**
 * Checks that a regex fragment works in Traefik's Go regex engine and has no capture groups of
 * its own (they would shift the numbering used by redirects).
 */
function availRoutingCheckRegex(string $regex): string
{
    if ($regex === '' || strlen($regex) > 200) {
        throw new InvalidArgumentException('a group is empty or too long.');
    }
    if (str_starts_with($regex, '?') || preg_match('/\(\?(?!:)/', $regex)) {
        throw new InvalidArgumentException('lookahead, lookbehind and named groups are not supported (only "(?:...)").');
    }
    if (preg_match('/\\\\[1-9]/', $regex)) {
        throw new InvalidArgumentException('back-references are not supported.');
    }

    // Turn the group's own capturing parentheses into non-capturing ones.
    $out = '';
    $length = strlen($regex);
    $inClass = false;
    for ($i = 0; $i < $length; $i++) {
        $char = $regex[$i];
        if ($char === '\\') {
            $out .= $char.($regex[++$i] ?? '');

            continue;
        }
        if ($inClass) {
            $inClass = $char !== ']';
        } elseif ($char === '[') {
            $inClass = true;
        } elseif ($char === '(' && ($regex[$i + 1] ?? '') !== '?') {
            $out .= '(?:';

            continue;
        }
        $out .= $char;
    }

    return $out;
}

/**
 * Add the rules to the Traefik labels of an application: redirects and site-wide headers join the
 * middleware chain of every router, path-scoped headers get an extra router with a higher priority
 * that copies the chain (so Preview Guard, basic auth and the rest still apply).
 *
 * Call it before applyPreviewGuardLabels(), so the guard still runs first.
 *
 * @param  Collection<int, string>  $labels
 * @return Collection<int, string>
 */
function availRoutingRulesLabels(Collection $labels, array $rules, string $uuid): Collection
{
    $headerRules = $rules['headers'] ?? [];
    $redirectRules = $rules['redirects'] ?? [];
    if ($headerRules === [] && $redirectRules === []) {
        return $labels;
    }

    $labels = $labels->values();
    $routers = $labels
        ->map(fn ($label) => preg_match('/^traefik\.http\.routers\.([^.]+)\.rule=/', (string) $label, $m) ? $m[1] : null)
        ->filter()
        ->unique()
        ->values();
    if ($routers->isEmpty()) {
        return $labels;
    }

    $mw = fn (string $suffix) => "avail-{$suffix}-{$uuid}";

    // Middlewares.
    $chainAll = [];
    foreach ($redirectRules as $i => $redirect) {
        $name = $mw("rr{$i}");
        $labels->push("traefik.http.middlewares.{$name}.redirectregex.regex={$redirect['regex']}");
        $labels->push("traefik.http.middlewares.{$name}.redirectregex.replacement={$redirect['replacement']}");
        $labels->push("traefik.http.middlewares.{$name}.redirectregex.permanent=".($redirect['permanent'] ? 'true' : 'false'));
        // A redirect with conditions only runs in its own router, below.
        if (empty($redirect['conditions'])) {
            $chainAll[] = $name;
        }
    }

    $siteWide = [];
    $scoped = [];
    foreach ($headerRules as $j => $rule) {
        $isSiteWide = $rule['matcher']['all'] && $rule['matcher']['excludes'] === [] && empty($rule['conditions']);
        if ($isSiteWide) {
            foreach ($rule['headers'] as [$headerName, $headerValue]) {
                $siteWide[strtolower($headerName)] = [$headerName, $headerValue];
            }
        } else {
            $scoped[] = [$j, $rule];
        }
    }
    if ($siteWide !== []) {
        $name = $mw('rh');
        foreach ($siteWide as [$headerName, $headerValue]) {
            $labels->push("traefik.http.middlewares.{$name}.headers.customresponseheaders.{$headerName}={$headerValue}");
        }
        $chainAll[] = $name;
    }
    foreach ($scoped as [$j, $rule]) {
        $name = $mw("rh{$j}");
        $unique = [];
        foreach ($rule['headers'] as [$headerName, $headerValue]) {
            $unique[strtolower($headerName)] = [$headerName, $headerValue];
        }
        foreach ($unique as [$headerName, $headerValue]) {
            $labels->push("traefik.http.middlewares.{$name}.headers.customresponseheaders.{$headerName}={$headerValue}");
        }
    }

    // Routers.
    foreach ($routers as $router) {
        $prefix = "traefik.http.routers.{$router}.";
        $props = [];
        foreach ($labels as $label) {
            if (str_starts_with((string) $label, $prefix)) {
                [$key, $value] = array_pad(explode('=', substr((string) $label, strlen($prefix)), 2), 2, '');
                $props[strtolower($key)] = [$key, $value];
            }
        }
        $existing = collect(explode(',', $props['middlewares'][1] ?? ''))->filter()->values();
        // Plain-HTTP routers that only redirect to HTTPS are left alone.
        if ($existing->contains('redirect-to-https')) {
            continue;
        }

        $chain = $existing->merge($chainAll)->values();
        $index = $labels->search(fn ($label) => str_starts_with((string) $label, $prefix.'middlewares='));
        if ($index === false) {
            $labels->push($prefix.'middlewares='.$chain->join(','));
        } else {
            $labels[$index] = $prefix.'middlewares='.$chain->join(',');
        }

        $rule = $props['rule'][1] ?? '';
        if (! preg_match('/PathPrefix\(`([^`]*)`\)/', $rule, $m) || ! in_array($m[1], ['', '/'], true)) {
            continue;
        }

        foreach ($scoped as [$j, $headerRule]) {
            $extra = "{$router}-rh{$j}";
            $extraPrefix = "traefik.http.routers.{$extra}.";
            $predicates = [];
            if ($headerRule['matcher']['regex'] !== null) {
                $predicates[] = 'PathRegexp(`'.$headerRule['matcher']['regex'].'`)';
            }
            foreach ($headerRule['matcher']['excludes'] as $excluded) {
                $predicates[] = '!PathPrefix(`'.$excluded.'`)';
            }
            $predicates = array_merge($predicates, availRoutingConditionPredicates($headerRule['conditions'] ?? []));
            $labels->push($extraPrefix.'rule='.$rule.' && '.implode(' && ', $predicates));
            $labels->push($extraPrefix.'priority='.(100000 - $j));
            foreach (['entrypoints', 'service', 'tls', 'tls.certresolver'] as $copy) {
                if (isset($props[$copy])) {
                    $labels->push($extraPrefix.$props[$copy][0].'='.$props[$copy][1]);
                }
            }
            $labels->push($extraPrefix.'middlewares='.$chain->concat([$mw("rh{$j}")])->join(','));
        }

        // Redirects with conditions: their own router, ahead of the header routers.
        foreach ($redirectRules as $i => $redirect) {
            if (empty($redirect['conditions'])) {
                continue;
            }
            $extra = "{$router}-rrc{$i}";
            $extraPrefix = "traefik.http.routers.{$extra}.";
            $predicates = array_merge(
                ['PathRegexp(`'.$redirect['path_regex'].'`)'],
                availRoutingConditionPredicates($redirect['conditions'])
            );
            $labels->push($extraPrefix.'rule='.$rule.' && '.implode(' && ', $predicates));
            $labels->push($extraPrefix.'priority='.(200000 - $i));
            foreach (['entrypoints', 'service', 'tls', 'tls.certresolver'] as $copy) {
                if (isset($props[$copy])) {
                    $labels->push($extraPrefix.$props[$copy][0].'='.$props[$copy][1]);
                }
            }
            $labels->push($extraPrefix.'middlewares='.$existing->concat([$mw("rr{$i}")])->join(','));
        }
    }

    return $labels->sort()->values();
}

/**
 * Hook for generateLabelsApplication(): applies the rules stored on the application (or, for a
 * pull request preview, on the preview).
 *
 * @param  Collection<int, string>  $labels
 * @return Collection<int, string>
 */
function applyAvailRoutingRulesLabels(Collection $labels, Application $application, ?ApplicationPreview $preview = null): Collection
{
    if (! config('avail.routing_rules_enabled', true)) {
        return $labels;
    }

    $pullRequestId = (int) data_get($preview, 'pull_request_id', 0);
    $rules = $pullRequestId === 0 ? $application->avail_routing_rules : $preview?->avail_routing_rules;
    if (! is_array($rules)) {
        return $labels;
    }

    $uuid = $pullRequestId === 0 ? $application->uuid : "{$application->uuid}-pr-{$pullRequestId}";

    return availRoutingRulesLabels($labels, $rules, $uuid);
}
