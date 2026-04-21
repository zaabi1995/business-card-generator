<?php
/**
 * Cardify - Careers
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Careers at Cardify — Join Our Team in Oman';
$pageDescription = 'Join Oman\'s leading business card platform. View open positions in development, design, sales, and more at Cardify.';
$canonicalUrl = 'https://cardify.om/careers';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

// Fetch career listings from database
$db = Database::getInstance();
$jobs = [];
$singleJob = null;

// Check if viewing single job. Same slug guard as blog.php.
$jobSlug = $_GET['job'] ?? null;
if (is_string($jobSlug) && !preg_match('~^[a-z0-9_-]{1,120}$~i', $jobSlug)) {
    $jobSlug = null;
}

if ($db->tableExists('career_listings')) {
    if ($jobSlug) {
        // Single job view
        $singleJob = $db->fetchOne(
            "SELECT * FROM career_listings WHERE slug = ? AND status = 'open'",
            [$jobSlug]
        );
        if ($singleJob) {
            $pageTitle = htmlspecialchars($singleJob['title']) . ' — Careers at Cardify';
            $pageDescription = 'Apply for ' . htmlspecialchars($singleJob['title']) . ' at Cardify in ' . htmlspecialchars($singleJob['location'] ?? 'Muscat') . ', Oman. ' . htmlspecialchars(substr(strip_tags($singleJob['description']), 0, 100));
            $canonicalUrl = 'https://cardify.om/careers/' . $singleJob['slug'];
        }
    }
    
    // Get all open listings
    $jobs = $db->fetchAll(
        "SELECT * FROM career_listings WHERE status = 'open' ORDER BY created_at DESC"
    );
}

require_once INCLUDES_DIR . '/ui-header.php';

$benefits = [
    ['icon' => 'fa-laptop-house', 'title' => 'Flexible Work', 'desc' => 'Remote-friendly with flexible hours'],
    ['icon' => 'fa-graduation-cap', 'title' => 'Learning Budget', 'desc' => 'Annual budget for courses and conferences'],
    ['icon' => 'fa-heart-pulse', 'title' => 'Health Insurance', 'desc' => 'Comprehensive medical coverage'],
    ['icon' => 'fa-umbrella-beach', 'title' => 'Paid Time Off', 'desc' => 'Generous vacation and personal days'],
    ['icon' => 'fa-chart-line', 'title' => 'Growth Path', 'desc' => 'Clear career progression opportunities'],
    ['icon' => 'fa-users', 'title' => 'Team Events', 'desc' => 'Regular team activities and celebrations'],
];
?>

<div class="min-h-screen bg-gray-50">
    <?php if ($singleJob): ?>
    <!-- Single Job View -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="<?php echo getBasePath(); ?>careers.php" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 mb-4">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Careers
            </a>
            <h1 class="text-4xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($singleJob['title']); ?></h1>
            <div class="flex flex-wrap gap-4 text-gray-500">
                <?php if ($singleJob['department']): ?>
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-building"></i>
                    <?php echo htmlspecialchars($singleJob['department']); ?>
                </span>
                <?php endif; ?>
                <?php if ($singleJob['location']): ?>
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-location-dot"></i>
                    <?php echo htmlspecialchars($singleJob['location']); ?>
                </span>
                <?php endif; ?>
                <?php if ($singleJob['employment_type']): ?>
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-clock"></i>
                    <?php echo ucfirst(str_replace('-', ' ', $singleJob['employment_type'])); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12">
            <div class="prose prose-lg max-w-none">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Job Description</h2>
                <p class="text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($singleJob['description']); ?></p>
                
                <?php if ($singleJob['requirements']): ?>
                <h2 class="text-xl font-bold text-gray-900 mt-8 mb-4">Requirements</h2>
                <p class="text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($singleJob['requirements']); ?></p>
                <?php endif; ?>
                
                <?php if ($singleJob['benefits']): ?>
                <h2 class="text-xl font-bold text-gray-900 mt-8 mb-4">Benefits</h2>
                <p class="text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($singleJob['benefits']); ?></p>
                <?php endif; ?>
                
                <?php if ($singleJob['salary_range']): ?>
                <h2 class="text-xl font-bold text-gray-900 mt-8 mb-4">Compensation</h2>
                <p class="text-gray-600"><?php echo htmlspecialchars($singleJob['salary_range']); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="mt-8 pt-8 border-t border-gray-200">
                <h3 class="font-bold text-gray-900 mb-4">Ready to Apply?</h3>
                <a href="<?php echo getBasePath(); ?>contact.php?subject=Application: <?php echo urlencode($singleJob['title']); ?>" 
                   class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors">
                    <i class="fa-solid fa-paper-plane"></i>
                    Apply Now
                </a>
            </div>
        </div>
        
        <div class="mt-8 text-center">
            <a href="<?php echo getBasePath(); ?>careers.php" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Careers
            </a>
        </div>
    </div>

    <?php if (isset($singleJob) && $singleJob): ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'JobPosting',
        'title' => $singleJob['title'],
        'description' => strip_tags($singleJob['description']),
        'datePosted' => $singleJob['created_at'],
        'employmentType' => strtoupper(str_replace('-', '_', $singleJob['employment_type'] ?? 'FULL_TIME')),
        'jobLocation' => [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $singleJob['location'] ?? 'Muscat',
                'addressCountry' => 'OM'
            ]
        ],
        'hiringOrganization' => [
            '@type' => 'Organization',
            'name' => 'Cardify',
            'sameAs' => 'https://cardify.om'
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>
    <?php endif; ?>

    <?php else: ?>
    <!-- Careers Listing -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3">Join Our Team</h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto">
                Help us build the future of professional networking. We're looking for passionate people to join our growing team.
            </p>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        
        <!-- About Working Here -->
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 mb-12">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <span class="text-blue-600 font-semibold text-sm uppercase tracking-wider">Why <?php echo $brandName; ?>?</span>
                    <h2 class="text-3xl font-bold text-gray-900 mt-2 mb-6">Build Something Meaningful</h2>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        At <?php echo $brandName; ?>, you'll work on products that help thousands of professionals 
                        connect and grow their networks. We value innovation, collaboration, and a healthy work-life balance.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        We're a small but mighty team where every voice matters. You'll have the opportunity to make a real 
                        impact, learn continuously, and grow your career in a supportive environment.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <?php 
                    $benefitIcons = [
                        ['icon' => 'fa-laptop-house', 'title' => 'Flexible Work', 'desc' => 'Remote-friendly with flexible hours'],
                        ['icon' => 'fa-graduation-cap', 'title' => 'Learning Budget', 'desc' => 'Annual budget for courses and conferences'],
                        ['icon' => 'fa-heart-pulse', 'title' => 'Health Insurance', 'desc' => 'Comprehensive medical coverage'],
                        ['icon' => 'fa-umbrella-beach', 'title' => 'Paid Time Off', 'desc' => 'Generous vacation and personal days'],
                    ];
                    foreach ($benefitIcons as $benefit): ?>
                        <div class="bg-gray-50 rounded-xl p-4 text-center">
                            <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center mx-auto mb-3">
                                <i class="fa-solid <?php echo $benefit['icon']; ?> text-blue-600"></i>
                            </div>
                            <h4 class="font-semibold text-gray-900 text-sm mb-1"><?php echo $benefit['title']; ?></h4>
                            <p class="text-gray-500 text-xs"><?php echo $benefit['desc']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Open Positions -->
        <div class="mb-12">
            <h2 class="text-2xl font-bold text-gray-900 mb-8">Open Positions</h2>
            
            <?php if (empty($jobs)): ?>
                <div class="bg-white rounded-xl shadow-sm p-12 text-center">
                    <div class="w-20 h-20 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-briefcase text-3xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No Open Positions</h3>
                    <p class="text-gray-600 mb-6">
                        We don't have any open positions at the moment, but we're always looking for talented people.
                    </p>
                    <a href="<?php echo getBasePath(); ?>contact.php" class="text-blue-600 hover:text-blue-700 font-medium">
                        Send us your resume via contact form →
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($jobs as $job): ?>
                        <div class="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition-shadow">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($job['title']); ?></h3>
                                    <div class="flex flex-wrap gap-3 text-sm">
                                        <?php if ($job['department']): ?>
                                        <span class="flex items-center gap-1 text-gray-600">
                                            <i class="fa-solid fa-building text-gray-400"></i>
                                            <?php echo htmlspecialchars($job['department']); ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($job['location']): ?>
                                        <span class="flex items-center gap-1 text-gray-600">
                                            <i class="fa-solid fa-location-dot text-gray-400"></i>
                                            <?php echo htmlspecialchars($job['location']); ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($job['employment_type']): ?>
                                        <span class="flex items-center gap-1 text-gray-600">
                                            <i class="fa-solid fa-clock text-gray-400"></i>
                                            <?php echo ucfirst(str_replace('-', ' ', $job['employment_type'])); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-gray-600 mt-2 line-clamp-2"><?php echo htmlspecialchars($job['description']); ?></p>
                                </div>
                                <div class="flex-shrink-0 flex gap-2">
                                    <a href="<?php echo getBasePath(); ?>careers.php?job=<?php echo urlencode($job['slug']); ?>" 
                                       class="inline-flex items-center gap-2 px-4 py-2 text-blue-600 bg-blue-50 font-medium rounded-lg hover:bg-blue-100 transition-colors">
                                        View Details
                                    </a>
                                    <a href="<?php echo getBasePath(); ?>contact.php?subject=Application: <?php echo urlencode($job['title']); ?>" 
                                       class="inline-flex items-center gap-2 px-6 py-2 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                        Apply
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Don't See Your Role -->
        <div class="bg-gradient-to-br from-blue-600 to-purple-700 rounded-2xl p-8 lg:p-12 text-white text-center">
            <h2 class="text-2xl font-bold mb-4">Don't See Your Role?</h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto">
                We're always looking for talented people. Send us your resume and tell us how you can contribute to our team.
            </p>
            <a href="<?php echo getBasePath(); ?>contact.php" 
               class="inline-flex items-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                <i class="fa-solid fa-paper-plane"></i>
                Get in Touch
            </a>
        </div>

        <!-- Back to Home -->
        <div class="mt-12 text-center">
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
