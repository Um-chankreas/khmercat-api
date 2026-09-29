<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $restaurant->name }} · Menu</title>
    <meta name="description" content="Menu of {{ $restaurant->name }} on {{ config('app.name') }}">
    <style>
        :root {
            --pink: #FF54AB; --purple: #9B6BFF; --blue: #74BFFF;
            --ink: #1F1B3A; --muted: #7A7896; --bg: #F7F6FC; --card: #FFFFFF;
            --line: rgba(155, 107, 255, .14);
        }
        @media (prefers-color-scheme: dark) {
            :root { --ink: #F2F1F7; --muted: #A9A6C4; --bg: #121018; --card: #1C1926; --line: rgba(155, 107, 255, .25); }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans Khmer", sans-serif;
        }
        .hero {
            background: linear-gradient(135deg, var(--pink), var(--purple), var(--blue));
            padding: 28px 16px 56px; text-align: center; color: #fff;
        }
        .logo {
            width: 84px; height: 84px; border-radius: 50%; object-fit: cover;
            border: 3px solid #fff; background: #fff; display: block; margin: 0 auto 10px;
        }
        h1 { margin: 0; font-size: 22px; font-weight: 800; }
        .category { margin-top: 4px; opacity: .9; font-size: 14px; }
        main { max-width: 720px; margin: -36px auto 0; padding: 0 16px 24px; }
        .page {
            background: var(--card); border: 1px solid var(--line); border-radius: 16px;
            overflow: hidden; margin-bottom: 14px;
        }
        .page img { display: block; width: 100%; height: auto; }
        .empty {
            background: var(--card); border: 1px solid var(--line); border-radius: 16px;
            padding: 40px 16px; text-align: center; color: var(--muted);
        }
        .meta { color: var(--muted); font-size: 13px; text-align: center; margin: 8px 0 18px; }
        footer { text-align: center; color: var(--muted); font-size: 12px; padding: 8px 0 28px; }
    </style>
</head>
<body>
    <header class="hero">
        @if ($restaurant->profile_picture)
            <img class="logo" src="{{ $restaurant->profile_picture }}" alt="{{ $restaurant->name }} logo">
        @endif
        <h1>{{ $restaurant->name }}</h1>
        @if ($restaurant->category)
            <div class="category">{{ $restaurant->category->name }}</div>
        @endif
    </header>

    <main>
        @if ($restaurant->address)
            <p class="meta">{{ $restaurant->address }}</p>
        @endif

        @forelse ($images as $image)
            <div class="page">
                <img src="{{ $image->url }}" alt="Menu page {{ $loop->iteration }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}">
            </div>
        @empty
            <div class="empty">This restaurant hasn't added its menu yet.</div>
        @endforelse
    </main>

    <footer>Menu by {{ config('app.name') }}</footer>
</body>
</html>
