@props(['id', 'type' => 'bar', 'height' => '300px'])
<div style="position:relative; height:{{ $height }};">
    <canvas id="{{ $id }}"></canvas>
</div>
