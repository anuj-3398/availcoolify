<?php

use Illuminate\Support\Collection;

/**
 * Avail: headers and redirects from availcoolify.json (see bootstrap/helpers/avail-routing-rules.php).
 */
dataset('routing patterns', [
    'everything' => ['/(.*)', '^/(.*)$', true, [null], []],
    'path star' => ['/:path*', '^(?:/(.*))?$', true, ['path'], []],
    'prefix star' => ['/assets/:path*', '^/assets(?:/(.*))?$', false, ['path'], []],
    'prefix group' => ['/assets/(.*)', '^/assets/(.*)$', false, [null], []],
    'named segment' => ['/blog/:slug', '^/blog/([^/?]+)/?$', false, ['slug'], []],
    'plain path' => ['/old', '^/old/?$', false, [], []],
    'alternatives' => ['/(api|trpc)/:path*', '^/(api|trpc)(?:/(.*))?$', false, [null, 'path'], []],
    'custom segment regex' => ['/:lang(en|fr)/about', '^/(en|fr)/about/?$', false, ['lang'], []],
    'one or more' => ['/a/:b+', '^/a/(.+)$', false, ['b'], []],
    'next matcher' => ['/((?!api|_next).*)', '^/(.*)$', true, [null], ['/api', '/_next']],
    'next matcher under a prefix' => ['/foo/((?!bar).*)', '^/foo/(.*)$', false, [null], ['/foo/bar']],
    'bare star' => ['/files/*', '^/files/(.*)$', false, [null], []],
]);

it('translates path patterns', function (string $source, string $regex, bool $all, array $groups, array $excludes) {
    $pattern = availRoutingPattern($source);

    expect($pattern['regex'])->toBe($regex)
        ->and($pattern['all'])->toBe($all)
        ->and($pattern['groups'])->toBe($groups)
        ->and($pattern['excludes'])->toBe($excludes);
})->with('routing patterns');

it('refuses patterns Traefik cannot match', function (string $source) {
    expect(fn () => availRoutingPattern($source))->toThrow(InvalidArgumentException::class);
})->with([
    'no leading slash' => 'blog',
    'positive lookahead' => '/((?=x).*)',
    'lookbehind' => '/(.*)(?<=a)',
    'back reference' => '/(a)(\1)',
    'named group' => '/(?<name>a)',
    'exclusion not last' => '/((?!api).*)/x',
    'exclusion with regex' => '/((?!a.+b).*)',
    'unbalanced group' => '/(abc',
    'brace syntax' => '/a/{b}',
    'whitespace' => '/a b',
    'backtick' => '/a`b',
]);

it('reads headers in Vercel list form and in map form alike', function () {
    $list = availRoutingParse(json_encode(['headers' => [['source' => '/(.*)', 'headers' => [['key' => 'X-Frame-Options', 'value' => 'DENY']]]]]), 'availcoolify.json');
    $map = availRoutingParse(json_encode(['headers' => [['source' => '/(.*)', 'headers' => ['X-Frame-Options' => 'DENY']]]]), 'availcoolify.json');

    expect($list['ok'])->toBeTrue()
        ->and($list['rules']['headers'][0]['headers'])->toBe([['X-Frame-Options', 'DENY']])
        ->and($map['rules'])->toBe($list['rules']);
});

it('ignores the other keys of a vercel.json and says so', function () {
    $result = availRoutingParse(json_encode([
        '$schema' => 'https://openapi.vercel.sh/vercel.json',
        'buildCommand' => 'npm run build',
        'rewrites' => [['source' => '/a', 'destination' => '/b']],
        'headers' => [['source' => '/(.*)', 'headers' => [['key' => 'X-A', 'value' => '1']]]],
    ]), 'vercel.json');

    expect($result['ok'])->toBeTrue()
        ->and($result['rules']['file'])->toBe('vercel.json')
        ->and(implode(' ', $result['notes']))->toContain('buildCommand')->toContain('rewrites');
});

