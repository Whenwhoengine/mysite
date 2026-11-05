@if (session('status'))
    <div class="notice notice--success" role="status"><p>{{ session('status') }}</p></div>
@endif
@if ($errors->any())
    <div class="notice notice--danger" role="alert">
        <p class="notice__title">無法完成操作</p>
        <ul class="flash-list">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
