<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ config('app.name') }} · News from the Association of Protecting Exotic Species CIC
            40 Morris Street, St Helens, Merseyside, WA9 3EN · 01744 374 015 · info@apes.org.uk
            © {{ date('Y') }} Association of Protecting Exotic Species CIC. CIC No: 16253848.
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
