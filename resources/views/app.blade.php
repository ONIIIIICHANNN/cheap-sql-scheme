<!doctype html>
<html lang="{{ $locale }}" data-theme="dark">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="color-scheme" content="dark light" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $locale === 'ru' ? 'SQL-схемы' : 'SQL schemas' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Onest:wght@400;500;600;700&family=Unbounded:wght@600;700&display=swap"
        rel="stylesheet"
    />
    <script>
        // Тема до первой отрисовки, иначе тёмный интерфейс моргает светлым.
        try {
            var t = localStorage.getItem('cheap-sql-scheme-theme')
            document.documentElement.dataset.theme = t === 'light' ? 'light' : 'dark'
        } catch (e) {}
        window.CheapSqlScheme = @json($config);
    </script>
    <link rel="stylesheet" href="{{ $assetUrl('app.css') }}" />
</head>
<body>
    <div id="cheap-sql-scheme"></div>
    <script type="module" src="{{ $assetUrl('app.js') }}"></script>
</body>
</html>
