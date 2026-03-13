<?php
/**
 * Cardify - Contact Us
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Contact Cardify — Get in Touch';
$pageDescription = 'Questions about business cards? Contact Cardify for support, partnerships, or print shop inquiries in Oman. We\'re here to help.';
$canonicalUrl = 'https://cardify.om/contact';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;
$navLinks = [
    ['href' => getBasePath() . '#features', 'label' => 'Features'],
    ['href' => getBasePath() . '#pricing', 'label' => 'Pricing'],
    ['href' => getBasePath() . 'about.php', 'label' => 'About'],
    ['href' => getBasePath() . 'contact.php', 'label' => 'Contact'],
];

// Handle form submission
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (empty($name) || empty($email) || empty($message)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // In production, send email or save to database
        // For now, just show success
        $success = true;
    }
    } // end CSRF else
}

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-gradient-to-br from-blue-600 to-blue-800 text-white py-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold mb-4">Contact Us</h1>
            <p class="text-blue-100 text-lg max-w-2xl mx-auto">
                Have questions? We'd love to hear from you. Send us a message and we'll respond as soon as possible.
            </p>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="grid lg:grid-cols-3 gap-12">
            
            <!-- Contact Info -->
            <div class="lg:col-span-1 space-y-8">
                
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-building text-xl text-blue-600"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-2">Company</h3>
                    <p class="text-gray-600">
                        <strong>Cardify</strong><br>
                        Professional Business Card Platform
                    </p>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-location-dot text-xl text-green-600"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-2">Address</h3>
                    <p class="text-gray-600">
                        AK Tower, PO Box 2237<br>
                        Bousher, Muscat 133<br>
                        Sultanate of Oman
                    </p>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="w-12 h-12 rounded-lg bg-purple-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-envelope text-xl text-purple-600"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-2">Get in Touch</h3>
                    <p class="text-gray-600">
                        Use the contact form to reach our team for general inquiries, support, or sales questions.
                    </p>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="w-12 h-12 rounded-lg bg-orange-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-share-nodes text-xl text-orange-600"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-2">Social Media</h3>
                    <div class="flex gap-3 mt-4">
                        <a href="https://instagram.com/cardifyom" target="_blank" rel="noopener noreferrer" 
                           class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-pink-600 hover:text-white flex items-center justify-center transition-colors">
                            <i class="fa-brands fa-instagram text-gray-600"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Contact Form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-2xl shadow-sm p-8">
                    <?php if ($success): ?>
                        <div class="text-center py-12">
                            <div class="w-20 h-20 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-6">
                                <i class="fa-solid fa-check text-3xl text-green-600"></i>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900 mb-4">Message Sent!</h2>
                            <p class="text-gray-600 mb-8">
                                Thank you for reaching out. We'll get back to you within 24-48 hours.
                            </p>
                            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                                <i class="fa-solid fa-arrow-left"></i>
                                Back to Home
                            </a>
                        </div>
                    <?php else: ?>
                        <h2 class="text-2xl font-bold text-gray-900 mb-6">Send us a Message</h2>
                        
                        <?php if ($error): ?>
                            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
                                <i class="fa-solid fa-circle-exclamation mr-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="space-y-6">
                            <?php echo csrfField(); ?>
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Your Name *</label>
                                    <input type="text" name="name" required
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                           class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="John Doe">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                                    <input type="email" name="email" required
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                           class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="john@example.com">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
                                <select name="subject" 
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="general">General Inquiry</option>
                                    <option value="support">Technical Support</option>
                                    <option value="sales">Sales Question</option>
                                    <option value="partnership">Partnership Opportunity</option>
                                    <option value="feedback">Feedback</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Message *</label>
                                <textarea name="message" rows="6" required
                                          class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                          placeholder="How can we help you?"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                            </div>

                            <button type="submit" 
                                    class="w-full md:w-auto px-8 py-4 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
                                <i class="fa-solid fa-paper-plane"></i>
                                Send Message
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Back to Home -->
        <div class="mt-12 text-center">
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
