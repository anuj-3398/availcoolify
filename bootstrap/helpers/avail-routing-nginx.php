<?php

/**
 * Avail: rewrites, cleanUrls and trailingSlash of availcoolify.json for static sites.
 *
 * Static apps run Nginx in their image, and Nginx can check whether a file exists before it
 * rewrites, as Vercel does. This builds the Nginx server block that replaces the default one;
 * the deployment checks it with `nginx -t` before using it. All values come from
 * availRoutingParse(), which only lets plain paths and capture references through.
 */

/**
 * @param  array<string, mixed>  $rules  the checked rules (see availRoutingParse())
 * @param  bool  $spa  the app's "Single Page Application" setting: keep an index.html fallback
 */
function availRoutingNginxConfig(array $rules, bool $spa = false): ?string
{
    $rewrites = $rules['rewrites'] ?? [];
    $cleanUrls = ($rules['cleanUrls'] ?? null) === true;
    $trailingSlash = $rules['trailingSlash'] ?? null;
    if ($rewrites === [] && ! $cleanUrls && ! is_bool($trailingSlash)) {
        return null;
    }

    $lines = [];
    $lines[] = '# Generated from availcoolify.json by AvailCoolify. Set a custom Nginx configuration on the app to replace it.';
    $lines[] = 'server {';
    $lines[] = '    root /usr/share/nginx/html;';
    $lines[] = '    index index.html index.htm;';
    // Behind the proxy Nginx only sees plain http, so redirects must not carry a scheme and host.
    $lines[] = '    absolute_redirect off;';
    $lines[] = '';

    if ($cleanUrls) {
        $lines[] = '    # cleanUrls: /about.html and /about/index.html are served at /about';
        $lines[] = '    location = /index.html {';
        $lines[] = '        return 308 /$is_args$args;';
        $lines[] = '    }';
        $lines[] = '    location ~ "^(?<avail_path>/.+?)(?:/index)?\.html$" {';
        $lines[] = '        return 308 $avail_path$is_args$args;';
        $lines[] = '    }';
        $lines[] = '';
    }

    if ($trailingSlash === true) {
        $lines[] = '    # trailingSlash: true. Paths without a file extension get a trailing slash.';
        $lines[] = '    location ~ "^(?<avail_path>/[^.]*[^/.])$" {';
        $lines[] = '        return 308 $avail_path/$is_args$args;';
        $lines[] = '    }';
        $lines[] = '';
    } elseif ($trailingSlash === false) {
        $lines[] = '    # trailingSlash: false. The trailing slash is removed.';
        $lines[] = '    location ~ "^(?<avail_path>/.+)/$" {';
        $lines[] = '        return 308 $avail_path$is_args$args;';
        $lines[] = '    }';
        $lines[] = '';
    }

    $fallback = $rewrites !== [] || $spa ? '@avail_rewrites' : '=404';
    $lines[] = '    # Files first, as on Vercel; rewrites only apply when no file matches.';
    $lines[] = '    location / {';
    $lines[] = '        try_files $uri $uri.html $uri/index.html $uri/index.htm $uri/ '.$fallback.';';
    $lines[] = '    }';
    $lines[] = '';

    if ($rewrites !== [] || $spa) {
        $lines[] = '    location @avail_rewrites {';
        foreach ($rewrites as $rewrite) {
            $lines[] = '        # '.preg_replace('/[^\x20-\x7E]/', '?', $rewrite['source']);
            $lines[] = '        rewrite "'.$rewrite['regex'].'" "'.$rewrite['replacement'].'" break;';
        }
        if ($spa) {
            $lines[] = '        # Single Page Application setting';
            $lines[] = '        rewrite ^ /index.html break;';
        }
        $lines[] = '        return 404;';
        $lines[] = '    }';
        $lines[] = '';
    }

    $lines[] = '    # Handle 404 errors';
    $lines[] = '    error_page 404 /404.html;';
    $lines[] = '    location = /404.html {';
    $lines[] = '        root /usr/share/nginx/html;';
    $lines[] = '        internal;';
    $lines[] = '    }';
    $lines[] = '';
    $lines[] = '    # Handle server errors (50x)';
    $lines[] = '    error_page 500 502 503 504 /50x.html;';
    $lines[] = '    location = /50x.html {';
    $lines[] = '        root /usr/share/nginx/html;';
    $lines[] = '        internal;';
    $lines[] = '    }';
    $lines[] = '}';

    return implode("\n", $lines)."\n";
}

/**
 * Whether the app serves its files from Nginx in its own image (static build pack, or a static
 * site built with Nixpacks or Railpack). Rewrites, cleanUrls and trailingSlash only apply there.
 */
function availRoutingAppIsStatic(\App\Models\Application $application): bool
{
    return $application->build_pack === 'static' || (bool) $application->settings?->is_static;
}
