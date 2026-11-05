<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.head')
</head>
<body class="min-h-screen">
    {{ $slot }}
    @livewireScripts
</body>
</html>
