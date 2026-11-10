<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Tubes MSBD')</title>
    
    <!-- Bootstrap CSS Sementara -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Tempat masuknya @push('css') dari file CEO kamu -->
    @stack('css')
    
    <style>
        body { background-color: #f4f6f9; padding: 20px; }
        .ceo-wrap { max-width: 1200px; margin: 0 auto; }
    </style>
</head>
<body>

    <!-- Tempat masuknya @section('content') dari file CEO kamu -->
    <div class="container-fluid">
        @yield('content')
    </div>

    <!-- Tempat masuknya @push('js') dari file CEO kamu -->
    @stack('js')

</body>
</html>