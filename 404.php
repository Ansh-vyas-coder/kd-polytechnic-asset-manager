<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-800">

    <!-- Main container -->
    <div class="relative min-h-screen flex items-center justify-center p-5">

        <!-- Hamburger Menu Icon -->
        <div class="absolute top-5 left-5">
            <a href="dashboard.php" class="p-2 rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition" aria-label="Go to dashboard">
                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </a>
        </div>

        <!-- Content -->
        <div class="flex flex-col lg:flex-row items-center gap-12 lg:gap-24">

            <!-- Illustration -->
            <div class="w-full max-w-xs lg:max-w-sm">
                <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" class="w-64 h-64 md:w-80 md:h-80 mx-auto">
    <!-- Light gray circular background -->
    <circle cx="100" cy="100" r="80" fill="#f3f4f6" />
    
    <!-- Left Wire -->
    <path d="M -20,160 Q 30,190 50,130 T 70,100" fill="none" stroke="#1e3a8a" stroke-width="6" stroke-linecap="round" />
    
    <!-- Left Plug Body -->
    <rect x="60" y="75" width="45" height="50" rx="8" fill="#1e3a8a" />
    <rect x="50" y="85" width="10" height="30" rx="3" fill="#1e3a8a" />
    
    <!-- Prongs -->
    <rect x="105" y="85" width="15" height="6" rx="2" fill="#1e3a8a" />
    <rect x="105" y="109" width="15" height="6" rx="2" fill="#1e3a8a" />
    
    <!-- Right Plug Socket -->
    <rect x="140" y="70" width="25" height="60" rx="5" fill="#1e3a8a" />
    <!-- Socket holes -->
    <line x1="140" y1="88" x2="130" y2="88" stroke="#1e3a8a" stroke-width="6" stroke-linecap="round" />
    <line x1="140" y1="112" x2="130" y2="112" stroke="#1e3a8a" stroke-width="6" stroke-linecap="round" />
    
    <!-- Right Wire -->
    <path d="M 165,100 Q 190,100 220,50" fill="none" stroke="#1e3a8a" stroke-width="6" stroke-linecap="round" />
    
    <!-- Disconnect Sparks/Lines -->
    <line x1="120" y1="60" x2="130" y2="50" stroke="#1e3a8a" stroke-width="4" stroke-linecap="round"/>
    <line x1="120" y1="140" x2="130" y2="150" stroke="#1e3a8a" stroke-width="4" stroke-linecap="round"/>
    <line x1="125" y1="100" x2="125" y2="100" stroke="#1e3a8a" stroke-width="4" stroke-linecap="round"/>
</svg>
            </div>

            <!-- Text Content -->
            <div class="text-center lg:text-left">
                <h1 class="text-8xl md:text-9xl font-black text-blue-900 tracking-tighter">404</h1>
                <h2 class="text-2xl md:text-3xl font-bold text-gray-800 mt-2">Page Not Found</h2>
                <p class="text-gray-500 text-sm max-w-sm mt-4 mx-auto lg:mx-0 text-center">
                    We're sorry, the page you requested could not be found. Please go back to the home page.
                </p>
                <a href="dashboard.php" class="inline-block mt-8 px-8 py-3 bg-blue-900 text-white font-semibold rounded-full hover:bg-blue-800 transition-colors duration-300">
                    GO HOME
                </a>
            </div>

        </div>
    </div>

</body>

</html>