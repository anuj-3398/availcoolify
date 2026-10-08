<?php

use Illuminate\Support\Collection;

/**
 * Avail: phase 2 of availcoolify.json: rewrites, cleanUrls and trailingSlash (static sites, Nginx)
 * and has/missing conditions (Traefik). See bootstrap/helpers/avail-routing-rules.php and
 * avail-routing-nginx.php.
 */
function routingDevFile(): string
{
    return <<<'JSON'
{
  "redirects": [
    { "source": "/bsc", "destination": "/bnb-smart-chain", "statusCode": 301 },
    { "source": "/bsc/:path*", "destination": "/bnb-smart-chain", "statusCode": 301 },
    { "source": "/faq", "destination": "/faqs", "statusCode": 301 }
  ],
  "rewrites": [
    { "source": "/guides/:slug", "destination": "/guides/:slug/index.html" },
    { "source": "/guides/:slug/(.*)", "destination": "/guides/:slug/index.html" },
    { "source": "/:slug(about|app|guides|faqs|contact|megaeth|monad)", "destination": "/:slug/index.html" },
    { "source": "/:slug(about|app|guides|faqs|contact|megaeth|monad)/(.*)", "destination": "/:slug/index.html" },
    { "source": "/(.*)", "destination": "/" }
  ],
  "headers": [
    { "source": "/assets/(.*)", "headers": [{ "key": "Cache-Control", "value": "public, max-age=31536000, immutable" }] },
    { "source": "/landing-new/(.*\\.css)", "headers": [{ "key": "Cache-Control", "value": "public, max-age=3600" }] },
    { "source": "/(.*\\.(?:ico|png|jpg|svg|woff2))", "headers": [{ "key": "Cache-Control", "value": "public, max-age=86400" }] },
    { "source": "/(.*)", "headers": [{ "key": "X-Frame-Options", "value": "DENY" }, { "key": "X-XSS-Protection", "value": "1; mode=block" }] }
  ]
}
JSON;
}

it('reads the devs vercel.json with all its rewrites, redirects and headers', function () {
    $result = availRoutingParseRepoFiles('', routingDevFile());

    expect($result['ok'])->toBeTrue()
        ->and($result['notes'])->toBe([])
        ->and($result['rules']['file'])->toBe('vercel.json')
        ->and($result['rules']['rewrites'])->toHaveCount(5)
        ->and($result['rules']['redirects'])->toHaveCount(3)
        ->and($result['rules']['headers'])->toHaveCount(4);
});

it('turns the devs rewrites into an Nginx configuration that serves files first', function () {
    $rules = availRoutingParseRepoFiles('', routingDevFile())['rules'];
    $conf = availRoutingNginxConfig($rules, false);

    expect($conf)->toContain('try_files $uri $uri.html $uri/index.html $uri/index.htm $uri/ @avail_rewrites;')
        ->and($conf)->toContain('absolute_redirect off;')
        ->and($conf)->toContain('rewrite "^/guides/([^/?]+)/?$" "/guides/$1/index.html" break;')
        ->and($conf)->toContain('rewrite "^/guides/([^/?]+)/(.*)$" "/guides/$1/index.html" break;')
        ->and($conf)->toContain('rewrite "^/(about|app|guides|faqs|contact|megaeth|monad)/?$" "/$1/index.html" break;')
        ->and($conf)->toContain('rewrite "^/(about|app|guides|faqs|contact|megaeth|monad)/(.*)$" "/$1/index.html" break;')
        ->and($conf)->toContain('rewrite "^/(.*)$" "/index.html" break;')
        ->and($conf)->toContain('return 404;')
        // The rewrites keep the order of the file.
        ->and(strpos($conf, '/guides/$1/index.html'))->toBeLessThan(strpos($conf, '"/index.html"'));
});

it('keeps the Single Page Application fallback last when that setting is on', function () {
    $rules = availRoutingParse(json_encode(['rewrites' => [['source' => '/about', 'destination' => '/about/index.html']]]), 'availcoolify.json')['rules'];

    $conf = availRoutingNginxConfig($rules, true);

    expect($conf)->toContain('rewrite ^ /index.html break;')
        ->and(strpos($conf, '/about/index.html'))->toBeLessThan(strpos($conf, 'rewrite ^ /index.html break;'));
    // Without rewrites, cleanUrls or trailingSlash there is nothing to generate.
    expect(availRoutingNginxConfig(['rewrites' => [], 'cleanUrls' => null, 'trailingSlash' => null], true))->toBeNull();
});