it('uses availcoolify.json when both files exist and vercel.json otherwise', function () {
    $own = json_encode(['redirects' => [['source' => '/a', 'destination' => '/b']]]);
    $vercel = json_encode(['redirects' => [['source' => '/c', 'destination' => '/d']]]);

    expect(availRoutingParseRepoFiles($own, $vercel)['rules']['file'])->toBe('availcoolify.json')
        ->and(availRoutingParseRepoFiles('', $vercel)['rules']['file'])->toBe('vercel.json')
        ->and(availRoutingParseRepoFiles('  ', '')['rules'])->toBeNull();
});

it('refuses a file that is not usable as a whole', function (string $contents) {
    $result = availRoutingParse($contents, 'availcoolify.json');

    expect($result['ok'])->toBeFalse()->and($result['rules'])->toBeNull()->and($result['errors'])->not->toBeEmpty();
})->with([
    'not json' => '{headers:',
    'a list' => '[1,2]',
    'headers not a list' => '{"headers": {"a": 1}}',
    'redirects not a list' => '{"redirects": "x"}',
]);

it('refuses a file over the size limit and too many rules', function () {
    expect(availRoutingParse(str_repeat(' ', AVAIL_ROUTING_MAX_BYTES + 1).'{}', 'availcoolify.json')['ok'])->toBeFalse();

    $many = array_fill(0, AVAIL_ROUTING_MAX_RULES + 1, ['source' => '/a', 'destination' => '/b']);
    expect(availRoutingParse(json_encode(['redirects' => $many]), 'availcoolify.json')['ok'])->toBeFalse();
});

it('skips single bad rules and keeps the rest', function () {
    $result = availRoutingParse(json_encode([
        'headers' => [
            ['source' => '/ok', 'headers' => [['key' => 'X-Ok', 'value' => '1'], ['key' => 'Set-Cookie', 'value' => 'a=b'], ['key' => 'X-Bad', 'value' => "a\r\nb"], ['key' => 'bad name', 'value' => 'x']]],
            ['source' => '/cond', 'has' => [['type' => 'header', 'key' => 'x']], 'headers' => [['key' => 'X-C', 'value' => '1']]],
            ['source' => '/((?=a).*)', 'headers' => [['key' => 'X-L', 'value' => '1']]],
            ['headers' => [['key' => 'X-N', 'value' => '1']]],
        ],
        'redirects' => [
            ['source' => '/loop', 'destination' => '/loop'],
            ['source' => '/x', 'destination' => 'javascript:alert(1)'],
            ['source' => '/((?!a).*)', 'destination' => '/z'],
            ['source' => '/good', 'destination' => '/better'],
        ],
    ]), 'availcoolify.json');

    expect($result['ok'])->toBeTrue()
        ->and($result['rules']['headers'])->toHaveCount(1)
        ->and($result['rules']['headers'][0]['headers'])->toBe([['X-Ok', '1']])
        ->and($result['rules']['redirects'])->toHaveCount(1)
        ->and($result['rules']['redirects'][0]['source'])->toBe('/good')
        ->and(count($result['notes']))->toBeGreaterThanOrEqual(7);
});

it('maps redirect status codes onto what Traefik can send', function () {
    $result = availRoutingParse(json_encode(['redirects' => [
        ['source' => '/a', 'destination' => '/x', 'permanent' => true],
        ['source' => '/b', 'destination' => '/x', 'permanent' => false],
        ['source' => '/c', 'destination' => '/x', 'statusCode' => 301],
        ['source' => '/d', 'destination' => '/x', 'statusCode' => 307],
        ['source' => '/e', 'destination' => '/x', 'statusCode' => 308],
        ['source' => '/f', 'destination' => '/x', 'statusCode' => 418],
    ]]), 'availcoolify.json');

    expect(array_column($result['rules']['redirects'], 'permanent'))->toBe([true, false, true, false, true])
        ->and(implode(' ', $result['notes']))->toContain('308')->toContain('statusCode must be');
});

