<?php

namespace App\Livewire;

use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Avail: in-app developer guide, rendered from resources/views/docs/developer-guide.md.
 */
class DeveloperGuide extends Component
{
    public function render()
    {
        $markdown = file_get_contents(resource_path('views/docs/developer-guide.md'));
        // Version image URLs by content so updated screenshots aren't served stale from the CDN cache.
        $markdown = preg_replace_callback('#\]\((/images/developer-guide/[^)?\s]+)\)#', function (array $match): string {
            $file = public_path(ltrim($match[1], '/'));

            return is_file($file) ? ']('.$match[1].'?v='.substr(md5_file($file), 0, 8).')' : $match[0];
        }, $markdown);

        return view('livewire.developer-guide', [
            'content' => Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        ]);
    }
}
