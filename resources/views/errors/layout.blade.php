{{-- Páginas de error en español con la guía visual (docs/GUIA-VISUAL.md); sólo respuestas HTML. Rúbrica 3.6/6.5. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · @yield('title') · Campus Digital</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 16px; box-sizing: border-box;
            font: 14px/1.5 "Segoe UI", system-ui, -apple-system, sans-serif; color: #263348; background: #f4f7fc; }
        main { width: 100%; max-width: 520px; background: #fff; border: 1px solid #e1e7f0; border-radius: 12px; overflow: hidden; }
        header { background: #12285a; color: #fff; padding: 20px 24px; }
        header p { margin: 0; font-size: 12px; letter-spacing: .12em; text-transform: uppercase; color: #94c6f3; }
        h1 { margin: 4px 0 0; font-size: 22px; }
        section { padding: 24px; }
        section p { margin: 0 0 20px; }
        nav { display: flex; flex-wrap: wrap; gap: 8px; }
        a { display: inline-block; padding: 10px 16px; border-radius: 8px; font-weight: 600; text-decoration: none; }
        a.primary { background: #00338d; color: #fff; }
        a.secondary { border: 1px solid #e1e7f0; color: #12285a; }
        a:focus-visible { outline: 3px solid #6c9cf4; outline-offset: 3px; }
    </style>
</head>
<body>
<main role="main" aria-labelledby="error-title">
    <header><p>Campus Digital · Error @yield('code')</p><h1 id="error-title">@yield('title')</h1></header>
    <section>
        <p role="alert">@yield('message')</p>
        <nav aria-label="Opciones">
            <a class="primary" href="{{ url('/dashboard') }}">Ir al inicio</a>
            <a class="secondary" href="javascript:history.back()">Regresar</a>
        </nav>
    </section>
</main>
</body>
</html>
