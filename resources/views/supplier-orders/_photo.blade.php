{{-- A product's photo as an .app-thumb, hidden if it cannot be loaded. Requires $product (nullable). --}}
@if($product && $product->photo && Route::has('products.photo'))
    <img src="{{ route('products.photo', $product) }}" alt="" class="app-thumb" loading="lazy" onerror="this.style.display='none'">
@endif