it('refuses rewrites that could change the Nginx configuration or leave the site', function (array $rule) {
    $result = availRoutingParse(json_encode(['rewrites' => [$rule]]), 'availcoolify.json');

    expect($result['rules'])->toBeNull()->and($result['notes'])->not->toBeEmpty();
})->with([
    'another site' => [['source' => '/a', 'destination' => 'https://example.org/b']],
    'semicolon' => [['source' => '/a', 'destination' => '/b;add_header x y']],
    'nginx variable' => [['source' => '/a', 'destination' => '/b$uri']],
    'quote' => [['source' => '/a', 'destination' => '/b"c']],
    'space' => [['source' => '/a', 'destination' => '/b c']],
    'condition' => [['source' => '/a', 'destination' => '/b', 'has' => [['type' => 'header', 'key' => 'x']]]],
    'quote in the source' => [['source' => '/a"b', 'destination' => '/b']],
    'certificate path' => [['source' => '/.well-known/acme-challenge/(.*)', 'destination' => '/b']],
    'too many captures' => [['source' => '/(a)/(b)/(c)/(d)/(e)/(f)/(g)/(h)/(i)/(j)', 'destination' => '/b']],
]);

it('turns the Next.js exclusion form of a rewrite into a lookahead for Nginx', function () {
    $rules = availRoutingParse(json_encode(['rewrites' => [['source' => '/((?!api|_next).*)', 'destination' => '/app/index.html']]]), 'availcoolify.json')['rules'];

    expect($rules['rewrites'][0]['regex'])->toBe('^(?!/api|/_next)/(.*)$');
});

it('reads cleanUrls and trailingSlash and generates the redirects', function () {
    $result = availRoutingParse(json_encode(['cleanUrls' => true, 'trailingSlash' => false]), 'availcoolify.json');
    $conf = availRoutingNginxConfig($result['rules']);

    expect($result['rules']['cleanUrls'])->toBeTrue()
        ->and($result['rules']['trailingSlash'])->toBeFalse()
        ->and($conf)->toContain('location = /index.html')
        ->and($conf)->toContain('return 308 $avail_path$is_args$args;')
        ->and($conf)->toContain('try_files $uri $uri.html $uri/index.html $uri/index.htm $uri/ =404;');

    $slash = availRoutingNginxConfig(availRoutingParse(json_encode(['trailingSlash' => true]), 'availcoolify.json')['rules']);
    expect($slash)->toContain('return 308 $avail_path/$is_args$args;');

    $bad = availRoutingParse(json_encode(['cleanUrls' => 'yes', 'trailingSlash' => 1, 'headers' => [['source' => '/a', 'headers' => ['X-A' => '1']]]]), 'availcoolify.json');
    expect($bad['rules']['cleanUrls'])->toBeNull()->and($bad['rules']['trailingSlash'])->toBeNull()->and(count($bad['notes']))->toBe(2);
});

it('reads has and missing conditions of header and redirect rules', function () {
    $result = availRoutingParse(json_encode([
        'headers' => [[
            'source' => '/(.*)',
            'has' => [['type' => 'header', 'key' => 'x-preview'], ['type' => 'query', 'key' => 'debug', 'value' => '1']],
            'missing' => [['type' => 'cookie', 'key' => 'session']],
            'headers' => [['key' => 'X-Robots-Tag', 'value' => 'noindex']],
        ]],
        'redirects' => [['source' => '/old', 'destination' => '/new', 'has' => [['type' => 'host', 'value' => 'old.example.com']]]],
    ]), 'availcoolify.json');

    expect($result['notes'])->toBe([])
        ->and($result['rules']['headers'][0]['conditions'])->toHaveCount(3)
        ->and(availRoutingConditionPredicates($result['rules']['headers'][0]['conditions']))->toBe([
            'HeaderRegexp(`x-preview`, `.*`)',
            'Query(`debug`, `1`)',
            '!HeaderRegexp(`Cookie`, `(^|;\s*)session=`)',
        ])
        ->and(availRoutingConditionPredicates($result['rules']['redirects'][0]['conditions']))->toBe(['Host(`old.example.com`)']);
});