it('turns captured segments into replacement groups and keeps the query string', function () {
    $result = availRoutingParse(json_encode(['redirects' => [
        ['source' => '/blog/:slug', 'destination' => '/posts/:slug'],
        ['source' => '/old/(.*)', 'destination' => 'https://example.org/new/$1?from=old'],
    ]]), 'availcoolify.json');

    [$blog, $old] = $result['rules']['redirects'];

    expect($blog['regex'])->toBe('^https?://[^/]+/blog/([^/?]+)/?(\?.*)?$')
        ->and($blog['replacement'])->toBe('/posts/${1}${2}')
        ->and($old['regex'])->toBe('^https?://[^/]+/old/(.*)(\?.*)?$')
        ->and($old['replacement'])->toBe('https://example.org/new/${1}?from=old');
});

function routingFixtureLabels(): Collection
{
    return collect([
        'traefik.enable=true',
        'traefik.http.middlewares.gzip.compress=true',
        'traefik.http.middlewares.redirect-to-https.redirectscheme.scheme=https',
        'traefik.http.routers.https-0-u1.rule=Host(`a.test`) && PathPrefix(`/`)',
        'traefik.http.routers.https-0-u1.entryPoints=https',
        'traefik.http.routers.https-0-u1.service=https-0-u1',
        'traefik.http.routers.https-0-u1.middlewares=gzip',
        'traefik.http.routers.https-0-u1.tls=true',
        'traefik.http.routers.https-0-u1.tls.certresolver=letsencrypt',
        'traefik.http.services.https-0-u1.loadbalancer.server.port=3000',
        'traefik.http.routers.http-0-u1.rule=Host(`a.test`) && PathPrefix(`/`)',
        'traefik.http.routers.http-0-u1.entryPoints=http',
        'traefik.http.routers.http-0-u1.service=http-0-u1',
        'traefik.http.routers.http-0-u1.middlewares=redirect-to-https',
        'traefik.http.services.http-0-u1.loadbalancer.server.port=3000',
    ]);
}

function routingFixtureRules(): array
{
    return availRoutingParse(json_encode([
        'headers' => [
            ['source' => '/(.*)', 'headers' => [['key' => 'X-Frame-Options', 'value' => 'DENY'], ['key' => 'Content-Security-Policy', 'value' => "default-src 'self'"]]],
            ['source' => '/assets/:path*', 'headers' => [['key' => 'Cache-Control', 'value' => 'public, max-age=31536000']]],
            ['source' => '/((?!api|_next).*)', 'headers' => [['key' => 'X-Page', 'value' => '1']]],
        ],
        'redirects' => [['source' => '/old', 'destination' => '/new', 'permanent' => true]],
    ]), 'availcoolify.json')['rules'];
}

