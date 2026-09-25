<div>
    <x-slot:title>Developer Guide</x-slot>
    <div class="mt-8 flex w-full max-w-3xl flex-col gap-2 lg:mt-3">
        <h1>Developer Guide</h1>
        <article
            class="text-sm leading-6 text-neutral-700 dark:text-fg-dim
                [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-black dark:[&_h2]:text-white
                [&_p]:my-3
                [&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:my-1
                [&_strong]:font-semibold [&_strong]:text-black dark:[&_strong]:text-white
                [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:text-black dark:hover:[&_a]:text-white
                [&_code]:rounded [&_code]:bg-neutral-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[12px] dark:[&_code]:bg-white/[0.06]
                [&_table]:my-4 [&_table]:w-full [&_table]:border-collapse
                [&_th]:border-b [&_th]:border-neutral-200 [&_th]:px-3 [&_th]:py-2 [&_th]:text-left [&_th]:font-semibold [&_th]:text-black dark:[&_th]:border-white/[0.07] dark:[&_th]:text-white
                [&_td]:border-b [&_td]:border-neutral-200 [&_td]:px-3 [&_td]:py-2 [&_td]:align-top dark:[&_td]:border-white/[0.07]">
            {!! $content !!}
        </article>
    </div>
</div>