it('escapes cookie names and values in the condition regex and skips conditions it cannot read', function () {
    $predicates = availRoutingConditionPredicates([['mode' => 'has', 'type' => 'cookie', 'key' => 'a.b', 'value' => 'x+y']]);

    expect($predicates[0])->toBe('HeaderRegexp(`Cookie`, `(^|;\s*)a\.b=x\+y(;|$)`)');

    foreach ([
        ['type' => 'header', 'key' => 'x', 'value' => ['eq' => 'a']],
        ['type' => 'path', 'key' => 'x'],
        ['type' => 'host', 'key' => 'x', 'value' => 'a.com'],
        ['type' => 'header', 'key' => 'bad key'],
        ['type' => 'query', 'key' => 'a', 'value' => 'back`tick'],
    ] as $condition) {
        $result = availRoutingParse(json_encode(['headers' => [['source' => '/a', 'has' => [$condition], 'headers' => [['key' => 'X-A', 'value' => '1']]]]]), 'availcoolify.json');
        expect($result['rules'])->toBeNull();
    }
});

function routingConditionLabels(): Collection
{
    return collect([
        'traefik.http.routers.https-0-u1.rule=Host(`a.test`) && PathPrefix(`/`)',
        'traefik.http.routers.https-0-u1.entryPoints=https',
        'traefik.http.routers.https-0-u1.service=https-0-u1',
        'traefik.http.routers.https-0-u1.middlewares=gzip',
        'traefik.http.routers.https-0-u1.tls=true',
        'traefik.http.routers.https-0-u1.tls.certresolver=letsencrypt',
    ]);
}

it('gives header rules with conditions their own router, and keeps them out of the site-wide middleware', function () {
    $rules = availRoutingParse(json_encode(['headers' => [
        ['source' => '/(.*)', 'headers' => [['key' => 'X-All', 'value' => '1']]],
        ['source' => '/(.*)', 'has' => [['type' => 'header', 'key' => 'x-preview', 'value' => 'yes']], 'headers' => [['key' => 'X-Robots-Tag', 'value' => 'noindex']]],
    ]]), 'availcoolify.json')['rules'];

    $labels = availRoutingRulesLabels(routingConditionLabels(), $rules, 'u1')->values();
    $get = fn (string $prefix) => $labels->first(fn ($label) => str_starts_with($label, $prefix));

    expect($get('traefik.http.middlewares.avail-rh-u1.headers.customresponseheaders.X-All='))->toEndWith('=1')
        ->and($labels->contains(fn ($label) => str_contains($label, 'avail-rh-u1.headers.customresponseheaders.X-Robots-Tag')))->toBeFalse()
        ->and($get('traefik.http.middlewares.avail-rh1-u1.headers.customresponseheaders.X-Robots-Tag='))->toEndWith('=noindex')
        ->and($get('traefik.http.routers.https-0-u1-rh1.rule='))->toBe('traefik.http.routers.https-0-u1-rh1.rule=Host(`a.test`) && PathPrefix(`/`) && Header(`x-preview`, `yes`)')
        ->and($get('traefik.http.routers.https-0-u1-rh1.middlewares='))->toBe('traefik.http.routers.https-0-u1-rh1.middlewares=gzip,avail-rh-u1,avail-rh1-u1');
});

it('runs a redirect with conditions in its own router, ahead of the others', function () {
    $rules = availRoutingParse(json_encode(['redirects' => [
        ['source' => '/old', 'destination' => '/new'],
        ['source' => '/beta', 'destination' => '/beta-app', 'has' => [['type' => 'cookie', 'key' => 'beta', 'value' => '1']]],
    ]]), 'availcoolify.json')['rules'];

    $labels = availRoutingRulesLabels(routingConditionLabels(), $rules, 'u1')->values();
    $get = fn (string $prefix) => $labels->first(fn ($label) => str_starts_with($label, $prefix));

    // The main router only carries the redirect that has no conditions.
    expect($get('traefik.http.routers.https-0-u1.middlewares='))->toBe('traefik.http.routers.https-0-u1.middlewares=gzip,avail-rr0-u1')
        ->and($get('traefik.http.routers.https-0-u1-rrc1.rule='))->toBe('traefik.http.routers.https-0-u1-rrc1.rule=Host(`a.test`) && PathPrefix(`/`) && PathRegexp(`^/beta/?$`) && HeaderRegexp(`Cookie`, `(^|;\s*)beta=1(;|$)`)')
        ->and($get('traefik.http.routers.https-0-u1-rrc1.priority='))->toEndWith('='.(200000 - 1))
        ->and($get('traefik.http.routers.https-0-u1-rrc1.middlewares='))->toBe('traefik.http.routers.https-0-u1-rrc1.middlewares=gzip,avail-rr1-u1')
        ->and($get('traefik.http.routers.https-0-u1-rrc1.tls.certresolver='))->toEndWith('=letsencrypt');
});