it('adds the rules to the middleware chain and path routers without touching the HTTPS redirect router', function () {
    $labels = availRoutingRulesLabels(routingFixtureLabels(), routingFixtureRules(), 'u1')->values();
    $get = fn (string $prefix) => $labels->first(fn ($label) => str_starts_with($label, $prefix));

    // Redirect and site-wide headers join the chain of the HTTPS router.
    expect($get('traefik.http.routers.https-0-u1.middlewares='))->toBe('traefik.http.routers.https-0-u1.middlewares=gzip,avail-rr0-u1,avail-rh-u1')
        ->and($get('traefik.http.middlewares.avail-rr0-u1.redirectregex.regex='))->toBe('traefik.http.middlewares.avail-rr0-u1.redirectregex.regex=^https?://[^/]+/old/?(\?.*)?$')
        ->and($get('traefik.http.middlewares.avail-rr0-u1.redirectregex.permanent='))->toEndWith('=true')
        ->and($get('traefik.http.middlewares.avail-rh-u1.headers.customresponseheaders.X-Frame-Options='))->toEndWith('=DENY')
        ->and($get('traefik.http.middlewares.avail-rh-u1.headers.customresponseheaders.Content-Security-Policy='))->toEndWith("=default-src 'self'")
        // The HTTP router only redirects to HTTPS and stays exactly as it was.
        ->and($get('traefik.http.routers.http-0-u1.middlewares='))->toBe('traefik.http.routers.http-0-u1.middlewares=redirect-to-https')
        ->and($labels->contains(fn ($label) => str_starts_with($label, 'traefik.http.routers.http-0-u1-rh')))->toBeFalse();

    // Path-scoped rules get their own router with a higher priority and the same chain plus theirs.
    expect($get('traefik.http.routers.https-0-u1-rh1.rule='))->toBe('traefik.http.routers.https-0-u1-rh1.rule=Host(`a.test`) && PathPrefix(`/`) && PathRegexp(`^/assets(?:/(.*))?$`)')
        ->and($get('traefik.http.routers.https-0-u1-rh1.priority='))->toBe('traefik.http.routers.https-0-u1-rh1.priority='.(100000 - 1))
        ->and($get('traefik.http.routers.https-0-u1-rh1.middlewares='))->toBe('traefik.http.routers.https-0-u1-rh1.middlewares=gzip,avail-rr0-u1,avail-rh-u1,avail-rh1-u1')
        ->and($get('traefik.http.routers.https-0-u1-rh1.entryPoints='))->toEndWith('=https')
        ->and($get('traefik.http.routers.https-0-u1-rh1.service='))->toEndWith('=https-0-u1')
        ->and($get('traefik.http.routers.https-0-u1-rh1.tls='))->toEndWith('=true')
        ->and($get('traefik.http.routers.https-0-u1-rh1.tls.certresolver='))->toEndWith('=letsencrypt')
        ->and($get('traefik.http.middlewares.avail-rh1-u1.headers.customresponseheaders.Cache-Control='))->toEndWith('=public, max-age=31536000');

    // The Next.js exclusion form becomes "every path except".
    expect($get('traefik.http.routers.https-0-u1-rh2.rule='))->toBe('traefik.http.routers.https-0-u1-rh2.rule=Host(`a.test`) && PathPrefix(`/`) && !PathPrefix(`/api`) && !PathPrefix(`/_next`)')
        ->and($get('traefik.http.routers.https-0-u1-rh2.priority='))->toEndWith('='.(100000 - 2));
});

it('leaves the labels alone when there are no rules or no routers', function () {
    $labels = routingFixtureLabels();

    expect(availRoutingRulesLabels($labels, ['headers' => [], 'redirects' => []], 'u1'))->toEqual($labels)
        ->and(availRoutingRulesLabels(collect(['traefik.enable=true']), routingFixtureRules(), 'u1')->all())->toBe(['traefik.enable=true']);
});

it('skips path-scoped routers for apps served under a sub-path', function () {
    $labels = collect([
        'traefik.http.routers.https-0-u1.rule=Host(`a.test`) && PathPrefix(`/app`)',
        'traefik.http.routers.https-0-u1.entryPoints=https',
        'traefik.http.routers.https-0-u1.middlewares=gzip',
    ]);

    $result = availRoutingRulesLabels($labels, routingFixtureRules(), 'u1');

    expect($result->contains(fn ($label) => str_contains($label, '-rh1.')))->toBeFalse()
        ->and($result->first(fn ($label) => str_starts_with($label, 'traefik.http.routers.https-0-u1.middlewares=')))->toContain('avail-rr0-u1');
});

it('keeps Preview Guard first in front of the rules and on the path routers', function () {
    $labels = availRoutingRulesLabels(routingFixtureLabels(), routingFixtureRules(), 'u1');
    $guarded = $labels->map(function ($label) {
        // What applyPreviewGuardLabels() does to a router that has middlewares.
        return preg_match('/^(traefik\.http\.routers\.https-0-u1(?:-rh\d)?\.middlewares=)(.*)$/', $label, $m) ? $m[1].'preview-guard-x,'.$m[2] : $label;
    });

    expect($guarded->filter(fn ($label) => preg_match('/^traefik\.http\.routers\.https-0-u1(-rh\d)?\.middlewares=preview-guard-x,gzip,/', $label))->count())->toBe(3);
});

it('refuses to start the rules from a source that touches certificate issuance', function () {
    $result = availRoutingParse(json_encode(['redirects' => [['source' => '/.well-known/acme-challenge/(.*)', 'destination' => '/x']]]), 'availcoolify.json');

    expect($result['rules'])->toBeNull();
});
