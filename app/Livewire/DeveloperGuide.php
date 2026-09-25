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

        return view('livewire.developer-guide', [
            'content' => Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        ]);
    }
}
