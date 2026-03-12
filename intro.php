<?php
/**
 * Interactive Introduction & Onboarding Page
 * An interactive journey explaining how Cardify works
 */

require_once __DIR__ . '/config.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = 'How It Works - ' . $brandName;
$basePath = getBasePath();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="Learn how <?php echo $brandName; ?> helps Omani SME companies create and manage professional digital business cards - Free to use!">
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?php echo $basePath; ?>favicon.svg">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {"50":"#eff6ff","100":"#dbeafe","200":"#bfdbfe","300":"#93c5fd","400":"#60a5fa","500":"#3b82f6","600":"#2563eb","700":"#1d4ed8","800":"#1e40af","900":"#1e3a8a","950":"#172554"}
                    }
                }
            }
        }
    </script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- GSAP for animations -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
    
    <!-- Lottie for animations -->
    <script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        /* Hide scrollbar but keep functionality */
        html {
            scrollbar-width: thin;
            scrollbar-color: #3b82f6 #f1f5f9;
        }
        
        /* Gradient text */
        .gradient-text {
            background: linear-gradient(135deg, #2563eb 0%, #7c3aed 50%, #db2777 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Animated gradient background */
        .animated-bg {
            background: linear-gradient(-45deg, #667eea, #764ba2, #6B8DD6, #8E37D7);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
        }
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        /* Floating animation */
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(2deg); }
        }
        .float { animation: float 6s ease-in-out infinite; }
        .float-delayed { animation: float 6s ease-in-out infinite; animation-delay: -3s; }
        
        /* Pulse ring animation */
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(2); opacity: 0; }
        }
        .pulse-ring::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            border: 2px solid currentColor;
            animation: pulse-ring 2s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }
        
        /* Interactive step styling */
        .step-card {
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .step-card:hover {
            transform: translateY(-8px) scale(1.02);
        }
        .step-card.active {
            border-color: #2563eb;
            box-shadow: 0 25px 50px -12px rgba(37, 99, 235, 0.25);
        }
        
        /* Progress bar animation */
        .progress-fill {
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Card flip effect */
        .card-3d {
            perspective: 1000px;
        }
        .card-3d-inner {
            transition: transform 0.8s;
            transform-style: preserve-3d;
        }
        .card-3d:hover .card-3d-inner {
            transform: rotateY(10deg) rotateX(5deg);
        }
        
        /* Reveal animations */
        .reveal-up {
            opacity: 0;
            transform: translateY(60px);
        }
        .reveal-left {
            opacity: 0;
            transform: translateX(-60px);
        }
        .reveal-right {
            opacity: 0;
            transform: translateX(60px);
        }
        .reveal-scale {
            opacity: 0;
            transform: scale(0.8);
        }
        
        /* Interactive cursor */
        .interactive-cursor {
            cursor: pointer;
        }
        .interactive-cursor:hover {
            transform: scale(1.05);
        }
        
        /* Shine effect */
        .shine {
            position: relative;
            overflow: hidden;
        }
        .shine::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                to right,
                rgba(255,255,255,0) 0%,
                rgba(255,255,255,0.3) 50%,
                rgba(255,255,255,0) 100%
            );
            transform: rotate(30deg);
            animation: shine 3s infinite;
        }
        @keyframes shine {
            0% { transform: translateX(-100%) rotate(30deg); }
            100% { transform: translateX(100%) rotate(30deg); }
        }
        
        /* Typing animation */
        .typing-cursor::after {
            content: '|';
            animation: blink 1s infinite;
        }
        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0; }
        }
        
        /* Interactive demo card */
        .demo-card {
            transition: all 0.3s ease;
        }
        .demo-card:hover {
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.25);
        }
        
        /* Step connector */
        .step-connector {
            position: absolute;
            top: 50%;
            right: -2rem;
            width: 4rem;
            height: 2px;
            background: linear-gradient(to right, #2563eb, transparent);
        }
        
        /* Omani flag colors accent */
        .oman-accent {
            background: linear-gradient(135deg, #c8102e 0%, #008000 50%, #ffffff 100%);
        }
        
        /* Scroll progress indicator */
        #scrollProgress {
            transform-origin: left;
            transform: scaleX(0);
        }
    </style>
</head>
<body class="bg-white antialiased overflow-x-hidden">
    <!-- Scroll Progress Bar -->
    <div id="scrollProgress" class="fixed top-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-500 via-purple-500 to-pink-500 z-[100]"></div>

    <!-- Navigation -->
    <nav class="fixed top-0 left-0 right-0 z-50 bg-white/90 backdrop-blur-xl border-b border-gray-100 transition-all duration-300" id="navbar">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="<?php echo $basePath; ?>" class="flex items-center gap-2">
                    <img src="<?php echo $basePath; ?>assets/images/logo.svg" alt="<?php echo $brandName; ?>" class="h-9 w-auto">
                </a>
                <div class="hidden md:flex items-center gap-8">
                    <a href="#journey" class="text-gray-600 hover:text-blue-600 font-medium transition-colors">The Journey</a>
                    <a href="#features" class="text-gray-600 hover:text-blue-600 font-medium transition-colors">Features</a>
                    <a href="#pricing" class="text-gray-600 hover:text-blue-600 font-medium transition-colors">Pricing</a>
                </div>
                <div class="flex items-center gap-3">
                    <a href="<?php echo $basePath; ?>login.php" class="text-gray-600 hover:text-gray-900 font-medium transition-colors hidden sm:block">Sign In</a>
                    <a href="<?php echo $basePath; ?>company/register.php" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition-all shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 hover:-translate-y-0.5">
                        Get Started Free
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section - Full Screen Interactive -->
    <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-16">
        <!-- Animated Background -->
        <div class="absolute inset-0 overflow-hidden">
            <div class="absolute -top-40 -right-40 w-96 h-96 bg-blue-100 rounded-full mix-blend-multiply filter blur-3xl opacity-70 float"></div>
            <div class="absolute top-40 -left-40 w-96 h-96 bg-purple-100 rounded-full mix-blend-multiply filter blur-3xl opacity-70 float-delayed"></div>
            <div class="absolute -bottom-40 left-1/3 w-96 h-96 bg-pink-100 rounded-full mix-blend-multiply filter blur-3xl opacity-70 float"></div>
            
            <!-- Grid pattern -->
            <div class="absolute inset-0 opacity-[0.03]" style="background-image: radial-gradient(#000 1px, transparent 1px); background-size: 40px 40px;"></div>
        </div>
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 relative z-10">
            <div class="text-center max-w-4xl mx-auto">
                <!-- Badge -->
                <div class="reveal-up inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-blue-50 to-purple-50 rounded-full border border-blue-100 mb-8">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                    </span>
                    <span class="text-blue-700 font-medium text-sm">Supporting Omani SME Companies</span>
                    <span class="text-xs px-2 py-0.5 bg-red-500 text-white rounded-full font-bold">FREE</span>
                </div>
                
                <!-- Main Headline -->
                <h1 class="reveal-up text-5xl sm:text-6xl lg:text-7xl font-black text-gray-900 leading-tight mb-6">
                    Design Once,<br>
                    <span class="gradient-text">Cards for Everyone</span>
                </h1>
                
                <!-- Subheadline -->
                <p class="reveal-up text-xl sm:text-2xl text-gray-600 mb-10 max-w-2xl mx-auto leading-relaxed">
                    Create a single template and generate professional business cards for your entire team. 
                    <span class="font-semibold text-gray-900">Free to use</span> — only pay when you print.
                </p>
                
                <!-- CTA Buttons -->
                <div class="reveal-up flex flex-col sm:flex-row gap-4 justify-center mb-12">
                    <a href="<?php echo $basePath; ?>company/register.php" class="group inline-flex items-center justify-center gap-3 px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white text-lg font-semibold rounded-2xl shadow-xl shadow-blue-500/30 transition-all hover:-translate-y-1 hover:shadow-2xl hover:shadow-blue-500/40">
                        <span>Start Creating — It's Free</span>
                        <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                    </a>
                    <a href="#journey" class="inline-flex items-center justify-center gap-3 px-8 py-4 bg-white hover:bg-gray-50 text-gray-900 text-lg font-semibold rounded-2xl border-2 border-gray-200 hover:border-blue-300 transition-all">
                        <i class="fa-solid fa-play-circle text-blue-600"></i>
                        <span>See How It Works</span>
                    </a>
                </div>
                
                <!-- Key Benefits Pills -->
                <div class="reveal-up flex flex-wrap justify-center gap-3">
                    <div class="flex items-center gap-2 px-4 py-2 bg-green-50 text-green-700 rounded-full text-sm font-medium">
                        <i class="fa-solid fa-check-circle"></i>
                        <span>100% Free Platform</span>
                    </div>
                    <div class="flex items-center gap-2 px-4 py-2 bg-blue-50 text-blue-700 rounded-full text-sm font-medium">
                        <i class="fa-solid fa-print"></i>
                        <span>Order Prints Instantly</span>
                    </div>
                    <div class="flex items-center gap-2 px-4 py-2 bg-purple-50 text-purple-700 rounded-full text-sm font-medium">
                        <i class="fa-solid fa-users"></i>
                        <span>Unlimited Employees</span>
                    </div>
                </div>
            </div>
            
            <!-- Interactive Card Preview -->
            <div class="reveal-scale mt-16 relative max-w-4xl mx-auto">
                <div class="relative bg-gradient-to-br from-gray-50 to-white rounded-3xl p-8 shadow-2xl border border-gray-100">
                    <!-- Before/After Labels -->
                    <div class="absolute -top-4 left-8 px-4 py-1 bg-gray-900 text-white text-sm font-bold rounded-full">
                        One Template
                    </div>
                    <div class="absolute -top-4 right-8 px-4 py-1 bg-blue-600 text-white text-sm font-bold rounded-full">
                        Unlimited Cards
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-8 items-center">
                        <!-- Single Template -->
                        <div class="relative">
                            <div class="card-3d">
                                <div class="card-3d-inner demo-card bg-gradient-to-br from-slate-800 via-slate-700 to-slate-900 rounded-2xl p-6 shadow-2xl" style="aspect-ratio: 3.5/2;">
                                    <div class="h-full flex flex-col justify-between relative overflow-hidden">
                                        <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -translate-y-1/2 translate-x-1/2"></div>
                                        <div>
                                            <div class="text-white/50 text-xs font-medium tracking-wider uppercase">Your Company</div>
                                        </div>
                                        <div>
                                            <div class="text-white/40 text-lg mb-1">[Employee Name]</div>
                                            <div class="text-white/30 text-sm">[Job Title]</div>
                                        </div>
                                        <div class="flex gap-4 text-white/30 text-xs">
                                            <span>[Email]</span>
                                            <span>[Phone]</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center mt-4 text-gray-500 text-sm">
                                <i class="fa-solid fa-palette mr-1"></i> Design your template once
                            </div>
                        </div>
                        
                        <!-- Arrow -->
                        <div class="hidden md:flex absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-10">
                            <div class="w-16 h-16 bg-blue-600 rounded-full flex items-center justify-center shadow-lg shadow-blue-500/30 animate-pulse">
                                <i class="fa-solid fa-arrow-right text-white text-xl"></i>
                            </div>
                        </div>
                        
                        <!-- Multiple Generated Cards -->
                        <div class="relative">
                            <div class="space-y-3">
                                <!-- Card 1 -->
                                <div class="demo-card bg-gradient-to-br from-slate-800 via-slate-700 to-slate-900 rounded-xl p-4 shadow-xl transform hover:scale-105 transition-transform" style="aspect-ratio: 3.5/2;">
                                    <div class="h-full flex flex-col justify-between">
                                        <div class="text-white/50 text-[10px] tracking-wider uppercase">Your Company</div>
                                        <div>
                                            <div class="text-white text-sm font-bold">Ahmed Al-Balushi</div>
                                            <div class="text-white/60 text-[10px]">Sales Manager</div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Card 2 -->
                                <div class="demo-card bg-gradient-to-br from-slate-800 via-slate-700 to-slate-900 rounded-xl p-4 shadow-xl transform translate-x-4 hover:scale-105 transition-transform" style="aspect-ratio: 3.5/2;">
                                    <div class="h-full flex flex-col justify-between">
                                        <div class="text-white/50 text-[10px] tracking-wider uppercase">Your Company</div>
                                        <div>
                                            <div class="text-white text-sm font-bold">Fatima Al-Rashdi</div>
                                            <div class="text-white/60 text-[10px]">Marketing Director</div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Card 3 -->
                                <div class="demo-card bg-gradient-to-br from-slate-800 via-slate-700 to-slate-900 rounded-xl p-4 shadow-xl transform translate-x-8 hover:scale-105 transition-transform" style="aspect-ratio: 3.5/2;">
                                    <div class="h-full flex flex-col justify-between">
                                        <div class="text-white/50 text-[10px] tracking-wider uppercase">Your Company</div>
                                        <div>
                                            <div class="text-white text-sm font-bold">Khalid Al-Habsi</div>
                                            <div class="text-white/60 text-[10px]">Software Engineer</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center mt-4 text-gray-500 text-sm">
                                <i class="fa-solid fa-users mr-1"></i> Generate for all employees
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Scroll Indicator -->
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2 text-gray-400">
            <span class="text-sm font-medium">Scroll to explore</span>
            <div class="w-6 h-10 border-2 border-gray-300 rounded-full p-1">
                <div class="w-1.5 h-1.5 bg-gray-400 rounded-full mx-auto animate-bounce"></div>
            </div>
        </div>
    </section>

    <!-- Interactive Journey Section -->
    <section id="journey" class="py-32 bg-gradient-to-b from-gray-50 to-white relative overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="text-center mb-20 reveal-up">
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-blue-50 rounded-full text-blue-600 font-medium text-sm mb-6">
                    <i class="fa-solid fa-route"></i>
                    <span>Your Journey</span>
                </div>
                <h2 class="text-4xl sm:text-5xl font-black text-gray-900 mb-6">
                    From Setup to Print<br>
                    <span class="gradient-text">In 4 Simple Steps</span>
                </h2>
                <p class="text-xl text-gray-600 max-w-2xl mx-auto">
                    See exactly how easy it is to get professional business cards for your entire team
                </p>
            </div>
            
            <!-- Interactive Steps -->
            <div class="relative">
                <!-- Progress Line -->
                <div class="hidden lg:block absolute top-1/2 left-0 right-0 h-1 bg-gray-200 -translate-y-1/2 rounded-full overflow-hidden">
                    <div id="journeyProgress" class="progress-fill h-full bg-gradient-to-r from-blue-500 via-purple-500 to-pink-500" style="width: 0%"></div>
                </div>
                
                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8 relative z-10">
                    <!-- Step 1 -->
                    <div class="step-card reveal-up bg-white rounded-3xl p-8 border-2 border-gray-100 hover:border-blue-500 cursor-pointer transition-all group" data-step="1">
                        <div class="relative mb-6">
                            <div class="w-20 h-20 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center text-white text-3xl font-black shadow-lg shadow-blue-500/30 group-hover:scale-110 transition-transform">
                                1
                            </div>
                            <div class="absolute -top-2 -right-2 w-8 h-8 bg-green-500 rounded-full flex items-center justify-center text-white opacity-0 group-hover:opacity-100 transition-opacity">
                                <i class="fa-solid fa-check text-sm"></i>
                            </div>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-3">Register Free</h3>
                        <p class="text-gray-600 mb-4">
                            Sign up with your company details in under 2 minutes. No credit card required.
                        </p>
                        <div class="flex items-center gap-2 text-blue-600 font-semibold">
                            <i class="fa-solid fa-clock"></i>
                            <span>2 min setup</span>
                        </div>
                        
                        <!-- Animated illustration -->
                        <div class="mt-6 p-4 bg-gray-50 rounded-xl">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fa-solid fa-building text-blue-600"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="h-2 bg-gray-200 rounded w-3/4 mb-1"></div>
                                    <div class="h-2 bg-gray-200 rounded w-1/2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 2 -->
                    <div class="step-card reveal-up bg-white rounded-3xl p-8 border-2 border-gray-100 hover:border-purple-500 cursor-pointer transition-all group" data-step="2" style="transition-delay: 0.1s;">
                        <div class="relative mb-6">
                            <div class="w-20 h-20 bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl flex items-center justify-center text-white text-3xl font-black shadow-lg shadow-purple-500/30 group-hover:scale-110 transition-transform">
                                2
                            </div>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-3">Design Template</h3>
                        <p class="text-gray-600 mb-4">
                            Create your card design with our visual editor. Drag, drop, and customize.
                        </p>
                        <div class="flex items-center gap-2 text-purple-600 font-semibold">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            <span>Visual editor</span>
                        </div>
                        
                        <!-- Animated illustration -->
                        <div class="mt-6 p-4 bg-gray-50 rounded-xl">
                            <div class="grid grid-cols-3 gap-2">
                                <div class="h-8 bg-purple-200 rounded"></div>
                                <div class="h-8 bg-purple-300 rounded"></div>
                                <div class="h-8 bg-purple-400 rounded"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3 -->
                    <div class="step-card reveal-up bg-white rounded-3xl p-8 border-2 border-gray-100 hover:border-pink-500 cursor-pointer transition-all group" data-step="3" style="transition-delay: 0.2s;">
                        <div class="relative mb-6">
                            <div class="w-20 h-20 bg-gradient-to-br from-pink-500 to-pink-600 rounded-2xl flex items-center justify-center text-white text-3xl font-black shadow-lg shadow-pink-500/30 group-hover:scale-110 transition-transform">
                                3
                            </div>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-3">Add Employees</h3>
                        <p class="text-gray-600 mb-4">
                            Import your team or let them self-register through your company portal.
                        </p>
                        <div class="flex items-center gap-2 text-pink-600 font-semibold">
                            <i class="fa-solid fa-users"></i>
                            <span>Bulk import</span>
                        </div>
                        
                        <!-- Animated illustration -->
                        <div class="mt-6 p-4 bg-gray-50 rounded-xl">
                            <div class="flex -space-x-2">
                                <div class="w-8 h-8 bg-pink-200 rounded-full border-2 border-white"></div>
                                <div class="w-8 h-8 bg-pink-300 rounded-full border-2 border-white"></div>
                                <div class="w-8 h-8 bg-pink-400 rounded-full border-2 border-white"></div>
                                <div class="w-8 h-8 bg-pink-500 rounded-full border-2 border-white flex items-center justify-center text-white text-xs font-bold">+5</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 4 -->
                    <div class="step-card reveal-up bg-white rounded-3xl p-8 border-2 border-gray-100 hover:border-green-500 cursor-pointer transition-all group" data-step="4" style="transition-delay: 0.3s;">
                        <div class="relative mb-6">
                            <div class="w-20 h-20 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center text-white text-3xl font-black shadow-lg shadow-green-500/30 group-hover:scale-110 transition-transform">
                                4
                            </div>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-3">Generate & Print</h3>
                        <p class="text-gray-600 mb-4">
                            Generate cards instantly. Order prints from verified local print shops.
                        </p>
                        <div class="flex items-center gap-2 text-green-600 font-semibold">
                            <i class="fa-solid fa-print"></i>
                            <span>Instant ordering</span>
                        </div>
                        
                        <!-- Animated illustration -->
                        <div class="mt-6 p-4 bg-gray-50 rounded-xl">
                            <div class="flex items-center gap-2">
                                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fa-solid fa-check text-green-600"></i>
                                </div>
                                <div class="flex-1 h-2 bg-green-200 rounded-full overflow-hidden">
                                    <div class="h-full w-full bg-green-500 animate-pulse"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Key Features Section -->
    <section id="features" class="py-32 bg-white relative overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Feature 1: Print Integration -->
            <div class="grid lg:grid-cols-2 gap-16 items-center mb-32">
                <div class="reveal-left">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-green-50 rounded-full text-green-600 font-medium text-sm mb-6">
                        <i class="fa-solid fa-print"></i>
                        <span>Print Integration</span>
                    </div>
                    <h2 class="text-4xl sm:text-5xl font-black text-gray-900 mb-6 leading-tight">
                        Order Prints from<br>
                        <span class="text-green-600">Verified Print Shops</span>
                    </h2>
                    <p class="text-xl text-gray-600 mb-8 leading-relaxed">
                        Skip the hassle of finding printers. Order professional prints directly from our network of verified Omani print shops. 
                        Fast delivery, guaranteed quality.
                    </p>
                    <ul class="space-y-4">
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                                <i class="fa-solid fa-check text-green-600 text-xs"></i>
                            </div>
                            <span class="text-gray-700">One-click ordering from your dashboard</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                                <i class="fa-solid fa-check text-green-600 text-xs"></i>
                            </div>
                            <span class="text-gray-700">Multiple paper types and finishes available</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                                <i class="fa-solid fa-check text-green-600 text-xs"></i>
                            </div>
                            <span class="text-gray-700">Track your order status in real-time</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                                <i class="fa-solid fa-check text-green-600 text-xs"></i>
                            </div>
                            <span class="text-gray-700">Delivery across Oman</span>
                        </li>
                    </ul>
                </div>
                <div class="reveal-right">
                    <div class="relative">
                        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-3xl p-8 shadow-xl">
                            <!-- Print Shop Card -->
                            <div class="bg-white rounded-2xl p-6 shadow-lg mb-4">
                                <div class="flex items-center gap-4 mb-4">
                                    <div class="w-14 h-14 bg-green-100 rounded-xl flex items-center justify-center">
                                        <i class="fa-solid fa-store text-2xl text-green-600"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-gray-900">Al Muscat Printing</h4>
                                        <div class="flex items-center gap-1 text-amber-500">
                                            <i class="fa-solid fa-star text-xs"></i>
                                            <i class="fa-solid fa-star text-xs"></i>
                                            <i class="fa-solid fa-star text-xs"></i>
                                            <i class="fa-solid fa-star text-xs"></i>
                                            <i class="fa-solid fa-star text-xs"></i>
                                            <span class="text-gray-600 text-sm ml-1">Verified</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 text-sm">
                                    <div class="bg-gray-50 rounded-lg p-3">
                                        <div class="text-gray-500">Standard</div>
                                        <div class="font-bold text-gray-900">OMR 5.00</div>
                                        <div class="text-xs text-gray-500">per 100 cards</div>
                                    </div>
                                    <div class="bg-gray-50 rounded-lg p-3">
                                        <div class="text-gray-500">Premium</div>
                                        <div class="font-bold text-gray-900">OMR 8.00</div>
                                        <div class="text-xs text-gray-500">per 100 cards</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Order Button -->
                            <button class="w-full py-4 bg-green-600 hover:bg-green-700 text-white font-bold rounded-xl shadow-lg shadow-green-500/30 transition-all flex items-center justify-center gap-2">
                                <i class="fa-solid fa-cart-shopping"></i>
                                Order Now
                            </button>
                        </div>
                        
                        <!-- Floating badge -->
                        <div class="absolute -top-4 -right-4 bg-white rounded-xl shadow-lg p-3 float">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fa-solid fa-truck-fast text-green-600"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-700">Fast Delivery</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Feature 2: Omani SME Focus -->
            <div class="grid lg:grid-cols-2 gap-16 items-center mb-32">
                <div class="reveal-left order-2 lg:order-1">
                    <div class="relative">
                        <div class="bg-gradient-to-br from-red-50 via-white to-green-50 rounded-3xl p-8 shadow-xl border border-gray-100">
                            <!-- Oman themed content -->
                            <div class="text-center mb-6">
                                <div class="inline-flex items-center gap-3 px-6 py-3 bg-white rounded-2xl shadow-lg">
                                    <span class="text-3xl">🇴🇲</span>
                                    <div class="text-left">
                                        <div class="font-bold text-gray-900">Made for Oman</div>
                                        <div class="text-sm text-gray-500">Supporting local businesses</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- SME Stats -->
                            <div class="grid grid-cols-3 gap-2 sm:gap-4">
                                <div class="bg-white rounded-xl p-3 sm:p-4 text-center shadow-sm">
                                    <div class="text-2xl sm:text-3xl font-black text-blue-600">500+</div>
                                    <div class="text-xs text-gray-500">SME Companies</div>
                                </div>
                                <div class="bg-white rounded-xl p-4 text-center shadow-sm">
                                    <div class="text-3xl font-black text-purple-600">10k+</div>
                                    <div class="text-xs text-gray-500">Cards Created</div>
                                </div>
                                <div class="bg-white rounded-xl p-4 text-center shadow-sm">
                                    <div class="text-3xl font-black text-green-600">100%</div>
                                    <div class="text-xs text-gray-500">Local Support</div>
                                </div>
                            </div>
                            
                            <!-- Testimonial -->
                            <div class="mt-6 p-4 bg-white rounded-xl shadow-sm">
                                <div class="flex items-start gap-3">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                        AA
                                    </div>
                                    <div>
                                        <p class="text-gray-600 text-sm italic">"Finally a solution designed for Omani businesses. Easy to use and great support!"</p>
                                        <p class="text-gray-900 font-semibold text-sm mt-1">Ahmed Al-Kindi</p>
                                        <p class="text-gray-500 text-xs">CEO, Muscat Tech Solutions</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="reveal-right order-1 lg:order-2">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-red-50 rounded-full text-red-600 font-medium text-sm mb-6">
                        <span class="text-lg">🇴🇲</span>
                        <span>Built for Oman</span>
                    </div>
                    <h2 class="text-4xl sm:text-5xl font-black text-gray-900 mb-6 leading-tight">
                        Proudly Supporting<br>
                        <span class="text-red-600">Omani SME Companies</span>
                    </h2>
                    <p class="text-xl text-gray-600 mb-8 leading-relaxed">
                        We understand the unique needs of Omani businesses. That's why we've built a platform that's simple, affordable, and speaks your language — literally.
                    </p>
                    <ul class="space-y-4">
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                                <i class="fa-solid fa-check text-red-600 text-xs"></i>
                            </div>
                            <span class="text-gray-700">Full Arabic and English bilingual support</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                                <i class="fa-solid fa-check text-red-600 text-xs"></i>
                            </div>
                            <span class="text-gray-700">AI-powered Arabic translation</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                                <i class="fa-solid fa-check text-red-600 text-xs"></i>
                            </div>
                            <span class="text-gray-700">Local print shop partnerships</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                                <i class="fa-solid fa-check text-red-600 text-xs"></i>
                            </div>
                            <span class="text-gray-700">Dedicated Omani customer support</span>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Feature 3: Free Platform -->
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div class="reveal-left">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-blue-50 rounded-full text-blue-600 font-medium text-sm mb-6">
                        <i class="fa-solid fa-gift"></i>
                        <span>100% Free</span>
                    </div>
                    <h2 class="text-4xl sm:text-5xl font-black text-gray-900 mb-6 leading-tight">
                        Free to Use,<br>
                        <span class="text-blue-600">Pay Only for Printing</span>
                    </h2>
                    <p class="text-xl text-gray-600 mb-8 leading-relaxed">
                        No subscriptions. No hidden fees. Create unlimited cards, add unlimited employees, design unlimited templates — all for free. 
                        You only pay when you decide to print physical cards.
                    </p>
                    
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-5 border border-blue-100">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fa-solid fa-infinity text-blue-600"></i>
                                </div>
                                <span class="font-bold text-gray-900">Unlimited</span>
                            </div>
                            <p class="text-gray-600 text-sm">Employees, templates, and digital cards</p>
                        </div>
                        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl p-5 border border-green-100">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fa-solid fa-hand-holding-dollar text-green-600"></i>
                                </div>
                                <span class="font-bold text-gray-900">Pay Per Print</span>
                            </div>
                            <p class="text-gray-600 text-sm">Only pay when ordering physical prints</p>
                        </div>
                    </div>
                </div>
                <div class="reveal-right">
                    <div class="relative">
                        <!-- Pricing comparison card -->
                        <div class="bg-white rounded-3xl p-8 shadow-2xl border border-gray-100">
                            <div class="text-center mb-8">
                                <div class="inline-flex items-center gap-2 px-4 py-1 bg-green-100 text-green-700 rounded-full text-sm font-bold mb-4">
                                    <i class="fa-solid fa-tag"></i>
                                    Simple Pricing
                                </div>
                                <h3 class="text-3xl font-black text-gray-900">OMR 0</h3>
                                <p class="text-gray-500">Platform access forever</p>
                            </div>
                            
                            <div class="space-y-4 mb-8">
                                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                                    <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                                        <i class="fa-solid fa-check text-white text-sm"></i>
                                    </div>
                                    <span class="text-gray-700 font-medium">Unlimited employees</span>
                                    <span class="ml-auto text-green-600 font-bold">FREE</span>
                                </div>
                                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                                    <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                                        <i class="fa-solid fa-check text-white text-sm"></i>
                                    </div>
                                    <span class="text-gray-700 font-medium">Unlimited templates</span>
                                    <span class="ml-auto text-green-600 font-bold">FREE</span>
                                </div>
                                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                                    <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                                        <i class="fa-solid fa-check text-white text-sm"></i>
                                    </div>
                                    <span class="text-gray-700 font-medium">Digital cards & QR codes</span>
                                    <span class="ml-auto text-green-600 font-bold">FREE</span>
                                </div>
                                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                                    <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                                        <i class="fa-solid fa-check text-white text-sm"></i>
                                    </div>
                                    <span class="text-gray-700 font-medium">Analytics & tracking</span>
                                    <span class="ml-auto text-green-600 font-bold">FREE</span>
                                </div>
                                <div class="flex items-center gap-3 p-3 bg-blue-50 rounded-xl border-2 border-blue-200">
                                    <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                                        <i class="fa-solid fa-print text-white text-sm"></i>
                                    </div>
                                    <span class="text-gray-700 font-medium">Physical printing</span>
                                    <span class="ml-auto text-blue-600 font-bold">Pay per order</span>
                                </div>
                            </div>
                            
                            <a href="<?php echo $basePath; ?>company/register.php" class="block w-full py-4 bg-blue-600 hover:bg-blue-700 text-white text-center font-bold rounded-xl shadow-lg shadow-blue-500/30 transition-all">
                                Get Started Free
                            </a>
                        </div>
                        
                        <!-- Decorative elements -->
                        <div class="absolute -top-4 -left-4 w-20 h-20 bg-blue-100 rounded-full blur-2xl"></div>
                        <div class="absolute -bottom-4 -right-4 w-32 h-32 bg-green-100 rounded-full blur-2xl"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- More Features Grid -->
    <section class="py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal-up">
                <h2 class="text-4xl font-black text-gray-900 mb-4">Everything You Need</h2>
                <p class="text-xl text-gray-600 max-w-2xl mx-auto">
                    Powerful features to help you manage business cards at scale
                </p>
            </div>
            
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Feature Cards -->
                <div class="reveal-up bg-white rounded-2xl p-6 border border-gray-100 hover:border-blue-200 hover:shadow-xl transition-all group cursor-pointer">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-layer-group text-xl text-blue-600"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Multi-Department</h3>
                    <p class="text-gray-600 text-sm">Different templates for different departments. Sales, Marketing, Engineering — each with unique branding.</p>
                </div>
                
                <div class="reveal-up bg-white rounded-2xl p-6 border border-gray-100 hover:border-purple-200 hover:shadow-xl transition-all group cursor-pointer" style="transition-delay: 0.05s;">
                    <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-qrcode text-xl text-purple-600"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Smart QR Codes</h3>
                    <p class="text-gray-600 text-sm">Every card includes a trackable QR code. Know when and where your cards are scanned.</p>
                </div>
                
                <div class="reveal-up bg-white rounded-2xl p-6 border border-gray-100 hover:border-pink-200 hover:shadow-xl transition-all group cursor-pointer" style="transition-delay: 0.1s;">
                    <div class="w-12 h-12 bg-pink-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-language text-xl text-pink-600"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Bilingual Cards</h3>
                    <p class="text-gray-600 text-sm">Full Arabic and English support with proper RTL formatting. AI translation included.</p>
                </div>
                
                <div class="reveal-up bg-white rounded-2xl p-6 border border-gray-100 hover:border-green-200 hover:shadow-xl transition-all group cursor-pointer" style="transition-delay: 0.15s;">
                    <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-bolt text-xl text-green-600"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Batch Generation</h3>
                    <p class="text-gray-600 text-sm">Generate cards for hundreds of employees at once. Import from Excel or CSV files.</p>
                </div>
                
                <div class="reveal-up bg-white rounded-2xl p-6 border border-gray-100 hover:border-amber-200 hover:shadow-xl transition-all group cursor-pointer" style="transition-delay: 0.2s;">
                    <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-door-open text-xl text-amber-600"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Self-Service Portal</h3>
                    <p class="text-gray-600 text-sm">Employees request their own cards through a branded portal. Admins approve and generate.</p>
                </div>
                
                <div class="reveal-up bg-white rounded-2xl p-6 border border-gray-100 hover:border-red-200 hover:shadow-xl transition-all group cursor-pointer" style="transition-delay: 0.25s;">
                    <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-chart-line text-xl text-red-600"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Analytics Dashboard</h3>
                    <p class="text-gray-600 text-sm">Track card usage, QR scans, and engagement with detailed analytics.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA Section -->
    <section class="py-32 animated-bg relative overflow-hidden">
        <div class="absolute inset-0 bg-black/10"></div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <div class="reveal-up">
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-white/20 backdrop-blur-sm rounded-full text-white font-medium text-sm mb-8">
                    <i class="fa-solid fa-rocket"></i>
                    <span>Ready to get started?</span>
                </div>
                <h2 class="text-4xl sm:text-5xl lg:text-6xl font-black text-white mb-6 leading-tight">
                    Create Your First Card<br>
                    <span class="text-white/80">In Under 5 Minutes</span>
                </h2>
                <p class="text-xl text-white/80 mb-10 max-w-2xl mx-auto">
                    Join hundreds of Omani companies already using Cardify. 
                    It's completely free to start — no credit card required.
                </p>
                
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <a href="<?php echo $basePath; ?>company/register.php" class="group inline-flex items-center justify-center gap-3 px-10 py-5 bg-white hover:bg-gray-100 text-gray-900 text-lg font-bold rounded-2xl shadow-xl transition-all hover:-translate-y-1">
                        <span>Start Free — No Credit Card</span>
                        <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                    </a>
                </div>
                
                <div class="mt-8 flex flex-wrap justify-center gap-6 text-white/70 text-sm">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-check-circle"></i>
                        <span>100% Free platform</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-check-circle"></i>
                        <span>Pay only for printing</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-check-circle"></i>
                        <span>Cancel anytime</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-gray-400 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-4 gap-8">
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <img src="<?php echo $basePath; ?>assets/images/logo.svg" alt="<?php echo $brandName; ?>" class="h-9 w-auto filter brightness-0 invert">
                    </div>
                    <p class="text-sm mb-4">
                        Professional digital business cards for Omani SME companies. Free to use.
                    </p>
                    <p class="text-xs text-gray-500">
                        Based in Muscat, Oman
                    </p>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Product</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#features" class="hover:text-white transition-colors">Features</a></li>
                        <li><a href="#pricing" class="hover:text-white transition-colors">Pricing</a></li>
                        <li><a href="#journey" class="hover:text-white transition-colors">How It Works</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Company</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?php echo $basePath; ?>about.php" class="hover:text-white transition-colors">About</a></li>
                        <li><a href="<?php echo $basePath; ?>contact.php" class="hover:text-white transition-colors">Contact</a></li>
                        <li><a href="<?php echo $basePath; ?>careers.php" class="hover:text-white transition-colors">Careers</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Legal</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?php echo $basePath; ?>privacy.php" class="hover:text-white transition-colors">Privacy Policy</a></li>
                        <li><a href="<?php echo $basePath; ?>terms.php" class="hover:text-white transition-colors">Terms of Service</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 mt-12 pt-8 flex flex-col md:flex-row justify-between items-center gap-4">
                <p class="text-sm">&copy; <?php echo date('Y'); ?> <?php echo $brandName; ?>. All rights reserved.</p>
                <div class="flex items-center gap-2 text-sm">
                    <span>🇴🇲</span>
                    <span>Made with ❤️ in Oman</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- GSAP Animations -->
    <script>
        // Register ScrollTrigger
        gsap.registerPlugin(ScrollTrigger);
        
        // Scroll progress bar
        gsap.to("#scrollProgress", {
            scaleX: 1,
            ease: "none",
            scrollTrigger: {
                trigger: "body",
                start: "top top",
                end: "bottom bottom",
                scrub: 0.3
            }
        });
        
        // Reveal animations
        document.querySelectorAll('.reveal-up').forEach((el, i) => {
            gsap.to(el, {
                opacity: 1,
                y: 0,
                duration: 0.8,
                ease: "power3.out",
                scrollTrigger: {
                    trigger: el,
                    start: "top 85%",
                    toggleActions: "play none none reverse"
                },
                delay: i * 0.05
            });
        });
        
        document.querySelectorAll('.reveal-left').forEach(el => {
            gsap.to(el, {
                opacity: 1,
                x: 0,
                duration: 0.8,
                ease: "power3.out",
                scrollTrigger: {
                    trigger: el,
                    start: "top 85%",
                    toggleActions: "play none none reverse"
                }
            });
        });
        
        document.querySelectorAll('.reveal-right').forEach(el => {
            gsap.to(el, {
                opacity: 1,
                x: 0,
                duration: 0.8,
                ease: "power3.out",
                scrollTrigger: {
                    trigger: el,
                    start: "top 85%",
                    toggleActions: "play none none reverse"
                }
            });
        });
        
        document.querySelectorAll('.reveal-scale').forEach(el => {
            gsap.to(el, {
                opacity: 1,
                scale: 1,
                duration: 0.8,
                ease: "back.out(1.7)",
                scrollTrigger: {
                    trigger: el,
                    start: "top 85%",
                    toggleActions: "play none none reverse"
                }
            });
        });
        
        // Journey progress bar
        ScrollTrigger.create({
            trigger: "#journey",
            start: "top center",
            end: "bottom center",
            onUpdate: (self) => {
                const progress = Math.min(self.progress * 100, 100);
                document.getElementById('journeyProgress').style.width = progress + '%';
            }
        });
        
        // Step cards interaction
        document.querySelectorAll('.step-card').forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.classList.add('active');
            });
            card.addEventListener('mouseleave', () => {
                card.classList.remove('active');
            });
        });
        
        // Navbar background on scroll
        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if (window.scrollY > 100) {
                nav.classList.add('shadow-lg', 'bg-white');
                nav.classList.remove('bg-white/90');
            } else {
                nav.classList.remove('shadow-lg', 'bg-white');
                nav.classList.add('bg-white/90');
            }
        });
        
        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    const offset = 80;
                    const bodyRect = document.body.getBoundingClientRect().top;
                    const elementRect = target.getBoundingClientRect().top;
                    const elementPosition = elementRect - bodyRect;
                    const offsetPosition = elementPosition - offset;

                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>
</body>
</html>
