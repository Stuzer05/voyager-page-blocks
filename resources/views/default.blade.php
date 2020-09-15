@foreach($blocks as $block)
    @if (!empty($block->html))
        @php echo (string)$block->html @endphp
    @endif
@endforeach
